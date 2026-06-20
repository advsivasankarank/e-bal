<?php
/**
 * AJAX: Search Auditor Master
 * Returns JSON list of auditors matching query.
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

$ownerId = getOwnerUserId($pdo, $userId);
$query = trim((string) ($_GET['q'] ?? ''));

$auditors = searchAuditors($pdo, $ownerId, $query);

echo json_encode(['ok' => true, 'auditors' => $auditors]);
