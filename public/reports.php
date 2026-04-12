<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/layouts/header.php';

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['report_action'] ?? '') === 'save_manual_company_note') {
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

if ($hasReportData) {
    updateWorkflow($company_id, $fy_id, 'notes_prepared');
    updateWorkflow($company_id, $fy_id, 'profit_loss_prepared');
    updateWorkflow($company_id, $fy_id, 'balance_sheet_prepared');
}
?>

<div class="page-title">Financial Statements</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fyName) ?></strong><br>
    Format: <strong><?= htmlspecialchars($fs['title'] ?? 'Financial Statements') ?></strong>
</div>

<style>
.report-shell { background: #fff; border: 1px solid #d8e2ef; border-radius: 16px; padding: 24px; }
.report-shell h2 { margin-top: 28px; }
.report-shell table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.report-shell th, .report-shell td { border: 1px solid #dbe3ef; padding: 10px 12px; text-align: left; vertical-align: top; }
.report-shell tr.section td, .report-shell tr.section th { background: #f5f8fc; font-weight: 700; }
.report-shell td.figure, .report-shell th.figure { text-align: right; white-space: nowrap; }
.manual-note-form { margin: 20px 0 28px; padding: 18px; border: 1px solid #dbe3ef; border-radius: 12px; background: #f8fbff; }
.manual-note-grid { display: grid; grid-template-columns: repeat(3, minmax(160px, 1fr)); gap: 14px; }
.manual-note-grid label { display: block; font-weight: 600; margin-bottom: 6px; }
.manual-note-grid input { width: 100%; padding: 10px 12px; border: 1px solid #cfd8e3; border-radius: 8px; }
.btn-primary { display: inline-block; margin-top: 14px; padding: 10px 16px; background: #1e5aa8; color: #fff; border: 0; border-radius: 8px; cursor: pointer; }
</style>

<?php if (!$hasReportData): ?>
    <div class="card">
        No report figures are available yet for this company and financial year. Complete ledger mapping first, then fetch the trial balance from Tally and reopen reports.
    </div>
<?php else: ?>
    <?php if (abs($currentDiff) > 0.01 || abs($previousDiff) > 0.01): ?>
        <div class="error-box" style="margin-bottom:20px;">
            <p>The report is not fully reconciled yet.</p>
            <p>Current year difference: <?= number_format($currentDiff, 2) ?></p>
            <p>Previous year difference: <?= number_format($previousDiff, 2) ?></p>
            <p>Review the mapped heads and note totals before treating this statement as final.</p>
        </div>
    <?php endif; ?>
    <?php if (!empty($parentGroupConflicts)): ?>
        <div class="error-box" style="margin-bottom:20px;">
            <p>Parent group validation conflicts were excluded from note building.</p>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach (array_slice($parentGroupConflicts, 0, 8) as $conflict): ?>
                    <li><?= htmlspecialchars($conflict['ledger_name'] . ' [' . ($conflict['parent_group'] !== '' ? $conflict['parent_group'] : 'No Parent Group') . '] -> ' . $conflict['schedule_code']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <div class="report-shell">
        <?php if (($fs['entity_category'] ?? '') === 'corporate'): ?>
            <form method="post" class="manual-note-form">
                <h2>Manual Share Capital Inputs</h2>
                <p>Fill these once for the first reporting year. In later years, the report carries forward the previous year values automatically and you can revise them if required.</p>
                <input type="hidden" name="report_action" value="save_manual_company_note">
                <div class="manual-note-grid">
                    <div>
                        <label for="share_capital_authorised">Authorised Capital</label>
                        <input id="share_capital_authorised" name="share_capital_authorised" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_authorised'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="share_capital_issued">Issued Capital</label>
                        <input id="share_capital_issued" name="share_capital_issued" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_issued'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="share_capital_paidup">Paid-up Capital</label>
                        <input id="share_capital_paidup" name="share_capital_paidup" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['share_capital_paidup'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note2_opening_profit_loss">Note 2 Opening P&amp;L Balance</label>
                        <input id="note2_opening_profit_loss" name="note2_opening_profit_loss" type="number" step="0.01" value="<?= htmlspecialchars((string) (($manualBundle['saved_current']['note2_opening_profit_loss'] ?? '') !== '' ? $manualBundle['saved_current']['note2_opening_profit_loss'] : ($fs['notes']['other_equity']['opening_balance'] ?? ''))) ?>">
                    </div>
                    <div>
                        <label for="note16_opening_raw_materials">Opening Raw Materials</label>
                        <input id="note16_opening_raw_materials" name="note16_opening_raw_materials" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note16_opening_raw_materials'] ?? $manualBundle['previous']['note16_closing_raw_materials'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note16_closing_raw_materials">Closing Raw Materials</label>
                        <input id="note16_closing_raw_materials" name="note16_closing_raw_materials" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note16_closing_raw_materials'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note24_opening_finished_goods">Opening FG</label>
                        <input id="note24_opening_finished_goods" name="note24_opening_finished_goods" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_finished_goods'] ?? $manualBundle['previous']['note24_closing_finished_goods'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note24_opening_work_in_progress">Opening WIP</label>
                        <input id="note24_opening_work_in_progress" name="note24_opening_work_in_progress" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_work_in_progress'] ?? $manualBundle['previous']['note24_closing_work_in_progress'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note24_opening_stock_in_trade">Opening Stock</label>
                        <input id="note24_opening_stock_in_trade" name="note24_opening_stock_in_trade" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_opening_stock_in_trade'] ?? $manualBundle['previous']['note24_closing_stock_in_trade'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note24_closing_finished_goods">Closing FG</label>
                        <input id="note24_closing_finished_goods" name="note24_closing_finished_goods" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_finished_goods'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note24_closing_work_in_progress">Closing WIP</label>
                        <input id="note24_closing_work_in_progress" name="note24_closing_work_in_progress" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_work_in_progress'] ?? '')) ?>">
                    </div>
                    <div>
                        <label for="note24_closing_stock_in_trade">Closing Stock</label>
                        <input id="note24_closing_stock_in_trade" name="note24_closing_stock_in_trade" type="number" step="0.01" value="<?= htmlspecialchars((string) ($manualBundle['current']['note24_closing_stock_in_trade'] ?? '')) ?>">
                    </div>
                </div>
                <button class="btn-primary" type="submit">Save Manual Inputs</button>
            </form>
        <?php endif; ?>

        <div id="balance-sheet"></div>
        <?php
        $data = $fs['data'];
        $notes = $fs['notes'];
        $company_meta = $fs['company_meta'] ?? [];
        include $fs['format_template'];
        ?>

        <?php if (($fs['entity_category'] ?? '') === 'corporate'): ?>
            <div class="card" style="margin:20px 0;">
                <strong>Next Step</strong><br>
                After reviewing the Balance Sheet, Profit &amp; Loss, and Notes to Accounts, continue to the Directors Report for the same company and financial year.<br><br>
                <a class="btn" href="<?= BASE_URL ?>directors_report.php">Open Directors Report</a>
            </div>
        <?php endif; ?>

        <div id="notes-to-accounts"></div>
        <?php include $fs['notes_template']; ?>
        <div id="profit-loss"></div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
