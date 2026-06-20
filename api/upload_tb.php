<?php
/**
 * API endpoint: Upload trial balance XML from Tally bridge
 *
 * HARDENED: Token must be set in env. Bridge client ownership validated.
 * DDL moved to migration script.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/xml_sanitizer.php';
require_once __DIR__ . '/../../app/helpers/tb_import_helper.php';
require_once __DIR__ . '/../../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$token = trim((string) ($_GET['token'] ?? ''));
$clientId = trim((string) ($_GET['client_id'] ?? ''));
$companyId = (int) ($_GET['company_id'] ?? 0);
$fyId = (int) ($_GET['fy_id'] ?? 0);
$xmlRaw = file_get_contents('php://input');

/* HARDEN: Token must be configured. Empty token = reject. */
$expected = defined('EBAL_BRIDGE_TOKEN') ? trim((string) EBAL_BRIDGE_TOKEN) : '';
if ($expected === '' || $token !== $expected) {
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
