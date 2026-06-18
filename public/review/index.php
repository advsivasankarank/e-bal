<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../app/helpers/company_reporting_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';

$page_title = 'Review Workspace';
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

// --- Tab definitions (same as financials.php) ---
$isCorporate = $entityCategory === 'corporate';
$isNonCorporate = in_array($entitySubcategory, ['proprietorship', 'partnership'], true);
$isLLP = $entitySubcategory === 'llp';
$tabDefs = [];
if ($isCorporate) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet'],
        ['id' => 'profit-loss', 'label' => 'P&amp;L'],
        ['id' => 'cash-flow', 'label' => 'Cash Flow'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes'],
    ];
} elseif ($isNonCorporate || $isLLP) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet'],
        ['id' => 'trading-account', 'label' => 'Trading A/c'],
        ['id' => 'profit-loss', 'label' => 'P&amp;L A/c'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes'],
    ];
} else {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet'],
        ['id' => 'income-expenditure', 'label' => 'Income &amp; Expenditure'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes'],
    ];
}

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['review_action'] ?? '';

    if ($action === 'save_remark') {
        $section = $_POST['remark_section'] ?? '';
        $text = trim($_POST['remark_text'] ?? '');
        $severity = $_POST['remark_severity'] ?? 'observation';
        $validSeverities = ['critical', 'important', 'observation', 'suggestion'];
        if (!in_array($severity, $validSeverities)) $severity = 'observation';

        $now = date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 0;

        saveManualInputs($pdo, $company_id, $fy_id, [
            'review_remark_text_' . $section => $text,
            'review_remark_severity_' . $section => $severity,
            'review_remark_by_' . $section => (string)$userId,
            'review_remark_at_' . $section => $now,
        ]);

        // Add timeline entry
        addTimelineEntry($pdo, $company_id, $fy_id, 'remark_added|' . $userId . '|' . $now . '|' . $section . '|' . $severity);

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }

    if ($action === 'remark_resolve' || $action === 'remark_reopen') {
        $section = $_POST['remark_section'] ?? '';
        $resolved = ($action === 'remark_resolve') ? '1' : '0';
        $now = date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 0;

        saveManualInputs($pdo, $company_id, $fy_id, [
            'review_remark_resolved_' . $section => $resolved,
        ]);

        $tlType = $action === 'remark_resolve' ? 'remark_resolved' : 'remark_reopened';
        addTimelineEntry($pdo, $company_id, $fy_id, $tlType . '|' . $userId . '|' . $now . '|' . $section);

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }

    if ($action === 'signoff_sign') {
        $role = $_POST['signoff_role'] ?? '';
        $validRoles = ['staff', 'manager', 'partner'];
        if (in_array($role, $validRoles)) {
            $now = date('Y-m-d H:i:s');
            $userId = $_SESSION['user_id'] ?? 0;

            saveManualInputs($pdo, $company_id, $fy_id, [
                'signoff_' . $role . '_by' => (string)$userId,
                'signoff_' . $role . '_at' => $now,
            ]);

            addTimelineEntry($pdo, $company_id, $fy_id, 'signoff_' . $role . '|' . $userId . '|' . $now);

            // Set workflow.verified if staff or manager signs
            if (in_array($role, ['staff', 'manager'])) {
                updateWorkflow($company_id, $fy_id, 'verified');
            }
        }

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }

    if ($action === 'signoff_revoke') {
        $role = $_POST['signoff_role'] ?? '';
        $validRoles = ['staff', 'manager', 'partner'];
        if (in_array($role, $validRoles)) {
            $now = date('Y-m-d H:i:s');
            $userId = $_SESSION['user_id'] ?? 0;

            saveManualInputs($pdo, $company_id, $fy_id, [
                'signoff_' . $role . '_by' => '',
                'signoff_' . $role . '_at' => '',
            ]);

            addTimelineEntry($pdo, $company_id, $fy_id, 'signoff_revoke|' . $userId . '|' . $now . '|' . $role);
        }

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }
}

// --- Helper to add timeline entry ---
function addTimelineEntry($pdo, $companyId, $fyId, $entry) {
    $countStmt = $pdo->prepare("SELECT meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key = 'review_timeline_count'");
    $countStmt->execute([$companyId, $fyId]);
    $count = (int)($countStmt->fetchColumn() ?: '0');
    $count++;
    saveManualInputs($pdo, $companyId, $fyId, [
        'review_timeline_count' => (string)$count,
        'review_timeline_' . $count => $entry,
    ]);
}

// --- Compute readiness using centralized engine ---
require_once __DIR__ . '/../../app/helpers/readiness_helper.php';
$readiness = computeReadiness($pdo, $company_id, $fy_id, $fs);

// Pass readiness data to partials
$validationChecks = $readiness['validation']['error_messages'];
$remarkData = [];
$remarkStmt2 = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'review_remark_%'");
$remarkStmt2->execute([$company_id, $fy_id]);
foreach ($remarkStmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $remarkData[$row['meta_key']] = $row['meta_value'];
}
$signoffData = [];
$signoffStmt2 = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'signoff_%'");
$signoffStmt2->execute([$company_id, $fy_id]);
foreach ($signoffStmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $signoffData[$row['meta_key']] = $row['meta_value'];
}

$lastValidated = date('M d, Y, g:i A');
?>

<meta name="csrf-token" content="<?= $_SESSION['_csrf_token'] ?? '' ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/review_workspace.css?v=<?= filemtime(__DIR__ . '/../asset/css/review_workspace.css') ?>">

<div class="active-info" style="display:flex;justify-content:space-between;align-items:center;">
    <span>
        Company: <strong><?= htmlspecialchars($companyName) ?></strong> &middot;
        FY: <strong><?= htmlspecialchars($fyName) ?></strong> &middot;
        Entity: <strong><?= htmlspecialchars($entityType) ?></strong>
    </span>
    <a class="btn btn-sm" href="<?= BASE_URL ?>assignment_home.php">&larr; Back to Assignment</a>
</div>

<?php if (!$hasReportData): ?>
    <div class="rw-section">
        <div class="rw-section-body" style="text-align:center;padding:40px;color:var(--muted);">
            <div style="font-size:2rem;margin-bottom:8px;opacity:0.3;">&#128269;</div>
            <div>Complete data import and financial statement generation before reviewing.</div>
            <a class="btn btn-primary" href="<?= BASE_URL ?>statements/financials.php" style="margin-top:12px;">Go to Financials</a>
        </div>
    </div>
<?php else: ?>

<!-- Readiness Score -->
<?php include __DIR__ . '/_readiness_score.php'; ?>

<!-- Validation Centre -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Validation Centre</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_validation_centre.php'; ?>
    </div>
</div>

<!-- Review Remarks -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Review Remarks</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_review_remarks.php'; ?>
    </div>
</div>

<!-- Sign-Offs -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Sign-Offs</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_signoffs.php'; ?>
    </div>
</div>

<!-- Review Timeline -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Review Timeline</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_review_timeline.php'; ?>
    </div>
</div>

<!-- Actions Bar -->
<div class="rw-actions-bar">
    <a href="<?= BASE_URL . 'review/index.php' ?>" class="btn btn-sm">Revalidate</a>
    <?php if (($signoffData['signoff_staff_by'] ?? '') === ''): ?>
    <form method="post" style="display:inline;">
        <?= csrfInput() ?>
        <input type="hidden" name="review_action" value="signoff_sign">
        <input type="hidden" name="signoff_role" value="staff">
        <button type="submit" class="btn btn-sm btn-primary">Mark as Reviewed</button>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-sm" onclick="window.print()">Export Validation Report</button>
</div>

<?php endif; ?>

<script src="<?= BASE_URL ?>asset/js/review_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/review_workspace.js') ?>"></script>

<?php
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
