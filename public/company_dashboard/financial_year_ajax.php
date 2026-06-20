<?php
/**
 * AJAX endpoint: Get financial years for a company
 * Requires authenticated session with valid assignment context.
 *
 * HARDENED: Added authentication + ownership validation.
 * Removed auto-create FY logic (moved to authenticated company_edit flow).
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
    echo json_encode([]);
    exit;
}

/* Validate company ownership */
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
$ownerId = getOwnerUserId($pdo, $userId);
$stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND (owner_user_id = ? OR owner_user_id IS NULL)");
$stmt->execute([$companyId, $ownerId]);
if ((int) $stmt->fetchColumn() === 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Access denied']);
    exit;
}

/* Get FYs for this company */
$stmt = $pdo->prepare("SELECT id, fy_label FROM financial_years WHERE company_id = ? ORDER BY id DESC");
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [];
foreach ($rows as $row) {
    $result[] = [
        'id' => (int) $row['id'],
        'fy_label' => $row['fy_label'] ?: ('FY ' . $row['id']),
    ];
}

echo json_encode($result);
