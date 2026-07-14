<?php
/**
 * e-BAL — Ledger Mapping Workbench
 *
 * Excel-like workspace for bulk ledger mapping.
 * Handles missing voucher_entries table gracefully.
 * Shows user-friendly error on failure instead of 500.
 *
 * error_reporting/display_errors/log_errors are now set centrally in
 * app/helpers/runtime_helper.php, loaded transitively before this file.
 */

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    error_log('Mapping Workbench FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h1>ReconHub Error</h1>';
    echo '<p>The mapping workbench encountered an error. Please try again or contact support.</p>';
    echo '<p><small>' . htmlspecialchars($e->getMessage()) . '</small></p>';
    echo '<p><a href="' . (defined('BASE_URL') ? BASE_URL : '/') . '">Return to Dashboard</a></p>';
    echo '</body></html>';
    exit;
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    if (error_reporting() & $errno) {
        error_log("Mapping Workbench ERROR [$errno]: $errstr in $errfile:$errline");
    }
    return false;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    error_log('Mapping Workbench FATAL (shutdown): ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h1>ReconHub Error</h1>';
    echo '<p>The mapping workbench took too long or ran out of resources for this dataset. Please try a smaller "Per page" size or the "TB Impact" view, or contact support.</p>';
    echo '<p><a href="' . (defined('BASE_URL') ? BASE_URL : '/') . '">Return to Dashboard</a></p>';
    echo '</body></html>';
});

require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/hierarchy_ai_mapping.php';
require_once '../../app/helpers/bulk_mapping_helper.php';
require_once '../../app/helpers/reconhub_context_resolver.php';

/* ---- Context resolution via dedicated resolver ---- */
$contextResolver = new ReconHubContextResolver($pdo);
$ctx = $contextResolver->resolve($_GET, $_SESSION);

appLog('INFO', 'ReconHub context timing', [
    'timing_ms' => $ctx['timing_ms'],
    'query_count' => $ctx['query_count'],
    'company_id' => $ctx['company']['id'],
    'fy_id' => $ctx['financial_year']['id'],
    'page' => $ctx['pagination']['page'],
    'per_page' => $ctx['pagination']['per_page'],
    'result' => $ctx['error'] ?? 'success',
]);

/* Handle auth redirect */
if ($ctx['error'] === 'authentication_required') {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

/* Handle access denied or archived — redirect to entity selector */
if ($ctx['error'] === 'company_access_denied' || $ctx['error'] === 'company_archived') {
    $_SESSION['error'] = $ctx['error_message'] ?? 'You do not have permission to access this entity.';
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

/* Handle context/fy/schema error pages */
if ($ctx['error'] !== null) {
    $page_title = $ctx['error_page_title'] ?? 'ReconHub Error';
    $showSidebar = true;
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <div style="max-width:560px;margin:60px auto;text-align:center;padding:40px;background:var(--panel-strong);border:1px solid var(--border);border-radius:12px;">
        <h2 style="margin-bottom:12px;"><?= htmlspecialchars($ctx['error_page_title'] ?? 'Error') ?></h2>
        <p style="font-size:0.95rem;color:var(--text);margin-bottom:20px;"><?= htmlspecialchars($ctx['error_message'] ?? 'An error occurred.') ?></p>
        <?php if ($ctx['error'] === 'schema'): ?>
            <p style="font-size:0.85rem;color:var(--muted);margin-bottom:20px;">Please ask your administrator to run migration <strong>007_mapping_workbench_schema.sql</strong> before using this page.</p>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>dashboard_company.php" class="btn btn-primary" style="padding:10px 24px;font-size:0.9rem;text-decoration:none;">Go to e-BAL Gateway</a>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

/* ---- Extract validated context into page variables ---- */
$company_id  = $ctx['company']['id'];
$companyCategory = $ctx['company']['category'];
$fy_id       = $ctx['financial_year']['id'];
$userId      = $ctx['user']['id'];
$mode        = $ctx['screen']['mode'];
$isGroupMode = $ctx['screen']['is_group_mode'];
$isLedgerMode = $ctx['screen']['is_ledger_mode'];
$mappingSchemaReady = $ctx['schema']['mapping_ready'];

// Step 3 boundary: ReconHub data loading begins below.
require_once __DIR__ . '/../../app/services/reconhub_data_loading_service.php';

/* Inject company category into context for the data-loading service */
$ctx['company']['category'] = $companyCategory;

$dataService = new ReconHubDataLoadingService($pdo);
$data = $dataService->load($ctx);

/* Handle data-loading error */
if ($data['error'] !== null) {
    $page_title = 'ReconHub Error';
    $showSidebar = true;
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <div style="max-width:560px;margin:60px auto;text-align:center;padding:40px;background:var(--panel-strong);border:1px solid var(--border);border-radius:12px;">
        <h2 style="margin-bottom:12px;">ReconHub Error</h2>
        <p style="font-size:0.95rem;color:var(--text);margin-bottom:20px;"><?= htmlspecialchars($data['error']['message'] ?? 'An error occurred.') ?></p>
        <a href="<?= BASE_URL ?>dashboard_company.php" class="btn btn-primary" style="padding:10px 24px;font-size:0.9rem;text-decoration:none;">Go to e-BAL Gateway</a>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

/* Extract variables from service result for the existing view */
$gridData = $data['grid_data'];
$stats = $data['stats'];
$perPage = $data['pagination']['per_page'];
$currentPage = $data['pagination']['page'];
$totalLedgerRows = $data['pagination']['total_rows'];
$totalPages = $data['pagination']['total_pages'];
$gridOffset = $data['pagination']['offset'];
$parentGroupCounts = $data['parent_groups']['counts'];
$parentGroupData = $data['parent_groups']['data'];
$topParentGroups = $data['parent_groups']['top'];
$groupMappingData = $data['parent_groups']['mapping_data'];
$mappingOptions = $data['mapping_options'];
$mappingOptionsJson = $data['mapping_options_json'];
$pageWarning = $data['meta']['page_warning'];
$processingError = $data['meta']['processing_error'];
$tbImpactCount = $data['meta']['tb_impact_count'];
$totalLedgerCount = $data['meta']['total_ledger_count'];
$defaultViewTbImpact = $data['meta']['default_view_tb_impact'];
$pctComplete = $data['meta']['pct_complete'];
$totalGridRows = $totalLedgerRows;
$paginatedGridData = $gridData;

// Step 4 boundary: ReconHub view rendering begins below.

/* Prepare variables for view partials (partials must not access $_SESSION, $_GET directly) */
$sessionCompanyName = $_SESSION['company_name'] ?? 'Not Selected';
$sessionFyName      = $_SESSION['fy_name'] ?? 'Not Selected';
$sessionSuccess     = $_SESSION['success'] ?? null;
$sessionError       = $_SESSION['error'] ?? null;
$currentGetParams   = $_GET;
$csrfTokenValue     = csrfToken();
$csrfInputHtml      = csrfInput();
$reconhubViewPath   = __DIR__ . '/../../app/views/data_console/reconhub';

$page_title = $isLedgerMode ? "Ledger-wise Mapping" : "ReconHub";
$showSidebar = true;
require_once __DIR__ . '/../layouts/header_v2.php';

require $reconhubViewPath . '/styles.php';
require $reconhubViewPath . '/page_header.php';
require $reconhubViewPath . '/context_strip.php';
require $reconhubViewPath . '/status_summary.php';

if ($isGroupMode) {
    require $reconhubViewPath . '/group_view.php';
} else {
    require $reconhubViewPath . '/ledger_view.php';
}

require $reconhubViewPath . '/scripts.php';

unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
