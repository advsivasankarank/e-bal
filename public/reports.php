<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';
$showSidebar = true;
$page_title = 'Management Reports';

requireFullContext();

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
        'note24_opening_stock_in_trade' => trim((string) ($_POST['note24_opening_stock_in_trade'] ?? '')),
        'note24_closing_finished_goods' => trim((string) ($_POST['note24_closing_finished_goods'] ?? '')),
        'note24_closing_work_in_progress' => trim((string) ($_POST['note24_closing_work_in_progress'] ?? '')),
        'note24_closing_stock_in_trade' => trim((string) ($_POST['note24_closing_stock_in_trade'] ?? '')),
        'tax_provision' => trim((string) ($_POST['tax_provision'] ?? '')),
    ];

    $derivedOpeningFinishedGoods = $manualBundle['previous']['note24_closing_finished_goods'] ?? $manualBundle['current']['note24_opening_finished_goods'] ?? '';
    $derivedOpeningWip = $manualBundle['previous']['note24_closing_work_in_progress'] ?? $manualBundle['current']['note24_opening_work_in_progress'] ?? '';
    $derivedOpeningStockTrade = $manualBundle['previous']['note24_closing_stock_in_trade'] ?? $manualBundle['current']['note24_opening_stock_in_trade'] ?? '';
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
        'note24_opening_stock_in_trade' => $postedManualInputs['note24_opening_stock_in_trade'] !== '' ? $postedManualInputs['note24_opening_stock_in_trade'] : (string) $derivedOpeningStockTrade,
        'note24_closing_finished_goods' => $postedManualInputs['note24_closing_finished_goods'],
        'note24_closing_work_in_progress' => $postedManualInputs['note24_closing_work_in_progress'],
        'note24_closing_stock_in_trade' => $postedManualInputs['note24_closing_stock_in_trade'],
        'tax_provision' => $postedManualInputs['tax_provision'],
    ]);

    header("Location: " . BASE_URL . "reports.php#notes-to-accounts");
    exit;
}

$fs = generateFinancialStatements(
    $pdo,
    $company_id,
    $fy_id,
    $fyName,
    $manualBundle['current'] ?? [],
    $manualBundle['previous'] ?? []
);
$hasReportData = (bool) ($fs['has_data'] ?? false);
$currentDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);
$previousDiff = (float) ($fs['validation']['previous_balance_difference'] ?? 0);
$parentGroupConflicts = $fs['validation']['parent_group_conflicts'] ?? [];
$noteCompleteness = $fs['validation']['note_completeness'] ?? ['missing' => [], 'is_complete' => true];

$validationResult = validateReportGeneration($pdo, $company_id, $fy_id, $fs);

if ($hasReportData) {
    $hasBlockingErrorsV = !empty($validationResult['errors']);
    $bsBalanced = abs($currentDiff) <= 0.01;
    $notesComplete = $noteCompleteness['is_complete'] ?? true;

    if (!$hasBlockingErrorsV && $bsBalanced && $notesComplete) {
        updateWorkflow($company_id, $fy_id, 'notes_prepared');
        updateWorkflow($company_id, $fy_id, 'profit_loss_prepared');
        updateWorkflow($company_id, $fy_id, 'balance_sheet_prepared');
    }
}

/* ============================================================
   PART A — READINESS STATUS COMPUTATION
   ============================================================ */
$conflictCount = count($parentGroupConflicts);
$missingNotesCount = count($noteCompleteness['missing'] ?? []);
$errorCount = count($validationResult['errors']);
$warningCount = count($validationResult['warnings']);
$bsDifference = abs($currentDiff) > 0.01;
$hasBlockingErrors = !empty($validationResult['errors']);
$hasConflicts = $conflictCount > 0;
$notesIncomplete = !($noteCompleteness['is_complete'] ?? true);
$hasWarnings = $warningCount > 0;

if ($bsDifference || $hasBlockingErrors) {
    $readinessStatus = 'blocked';
    $readinessLabel = 'Reports Blocked';
} elseif ($hasConflicts) {
    $readinessStatus = 'mapping_review';
    $readinessLabel = 'Mapping Review Required';
} elseif ($notesIncomplete) {
    $readinessStatus = 'notes_incomplete';
    $readinessLabel = 'Notes Incomplete';
} elseif ($hasWarnings) {
    $readinessStatus = 'warnings';
    $readinessLabel = 'Ready with Warnings';
} else {
    $readinessStatus = 'ready';
    $readinessLabel = 'Reports Ready';
}

$isBlocked = in_array($readinessStatus, ['blocked', 'mapping_review', 'notes_incomplete'], true);
$readinessColor = $readinessStatus === 'ready' ? '#16a34a' : ($readinessStatus === 'warnings' ? '#d97706' : '#dc2626');
$readinessBg = $readinessStatus === 'ready' ? '#dcfce7' : ($readinessStatus === 'warnings' ? '#fef3c7' : '#fee2e2');
$readinessBorder = $readinessStatus === 'ready' ? '#86efac' : ($readinessStatus === 'warnings' ? '#fcd34d' : '#fca5a5');
?>

<?php
$entityCategory = $fs['entity_category'] ?? '';
$entitySubcategory = $fs['entity_subcategory'] ?? '';
$isCorporate = $entityCategory === 'corporate';
$isNonCorporate = in_array($entitySubcategory, ['proprietorship', 'partnership'], true);
$isLLP = $entitySubcategory === 'llp';
$isTrustSociety = in_array($entitySubcategory, ['trust', 'society'], true);

$tabDefs = [];
if ($isCorporate) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'icon' => '&#128203;'],
        ['id' => 'profit-loss', 'label' => 'Profit &amp; Loss', 'icon' => '&#128200;'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes to Accounts', 'icon' => '&#128221;'],
        ['id' => 'directors-report', 'label' => "Director's Report", 'icon' => '&#128196;'],
    ];
} elseif ($isNonCorporate || $isLLP) {
    $tabDefs = [
        ['id' => 'trading-account', 'label' => 'Trading A/c', 'icon' => '&#128200;'],
        ['id' => 'profit-loss', 'label' => 'P&amp;L A/c', 'icon' => '&#128200;'],
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'icon' => '&#128203;'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes to Accounts', 'icon' => '&#128221;'],
    ];
} else {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet', 'icon' => '&#128203;'],
        ['id' => 'profit-loss', 'label' => 'Income &amp; Expenditure', 'icon' => '&#128200;'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes to Accounts', 'icon' => '&#128221;'],
    ];
}

$validationIssues = [];
if (abs($currentDiff) > 0.01 || abs($previousDiff) > 0.01) {
    $validationIssues[] = ['type' => 'error', 'text' => 'BS not balanced (Diff: &#8377;' . format_inr_number($currentDiff) . ')', 'link' => BASE_URL . 'reconciliation_console.php'];
}
if (!empty($parentGroupConflicts)) {
    $validationIssues[] = ['type' => 'warning', 'text' => count($parentGroupConflicts) . ' parent-group conflict(s)', 'link' => BASE_URL . 'data_console/mapping_workbench.php'];
}
if (!($noteCompleteness['is_complete'] ?? true)) {
    $validationIssues[] = ['type' => 'warning', 'text' => count($noteCompleteness['missing'] ?? []) . ' expected note heading(s) missing', 'link' => '#notes-to-accounts'];
}
$validationSummary = [];
if (!empty($validationResult['errors'])) {
    $validationSummary['errors'] = count($validationResult['errors']);
    $validationIssues[] = ['type' => 'error', 'text' => count($validationResult['errors']) . ' validation error(s)', 'link' => BASE_URL . 'review/index.php'];
}
if (!empty($validationResult['warnings'])) {
    $validationSummary['warnings'] = count($validationResult['warnings']);
    $validationIssues[] = ['type' => 'warning', 'text' => count($validationResult['warnings']) . ' validation warning(s)', 'link' => BASE_URL . 'review/index.php'];
}

/* ---- All redirects complete, safe to output HTML ---- */
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
    ['label' => 'Dashboard']
]) ?>

<?= uiPageHero('Financial Statement Workspace') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => $fs['company_meta']['entity_type'] ?? '',
    'profile' => 0,
    'status' => $hasReportData ? $readinessLabel : 'Setup Required',
    'edit_url' => '',
]) ?>

<?php if ($hasReportData): ?>
<!-- ============================================================
     PART B — BLOCKING BANNER
     ============================================================ -->
<?php if ($isBlocked || $readinessStatus === 'mapping_review' || $readinessStatus === 'notes_incomplete'): ?>
<div style="display:flex;align-items:center;gap:16px;padding:16px 24px;border-radius:12px;margin-bottom:14px;background:<?= $readinessBg ?>;border:1px solid <?= $readinessBorder ?>;">
    <div style="font-size:1.6rem;flex-shrink:0;"><?= $readinessStatus === 'blocked' ? '&#10060;' : '&#9888;&#65039;' ?></div>
    <div style="flex:1;">
        <div style="font-size:1.05rem;font-weight:700;color:<?= $readinessColor ?>;margin-bottom:2px;">Financial Statements Not Ready</div>
        <div style="font-size:0.82rem;color:#475569;">
            <?php if ($bsDifference): ?>Balance Sheet difference: &#8377;<?= format_inr_number($currentDiff) ?> &middot; <?php endif; ?>
            <?php if ($hasConflicts): ?><?= $conflictCount ?> parent-group conflict(s) &middot; <?php endif; ?>
            <?php if ($notesIncomplete): ?><?= $missingNotesCount ?> missing note heading(s) &middot; <?php endif; ?>
            <?php if ($errorCount > 0): ?><?= $errorCount ?> validation error(s) &middot; <?php endif; ?>
            <?php if ($warningCount > 0): ?><?= $warningCount ?> warning(s)<?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-shrink:0;flex-wrap:wrap;">
        <a class="btn" href="<?= BASE_URL ?>reconciliation_console.php" style="font-size:0.78rem;padding:6px 14px;background:#fff;border:1px solid <?= $readinessBorder ?>;">Open Reconciliation Console</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/mapping_workbench.php" style="font-size:0.78rem;padding:6px 14px;background:#fff;border:1px solid <?= $readinessBorder ?>;">Open ReconHub</a>
        <a class="btn" href="<?= BASE_URL ?>review/index.php" style="font-size:0.78rem;padding:6px 14px;background:#fff;border:1px solid <?= $readinessBorder ?>;">Open Review Centre</a>
        <a class="btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_review" style="font-size:0.78rem;padding:6px 14px;background:#fff;border:1px solid <?= $readinessBorder ?>;">Ask IntelAI</a>
    </div>
</div>
<?php elseif ($readinessStatus === 'ready'): ?>
<div style="display:flex;align-items:center;gap:12px;padding:12px 20px;border-radius:10px;margin-bottom:14px;background:#dcfce7;border:1px solid #86efac;">
    <span style="font-size:1.2rem;">&#9989;</span>
    <span style="font-size:0.9rem;font-weight:600;color:#166534;">Financial Statements Ready for Export</span>
</div>
<?php endif; ?>

<!-- ============================================================
     PART C — EXPORT BUTTONS (Draft Labels)
     ============================================================ -->
<div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:14px;">
    <?php if ($isBlocked): ?>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=pdf" style="min-height:34px;padding:0 14px;font-size:0.8rem;position:static;opacity:0.85;" onclick="return confirm('Financial statements are not ready. Export will be marked as draft. Continue?');">&#128196; Draft PDF</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=word" style="min-height:34px;padding:0 14px;font-size:0.8rem;position:static;background:#6b7280;opacity:0.85;" onclick="return confirm('Financial statements are not ready. Export will be marked as draft. Continue?');">&#128196; Draft Word</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=excel" style="min-height:34px;padding:0 14px;font-size:0.8rem;position:static;background:#6b7280;opacity:0.85;" onclick="return confirm('Financial statements are not ready. Export will be marked as draft. Continue?');">&#128196; Draft Excel</a>
    <?php else: ?>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=pdf" style="min-height:34px;padding:0 14px;font-size:0.8rem;position:static;">PDF</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=word" style="min-height:34px;padding:0 14px;font-size:0.8rem;position:static;background:linear-gradient(135deg,#1d4ed8,#1e40af);">Word</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?format=excel" style="min-height:34px;padding:0 14px;font-size:0.8rem;position:static;background:linear-gradient(135deg,#15803d,#166534);">Excel</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>

<style>
.fs-tab-bar {
    display: flex;
    gap: 2px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 14px;
    box-shadow: var(--shadow-sm);
}
.fs-tab {
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--muted);
    transition: all 0.15s;
    border: none;
    background: none;
    white-space: nowrap;
}
.fs-tab:hover { color: var(--text); background: var(--bg); }
.fs-tab.active { background: var(--brand); color: #fff; font-weight: 600; }
.fs-tab-spacer { flex: 1; }

/* ---- PART D: CLICKABLE VALIDATION STRIP ---- */
.fs-validation-strip {
    display: flex;
    gap: 8px;
    padding: 10px 16px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    margin-bottom: 14px;
    font-size: 0.78rem;
    align-items: center;
    flex-wrap: wrap;
}
.fs-val-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
    border: 1px solid transparent;
}
.fs-val-chip:hover { opacity: 0.85; transform: translateY(-1px); }
.fs-val-chip.error { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
.fs-val-chip.warning { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.fs-val-chip.ok { background: #dcfce7; color: #166534; border-color: #86efac; }
.fs-val-chip .chip-count { font-weight: 700; }

.fs-year-toggle {
    display: flex;
    gap: 4px;
    background: var(--bg);
    border-radius: 8px;
    padding: 3px;
    margin-bottom: 14px;
    width: fit-content;
}
.fs-year-btn {
    padding: 6px 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.8rem;
    font-weight: 500;
    background: none;
    color: var(--muted);
}
.fs-year-btn.active { background: var(--panel-strong); color: var(--text); box-shadow: var(--shadow-sm); }

.fs-workspace {
    display: grid;
    grid-template-columns: 220px 1fr 0fr;
    gap: 16px;
    min-height: 600px;
    transition: grid-template-columns 0.3s ease;
}
.fs-workspace.input-panel-open {
    grid-template-columns: 220px 1fr 360px;
}

/* Section sidebar */
.fs-section-sidebar {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    box-shadow: var(--shadow-sm);
    overflow-y: auto;
    max-height: 700px;
}
.fs-section-sidebar h3 {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--muted);
    margin: 0 0 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.fs-section-item {
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.85rem;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.1s;
}
.fs-section-item:hover { background: var(--bg); }
.fs-section-item.active { background: var(--brand); color: #fff; font-weight: 600; }
.fs-section-item .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.fs-section-item .dot.ok { background: var(--success); }
.fs-section-item .dot.warn { background: var(--warning); }
.fs-section-item .dot.err { background: var(--danger); }

/* Report canvas */
.fs-canvas {
    background: transparent;
    overflow-y: auto;
    max-height: 800px;
    position: relative;
}
.fs-canvas .report-page {
    width: 100%;
    min-height: auto;
    margin: 0 0 16px;
    background: #fff;
    border: 1px solid #d8e2ef;
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    padding: 28px 32px;
    box-sizing: border-box;
}
.fs-canvas .report-page:last-child { margin-bottom: 0; }
.fs-canvas .report-page[data-tab]:not([data-tab="active"]) { display: none; }

/* ---- PART F: DRAFT WATERMARK ---- */
.fs-draft-watermark {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 4px 12px;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    color: #92400e;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    z-index: 5;
    pointer-events: none;
}

/* ---- PART E: REVIEW & FIX PANEL ---- */
.fs-input-panel {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    max-height: 700px;
}
.fs-input-panel .panel-header {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.fs-input-panel .panel-header h3 { font-size: 0.95rem; margin: 0; }
.fs-input-panel .panel-header .toggle { font-size: 0.8rem; color: var(--brand); cursor: pointer; }
.fs-input-panel .panel-tabs {
    display: flex;
    border-bottom: 1px solid var(--border);
}
.fs-input-panel .panel-tab {
    flex: 1;
    padding: 8px;
    text-align: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.15s;
    background: none;
    border-top: none;
    border-left: none;
    border-right: none;
}
.fs-input-panel .panel-tab:hover { color: var(--text); background: var(--bg); }
.fs-input-panel .panel-tab.active { color: var(--brand); border-bottom-color: var(--brand); }
.fs-input-panel .panel-body { padding: 14px 16px; flex: 1; overflow-y: auto; }
.fs-input-panel .form-group { margin-bottom: 14px; }
.fs-input-panel .form-group label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--muted); margin-bottom: 4px; }
.fs-input-panel .form-group input, .fs-input-panel .form-group select { width: 100%; padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font-size: 0.85rem; }
.fs-input-panel .form-group .hint { font-size: 0.75rem; color: var(--muted); margin-top: 3px; }
.fs-input-panel .btn-primary { margin-top: 14px; }

/* Issue list in Review & Fix panel */
.fs-issue-list { list-style: none; padding: 0; margin: 0; }
.fs-issue-item {
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 0.8rem;
}
.fs-issue-item .fs-issue-title { font-weight: 600; margin-bottom: 3px; }
.fs-issue-item .fs-issue-reason { color: var(--muted); font-size: 0.75rem; margin-bottom: 6px; }
.fs-issue-item .fs-issue-action { font-size: 0.72rem; }
.fs-issue-item .fs-issue-action a { color: var(--brand); text-decoration: none; font-weight: 600; }
.fs-issue-item .fs-issue-action a:hover { text-decoration: underline; }
.fs-issue-item.error { border-color: #fca5a5; background: #fef2f2; }
.fs-issue-item.warning { border-color: #fcd34d; background: #fffbeb; }

/* IntelAI buttons in panel */
.fs-ai-btns { display: flex; flex-direction: column; gap: 6px; }
.fs-ai-btn {
    display: block;
    padding: 8px 12px;
    background: #f0f7ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    font-size: 0.78rem;
    color: var(--brand);
    cursor: pointer;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.15s;
}
.fs-ai-btn:hover { background: #e0efff; }

/* ---- PART G: NOTES UX ---- */
.fs-note-search {
    padding: 6px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 6px;
    font-size: 0.8rem;
    width: 100%;
    margin-bottom: 10px;
}
.fs-note-toggle-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 10px;
}
.fs-note-toggle-bar button {
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: #fff;
    font-size: 0.72rem;
    cursor: pointer;
}
.fs-note-toggle-bar button:hover { background: var(--bg); }

/* ---- PART H: BS HIGHLIGHTS ---- */
.fs-highlight-badge {
    display: inline-block;
    font-size: 0.65rem;
    padding: 1px 6px;
    border-radius: 4px;
    font-weight: 600;
    margin-left: 4px;
}
.fs-highlight-badge.review { background: #fef3c7; color: #92400e; }
.fs-highlight-badge.negative { background: #fee2e2; color: #991b1b; }
.fs-highlight-badge.od { background: #dbeafe; color: #1e40af; }

/* Override report-page styles only in canvas */
.fs-canvas h2.report-page-title {
    margin: 0 0 8px;
    font-size: 22px;
    line-height: 1.25;
    color: #0f172a;
}
.fs-canvas p.report-page-subtitle {
    margin: 0 0 16px;
    color: #475569;
    font-size: 13px;
}
.fs-canvas table { width: 100%; border-collapse: collapse; margin-top: 10px; }
.fs-canvas th, .fs-canvas td { border: 1px solid #dbe3ef; padding: 7px 10px; text-align: left; vertical-align: top; font-size: 12.5px; }
.fs-canvas tr.section td, .fs-canvas tr.section th { background: #f5f8fc; font-weight: 700; }
.fs-canvas td.figure, .fs-canvas th.figure { text-align: right; white-space: nowrap; }
.fs-canvas .signature-table td { border: 0 !important; }
.fs-canvas .notes-cover { margin-bottom: 14px; }
.fs-canvas .note-block { break-inside: avoid; margin-bottom: 20px; }
.fs-canvas .notes-shell { border-top: 2px solid #d7e3f3; padding-top: 6px; }
.fs-canvas .note-heading {
    margin: 0 0 10px;
    padding: 10px 12px;
    background: linear-gradient(180deg, #f8fbff 0%, #eef5fb 100%);
    border: 1px solid #d9e5f2;
    border-radius: 8px;
    color: #0f172a;
    font-size: 14px;
    line-height: 1.35;
    cursor: pointer;
    user-select: none;
}
.fs-canvas .note-heading:hover { background: linear-gradient(180deg, #f0f7ff 0%, #e0efff 100%); }
.fs-canvas .note-heading .note-collapse-icon { float: right; font-size: 0.75rem; color: var(--muted); transition: transform 0.2s; }
.fs-canvas .note-heading.collapsed .note-collapse-icon { transform: rotate(-90deg); }
.fs-canvas .note-table { margin-top: 0; border: 1px solid #d9e5f2; }
.fs-canvas .note-table thead th { background: #eef4f9; font-size: 12px; letter-spacing: 0.02em; color: #334155; }
.fs-canvas .note-table tbody tr:nth-child(even) td { background: #fbfdff; }
.fs-canvas .note-table tfoot td { background: #f3f7fb; font-weight: 700; }
.fs-canvas .note-policy-list { margin: 12px 0 0 18px; padding: 0; }
.fs-canvas .note-policy-list li { margin-bottom: 6px; line-height: 1.5; }
.fs-canvas .note-negative { color: #dc2626; font-weight: 600; }

.fs-no-data {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
}
.fs-no-data .icon { font-size: 2.5rem; margin-bottom: 12px; opacity: 0.3; }

@media print {
    @page { size: A4; margin: 10mm; }
    body { background: #fff !important; }
    .topbar, .page-title, .active-info, .fs-tab-bar, .fs-validation-strip, .fs-year-toggle, .fs-section-sidebar, .fs-input-panel, .btn, .btn-primary, .success-box, .error-box { display: none !important; }
    .fs-workspace { display: block !important; grid-template-columns: 1fr !important; }
    .fs-canvas { max-height: none !important; }
    .fs-canvas .report-page {
        width: auto;
        min-height: auto;
        margin: 0 0 10mm;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        padding: 0;
        page-break-after: always;
    }
    .fs-canvas .report-page:last-child { page-break-after: auto; }
    .fs-canvas .report-page[data-tab] { display: block !important; }
    .note-heading, .note-table thead th, .note-table tfoot td, .note-table tbody tr:nth-child(even) td {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .fs-draft-watermark { display: none !important; }
}
</style>

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
        <?= $tab['icon'] ?> <?= $tab['label'] ?>
    </button>
    <?php endforeach; ?>
    <span class="fs-tab-spacer"></span>
    <button class="fs-tab" onclick="window.print()">&#128424; Print</button>
    <button class="fs-tab" onclick="window.location.reload()">&#128260; Rebuild</button>
</div>

<!-- ============================================================
     PART D — CLICKABLE VALIDATION STRIP
     ============================================================ -->
<?php if (!empty($validationIssues) || !empty($validationResult['errors']) || !empty($validationResult['warnings'])): ?>
<div class="fs-validation-strip" id="fsValidationStrip">
    <?php foreach ($validationIssues as $vi): ?>
    <a class="fs-val-chip <?= $vi['type'] ?>" href="<?= htmlspecialchars($vi['link'] ?? '#') ?>" title="Click to fix">
        <?= $vi['type'] === 'error' ? '&#10060;' : '&#9888;&#65039;' ?>
        <?= $vi['text'] ?>
    </a>
    <?php endforeach; ?>
    <?php if ($hasReportData && abs($currentDiff) <= 0.01 && abs($previousDiff) <= 0.01): ?>
    <a class="fs-val-chip ok" href="<?= BASE_URL ?>reconciliation_console.php" title="View reconciliation">
        &#9989; BS Balanced (&#8377;<?= format_inr($fs['data']['total_assets'] ?? 0) ?>)
    </a>
    <?php endif; ?>
    <span style="margin-left:auto;">
        <button class="btn btn-sm btn-outline" onclick="window.location.reload()">&#128260; Refresh</button>
    </span>
</div>
<?php endif; ?>

<!-- Year Toggle -->
<div class="fs-year-toggle" id="fsYearToggle">
    <button class="fs-year-btn" data-year="current">Current Year</button>
    <button class="fs-year-btn" data-year="previous">Previous Year</button>
    <button class="fs-year-btn active" data-year="both">Side-by-Side</button>
</div>

<!-- Three-panel Workspace -->
<div class="fs-workspace" id="fsWorkspace">
    <!-- LEFT: Sections -->
    <div class="fs-section-sidebar" id="fsSectionSidebar">
        <h3 id="fsSectionTitle">Sections</h3>
        <div id="fsSectionList"></div>
    </div>

    <!-- CENTER: Report Canvas -->
    <div class="fs-canvas" id="fsCanvas">
        <?php if ($isBlocked): ?>
        <div class="fs-draft-watermark">DRAFT &mdash; VALIDATION PENDING</div>
        <?php endif; ?>
        <?php
        $data = $fs['data'];
        $notes = $fs['notes'];
        $company_meta = $fs['company_meta'] ?? [];
        $isFirstYear = (bool) ($fs['is_first_year'] ?? false);
        $subcategory = $fs['entity_subcategory'] ?? '';
        if ($subcategory === 'trust') {
            $fs['format_template'] = __DIR__ . '/reports_dashboard/formats/trust_format.php';
            $fs['notes_template'] = __DIR__ . '/reports_dashboard/formats/notes_trust.php';
        } elseif ($subcategory === 'society') {
            $fs['format_template'] = __DIR__ . '/reports_dashboard/formats/society_format.php';
            $fs['notes_template'] = __DIR__ . '/reports_dashboard/formats/notes_society.php';
        }
        include $fs['format_template'];
        ?>
        <?php include $fs['notes_template']; ?>
    </div>

    <!-- ============================================================
         PART E — RIGHT PANEL: REVIEW & FIX
         ============================================================ -->
    <div class="fs-input-panel" id="fsInputPanel">
        <div class="panel-header">
            <h3>&#128269; Review &amp; Fix</h3>
            <span class="toggle" id="fsTogglePanel">&#9654; Hide</span>
        </div>
        <div class="panel-tabs">
            <button class="panel-tab active" data-panel="issues">Issues</button>
            <button class="panel-tab" data-panel="adjustments">Adjustments</button>
            <button class="panel-tab" data-panel="intelai">IntelAI</button>
        </div>
        <div class="panel-body">
            <!-- Issues Tab -->
            <div class="panel-tab-content" id="panel-issues">
                <?php if ($isBlocked || $hasConflicts || $notesIncomplete || $hasWarnings): ?>
                <ul class="fs-issue-list">
                    <?php if ($bsDifference): ?>
                    <li class="fs-issue-item error">
                        <div class="fs-issue-title">&#10060; Balance Sheet Not Balanced</div>
                        <div class="fs-issue-reason">Current year difference: &#8377;<?= format_inr_number($currentDiff) ?>. Assets and liabilities do not match.</div>
                        <div class="fs-issue-action"><a href="<?= BASE_URL ?>reconciliation_console.php">Open Reconciliation Console</a></div>
                    </li>
                    <?php endif; ?>
                    <?php if ($hasConflicts): ?>
                    <li class="fs-issue-item error">
                        <div class="fs-issue-title">&#128681; Parent-Group Conflicts (<?= $conflictCount ?>)</div>
                        <div class="fs-issue-reason">Ledgers with conflicting parent-group mappings are excluded from classification.</div>
                        <div class="fs-issue-action"><a href="<?= BASE_URL ?>data_console/mapping_workbench.php">Open ReconHub</a></div>
                    </li>
                    <?php endif; ?>
                    <?php if ($notesIncomplete): ?>
                    <li class="fs-issue-item warning">
                        <div class="fs-issue-title">&#128221; Missing Note Headings (<?= $missingNotesCount ?>)</div>
                        <div class="fs-issue-reason">Expected note sections are missing from the report.</div>
                        <div class="fs-issue-action"><a href="#notes-to-accounts">View Notes Tab</a></div>
                    </li>
                    <?php endif; ?>
                    <?php foreach ($validationResult['errors'] as $err): ?>
                    <li class="fs-issue-item error">
                        <div class="fs-issue-title">&#10060; <?= htmlspecialchars($err['check'] ?? 'Error') ?></div>
                        <div class="fs-issue-reason"><?= htmlspecialchars($err['message'] ?? '') ?></div>
                        <div class="fs-issue-action"><a href="<?= BASE_URL ?>review/index.php">Open Review Centre</a></div>
                    </li>
                    <?php endforeach; ?>
                    <?php foreach ($validationResult['warnings'] as $warn): ?>
                    <li class="fs-issue-item warning">
                        <div class="fs-issue-title">&#9888;&#65039; <?= htmlspecialchars($warn['check'] ?? 'Warning') ?></div>
                        <div class="fs-issue-reason"><?= htmlspecialchars($warn['message'] ?? '') ?></div>
                        <div class="fs-issue-action"><a href="<?= BASE_URL ?>review/index.php">Open Review Centre</a></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div style="padding:16px;text-align:center;color:var(--success);font-size:0.85rem;">
                    &#9989; No issues detected. Reports are ready.
                </div>
                <?php endif; ?>
            </div>

            <!-- Manual Adjustments Tab -->
            <div class="panel-tab-content" id="panel-adjustments" style="display:none;">
                <?php if ($isCorporate): ?>
                <form method="post" id="manualInputForm">
                    <?= csrfInput() ?>
                    <input type="hidden" name="report_action" value="save_manual_company_note">
                    <div class="form-group">
                        <label for="share_capital_authorised">Authorised Capital (&#8377;)</label>
                        <input id="share_capital_authorised" name="share_capital_authorised" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_authorised'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="share_capital_issued">Issued Capital (&#8377;)</label>
                        <input id="share_capital_issued" name="share_capital_issued" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_issued'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="share_capital_paidup">Paid-up Capital (&#8377;)</label>
                        <input id="share_capital_paidup" name="share_capital_paidup" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_paidup'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note2_opening_profit_loss">Note 2 Opening P&amp;L (&#8377;)</label>
                        <input id="note2_opening_profit_loss" name="note2_opening_profit_loss" type="number" step="0.01" value="<?= htmlspecialchars((string) (($manualBundle['saved_current']['note2_opening_profit_loss'] ?? '') !== '' ? $manualBundle['saved_current']['note2_opening_profit_loss'] : ($fs['notes']['other_equity']['opening_balance'] ?? ''))) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note16_opening_raw_materials">Opening Raw Materials (&#8377;)</label>
                        <input id="note16_opening_raw_materials" name="note16_opening_raw_materials" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note16_opening_raw_materials'] ?? $manualBundle['previous']['note16_closing_raw_materials'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note16_closing_raw_materials">Closing Raw Materials (&#8377;)</label>
                        <input id="note16_closing_raw_materials" name="note16_closing_raw_materials" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note16_closing_raw_materials'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note24_opening_finished_goods">Opening FG (&#8377;)</label>
                        <input id="note24_opening_finished_goods" name="note24_opening_finished_goods" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_finished_goods'] ?? $manualBundle['previous']['note24_closing_finished_goods'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note24_opening_work_in_progress">Opening WIP (&#8377;)</label>
                        <input id="note24_opening_work_in_progress" name="note24_opening_work_in_progress" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_work_in_progress'] ?? $manualBundle['previous']['note24_closing_work_in_progress'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note24_opening_stock_in_trade">Opening Stock (&#8377;)</label>
                        <input id="note24_opening_stock_in_trade" name="note24_opening_stock_in_trade" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_stock_in_trade'] ?? $manualBundle['previous']['note24_closing_stock_in_trade'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note24_closing_finished_goods">Closing FG (&#8377;)</label>
                        <input id="note24_closing_finished_goods" name="note24_closing_finished_goods" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_finished_goods'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note24_closing_work_in_progress">Closing WIP (&#8377;)</label>
                        <input id="note24_closing_work_in_progress" name="note24_closing_work_in_progress" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_work_in_progress'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="note24_closing_stock_in_trade">Closing Stock (&#8377;)</label>
                        <input id="note24_closing_stock_in_trade" name="note24_closing_stock_in_trade" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_stock_in_trade'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="tax_provision">Provision for Tax, Current Year (&#8377;)</label>
                        <input id="tax_provision" name="tax_provision" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['tax_provision'] ?? '')) ?>">
                    </div>
                    <button class="btn btn-primary" type="submit" style="width:100%;">Save Manual Inputs</button>
                </form>
                <?php else: ?>
                <div style="font-size:0.85rem;color:var(--muted);">Manual adjustments for this entity type are configured in the report engine.</div>
                <?php endif; ?>
                <?php if ($isCorporate): ?>
                <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
                    <h4 style="font-size:0.85rem;margin:0 0 8px;color:var(--muted);">Adjustment History</h4>
                    <div style="font-size:0.8rem;padding:8px;background:var(--bg);border-radius:8px;">
                        <div>Values are carried forward from the previous financial year automatically.</div>
                        <div style="color:var(--muted);margin-top:4px;">Last saved adjustments are displayed above.</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- IntelAI Tab -->
            <div class="panel-tab-content" id="panel-intelai" style="display:none;">
                <div style="font-size:0.82rem;color:var(--muted);margin-bottom:10px;">Ask IntelAI to explain your financial statements.</div>
                <div class="fs-ai-btns">
                    <a class="fs-ai-btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_review&entity=<?= urlencode($companyName) ?>&fy=<?= urlencode($fyName) ?>" target="_blank">Review Financial Statements</a>
                    <a class="fs-ai-btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_bs_diff&diff=<?= urlencode(format_inr_number($currentDiff)) ?>" target="_blank">Why is Balance Sheet Not Balanced?</a>
                    <a class="fs-ai-btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_missing_notes" target="_blank">Explain Missing Notes</a>
                    <a class="fs-ai-btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_mapping_risks" target="_blank">Review Mapping Risks</a>
                    <a class="fs-ai-btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_conflicts" target="_blank">Explain Parent-Group Conflict</a>
                    <a class="fs-ai-btn" href="<?= BASE_URL ?>data_console/ai_mapping.php?context=fs_make_ready" target="_blank">How to Make Reports Ready?</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isCorporate): ?>
<div class="card" style="margin-top:16px;">
    <strong>Next Step</strong><br>
    After reviewing the Balance Sheet, Profit &amp; Loss, and Notes to Accounts, continue to the Directors Report for the same company and financial year.<br><br>
    <a class="btn" href="<?= BASE_URL ?>directors_report.php">Open Directors Report</a>
</div>
<?php endif; ?>

<!-- ============================================================
     PART I — INTELAI CONTEXT
     ============================================================ -->
<script>
window.ebalIntelAIContext = {
    module: "Financial Statements",
    entityName: <?= json_encode($companyName) ?>,
    fy: <?= json_encode($fyName) ?>,
    activeStatement: "balance-sheet",
    bsDifference: <?= json_encode(format_inr_number($currentDiff)) ?>,
    validationErrors: <?= json_encode($errorCount) ?>,
    validationWarnings: <?= json_encode($warningCount) ?>,
    parentGroupConflicts: <?= json_encode($conflictCount) ?>,
    missingNotes: <?= json_encode($missingNotesCount) ?>
};
</script>

<script>
(function () {
    'use strict';

    var tabBar = document.getElementById('fsTabBar');
    var canvas = document.getElementById('fsCanvas');
    var sidebar = document.getElementById('fsSectionList');
    var sidebarTitle = document.getElementById('fsSectionTitle');
    var togglePanel = document.getElementById('fsTogglePanel');
    var workspace = document.getElementById('fsWorkspace');
    var yearBtns = document.querySelectorAll('.fs-year-btn');

    // Get all report-page sections and assign data-tab attribute
    var pages = canvas.querySelectorAll('.report-page');
    pages.forEach(function (page) {
        var id = page.getAttribute('id');
        if (id) {
            page.setAttribute('data-tab', id);
        }
    });

    // Show only the first tab's pages initially
    var activeTab = tabBar.querySelector('.fs-tab.active');
    if (activeTab) switchTab(activeTab.getAttribute('data-tab'));

    // Tab switching
    tabBar.addEventListener('click', function (e) {
        var btn = e.target.closest('.fs-tab');
        if (!btn || btn.classList.contains('active')) return;
        if (btn.onclick) return;
        var tabId = btn.getAttribute('data-tab');
        if (!tabId) return;
        tabBar.querySelectorAll('.fs-tab').forEach(function (t) { t.classList.remove('active'); });
        btn.classList.add('active');
        switchTab(tabId);
    });

    function switchTab(tabId) {
        pages.forEach(function (p) {
            p.style.display = p.getAttribute('data-tab') === tabId ? '' : 'none';
        });
        buildSectionSidebar(tabId);
    }

    function buildSectionSidebar(tabId) {
        var activePage = null;
        pages.forEach(function (p) {
            if (p.getAttribute('data-tab') === tabId && p.style.display !== 'none') {
                activePage = p;
            }
        });
        if (!activePage) {
            sidebarTitle.textContent = 'Sections';
            sidebar.innerHTML = '<div style="font-size:0.85rem;color:var(--muted);padding:8px;">No sections available</div>';
            return;
        }

        var headings = activePage.querySelectorAll('h2, h3, .section-header td, .note-heading');
        var items = [];
        headings.forEach(function (h) {
            var text = h.textContent.trim().replace(/\s+/g, ' ');
            if (text.length > 0 && text.length < 100) {
                items.push(text);
            }
        });

        if (items.length === 0) {
            var boldRows = activePage.querySelectorAll('tr td b, tr td strong');
            boldRows.forEach(function (b) {
                var text = b.textContent.trim().replace(/\s+/g, ' ');
                if (text.length > 0 && text.length < 80 && items.indexOf(text) < 0) {
                    items.push(text);
                }
            });
        }

        if (items.length === 0) {
            sidebarTitle.textContent = 'Sections';
            sidebar.innerHTML = '<div style="font-size:0.85rem;color:var(--muted);padding:8px;">Scroll to navigate</div>';
            return;
        }

        var titles = { 'balance-sheet': 'Balance Sheet Sections', 'profit-loss': 'P&amp;L Sections', 'trading-account': 'Trading A/c Sections', 'notes-to-accounts': 'Note Sections', 'directors-report': 'Report Sections' };
        sidebarTitle.textContent = titles[tabId] || 'Sections';

        var html = '';
        items.forEach(function (item) {
            var dot = 'ok';
            if (item.indexOf('Inventory') >= 0 || item.indexOf('Stock') >= 0) dot = 'warn';
            if (item.indexOf('Total') >= 0 || item.indexOf('TOTAL') >= 0) dot = 'ok';
            html += '<div class="fs-section-item" data-section="' + escAttr(item) + '"><span class="dot ' + dot + '"></span>' + escHtml(item) + '</div>';
        });
        sidebar.innerHTML = html;

        sidebar.querySelectorAll('.fs-section-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var text = this.getAttribute('data-section');
                sidebar.querySelectorAll('.fs-section-item').forEach(function (s) { s.classList.remove('active'); });
                this.classList.add('active');
                scrollToSection(text);
            });
        });
    }

    function scrollToSection(text) {
        var allElements = canvas.querySelectorAll('h2, h3, .note-heading, .section-header td, td b, td strong, tr');
        var bestMatch = null;
        allElements.forEach(function (el) {
            var t = el.textContent.trim().replace(/\s+/g, ' ');
            if (t === text || t.indexOf(text) >= 0 || text.indexOf(t) >= 0) {
                bestMatch = el;
            }
        });
        if (bestMatch) {
            bestMatch.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) {
        if (!s) return '';
        return String(s).replace(/"/g, '&quot;').replace(/&/g, '&amp;');
    }

    // Year toggle
    yearBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            yearBtns.forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            var year = this.getAttribute('data-year');
            var prevCols = canvas.querySelectorAll('[data-prev]');
            if (year === 'previous') {
                var allFigs = canvas.querySelectorAll('.figure, th.figure');
                allFigs.forEach(function (f) {
                    if (!f.hasAttribute('data-prev')) {
                        f.style.display = 'none';
                    } else {
                        f.style.display = '';
                    }
                });
            } else {
                var allFigs = canvas.querySelectorAll('.figure, th.figure');
                allFigs.forEach(function (f) {
                    f.style.display = '';
                });
                if (year === 'current') {
                    prevCols.forEach(function (col) { col.style.display = 'none'; });
                }
            }
        });
    });

    // Panel toggle
    if (togglePanel) {
        togglePanel.addEventListener('click', function () {
            workspace.classList.toggle('input-panel-open');
            this.innerHTML = workspace.classList.contains('input-panel-open') ? '&#9654; Hide' : '&#9664; Show';
        });
    }

    // ---- PART E: Panel tab switching ----
    var panelTabs = document.querySelectorAll('.panel-tab');
    var panelContents = document.querySelectorAll('.panel-tab-content');
    panelTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = this.getAttribute('data-panel');
            panelTabs.forEach(function (t) { t.classList.remove('active'); });
            panelContents.forEach(function (c) { c.style.display = 'none'; });
            this.classList.add('active');
            var el = document.getElementById('panel-' + target);
            if (el) el.style.display = '';
        });
    });

    // ---- PART G: Notes UX — Collapse/Expand & Search ----
    var noteHeadings = canvas.querySelectorAll('.note-heading');
    noteHeadings.forEach(function (h) {
        // Add collapse icon
        var icon = document.createElement('span');
        icon.className = 'note-collapse-icon';
        icon.innerHTML = '&#9660;';
        h.appendChild(icon);

        // Click to toggle
        h.addEventListener('click', function () {
            var block = this.closest('.note-block');
            if (!block) return;
            var content = block.querySelector('.note-table, .note-policy-list, table, ul');
            if (!content) return;
            var isCollapsed = this.classList.toggle('collapsed');
            content.style.display = isCollapsed ? 'none' : '';
            icon.innerHTML = isCollapsed ? '&#9654;' : '&#9660;';
        });
    });

    // Notes expand/collapse all buttons (in sidebar when notes tab active)
    // These are invoked from the notes tab
    window.fsExpandAllNotes = function () {
        noteHeadings.forEach(function (h) {
            h.classList.remove('collapsed');
            var block = h.closest('.note-block');
            if (block) {
                var content = block.querySelector('.note-table, .note-policy-list, table, ul');
                if (content) content.style.display = '';
                var icon = h.querySelector('.note-collapse-icon');
                if (icon) icon.innerHTML = '&#9660;';
            }
        });
    };
    window.fsCollapseAllNotes = function () {
        noteHeadings.forEach(function (h) {
            h.classList.add('collapsed');
            var block = h.closest('.note-block');
            if (block) {
                var content = block.querySelector('.note-table, .note-policy-list, table, ul');
                if (content) content.style.display = 'none';
                var icon = h.querySelector('.note-collapse-icon');
                if (icon) icon.innerHTML = '&#9654;';
            }
        });
    };

    // ---- PART H: BS Issue Highlights ----
    // Highlight negative figures in the canvas
    var figures = canvas.querySelectorAll('.figure');
    figures.forEach(function (fig) {
        var val = parseFloat(fig.textContent.replace(/[₹,\s]/g, ''));
        if (!isNaN(val) && val < 0) {
            fig.classList.add('note-negative');
        }
    });

    // Init: open input panel by default for corporate
    <?php if ($isCorporate): ?>
    workspace.classList.add('input-panel-open');
    <?php endif; ?>
})();
</script>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/layouts/footer_v2.php';
?>
