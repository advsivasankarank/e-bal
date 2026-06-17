<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/layouts/header.php';

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
$noteCompleteness = $fs['validation']['note_completeness'] ?? ['missing' => [], 'is_complete' => true];

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
    Format: <strong><?= htmlspecialchars($fs['title'] ?? 'Financial Statements') ?></strong><br>
    Entity: <strong><?= htmlspecialchars($fs['company_meta']['entity_type'] ?? 'N/A') ?></strong>
</div>

<?php if ($hasReportData): ?>
    <div class="card section-card report-actions">
        <strong>Report Actions</strong><br>
        Download the same report in PDF, Word, or Excel, or use the print layout for browser PDF output.
        <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn btn-export btn-download-pdf" href="<?= BASE_URL ?>report_download.php?format=pdf">Download PDF</a>
            <a class="btn btn-export btn-download-word" href="<?= BASE_URL ?>report_download.php?format=word">Download Word</a>
            <a class="btn btn-export btn-download-excel" href="<?= BASE_URL ?>report_download.php?format=excel">Download Excel</a>
            <button type="button" class="btn btn-export btn-print" onclick="window.print()">Save as PDF</button>
        </div>
    </div>
<?php endif; ?>

<style>
.report-shell { background: transparent; border: 0; padding: 0; }
.report-page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto 24px;
    background: #fff;
    border: 1px solid #d8e2ef;
    border-radius: 12px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
    padding: 18mm 16mm;
    box-sizing: border-box;
    page-break-after: always;
}
.report-page:last-child { page-break-after: auto; }
.report-page-title {
    margin: 0 0 8px;
    font-size: 24px;
    line-height: 1.25;
    color: #0f172a;
}
.report-page-subtitle {
    margin: 0 0 18px;
    color: #475569;
    font-size: 13px;
}
.report-shell h2 { margin: 0 0 10px; font-size: 22px; }
.report-shell h3 { margin: 18px 0 10px; font-size: 16px; }
.report-shell table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.report-shell th, .report-shell td { border: 1px solid #dbe3ef; padding: 8px 10px; text-align: left; vertical-align: top; font-size: 13px; }
.report-shell tr.section td, .report-shell tr.section th { background: #f5f8fc; font-weight: 700; }
.report-shell td.figure, .report-shell th.figure { text-align: right; white-space: nowrap; }
.notes-cover { margin-bottom: 16px; }
.note-block { break-inside: avoid; page-break-inside: avoid; margin-bottom: 22px; }
.notes-shell {
    border-top: 2px solid #d7e3f3;
    padding-top: 6px;
}
.note-heading {
    margin: 0 0 10px;
    padding: 10px 12px;
    background: linear-gradient(180deg, #f8fbff 0%, #eef5fb 100%);
    border: 1px solid #d9e5f2;
    border-radius: 8px;
    color: #0f172a;
    font-size: 15px;
    line-height: 1.35;
}
.note-table {
    margin-top: 0;
    border: 1px solid #d9e5f2;
}
.note-table thead th {
    background: #eef4f9;
    font-size: 12.5px;
    letter-spacing: 0.02em;
    color: #334155;
}
.note-table tbody tr:nth-child(even) td {
    background: #fbfdff;
}
.note-table tfoot td {
    background: #f3f7fb;
    font-weight: 700;
}
.note-policy-list {
    margin: 12px 0 0 18px;
    padding: 0;
}
.note-policy-list li {
    margin-bottom: 6px;
    line-height: 1.5;
}
.signature-table td { border: 0 !important; }
.signature-block { margin-top: 28px; }
.signature-caption { color: #475569; font-size: 12px; margin-top: 8px; }
.manual-note-form { margin: 20px 0 28px; padding: 18px; border: 1px solid #dbe3ef; border-radius: 12px; background: #f8fbff; }
.manual-note-grid { display: grid; grid-template-columns: repeat(3, minmax(160px, 1fr)); gap: 14px; }
.manual-note-grid label { display: block; font-weight: 600; margin-bottom: 6px; }
.manual-note-grid input { width: 100%; padding: 10px 12px; border: 1px solid #cfd8e3; border-radius: 8px; }
.btn-primary { display: inline-block; margin-top: 14px; padding: 10px 16px; background: #1e5aa8; color: #fff; border: 0; border-radius: 8px; cursor: pointer; }
.btn-export {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 148px;
    padding: 10px 16px;
    border: 0;
    border-radius: 8px;
    color: #fff;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
}
.btn-download-pdf { background: #0f766e; }
.btn-download-pdf:hover { background: #115e59; }
.btn-download-word { background: #1d4ed8; }
.btn-download-word:hover { background: #1e40af; }
.btn-download-excel { background: #15803d; }
.btn-download-excel:hover { background: #166534; }
.btn-print { background: #7c3aed; }
.btn-print:hover { background: #6d28d9; }
@media print {
    @page {
        size: A4;
        margin: 10mm;
    }
    body { background: #fff !important; }
    .topbar, .page-title, .active-info, .card.section-card, .report-actions, .manual-note-form, .btn, .btn-primary, .btn-export, .error-box { display: none !important; }
    .report-shell { margin: 0; padding: 0; }
    .report-page {
        width: auto;
        min-height: auto;
        margin: 0;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        padding: 12mm 10mm;
    }
    .note-heading,
    .note-table thead th,
    .note-table tfoot td,
    .note-table tbody tr:nth-child(even) td {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
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
    <?php if (!(bool) ($noteCompleteness['is_complete'] ?? true)): ?>
        <div class="error-box" style="margin-bottom:20px;">
            <p>Some expected note headings are missing for this report format.</p>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach (($noteCompleteness['missing'] ?? []) as $missingTitle): ?>
                    <li><?= htmlspecialchars((string) $missingTitle) ?></li>
                <?php endforeach; ?>
            </ul>
            <p style="margin-top:8px;">Please review the mapped heads and note payload before issuing or exporting the statements.</p>
        </div>
    <?php endif; ?>

    <?php
    $validationResult = validateReportGeneration($pdo, $company_id, $fy_id, $fs);
    if (!empty($validationResult['errors'])): ?>
        <div class="card" style="margin-bottom:20px; border: 2px solid #dc2626; border-radius: 10px; padding: 16px;">
            <strong style="color:#dc2626; font-size:15px;">Validation Errors — Report May Be Incomplete</strong>
            <ul style="margin:10px 0 0 18px;">
                <?php foreach ($validationResult['errors'] as $ve): ?>
                    <li style="margin-bottom:6px;"><?= htmlspecialchars($ve['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($validationResult['warnings'])): ?>
        <div class="card" style="margin-bottom:20px; border: 2px solid #f59e0b; border-radius: 10px; padding: 16px;">
            <strong style="color:#f59e0b; font-size:15px;">Validation Warnings</strong>
            <ul style="margin:10px 0 0 18px;">
                <?php foreach ($validationResult['warnings'] as $ve): ?>
                    <li style="margin-bottom:6px;"><?= htmlspecialchars($ve['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <div class="report-shell">
        <?php if (($fs['entity_category'] ?? '') === 'corporate'): ?>
            <form method="post" class="manual-note-form">
                <?= csrfInput() ?>
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
