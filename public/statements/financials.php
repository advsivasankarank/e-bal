<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';

$page_title = 'Financial Statements';
requireAssignmentAccess();

require_once __DIR__ . '/../layouts/header_v2.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['report_action'] ?? '') === 'save_manual_company_note') {
    requireCsrfToken();
    $classifiedForManualSave = getClassifiedData($pdo, $company_id, $fy_id);
    $postedManualInputs = [
        'share_capital_authorised' => trim((string) ($_POST['share_capital_authorised'] ?? '')),
        'share_capital_issued' => trim((string) ($_POST['share_capital_issued'] ?? '')),
        'share_capital_paidup' => trim((string) ($_POST['share_capital_paidup'] ?? '')),
        'note2_opening_profit_loss' => trim((string) ($_POST['note2_opening_profit_loss'] ?? '')),
        'note16_opening_raw_materials' => trim((string) ($_POST['note16_opening_raw_materials'] ?? '')),
        'note16_closing_raw_materials' => trim((string) ($_POST['note16_closing_raw_materials'] ?? '')),
        'note24_opening_finished_goods' => trim((string) ($_POST['note24_opening_finished_goods'] ?? '')),
        'note24_opening_work_in_progress' => trim((string) ($_POST['note24_opening_work_in_progress'] ?? '')),
        'note24_closing_finished_goods' => trim((string) ($_POST['note24_closing_finished_goods'] ?? '')),
        'note24_closing_work_in_progress' => trim((string) ($_POST['note24_closing_work_in_progress'] ?? '')),
    ];
    $derivedOpeningFinishedGoods = $manualBundle['previous']['note24_closing_finished_goods'] ?? $manualBundle['current']['note24_opening_finished_goods'] ?? '';
    $derivedOpeningWip = $manualBundle['previous']['note24_closing_work_in_progress'] ?? $manualBundle['current']['note24_opening_work_in_progress'] ?? '';
    $derivedOpeningRawMaterials = $manualBundle['previous']['note16_closing_raw_materials'] ?? $manualBundle['current']['note16_opening_raw_materials'] ?? '';
    $note2OpeningBalance = trim((string) ($postedManualInputs['note2_opening_profit_loss'] ?? ''));
    $note2ClosingBalance = '';
    if ($note2OpeningBalance !== '') {
        $note2ClosingBalance = (string) (
            (float) $note2OpeningBalance
            + buildCompanyProfitAfterTax($classifiedForManualSave, $postedManualInputs, $manualBundle['previous'] ?? [])
        );
    }
    saveManualInputs($pdo, $company_id, $fy_id, [
        'share_capital_authorised' => $postedManualInputs['share_capital_authorised'],
        'share_capital_issued' => $postedManualInputs['share_capital_issued'],
        'share_capital_paidup' => $postedManualInputs['share_capital_paidup'],
        'note2_opening_profit_loss' => $note2OpeningBalance,
        'note2_closing_profit_loss' => $note2ClosingBalance,
        'note16_opening_raw_materials' => $postedManualInputs['note16_opening_raw_materials'] !== '' ? $postedManualInputs['note16_opening_raw_materials'] : (string) $derivedOpeningRawMaterials,
        'note16_closing_raw_materials' => $postedManualInputs['note16_closing_raw_materials'],
        'note24_opening_finished_goods' => $postedManualInputs['note24_opening_finished_goods'] !== '' ? $postedManualInputs['note24_opening_finished_goods'] : (string) $derivedOpeningFinishedGoods,
        'note24_opening_work_in_progress' => $postedManualInputs['note24_opening_work_in_progress'] !== '' ? $postedManualInputs['note24_opening_work_in_progress'] : (string) $derivedOpeningWip,
        'note24_closing_finished_goods' => $postedManualInputs['note24_closing_finished_goods'],
        'note24_closing_work_in_progress' => $postedManualInputs['note24_closing_work_in_progress'],
    ]);
    header("Location: " . BASE_URL . "statements/financials.php");
    exit;
}

$fs = generateFinancialStatements(
    $pdo, $company_id, $fy_id, $fyName,
    $manualBundle['current'] ?? [], $manualBundle['previous'] ?? []
);
$hasReportData = (bool) ($fs['has_data'] ?? false);
$currentDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);
$previousDiff = (float) ($fs['validation']['previous_balance_difference'] ?? 0);
$parentGroupConflicts = $fs['validation']['parent_group_conflicts'] ?? [];
$noteCompleteness = $fs['validation']['note_completeness'] ?? ['missing' => [], 'is_complete' => true];

$validationResult = validateReportGeneration($pdo, $company_id, $fy_id, $fs);

if ($hasReportData) {
    /* HARDENED: Only mark workflow stages complete if validation passes.
       No blocking errors, BS must be balanced, notes must be complete. */
    $hasBlockingErrors = !empty($validationResult['errors']);
    $bsBalanced = abs($currentDiff) <= 0.01;
    $notesComplete = $noteCompleteness['is_complete'] ?? true;

    if (!$hasBlockingErrors && $bsBalanced && $notesComplete) {
        updateWorkflow($company_id, $fy_id, 'notes_prepared');
        updateWorkflow($company_id, $fy_id, 'profit_loss_prepared');
        updateWorkflow($company_id, $fy_id, 'balance_sheet_prepared');
    } else {
        /* CLEAR workflow stages if conditions no longer met */
        $pdo->prepare("UPDATE workflow_status SET notes_prepared=0, profit_loss_prepared=0, balance_sheet_prepared=0, updated_at=NOW() WHERE company_id=? AND fy_id=?")
            ->execute([$company_id, $fy_id]);
    }
}

$entityCategory = $fs['entity_category'] ?? '';
$entitySubcategory = $fs['entity_subcategory'] ?? '';
$isCorporate = $entityCategory === 'corporate';
$isNonCorporate = in_array($entitySubcategory, ['proprietorship', 'partnership'], true);
$isLLP = $entitySubcategory === 'llp';
$isTrustSociety = in_array($entitySubcategory, ['trust', 'society'], true);

$tabDefs = [];
if ($isCorporate) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'has_wp' => true],
        ['id' => 'profit-loss', 'label' => 'P&amp;L', 'has_wp' => true],
        ['id' => 'cash-flow', 'label' => 'Cash Flow', 'has_wp' => false],
        ['id' => 'notes-to-accounts', 'label' => 'Notes', 'has_wp' => true],
    ];
} elseif ($isNonCorporate || $isLLP) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'has_wp' => true],
        ['id' => 'trading-account', 'label' => 'Trading A/c', 'has_wp' => true],
        ['id' => 'profit-loss', 'label' => 'P&amp;L A/c', 'has_wp' => true],
        ['id' => 'notes-to-accounts', 'label' => 'Notes', 'has_wp' => true],
    ];
} else {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'has_wp' => true],
        ['id' => 'income-expenditure', 'label' => 'Income &amp; Expenditure', 'has_wp' => true],
        ['id' => 'notes-to-accounts', 'label' => 'Notes', 'has_wp' => true],
    ];
}

$hasWPTabs = false;
foreach ($tabDefs as $td) { if ($td['has_wp'] ?? false) { $hasWPTabs = true; break; } }

$validationIssues = [];
if (abs($currentDiff) > 0.01 || abs($previousDiff) > 0.01) {
    $validationIssues[] = ['type' => 'error', 'text' => 'BS not balanced (Diff: ' . format_inr($currentDiff) . ')'];
}
if (!empty($parentGroupConflicts)) {
    $validationIssues[] = ['type' => 'warning', 'text' => count($parentGroupConflicts) . ' parent-group conflict(s) excluded from notes'];
}
if (!($noteCompleteness['is_complete'] ?? true)) {
    $validationIssues[] = ['type' => 'warning', 'text' => count($noteCompleteness['missing'] ?? []) . ' expected note heading(s) missing'];
}
if (!empty($validationResult['errors'])) {
    $validationIssues[] = ['type' => 'error', 'text' => count($validationResult['errors']) . ' validation error(s)'];
}
if (!empty($validationResult['warnings'])) {
    $validationIssues[] = ['type' => 'warning', 'text' => count($validationResult['warnings']) . ' validation warning(s)'];
}

$isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
$debugMode = (defined('DEBUG_MODE') && DEBUG_MODE === true);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/financials_workspace.css?v=<?= filemtime(__DIR__ . '/../asset/css/financials_workspace.css') ?>">

<div class="active-info" style="display:flex;justify-content:space-between;align-items:center;">
    <span>
        Company: <strong><?= htmlspecialchars($companyName) ?></strong> &middot;
        FY: <strong><?= htmlspecialchars($fyName) ?></strong> &middot;
        Entity: <strong><?= htmlspecialchars($fs['company_meta']['entity_type'] ?? 'N/A') ?></strong>
    </span>
    <?php if ($hasReportData): ?>
    <span style="display:flex;gap:8px;">
        <a class="btn" href="<?= BASE_URL ?>report_download.php?format=pdf" style="min-height:34px;padding:0 14px;font-size:0.8rem;">PDF</a>
        <a class="btn" href="<?= BASE_URL ?>report_download.php?format=word" style="min-height:34px;padding:0 14px;font-size:0.8rem;background:linear-gradient(135deg,#1d4ed8,#1e40af);">Word</a>
        <a class="btn" href="<?= BASE_URL ?>report_download.php?format=excel" style="min-height:34px;padding:0 14px;font-size:0.8rem;background:linear-gradient(135deg,#15803d,#166534);">Excel</a>
    </span>
    <?php endif; ?>
</div>

<?php if (!$hasReportData): ?>
    <div class="fs-no-data">
        <div class="icon">&#128202;</div>
        <div>No report figures are available yet for this company and financial year.</div>
        <div style="margin-top:8px;font-size:0.85rem;">Complete ledger mapping first, then fetch the trial balance from Tally and reopen reports.</div>
    </div>
<?php else: ?>

<!-- Tab Bar -->
<div class="fs-tab-bar" id="fsTabBar">
    <?php foreach ($tabDefs as $i => $tab): ?>
    <button class="fs-tab <?= $i === 0 ? 'active' : '' ?>" data-tab="<?= htmlspecialchars($tab['id']) ?>">
        <?= $tab['label'] ?>
    </button>
    <?php endforeach; ?>
    <span class="fs-tab-spacer"></span>
    <button class="fs-tab" onclick="window.location.reload()">&#128260; Rebuild</button>
</div>

<!-- Controls Row: View Mode + WP Toggle + Year Toggle -->
<div class="fs-controls">
    <div class="fs-control-group">
        <span class="fs-control-label">View</span>
        <button class="fs-ctrl-btn fs-view-btn active" data-view="statement">Statement</button>
        <button class="fs-ctrl-btn fs-view-btn" data-view="spreadsheet">Spreadsheet</button>
        <button class="fs-ctrl-btn fs-view-btn" data-view="print">Print Preview</button>
    </div>
    <?php if ($hasWPTabs): ?>
    <div class="fs-control-group">
        <span class="fs-control-label">Mode</span>
        <button class="fs-ctrl-btn fs-wp-btn active" data-wp="statement">Statement</button>
        <button class="fs-ctrl-btn fs-wp-btn" data-wp="working">Working Paper</button>
    </div>
    <?php endif; ?>
    <div class="fs-control-group">
        <span class="fs-control-label">Year</span>
        <button class="fs-ctrl-btn fs-year-btn" data-year="current">Current</button>
        <button class="fs-ctrl-btn fs-year-btn" data-year="previous">Previous</button>
        <button class="fs-ctrl-btn fs-year-btn active" data-year="both">Side-by-Side</button>
    </div>
</div>

<!-- Validation Strip -->
<?php if (!empty($validationIssues)): ?>
<div class="fs-validation-strip">
    <?php foreach ($validationIssues as $vi): ?>
    <span class="item <?= $vi['type'] ?>"><?= $vi['type'] === 'error' ? '&#10060;' : '&#9888;&#65039;' ?> <?= htmlspecialchars($vi['text']) ?></span>
    <?php endforeach; ?>
    <?php if (abs($currentDiff) <= 0.01 && abs($previousDiff) <= 0.01): ?>
    <span style="color:#16a34a;">&#9989; BS Identity: Balanced</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Three-Panel Workspace -->
<div class="fs-workspace" id="fsWorkspace">
    <!-- LEFT: Sections -->
    <div class="fs-section-sidebar">
        <h3 id="fsSectionTitle">Sections</h3>
        <div id="fsSectionList"></div>
    </div>

    <!-- CENTER: Report Canvas -->
    <div class="fs-canvas" id="fsCanvas">
        <?php
        $data = $fs['data'];
        $notes = $fs['notes'];
        $company_meta = $fs['company_meta'] ?? [];
        $isFirstYear = (bool) ($fs['is_first_year'] ?? false);
        $subcategory = $fs['entity_subcategory'] ?? '';
        if ($subcategory === 'trust') {
            $fs['format_template'] = __DIR__ . '/../reports_dashboard/formats/trust_format.php';
            $fs['notes_template'] = __DIR__ . '/../reports_dashboard/formats/notes_trust.php';
        } elseif ($subcategory === 'society') {
            $fs['format_template'] = __DIR__ . '/../reports_dashboard/formats/society_format.php';
            $fs['notes_template'] = __DIR__ . '/../reports_dashboard/formats/notes_society.php';
        }
        include $fs['format_template'];
        ?>
        <?php include $fs['notes_template']; ?>

        <!-- Working Paper Pages (hidden by default, toggled by WP button) -->
        <?php if ($hasWPTabs): ?>
        <section class="report-page fs-wp-page" id="wp-balance-sheet" style="display:none;">
            <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Working Paper: Balance Sheet</h2>
            <?php include __DIR__ . '/_wp_balance_sheet.php'; ?>
        </section>
        <section class="report-page fs-wp-page" id="wp-profit-loss" style="display:none;">
            <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Working Paper: Profit &amp; Loss</h2>
            <?php include __DIR__ . '/_wp_profit_loss.php'; ?>
        </section>
        <section class="report-page fs-wp-page" id="wp-notes-to-accounts" style="display:none;">
            <h2 style="margin:0 0 12px;font-size:18px;color:#0f172a;">Working Paper: Notes to Accounts</h2>
            <?php include __DIR__ . '/_wp_notes.php'; ?>
        </section>
        <?php endif; ?>

        <!-- Spreadsheet View Container (hidden by default) -->
        <div id="fsSpreadsheetView" style="display:none;">
            <?php include __DIR__ . '/_spreadsheet_view.php'; ?>
        </div>

        <!-- Print Preview Container (hidden by default) -->
        <div id="fsPrintPreview" style="display:none;">
            <div class="fs-print-controls">
                <button onclick="window.print()">Print</button>
                <a href="<?= BASE_URL ?>report_download.php?format=pdf" style="padding:6px 14px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;font-size:0.82rem;">Download PDF</a>
            </div>
        </div>
    </div>

    <!-- RIGHT: Manual Input Panel -->
    <div class="fs-input-panel" id="fsInputPanel">
        <?php include __DIR__ . '/_manual_inputs.php'; ?>
    </div>
</div>

<!-- Compliance Zone -->
<div class="fs-compliance-zone">
    <div class="fs-compliance-zone-title">Compliance</div>

    <?php if ($isCorporate): ?>
    <div class="fs-compliance-card" style="margin-bottom:16px;">
        <h3>Directors Report</h3>
        <p style="font-size:0.88rem;color:#475569;margin:0 0 12px;">Generate and review the Directors Report for this company and financial year.</p>
        <a class="btn" href="<?= BASE_URL ?>directors_report.php" style="display:inline-block;">Open Directors Report</a>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin || $debugMode): ?>
    <div class="fs-compliance-card">
        <h3>Review Notes</h3>
        <div class="fs-compliance-placeholder">
            <div class="badge">Coming Soon</div>
            <div>Per-note review comments, sign-off tracking, and audit trail annotations.</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script src="<?= BASE_URL ?>asset/js/financials_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/financials_workspace.js') ?>"></script>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
