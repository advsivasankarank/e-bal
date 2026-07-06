<?php
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/engines/fs_engine.php';
require_once __DIR__ . '/../../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
require_once __DIR__ . '/../../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../../app/helpers/company_reporting_helper.php';
require_once __DIR__ . '/../../app/workflow_engine.php';
require_once __DIR__ . '/../../app/helpers/workflow_navigation_helper.php';

$page_title = 'Deliverables';
requireAssignmentAccess();

require_once __DIR__ . '/../layouts/header_v2.php';

require_once __DIR__ . '/../../app/helpers/demo_helper.php';
$v2Demo = isDemoUser($pdo);

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
$remarkData = loadManualInputsByPrefix($pdo, $company_id, $fy_id, 'review_remark_');
$signoffData = [];
$signoffData = loadManualInputsByPrefix($pdo, $company_id, $fy_id, 'signoff_');

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
$clientTotal = in_array($entitySubcategory, ['trust', 'society'], true) ? 4 : 5;
$clientReady = $hasReportData ? $clientTotal : 0;
$internalReady = 4;

$baseUrl = BASE_URL . 'report_download.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Deliverables', 'href' => BASE_URL . 'deliverables/index.php'],
    ['label' => 'Package']
]) ?>

<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/deliverables_workspace.css?v=<?= filemtime(__DIR__ . '/../asset/css/deliverables_workspace.css') ?>">

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => $entityType,
    'profile' => 0,
    'status' => $deliveryStatus === 'ready' ? 'Ready for Delivery' : ucfirst($deliveryStatus),
    'edit_url' => '',
]) ?>

<?php
$navData = getWorkflowNavigation($pdo, $company_id, $fy_id);
echo renderWorkflowNavigation($navData);
?>

<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
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
                <?php if ($blockingErrors): ?><?= $readiness['validation']['errors'] ?> validation error(s)<?php endif; ?>
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
            <?php foreach ($readiness['validation']['error_messages'] ?? [] as $err): ?>
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
    <?php if ($v2Demo): ?>
    <div style="width:100%;padding:16px;background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#92400e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <strong style="color:#92400e;font-size:14px;">Demo Mode — Restricted Deliverables</strong>
        </div>
        <p style="margin:0 0 10px;font-size:13px;color:#78350f;">Demo users can generate PDF demo copies only. Upgrade to unlock final deliverables.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>demo_upgrade.php" style="display:inline-block;padding:8px 18px;background:#C69214;color:#fff;border-radius:7px;text-decoration:none;font-weight:600;font-size:13px;">Upgrade to Paid</a>
            <a href="https://etaxadv.com/contact" style="display:inline-block;padding:8px 18px;background:transparent;color:#92400e;border:2px solid #92400e;border-radius:7px;text-decoration:none;font-weight:600;font-size:13px;">Contact Sales</a>
        </div>
    </div>
    <a href="<?= BASE_URL ?>report_download.php?format=pdf" class="btn btn-primary">Generate PDF Demo Copy</a>
    <span class="btn" style="opacity:0.5;cursor:not-allowed;position:relative;" title="Upgrade to unlock Word export">
        Generate Client Package (Word)
        <span style="position:absolute;top:-6px;right:-6px;background:#dc2626;color:#fff;font-size:8px;padding:2px 5px;border-radius:4px;font-weight:700;">PRO</span>
    </span>
    <span class="btn" style="opacity:0.5;cursor:not-allowed;position:relative;" title="Upgrade to unlock Excel export">
        Generate Client Package (Excel)
        <span style="position:absolute;top:-6px;right:-6px;background:#dc2626;color:#fff;font-size:8px;padding:2px 5px;border-radius:4px;font-weight:700;">PRO</span>
    </span>
    <?php else: ?>
    <a href="<?= BASE_URL ?>report_download.php?format=pdf" class="btn btn-primary">Generate Client Package (PDF)</a>
    <a href="<?= BASE_URL ?>report_download.php?format=docx" class="btn">Generate Client Package (Word)</a>
    <a href="<?= BASE_URL ?>report_download.php?format=xlsx" class="btn">Generate Client Package (Excel)</a>
    <?php endif; ?>
</div>

<script src="<?= BASE_URL ?>asset/js/deliverables_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/deliverables_workspace.js') ?>"></script>

<?php
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
