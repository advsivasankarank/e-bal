<?php
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/engines/fs_engine.php';
require_once __DIR__ . '/../../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../../app/helpers/share_capital_helper.php';
require_once __DIR__ . '/../../app/helpers/financial_year_helper.php';
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
$shareholders = getShareholders($pdo, $company_id, $fy_id);
$shareCapitalClasses = getShareCapitalClasses($pdo, $company_id, $fy_id);

$prevFyLabel = getPreviousFinancialYearLabel($fyName);
$prevFy = $prevFyLabel !== '' ? findFinancialYearByLabel($pdo, $prevFyLabel, $company_id) : null;
$prevFyId = $prevFy !== null ? (int) ($prevFy['id'] ?? 0) : 0;
$prevShareholders = $prevFyId > 0 ? getShareholders($pdo, $company_id, $prevFyId) : [];
$prevShareCapitalClasses = $prevFyId > 0 ? getShareCapitalClasses($pdo, $company_id, $prevFyId) : [];

/* A saved share-class row (Equity/Preference/...) is this year's own Note 1
   detail; the old scalar share_capital_authorised_shares/... fields are gone
   in favour of the per-share-type breakup. */
$hasCurrentShareCapitalDetail = !empty($shareCapitalClasses);
$hasPreviousShareCapitalDetail = !empty($prevShareCapitalClasses);
$showShareCapitalCarryForwardPrompt = !$hasCurrentShareCapitalDetail && $hasPreviousShareCapitalDetail;

/* Closing stock (Note 24) has no sensible auto-fill -- Tally only ever gives
   an opening-stock-style ledger total, never a closing figure -- so it must
   be entered fresh every year. Flag it as incomplete whenever none of the
   three closing components have been saved for the current year. */
$hasClosingStockDetail = trim((string) ($manualBundle['saved_current']['note24_closing_finished_goods'] ?? '')) !== ''
    || trim((string) ($manualBundle['saved_current']['note24_closing_work_in_progress'] ?? '')) !== ''
    || trim((string) ($manualBundle['saved_current']['note24_closing_stock_in_trade'] ?? '')) !== '';

/* A "Profit & Loss A/c"-style ledger in the Trial Balance is normally an
   OPENING figure (brought forward from prior years), not this year's
   closing balance -- but a bare Trial Balance import has no way to know
   that for certain. Rather than silently guessing, ask the CA to
   explicitly confirm it (or override it) before it's used for Note 2. */
$plOpeningCandidate = detectProfitLossLedgerOpeningCandidate(getClassifiedData($pdo, $company_id, $fy_id));
$savedNote2Opening = trim((string) ($manualBundle['saved_current']['note2_opening_profit_loss'] ?? ''));
$hasNote2OpeningConfirmed = $savedNote2Opening !== '' && (float) $savedNote2Opening != 0.0;
$showNote2OpeningConfirmPrompt = !$hasNote2OpeningConfirmed && $plOpeningCandidate !== null;

$manualNoteDataIncomplete = !$hasCurrentShareCapitalDetail || !$hasClosingStockDetail;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['report_action'] ?? '') === 'carry_forward_share_capital') {
    requireCsrfToken();
    saveManualInputs($pdo, $company_id, $fy_id, [
        'share_capital_authorised' => (string) ($manualBundle['previous']['share_capital_authorised'] ?? ''),
        'share_capital_issued' => (string) ($manualBundle['previous']['share_capital_issued'] ?? ''),
        'share_capital_paidup' => (string) ($manualBundle['previous']['share_capital_paidup'] ?? ''),
    ]);
    saveShareCapitalClasses($pdo, $company_id, $fy_id, array_map(
        static fn (array $row): array => [
            'share_type' => $row['share_type'],
            'face_value' => $row['face_value'],
            'authorised_shares' => $row['authorised_shares'],
            'opening_shares' => $row['closing_shares'],
            'issued_during_year' => 0,
            'bought_back_during_year' => 0,
            'closing_shares' => $row['closing_shares'],
        ],
        $prevShareCapitalClasses
    ));
    saveShareholders($pdo, $company_id, $fy_id, array_map(
        static fn (array $row): array => ['name' => $row['name'], 'shares' => $row['shares']],
        $prevShareholders
    ));
    header("Location: " . BASE_URL . "statements/financials.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['report_action'] ?? '') === 'confirm_note2_opening_balance') {
    requireCsrfToken();
    $classifiedForConfirm = getClassifiedData($pdo, $company_id, $fy_id);
    $confirmCandidate = detectProfitLossLedgerOpeningCandidate($classifiedForConfirm);
    if ($confirmCandidate !== null) {
        $confirmOpening = (string) $confirmCandidate['amount'];
        $confirmClosing = (string) (
            $confirmCandidate['amount']
            + buildCompanyProfitAfterTax($classifiedForConfirm, $manualBundle['current'] ?? [], $manualBundle['previous'] ?? [])
        );
        saveManualInputs($pdo, $company_id, $fy_id, [
            'note2_opening_profit_loss' => $confirmOpening,
            'note2_closing_profit_loss' => $confirmClosing,
        ]);
    }
    header("Location: " . BASE_URL . "statements/financials.php");
    exit;
}

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
        'tax_provision' => trim((string) ($_POST['tax_provision'] ?? '')),
        'note24_opening_stock_in_trade' => trim((string) ($_POST['note24_opening_stock_in_trade'] ?? '')),
        'note24_closing_stock_in_trade' => trim((string) ($_POST['note24_closing_stock_in_trade'] ?? '')),
        'note_disclosure_cl' => trim((string) ($_POST['note_disclosure_cl'] ?? '')),
        'note_disclosure_com' => trim((string) ($_POST['note_disclosure_com'] ?? '')),
        'note_disclosure_msme' => trim((string) ($_POST['note_disclosure_msme'] ?? '')),
        'note_disclosure_rpt' => trim((string) ($_POST['note_disclosure_rpt'] ?? '')),
    ];
    $derivedOpeningFinishedGoods = $manualBundle['previous']['note24_closing_finished_goods'] ?? $manualBundle['current']['note24_opening_finished_goods'] ?? '';
    $derivedOpeningWip = $manualBundle['previous']['note24_closing_work_in_progress'] ?? $manualBundle['current']['note24_opening_work_in_progress'] ?? '';
    $derivedOpeningStockInTrade = $manualBundle['previous']['note24_closing_stock_in_trade'] ?? $manualBundle['current']['note24_opening_stock_in_trade'] ?? '';
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
        'tax_provision' => $postedManualInputs['tax_provision'],
        'note24_opening_stock_in_trade' => $postedManualInputs['note24_opening_stock_in_trade'] !== '' ? $postedManualInputs['note24_opening_stock_in_trade'] : (string) $derivedOpeningStockInTrade,
        'note24_closing_stock_in_trade' => $postedManualInputs['note24_closing_stock_in_trade'],
        'note_disclosure_cl' => $postedManualInputs['note_disclosure_cl'],
        'note_disclosure_com' => $postedManualInputs['note_disclosure_com'],
        'note_disclosure_msme' => $postedManualInputs['note_disclosure_msme'],
        'note_disclosure_rpt' => $postedManualInputs['note_disclosure_rpt'],
    ]);

    $shareClassTypes = $_POST['share_class_type'] ?? [];
    $shareClassFaceValues = $_POST['share_class_face_value'] ?? [];
    $shareClassAuthorisedShares = $_POST['share_class_authorised_shares'] ?? [];
    $shareClassOpeningShares = $_POST['share_class_opening_shares'] ?? [];
    $shareClassIssuedDuringYear = $_POST['share_class_issued_during_year'] ?? [];
    $shareClassBoughtBackDuringYear = $_POST['share_class_bought_back_during_year'] ?? [];
    $shareClassClosingShares = $_POST['share_class_closing_shares'] ?? [];
    $shareClassRowsToSave = [];
    foreach ($shareClassTypes as $index => $shareType) {
        $shareClassRowsToSave[] = [
            'share_type' => $shareType,
            'face_value' => $shareClassFaceValues[$index] ?? 0,
            'authorised_shares' => $shareClassAuthorisedShares[$index] ?? 0,
            'opening_shares' => $shareClassOpeningShares[$index] ?? 0,
            'issued_during_year' => $shareClassIssuedDuringYear[$index] ?? 0,
            'bought_back_during_year' => $shareClassBoughtBackDuringYear[$index] ?? 0,
            'closing_shares' => $shareClassClosingShares[$index] ?? 0,
        ];
    }
    saveShareCapitalClasses($pdo, $company_id, $fy_id, $shareClassRowsToSave);

    $shareholderNames = $_POST['shareholder_name'] ?? [];
    $shareholderShares = $_POST['shareholder_shares'] ?? [];
    $shareholderRowsToSave = [];
    foreach ($shareholderNames as $index => $name) {
        $shareholderRowsToSave[] = [
            'name' => $name,
            'shares' => $shareholderShares[$index] ?? 0,
        ];
    }
    saveShareholders($pdo, $company_id, $fy_id, $shareholderRowsToSave);

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

syncWorkflowFromValidation($pdo, $company_id, $fy_id, $hasReportData, $validationResult, $currentDiff, $noteCompleteness);

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
    $validationIssues[] = ['type' => 'error', 'text' => 'BS not balanced (Diff: ' . format_inr($currentDiff) . ')', 'diag' => true];
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
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/bs_diagnostics_panel.css?v=<?= filemtime(__DIR__ . '/../asset/css/bs_diagnostics_panel.css') ?>">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<script>
    window.BS_DIAG_BASE_URL = <?= json_encode(BASE_URL) ?>;
    window.BS_DIAG_ENTITY_ID = <?= (int) $company_id ?>;
    window.BS_DIAG_FY_ID = <?= (int) $fy_id ?>;
</script>
<script src="<?= BASE_URL ?>asset/js/bs_diagnostics_panel.js?v=<?= filemtime(__DIR__ . '/../asset/js/bs_diagnostics_panel.js') ?>"></script>

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

<?php if ($hasReportData && $isCorporate && $manualNoteDataIncomplete): ?>
<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;margin-bottom:14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:0.85rem;">
    <span>&#9998;</span>
    <div style="flex:1;">
        <strong style="color:#92400e;">Manual entry needed:</strong>
        <span style="color:#475569;">
            <?php if (!$hasCurrentShareCapitalDetail): ?>Note 1 (Share Capital) details -- authorised/issued shares, face value, shareholders &gt;5%<?= !$hasClosingStockDetail ? ' and ' : '' ?><?php endif; ?>
            <?php if (!$hasClosingStockDetail): ?>Closing Stock for the year (Tally only provides an opening figure)<?php endif; ?>
            <?= !$hasCurrentShareCapitalDetail || !$hasClosingStockDetail ? ' -- required for a complete Balance Sheet and Note 1/Note 24.' : '' ?>
        </span>
    </div>
    <button type="button" class="btn" onclick="document.getElementById('fsWorkspace').classList.add('input-open');document.getElementById('fsTogglePanel').innerHTML='&#9664; Hide';document.getElementById('fsInputPanel').scrollIntoView({behavior:'smooth'});" style="font-size:0.8rem;padding:6px 14px;white-space:nowrap;">Enter Details</button>
</div>
<?php endif; ?>

<?php if ($hasReportData && $isCorporate && $showNote2OpeningConfirmPrompt): ?>
<div style="display:flex;align-items:center;gap:10px;padding:12px 16px;margin-bottom:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:0.85rem;">
    <span>&#9432;</span>
    <div style="flex:1;">
        <strong style="color:#1e3a8a;">Confirm Note 2 opening balance:</strong>
        <span style="color:#475569;">
            Found "<?= htmlspecialchars($plOpeningCandidate['ledger_name']) ?>" (<?= format_inr($plOpeningCandidate['amount']) ?>) in the Trial Balance.
            This is normally your <strong>opening</strong> balance carried forward from prior years, not this year's closing figure.
            Confirm to use it as the Opening Balance for Note 2 (Reserves &amp; Surplus), or enter a different figure manually below.
        </span>
    </div>
    <form method="post" style="margin:0;" onsubmit="return confirm('Use <?= htmlspecialchars(addslashes(format_inr($plOpeningCandidate['amount']))) ?> as the Opening Balance for Note 2? You can still edit it afterward.');">
        <?= csrfInput() ?>
        <input type="hidden" name="report_action" value="confirm_note2_opening_balance">
        <button type="submit" class="btn btn-primary" style="font-size:0.8rem;padding:6px 14px;white-space:nowrap;">Confirm <?= format_inr($plOpeningCandidate['amount']) ?></button>
    </form>
    <button type="button" class="btn" onclick="document.getElementById('fsWorkspace').classList.add('input-open');document.getElementById('fsTogglePanel').innerHTML='&#9664; Hide';document.getElementById('note2_opening_profit_loss').scrollIntoView({behavior:'smooth'});document.getElementById('note2_opening_profit_loss').focus();" style="font-size:0.8rem;padding:6px 14px;white-space:nowrap;">Enter Different Figure</button>
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
    <span class="item <?= $vi['type'] ?>" style="cursor:pointer;" onclick="<?= !empty($vi['diag']) ? 'openBsDiagnosticsPanel();' : "document.getElementById('validationModal').style.display='flex';" ?>"><?= $vi['type'] === 'error' ? '&#10060;' : '&#9888;&#65039;' ?> <?= htmlspecialchars($vi['text']) ?></span>
    <?php endforeach; ?>
    <?php if (abs($currentDiff) <= 0.01 && abs($previousDiff) <= 0.01): ?>
    <span style="color:#16a34a;">&#9989; BS Identity: Balanced</span>
    <?php endif; ?>
</div>

<!-- Validation Issues Modal -->
<?= uiModalStart('validationModal', '⚠️ Validation Issues') ?>

<!-- BS Difference -->
<?php if (abs($currentDiff) > 0.01 || abs($previousDiff) > 0.01): ?>
<div style="border:1px solid #fca5a5;background:#fef2f2;border-radius:8px;padding:14px;margin-bottom:12px;">
    <div style="font-weight:700;color:#991b1b;margin-bottom:6px;">&#10060; Balance Sheet Not Balanced</div>
    <div style="font-size:0.88rem;color:#475569;margin-bottom:8px;">
        Current year difference: <strong><?= format_inr($currentDiff) ?></strong>
        <?php if (abs($previousDiff) > 0.01): ?><br>Previous year difference: <strong><?= format_inr($previousDiff) ?></strong><?php endif; ?>
    </div>
    <div style="font-size:0.82rem;color:#666;margin-bottom:10px;">This is the current residual, explained below — not a single root cause.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="btn" onclick="document.getElementById('validationModal').style.display='none';openBsDiagnosticsPanel();" style="font-size:0.78rem;padding:5px 12px;background:#dc2626;color:#fff;border:none;cursor:pointer;">View Difference Analysis</button>
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

<?= uiModalEnd() ?>
<?php endif; ?>

<!-- Three-Panel Workspace -->
<div class="fs-workspace<?= $manualNoteDataIncomplete ? ' input-open' : '' ?>" id="fsWorkspace">
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

    <?php if ($entityCategory === 'llp' || $entitySubcategory === 'partnership'): ?>
    <div class="fs-compliance-card" style="margin-bottom:16px;">
        <h3>Partners' Capital Schedule</h3>
        <p style="font-size:0.88rem;color:#475569;margin:0 0 12px;">Manage partners and their capital account movements for this financial year.</p>
        <a class="btn" href="<?= BASE_URL ?>statements/partner_capital.php" style="display:inline-block;">Open Partners' Capital Schedule</a>
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
