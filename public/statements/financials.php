<?php
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/engines/fs_engine.php';
require_once __DIR__ . '/../../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
require_once __DIR__ . '/../../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../../app/workflow_engine.php';
require_once __DIR__ . '/../../app/helpers/workflow_navigation_helper.php';

$page_title = 'Financial Statements';
requireAssignmentAccess();

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

require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Financials', 'href' => BASE_URL . 'statements/financials.php'],
    ['label' => 'Statements']
]) ?>

<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/financials_workspace.css?v=<?= filemtime(__DIR__ . '/../asset/css/financials_workspace.css') ?>">

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => $fs['company_meta']['entity_type'] ?? '',
    'profile' => 0,
    'status' => $hasReportData ? 'Reports Ready' : 'Setup Required',
    'edit_url' => '',
]) ?>

<?php
$navData = getWorkflowNavigation($pdo, $company_id, $fy_id);
echo renderWorkflowNavigation($navData);
?>

<?php if ($hasReportData): ?>
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;">
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=pdf" style="min-height:34px;padding:0 14px;font-size:0.8rem;">PDF</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=word" style="min-height:34px;padding:0 14px;font-size:0.8rem;background:linear-gradient(135deg,#1d4ed8,#1e40af);">Word</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=excel" style="min-height:34px;padding:0 14px;font-size:0.8rem;background:linear-gradient(135deg,#15803d,#166534);">Excel</a>
</div>
<?php endif; ?>

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
    <span class="item <?= $vi['type'] ?>" style="cursor:pointer;" onclick="document.getElementById('validationModal').style.display='flex';"><?= $vi['type'] === 'error' ? '&#10060;' : '&#9888;&#65039;' ?> <?= htmlspecialchars($vi['text']) ?></span>
    <?php endforeach; ?>
    <?php if (abs($currentDiff) <= 0.01 && abs($previousDiff) <= 0.01): ?>
    <span style="color:#16a34a;">&#9989; BS Identity: Balanced</span>
    <?php endif; ?>
</div>

<!-- Validation Issues Modal -->
<div id="validationModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:12px;max-width:700px;width:95%;max-height:85vh;overflow-y:auto;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h3 style="margin:0;font-size:1.1rem;">&#9888;&#65039; Validation Issues</h3>
    <button onclick="document.getElementById('validationModal').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;">&times;</button>
</div>

<!-- BS Difference -->
<?php if (abs($currentDiff) > 0.01 || abs($previousDiff) > 0.01): ?>
<div style="border:1px solid #fca5a5;background:#fef2f2;border-radius:8px;padding:14px;margin-bottom:12px;">
    <div style="font-weight:700;color:#991b1b;margin-bottom:6px;">&#10060; Balance Sheet Not Balanced</div>
    <div style="font-size:0.88rem;color:#475569;margin-bottom:8px;">
        Current year difference: <strong><?= format_inr($currentDiff) ?></strong>
        <?php if (abs($previousDiff) > 0.01): ?><br>Previous year difference: <strong><?= format_inr($previousDiff) ?></strong><?php endif; ?>
    </div>
    <div style="font-size:0.82rem;color:#666;margin-bottom:10px;">Suggested causes: parent-group conflict, unmapped ledger, duplicate ledger, or data mismatch.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>review/index.php?issue=bs_difference&company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;background:#dc2626;color:#fff;">View Difference Analysis</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;">Open Mapping Workbench</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;">Open Trial Balance</a>
        <button onclick="window.location.reload()" style="font-size:0.78rem;padding:5px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;">Rebuild</button>
    </div>
</div>
<?php endif; ?>

<!-- Parent Group Conflicts -->
<?php if (!empty($parentGroupConflicts)): ?>
<div style="border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;padding:14px;margin-bottom:12px;">
    <div style="font-weight:700;color:#92400e;margin-bottom:6px;">&#128681; Parent-Group Conflicts (<?= count($parentGroupConflicts) ?>)</div>
    <div style="font-size:0.88rem;color:#475569;margin-bottom:8px;">Ledgers with conflicting parent-group mappings are excluded from classification.</div>
    <?php if (count($parentGroupConflicts) <= 5): ?>
    <ul style="font-size:0.82rem;color:#666;margin:0 0 10px 16px;">
        <?php foreach (array_slice($parentGroupConflicts, 0, 5) as $c): ?>
        <li><?= htmlspecialchars($c['ledger_name'] ?? '?') ?> (<?= htmlspecialchars($c['parent_group'] ?? '') ?> → <?= htmlspecialchars($c['schedule_code'] ?? '') ?>)</li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div style="font-size:0.82rem;color:#666;margin-bottom:8px;"><?= count($parentGroupConflicts) ?> ledgers affected. Open Mapping Workbench to review.</div>
    <?php endif; ?>
    <a class="btn" href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;display:inline-block;">Open Mapping Workbench</a>
</div>
<?php endif; ?>

<!-- Missing Note Headings -->
<?php if (!($noteCompleteness['is_complete'] ?? true) && !empty($noteCompleteness['missing'])): ?>
<div style="border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;padding:14px;margin-bottom:12px;">
    <div style="font-weight:700;color:#92400e;margin-bottom:6px;">&#128221; Missing Note Headings (<?= count($noteCompleteness['missing']) ?>)</div>
    <div style="font-size:0.88rem;color:#475569;margin-bottom:8px;">Expected note sections are missing from the report.</div>
    <ul style="font-size:0.82rem;color:#666;margin:0 0 10px 16px;">
        <?php foreach (array_slice($noteCompleteness['missing'] ?? [], 0, 8) as $m): ?>
        <li><?= htmlspecialchars(is_array($m) ? ($m['title'] ?? $m['label'] ?? '?') : (string)$m) ?></li>
        <?php endforeach; ?>
        <?php if (count($noteCompleteness['missing'] ?? []) > 8): ?>
        <li>+<?= count($noteCompleteness['missing']) - 8 ?> more</li>
        <?php endif; ?>
    </ul>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;">Open Mapping Workbench</a>
        <button onclick="window.location.reload()" style="font-size:0.78rem;padding:5px 12px;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;">Rebuild</button>
    </div>
</div>
<?php endif; ?>

<!-- Validation Errors -->
<?php if (!empty($validationResult['errors'])): ?>
<div style="border:1px solid #fca5a5;background:#fef2f2;border-radius:8px;padding:14px;margin-bottom:12px;">
    <div style="font-weight:700;color:#991b1b;margin-bottom:6px;">&#10060; Validation Errors (<?= count($validationResult['errors']) ?>)</div>
    <ul style="font-size:0.82rem;color:#666;margin:0 0 10px 16px;">
        <?php foreach (array_slice($validationResult['errors'], 0, 8) as $err): ?>
        <li><?= htmlspecialchars($err['message'] ?? $err['check'] ?? '?') ?></li>
        <?php endforeach; ?>
        <?php if (count($validationResult['errors']) > 8): ?>
        <li>+<?= count($validationResult['errors']) - 8 ?> more</li>
        <?php endif; ?>
    </ul>
    <div style="font-size:0.82rem;color:#666;margin-bottom:8px;">Open Mapping Workbench and Data Console to review ledger mapping and note classification.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;">Open Mapping Workbench</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.78rem;padding:5px 12px;">Open Trial Balance</a>
    </div>
</div>
<?php endif; ?>

<!-- Validation Warnings -->
<?php if (!empty($validationResult['warnings'])): ?>
<div style="border:1px solid #fcd34d;background:#fffbeb;border-radius:8px;padding:14px;margin-bottom:12px;">
    <div style="font-weight:700;color:#92400e;margin-bottom:6px;">&#9888;&#65039; Validation Warnings (<?= count($validationResult['warnings']) ?>)</div>
    <ul style="font-size:0.82rem;color:#666;margin:0 0 10px 16px;">
        <?php foreach (array_slice($validationResult['warnings'], 0, 8) as $warn): ?>
        <li><?= htmlspecialchars($warn['message'] ?? $warn['check'] ?? '?') ?></li>
        <?php endforeach; ?>
        <?php if (count($validationResult['warnings']) > 8): ?>
        <li>+<?= count($validationResult['warnings']) - 8 ?> more</li>
        <?php endif; ?>
    </ul>
    <div style="font-size:0.82rem;color:#666;">Review these warnings in Mapping Workbench or Data Console.</div>
</div>
<?php endif; ?>

</div>
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
            <div class="fs-print-preview-content">
                <!-- Render same content as statement view for printing -->
                <?php include $fs['format_template']; ?>
                <?php include $fs['notes_template']; ?>
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
        <p style="font-size:0.88rem;color:#475569;margin:0 0 12px;">Manage per-note review comments, sign-off tracking, and audit trail annotations.</p>
        <a class="btn" href="<?= BASE_URL ?>review/index.php" style="display:inline-block;">Open Review Workspace</a>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<script src="<?= BASE_URL ?>asset/js/financials_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/financials_workspace.js') ?>"></script>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
