<?php
/**
 * Spreadsheet View: Grid / Table format
 * Renders Balance Sheet, P&L or Notes data in spreadsheet-style table.
 * If Handsontable is available it will be enhanced by JS; otherwise the
 * static HTML table is the primary display.
 * Expects: $fs, $data, $notes
 */
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
if (!isset($fs)) return;

$data = $fs['data'] ?? [];
$notes = $fs['notes'] ?? [];
$summary = $fs['data'] ?? [];
$prevSummary = $fs['previous_summary'] ?? [];
$entityCategory = $fs['entity_category'] ?? 'corporate';
$isFirstYear = $fs['is_first_year'] ?? true;

$rows = [];
$addRow = function (string $label, string $noteRef, $current, $previous) use (&$rows, $isFirstYear) {
    $rows[] = [
        'label' => $label,
        'note'  => $noteRef,
        'current' => (float) $current,
        'previous' => $isFirstYear ? null : (float) $previous,
    ];
};

/* ---- Build spreadsheet rows from FS data ---- */
if ($entityCategory === 'corporate') {
    $addRow('EQUITY & LIABILITIES', '', '', '');
    $addRow("  Shareholders' Funds", '', '', '');
    $addRow('    Share Capital', '1', $summary['share_capital'] ?? 0, $prevSummary['share_capital'] ?? 0);
    $addRow('    Reserves & Surplus', '2', $summary['reserves'] ?? 0, $prevSummary['reserves'] ?? 0);
    $addRow('  Non-Current Liabilities', '', '', '');
    $addRow('    Long-term Borrowings', '3', $summary['lt_borrowings'] ?? 0, $prevSummary['lt_borrowings'] ?? 0);
    $addRow('    Deferred Tax (Net)', '4', $summary['deferred_tax'] ?? 0, $prevSummary['deferred_tax'] ?? 0);
    $addRow('    Other Non-Current Liabilities', '5', $summary['other_non_current_liabilities'] ?? 0, $prevSummary['other_non_current_liabilities'] ?? 0);
    $addRow('    Long-term Provisions', '6', $summary['long_term_provisions'] ?? 0, $prevSummary['long_term_provisions'] ?? 0);
    $addRow('  Current Liabilities', '', '', '');
    $addRow('    Short-term Borrowings', '7', $summary['st_borrowings'] ?? 0, $prevSummary['st_borrowings'] ?? 0);
    $addRow('    Trade Payables', '8', $summary['trade_payables'] ?? 0, $prevSummary['trade_payables'] ?? 0);
    $addRow('    Other Current Liabilities', '9', $summary['other_current_liabilities'] ?? 0, $prevSummary['other_current_liabilities'] ?? 0);
    $addRow('    Short-term Provisions', '10', $summary['short_term_provisions'] ?? 0, $prevSummary['short_term_provisions'] ?? 0);
    $addRow('TOTAL EQUITY & LIABILITIES', '', $summary['total_liabilities'] ?? ($summary['total_assets'] ?? 0), $prevSummary['total_liabilities'] ?? ($prevSummary['total_assets'] ?? 0));
    $addRow('', '', '', '');
    $addRow('ASSETS', '', '', '');
    $addRow('  Non-Current Assets', '', '', '');
    $addRow('    Property, Plant & Equipment', '11', $summary['fixed_assets'] ?? 0, $prevSummary['fixed_assets'] ?? 0);
    $addRow('    Intangible Assets', '12', $summary['intangible_assets'] ?? 0, $prevSummary['intangible_assets'] ?? 0);
    $addRow('    Non-Current Investments', '13', $summary['investments'] ?? 0, $prevSummary['investments'] ?? 0);
    $addRow('    Long-term Loans & Advances', '14', $summary['loans'] ?? 0, $prevSummary['loans'] ?? 0);
    $addRow('    Other Non-Current Assets', '15', $summary['other_non_current_assets'] ?? 0, $prevSummary['other_non_current_assets'] ?? 0);
    $addRow('  Current Assets', '', '', '');
    $addRow('    Inventories', '16', $summary['inventory'] ?? 0, $prevSummary['inventory'] ?? 0);
    $addRow('    Trade Receivables', '17', $summary['receivables'] ?? 0, $prevSummary['receivables'] ?? 0);
    $addRow('    Cash & Cash Equivalents', '18', $summary['cash'] ?? 0, $prevSummary['cash'] ?? 0);
    $addRow('    Other Current Assets', '19', $summary['other_current_assets'] ?? 0, $prevSummary['other_current_assets'] ?? 0);
    $addRow('TOTAL ASSETS', '', $summary['total_assets'] ?? 0, $prevSummary['total_assets'] ?? 0);
} else {
    /* Non-corporate / LLP: render from notes sections */
    $sections = $notes['sections'] ?? [];
    foreach ($sections as $section) {
        $lines = $section['lines'] ?? [];
        $addRow(mb_strtoupper($section['title'] ?? ''), '', '', '');
        foreach ($lines as $line) {
            $addRow('  ' . ($line['label'] ?? ''), '', $line['current'] ?? 0, $line['previous'] ?? 0);
        }
        $addRow('  Total', '', $section['current_total'] ?? 0, $section['previous_total'] ?? 0);
        $addRow('', '', '', '');
    }
}
?>

<!-- Handsontable data (for optional JS enhancement) -->
<script>window.__fsSpreadsheetData = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>

<!-- Primary: static HTML table (always visible) -->
<div class="fs-spreadsheet-table" id="fsHandsontableContainer">
<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
<thead>
<tr style="border-bottom:2px solid #dbe3ef;">
    <th style="text-align:left;padding:8px 10px;background:#f3f6fb;width:50%;">Particulars</th>
    <th style="text-align:center;padding:8px 10px;background:#f3f6fb;width:8%;">Note</th>
    <th style="text-align:right;padding:8px 10px;background:#f3f6fb;white-space:nowrap;min-width:130px;">Current Year (&#8377;)</th>
    <?php if (!$isFirstYear): ?>
    <th style="text-align:right;padding:8px 10px;background:#f3f6fb;white-space:nowrap;min-width:130px;" data-prev>Previous Year (&#8377;)</th>
    <?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $row):
    $label = $row['label'];
    $isSection = ($label !== '' && $label === rtrim($label) && !str_starts_with($label, ' ') && $row['note'] === '' && $row['current'] == 0 && $row['previous'] == 0);
    $isSubSection = ($label !== '' && str_starts_with($label, '  ') && !str_starts_with($label, '    ') && $row['note'] === '' && $row['current'] == 0);
    $isTotal = str_starts_with(strtoupper($label), 'TOTAL');
    $isBlank = ($label === '');
?>
<?php if ($isBlank): ?>
<tr style="height:6px;"><td colspan="<?= !$isFirstYear ? 4 : 3 ?>" style="border:0;padding:0;"></td></tr>
<?php else: ?>
<tr style="<?= $isSection || $isTotal ? 'font-weight:700;background:#f5f8fc;' : ($isSubSection ? 'font-weight:600;' : '') ?>">
    <td style="padding:6px 10px;<?= $isSection ? 'font-size:0.85rem;' : '' ?>"><?= htmlspecialchars(rtrim($label)) ?></td>
    <td style="text-align:center;padding:6px 10px;"><?= $row['note'] !== '' ? htmlspecialchars($row['note']) : '' ?></td>
    <td style="text-align:right;padding:6px 10px;white-space:nowrap;"><?= $row['current'] != 0 ? format_inr_number($row['current']) : '' ?></td>
    <?php if (!$isFirstYear): ?>
    <td style="text-align:right;padding:6px 10px;white-space:nowrap;" data-prev><?= $row['previous'] !== null && $row['previous'] != 0 ? format_inr_number($row['previous']) : '' ?></td>
    <?php endif; ?>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
