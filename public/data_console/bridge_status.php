<?php
require_once '../../app/context_check.php';
require_once '../../config/database.php';

requireFullContext();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$companyId = (int) ($_SESSION['company_id'] ?? 0);
$fyId = (int) ($_SESSION['fy_id'] ?? 0);

if ($companyId <= 0 || $fyId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing company context']);
    exit;
}

$stmt = $pdo->prepare("SELECT ledger_fetched, mapping_completed, tally_fetched FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$companyId, $fyId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'ok' => true,
    'ledger_fetched' => (int) ($row['ledger_fetched'] ?? 0),
    'mapping_completed' => (int) ($row['mapping_completed'] ?? 0),
    'tally_fetched' => (int) ($row['tally_fetched'] ?? 0),
]);
