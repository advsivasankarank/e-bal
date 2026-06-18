<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../app/helpers/company_reporting_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';

$page_title = 'Deliverables';
require_once __DIR__ . '/../layouts/header_v2.php';

requireAssignmentAccess();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$companyMeta = getCompanyReportingMeta($pdo, $company_id);
$entityCategory = getEntityCategory($pdo, $company_id);
$entitySubcategory = $companyMeta['entity_subcategory'] ?? '';
$entityType = $companyMeta['entity_type'] ?? 'N/A';

$workflow = getWorkflow($company_id, $fy_id);

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);
$fs = generateFinancialStatements(
    $pdo, $company_id, $fy_id, $fyName,
    $manualBundle['current'] ?? [], $manualBundle['previous'] ?? []
);
$hasReportData = (bool) ($fs['has_data'] ?? false);
$companyMeta = $fs['company_meta'] ?? $companyMeta;

// --- Compute readiness using centralized engine ---
require_once __DIR__ . '/../../app/helpers/readiness_helper.php';
$readiness = computeReadiness($pdo, $company_id, $fy_id, $fs);
$readinessScore = $readiness['score'];

// Get remark/signoff data for sub-components
$remarkData = [];
$remarkStmt = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'review_remark_%'");
$remarkStmt->execute([$company_id, $fy_id]);
foreach ($remarkStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $remarkData[$row['meta_key']] = $row['meta_value'];
}
$signoffData = [];
$signoffStmt = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'signoff_%'");
$signoffStmt->execute([$company_id, $fy_id]);
foreach ($signoffStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $signoffData[$row['meta_key']] = $row['meta_value'];
}

// Determine delivery status
$blockingErrors = $readiness['validation']['blocking'];
$blockingMissing = !$hasReportData;
$blockingDR = $entityCategory === 'corporate' && ($workflow['directors_report_prepared'] ?? 0) != 1;

if ($blockingMissing) {
    $deliveryStatus = 'draft';
    $deliveryMsg = 'Complete data import and financial statement generation before generating deliverables.';
} elseif ($blockingErrors || $blockingDR) {
    $deliveryStatus = 'blocked';
    $deliveryMsg = 'Issues prevent delivery. Resolve the items below before generating the package.';
} else {
    $deliveryStatus = 'ready';
    $deliveryMsg = 'All documents verified and ready for client delivery.';
}

// Count ready docs
$clientReady = $hasReportData ? 5 : 0;
if ($entityCategory === 'corporate') $clientReady = $hasReportData ? 5 : 0;
elseif ($entitySubcategory === 'llp') $clientReady = $hasReportData ? 5 : 0;
elseif (in_array($entitySubcategory, ['trust', 'society'], true)) $clientReady = $hasReportData ? 4 : 0;
else $clientReady = $hasReportData ? 5 : 0;
$clientTotal = $clientReady;
$internalReady = 4;

$baseUrl = BASE_URL . 'report_download.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/deliverables_workspace.css?v=<?= filemtime(__DIR__ . '/../asset/css/deliverables_workspace.css') ?>">

<div class="active-info" style="display:flex;justify-content:space-between;align-items:center;">
    <span>
        Company: <strong><?= htmlspecialchars($companyName) ?></strong> &middot;
        FY: <strong><?= htmlspecialchars($fyName) ?></strong> &middot;
        Entity: <strong><?= htmlspecialchars($entityType) ?></strong>
    </span>
    <a class="btn btn-sm" href="<?= BASE_URL ?>assignment_home.php">&larr; Back to Assignment</a>
</div>

<!-- Delivery Status Banner -->
<div class="dw-status-banner">
    <div class="dw-status-icon <?= $deliveryStatus ?>">
        <?= $deliveryStatus === 'ready' ? '&#10003;' : ($deliveryStatus === 'blocked' ? '&#10007;' : '&#9998;') ?>
    </div>
    <div class="dw-status-info">
        <h3><?= $deliveryStatus === 'ready' ? 'Ready for Delivery' : ($deliveryStatus === 'blocked' ? 'Delivery Blocked' : 'Draft') ?></h3>
        <div class="dw-status-msg"><?= htmlspecialchars($deliveryMsg) ?></div>
        <div class="dw-status-counts">
            <span>Client: <strong><?= $clientReady ?>/<?= $clientTotal ?></strong></span>
            <span>Internal: <strong><?= $internalReady ?>/4</strong></span>
            <span>Readiness: <strong><?= $readinessScore ?>%</strong></span>
            <?php if ($deliveryStatus === 'blocked'): ?>
            <span style="color:#dc2626;">
                <?php if ($blockingErrors): ?><?= count($validationResult['errors']) ?> validation error(s)<?php endif; ?>
                <?php if ($blockingDR): ?><?php if ($blockingErrors): ?>, <?php endif; ?>Directors Report missing<?php endif; ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Blocked Items (if blocked) -->
<?php if ($deliveryStatus === 'blocked'): ?>
<div class="dw-section">
    <div class="dw-section-body">
        <div class="dw-blocked-list">
            <?php foreach ($validationResult['errors'] ?? [] as $err): ?>
            <div class="dw-blocked-item">
                <span>&#10007;</span>
                <span><?= htmlspecialchars($err['message'] ?? $err['check'] ?? 'Unknown error') ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($blockingDR): ?>
            <div class="dw-blocked-item">
                <span>&#10007;</span>
                <span>Directors Report not prepared</span>
            </div>
            <?php endif; ?>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;">
            <?php if ($blockingErrors): ?>
            <a href="<?= BASE_URL ?>statements/financials.php" class="btn btn-sm">Go to Financials</a>
            <?php endif; ?>
            <?php if ($blockingDR): ?>
            <a href="<?= BASE_URL ?>directors_report.php" class="btn btn-sm">Go to Directors Report</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Client Deliverables -->
<div class="dw-section">
    <div class="dw-section-header">
        <h3>Client Deliverables</h3>
        <span class="dw-badge client">Client Package</span>
    </div>
    <div class="dw-section-body">
        <?php include __DIR__ . '/_package_list.php'; ?>
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border,#e0e4e8);">
            <?php include __DIR__ . '/_export_formats.php'; ?>
        </div>
    </div>
</div>

<!-- Internal Deliverables -->
<div class="dw-section">
    <div class="dw-section-header">
        <h3>Internal Deliverables</h3>
        <span class="dw-badge internal">Internal Package</span>
    </div>
    <div class="dw-section-body">
        <?php include __DIR__ . '/_internal_list.php'; ?>
    </div>
</div>

<!-- Package Preview -->
<div class="dw-section">
    <div class="dw-section-header">
        <h3>Package Preview</h3>
        <span class="dw-toggle">&#9660;</span>
    </div>
    <div class="dw-section-body">
        <?php if ($hasReportData): ?>
        <iframe src="<?= BASE_URL ?>report_download.php?format=html" class="dw-preview-frame"></iframe>
        <?php else: ?>
        <div style="text-align:center;padding:30px;color:var(--muted,#6b7280);">
            Generate financial statements to preview the package.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Review Snapshot -->
<div class="dw-section">
    <div class="dw-section-header">
        <h3>Review Snapshot</h3>
        <span class="dw-toggle">&#9660;</span>
    </div>
    <div class="dw-section-body">
        <?php include __DIR__ . '/_review_snapshot.php'; ?>
    </div>
</div>

<!-- Delivery Actions -->
<div class="dw-actions-bar">
    <a href="<?= BASE_URL ?>report_download.php?format=pdf" class="btn btn-primary">Generate Client Package (PDF)</a>
    <a href="<?= BASE_URL ?>report_download.php?format=docx" class="btn">Generate Client Package (Word)</a>
    <a href="<?= BASE_URL ?>report_download.php?format=xlsx" class="btn">Generate Client Package (Excel)</a>
</div>

<script src="<?= BASE_URL ?>asset/js/deliverables_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/deliverables_workspace.js') ?>"></script>

<?php
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
