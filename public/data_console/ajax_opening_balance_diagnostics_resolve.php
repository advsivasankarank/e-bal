<?php
/**
 * e-BAL — Opening Balance Diagnostics Resolve Endpoint
 *
 * POST /data_console/ajax_opening_balance_diagnostics_resolve.php
 * Body: { entity_id, fy_id, ledger_name, resolution: 'accept_tally'|'keep_app' }
 *
 * 'accept_tally' copies Tally's reported opening balance into e-BAL's own
 * opening_amount/opening_dr_cr columns for that ledger. 'keep_app' makes no
 * data change -- it only records that the CA reviewed the mismatch and
 * chose to keep e-BAL's existing figure. Neither side is ever auto-applied
 * without this explicit call; every resolution is logged to
 * opening_balance_diagnostics_audit for the audit trail.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/helpers/opening_balance_diagnostics_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id']) || empty($_SESSION['fy_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.', 'redirect' => true]);
    exit;
}

requireFullContext();
requireCsrfToken(true);

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);
$userId     = (int) ($_SESSION['user_id'] ?? 0);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

$requestedEntityId = isset($data['entity_id']) ? (int) $data['entity_id'] : $company_id;
$requestedFyId = isset($data['fy_id']) ? (int) $data['fy_id'] : $fy_id;
if ($requestedEntityId !== $company_id || $requestedFyId !== $fy_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'entity_id/fy_id does not match the active session context.']);
    exit;
}

$ledgerName = trim((string) ($data['ledger_name'] ?? ''));
$resolution = trim((string) ($data['resolution'] ?? ''));

if ($ledgerName === '' || !in_array($resolution, ['accept_tally', 'keep_app'], true)) {
    echo json_encode(['success' => false, 'error' => 'ledger_name and a valid resolution are required.']);
    exit;
}

try {
    $result = resolveOpeningBalanceDiagnostic($pdo, $company_id, $fy_id, $ledgerName, $resolution, $userId > 0 ? $userId : null);

    if (!($result['ok'] ?? false)) {
        echo json_encode(['success' => false, 'error' => $result['message'] ?? 'Could not resolve this diagnostic.']);
        exit;
    }

    /* Re-run the full comparison so the response always reflects the real,
       current state — never a stale claim that everything now matches. */
    $freshDiagnostics = computeOpeningBalanceDiagnostics($pdo, $company_id, $fy_id);

    echo json_encode([
        'success' => true,
        'ledger_name' => $ledgerName,
        'resolution' => $resolution,
        'diagnostics' => $freshDiagnostics,
    ]);
} catch (Throwable $e) {
    error_log('Opening balance diagnostics resolve failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while applying the resolution. Please try again.']);
}
