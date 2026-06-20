<?php
/**
 * AJAX: List available Tally companies
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
require_once __DIR__ . '/../../app/services/tally_company_import_service.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

$ownerId = getOwnerUserId($pdo, $userId);
$service = new TallyCompanyImportService($pdo, $ownerId);

/* Health check first */
$health = $service->healthCheck();
if (!$health['ok']) {
    echo json_encode($health);
    exit;
}

/* List companies */
$result = $service->listCompanies();
echo json_encode($result);
