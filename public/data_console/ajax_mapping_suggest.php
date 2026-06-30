<?php
/**
 * e-BAL — AJAX Mapping Suggestion Endpoint
 *
 * Generates mapping suggestions for all ledgers using the unified pipeline.
 * Returns JSON only.
 *
 * POST body (JSON): {} (empty or with filters)
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/hierarchy_ai_mapping.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/bulk_mapping_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Early session check to return JSON instead of redirect on expiry */
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id']) || empty($_SESSION['fy_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.', 'redirect' => true]);
    exit;
}

requireFullContext();
requireCsrfToken(true);

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);

if ($company_id <= 0 || $fy_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid context.']);
    exit;
}

ensureMappingAiSchema($pdo);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());

$mappingEngine = new AIMappingEngine($companyCategory, $pdo, $company_id);
$hierarchyEngine = null;
try {
    $hierarchyEngine = new HierarchyAIMappingEngine($pdo, $company_id, $companyCategory);
} catch (Throwable $e) {
    error_log('Mapping suggest: hierarchy engine init failed: ' . $e->getMessage());
}

$suggestions = generateBulkSuggestions($pdo, $company_id, $fy_id, $hierarchyEngine, $mappingEngine);

// Compute summary stats
$total = count($suggestions);
$mapped = 0;
$unmapped = 0;
$autoSuggested = 0;
$highConfidence = 0;
$manualReview = 0;

foreach ($suggestions as $s) {
    if ($s['is_mapped']) {
        $mapped++;
    } else {
        $unmapped++;
        if ($s['confidence'] >= 90) {
            $autoSuggested++;
            $highConfidence++;
        } elseif ($s['confidence'] >= 70) {
            $autoSuggested++;
        } else {
            $manualReview++;
        }
    }
}

echo json_encode([
    'success' => true,
    'suggestions' => $suggestions,
    'summary' => [
        'total' => $total,
        'mapped' => $mapped,
        'unmapped' => $unmapped,
        'auto_suggested' => $autoSuggested,
        'high_confidence' => $highConfidence,
        'manual_review' => $manualReview,
    ],
]);
