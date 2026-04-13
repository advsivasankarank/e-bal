<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/xml_sanitizer.php';
require_once __DIR__ . '/../../app/helpers/tb_import_helper.php';
require_once __DIR__ . '/../../config/app.php';

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
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bridge_clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id VARCHAR(50) NOT NULL UNIQUE,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
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

$rows = [];
$nameNodes = $xml->xpath("//*[local-name()='DSPACCNAME']");
$infoNodes = $xml->xpath("//*[local-name()='DSPACCINFO']");

if (!empty($nameNodes) && !empty($infoNodes)) {
    $total = min(count($nameNodes), count($infoNodes));
    for ($i = 0; $i < $total; $i++) {
        $name = trim((string) ($nameNodes[$i]->DSPDISPNAME ?? ''));
        $dr = (float) ($infoNodes[$i]->DSPCLDRAMT->DSPCLDRAMTA ?? 0);
        $cr = (float) ($infoNodes[$i]->DSPCLCRAMT->DSPCLCRAMTA ?? 0);
        if ($name === '' || ($dr == 0.0 && $cr == 0.0)) {
            continue;
        }
        $rows[] = [
            'ledger_name' => $name,
            'parent_group' => '',
            'amount' => $dr != 0.0 ? abs($dr) : abs($cr),
            'type' => $dr != 0.0 ? 'DR' : 'CR',
        ];
    }
} else {
    foreach ($xml->xpath("//*[local-name()='DSPACCLINE']") as $node) {
        $name = trim((string) ($node->dspaccname->dspdispname ?? ''));
        $dr = (float) ($node->dspaccinfo->dspcldramt->dspcldramta ?? 0);
        $cr = (float) ($node->dspaccinfo->dspclcramt->dspclcramta ?? 0);
        if ($name === '' || ($dr == 0.0 && $cr == 0.0)) {
            continue;
        }
        $rows[] = [
            'ledger_name' => $name,
            'parent_group' => '',
            'amount' => $dr != 0.0 ? abs($dr) : abs($cr),
            'type' => $dr != 0.0 ? 'DR' : 'CR',
        ];
    }
}

try {
    $result = importTrialBalanceRows($pdo, $companyId, $fyId, $rows, [], [], true);
    echo json_encode(['ok' => true, 'client_id' => $clientId, 'result' => $result]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}
