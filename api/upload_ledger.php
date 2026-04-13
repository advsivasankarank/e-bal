<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/xml_sanitizer.php';
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$token = trim((string) ($payload['token'] ?? ''));
$clientId = trim((string) ($payload['client_id'] ?? ''));
$companyId = (int) ($payload['company_id'] ?? 0);
$fyId = (int) ($payload['fy_id'] ?? 0);
$xmlRaw = (string) ($payload['xml'] ?? '');

$expected = getenv('EBAL_BRIDGE_TOKEN') ?: '';
if ($expected !== '' && $token !== $expected) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($companyId <= 0 || $fyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'company_id and fy_id are required']);
    exit;
}

if (trim($xmlRaw) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'XML payload is empty']);
    exit;
}

$xmlRaw = sanitizeTallyXML($xmlRaw);
libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlRaw);
if ($xml === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid XML']);
    exit;
}

$ledgers = $xml->xpath("//*[local-name()='LEDGER']");
if (!$ledgers || count($ledgers) === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'No ledger data found']);
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM tally_ledger_master WHERE company_id=?")
        ->execute([$companyId]);

    $stmt = $pdo->prepare("
        INSERT INTO tally_ledger_master
        (company_id, ledger_name, parent_group)
        VALUES (?, ?, ?)
    ");

    $count = 0;
    foreach ($ledgers as $l) {
        $name = trim((string) ($l['NAME'] ?? ''));
        $parent = trim((string) ($l->PARENT ?? ''));
        if ($name === '') {
            continue;
        }
        $stmt->execute([$companyId, $name, $parent]);
        $count++;
    }

    $pdo->prepare("
        INSERT INTO workflow_status
        (company_id, fy_id, ledger_fetched, updated_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            ledger_fetched = 1,
            updated_at = NOW()
    ")->execute([$companyId, $fyId]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'ledgers' => $count, 'client_id' => $clientId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
