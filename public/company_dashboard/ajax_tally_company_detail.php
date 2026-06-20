<?php
/**
 * AJAX: Fetch Tally company detail + duplicate check
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

$companyName = trim((string) ($_GET['company_name'] ?? ''));
if ($companyName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'company_name is required']);
    exit;
}

$ownerId = getOwnerUserId($pdo, $userId);
$service = new TallyCompanyImportService($pdo, $ownerId);

/* Fetch company detail */
$result = $service->fetchCompanyDetail($companyName);
if (!$result['ok']) {
    echo json_encode($result);
    exit;
}

/* Check duplicates */
$duplicates = $service->checkDuplicates($result['mapped']);

echo json_encode([
    'ok' => true,
    'mapped' => $result['mapped'],
    'duplicates' => $duplicates,
    'has_duplicates' => count($duplicates) > 0,
]);
