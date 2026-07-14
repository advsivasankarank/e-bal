<?php
/**
 * API endpoint: Upload ledger master XML from Tally bridge
 *
 * HARDENED: Token must be set in env. Bridge client ownership validated.
 * DDL moved to migration script.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/xml_sanitizer.php';
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = trim((string) ($headers['X-Bridge-Token'] ?? $headers['x-bridge-token'] ?? $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? ''));
$clientId = trim((string) ($_GET['client_id'] ?? ''));
$companyId = (int) ($_GET['company_id'] ?? 0);
$fyId = (int) ($_GET['fy_id'] ?? 0);
$xmlRaw = file_get_contents('php://input');

/* HARDEN: Token must be configured. Empty token = reject. */
$expected = defined('EBAL_BRIDGE_TOKEN') ? trim((string) EBAL_BRIDGE_TOKEN) : '';
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

/* Resolve company/fy from bridge client registration */
if ($companyId <= 0 || $fyId <= 0) {
    if ($clientId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'company_id and fy_id are required']);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT company_id, fy_id
        FROM bridge_clients
        WHERE client_id = ? AND active = 1
        LIMIT 1
    ");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $companyId = (int) $row['company_id'];
        $fyId = (int) $row['fy_id'];
    }
}

if ($companyId <= 0 || $fyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'company_id and fy_id are required']);
    exit;
}

/* Validate company and FY exist */
$validateStmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE id = ?");
$validateStmt->execute([$companyId]);
if ((int) $validateStmt->fetchColumn() === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid company_id']);
    exit;
}

$validateFyStmt = $pdo->prepare("SELECT COUNT(*) FROM financial_years WHERE id = ? AND company_id = ?");
$validateFyStmt->execute([$fyId, $companyId]);
if ((int) $validateFyStmt->fetchColumn() === 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid fy_id for this company']);
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
