<?php
/**
 * e-BAL — Opening Balance Diagnostics Endpoint
 *
 * GET /data_console/ajax_opening_balance_diagnostics.php?entity_id=&fy_id=
 *
 * Compares Tally's own reported opening balance (captured at import time)
 * against e-BAL's carried-forward expected opening for every ledger, and
 * returns the differing ledgers. See app/helpers/opening_balance_diagnostics_helper.php.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/helpers/opening_balance_diagnostics_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);

/* This app scopes everything to the session's active company/FY — there is
   no existing concept of viewing another company's data by ID, so an
   entity_id/fy_id from the query string is only accepted if it matches the
   session context (otherwise this would be a new IDOR). */
$requestedEntityId = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : $company_id;
$requestedFyId = isset($_GET['fy_id']) ? (int) $_GET['fy_id'] : $fy_id;

if ($requestedEntityId !== $company_id || $requestedFyId !== $fy_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'entity_id/fy_id does not match the active session context.']);
    exit;
}

try {
    $diagnostics = computeOpeningBalanceDiagnostics($pdo, $company_id, $fy_id);
    echo json_encode(array_merge(['success' => true], $diagnostics));
} catch (Throwable $e) {
    error_log('Opening balance diagnostics build failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while building diagnostics.']);
}
