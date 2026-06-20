<?php
/**
 * AJAX: Create Auditor Master Record
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
require_once __DIR__ . '/../../app/helpers/entity_master_helper.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

requireCsrfToken();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid data']);
    exit;
}

$ownerId = getOwnerUserId($pdo, $userId);
$auditorId = createAuditor($pdo, $ownerId, $data);

/* Return full auditor data for auto-selection */
$auditor = getAuditor($pdo, $auditorId);

echo json_encode([
    'ok' => true,
    'auditor_id' => $auditorId,
    'firm_name' => $auditor['firm_name'] ?? ($data['firm_name'] ?? ''),
    'partner_name' => $auditor['partner_name'] ?? ($data['partner_name'] ?? ''),
    'frn' => $auditor['frn'] ?? ($data['frn'] ?? ''),
    'membership_no' => $auditor['membership_no'] ?? ($data['membership_no'] ?? ''),
    'email' => $auditor['email'] ?? ($data['email'] ?? ''),
    'mobile' => $auditor['mobile'] ?? ($data['mobile'] ?? ''),
]);
