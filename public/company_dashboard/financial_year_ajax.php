<?php
/**
 * AJAX endpoint: Get financial years for a company
 * Returns enriched FY data with workflow status for the workspace launcher.
 *
 * HARDENED: Authentication + ownership validation required.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

$companyId = (int) ($_GET['company_id'] ?? 0);
if ($companyId <= 0) {
    echo json_encode(['ok' => false, 'fys' => [], 'company' => null]);
    exit;
}

/* Validate company ownership */
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
$ownerId = getOwnerUserId($pdo, $userId);
$stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND owner_user_id = ?");
$stmt->execute([$companyId, $ownerId]);
if ((int) $stmt->fetchColumn() === 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied']);
    exit;
}

/* Get company details */
$coStmt = $pdo->prepare("SELECT id, name, category, pan, cin, llp_code FROM companies WHERE id = ?");
$coStmt->execute([$companyId]);
$company = $coStmt->fetch(PDO::FETCH_ASSOC);

/* Get FYs with workflow status */
$stmt = $pdo->prepare("
    SELECT
        fy.id,
        fy.fy_label,
        fy.fy_start,
        fy.fy_end,
        COALESCE(ws.ledger_fetched, 0) AS ledger_fetched,
        COALESCE(ws.mapping_completed, 0) AS mapping_completed,
        COALESCE(ws.tally_fetched, 0) AS tally_fetched,
        COALESCE(ws.notes_prepared, 0) AS notes_prepared,
        COALESCE(ws.profit_loss_prepared, 0) AS profit_loss_prepared,
        COALESCE(ws.balance_sheet_prepared, 0) AS balance_sheet_prepared,
        COALESCE(ws.directors_report_prepared, 0) AS directors_report_prepared,
        COALESCE(ws.verified, 0) AS verified,
        ws.updated_at
    FROM financial_years fy
    LEFT JOIN workflow_status ws ON ws.company_id = fy.company_id AND ws.fy_id = fy.id
    WHERE fy.company_id = ?
    ORDER BY fy.fy_start DESC
");
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Current Indian FY: April 1 to March 31 */
$currentMonth = (int) date('m');
$currentYear = (int) date('Y');
$currentFYStart = $currentMonth >= 4 ? $currentYear : $currentYear - 1;
$currentFYLabel = $currentFYStart . '-' . ($currentFYStart + 1);

$fys = [];
foreach ($rows as $row) {
    /* Compute step completion */
    $steps = ['ledger_fetched', 'mapping_completed', 'tally_fetched', 'notes_prepared', 'profit_loss_prepared', 'balance_sheet_prepared'];
    $isCorporate = strtolower(str_replace(['-', ' '], '_', $company['category'] ?? '')) === 'corporate';
    if ($isCorporate) $steps[] = 'directors_report_prepared';

    $completed = 0;
    foreach ($steps as $s) {
        if (!empty($row[$s])) $completed++;
    }
    $total = count($steps);
    $pct = $total > 0 ? (int) round($completed / $total * 100) : 0;
    $verified = !empty($row['verified']);

    /* Determine status */
    if ($verified) $status = 'completed';
    elseif ($completed === $total) $status = 'ready_for_review';
    elseif ($completed === 0) $status = 'new';
    else $status = 'in_progress';

    /* Is this the current FY? */
    $fyStart = (int) substr($row['fy_start'] ?? '', 0, 4);
    $isCurrent = ($fyStart === $currentFYStart);

    $fys[] = [
        'id' => (int) $row['id'],
        'fy_label' => $row['fy_label'] ?: ('FY ' . $row['id']),
        'fy_start' => $row['fy_start'] ?? '',
        'fy_end' => $row['fy_end'] ?? '',
        'is_current' => $isCurrent,
        'status' => $status,
        'progress_pct' => $pct,
        'completed_steps' => $completed,
        'total_steps' => $total,
        'verified' => $verified,
        'last_modified' => $row['updated_at'] ?? '',
    ];
}

echo json_encode([
    'ok' => true,
    'company' => $company ? [
        'id' => (int) $company['id'],
        'name' => $company['name'],
        'category' => $company['category'],
        'pan' => $company['pan'] ?? '',
        'cin' => $company['cin'] ?? '',
        'llp_code' => $company['llp_code'] ?? '',
    ] : null,
    'fys' => $fys,
]);
