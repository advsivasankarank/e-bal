<?php
/**
 * Spreadsheet View: Handsontable wrapper
 * Converts $fs data to grid format
 * Expects: $fs, $data, $notes
 */
if (!isset($fs)) return;
$data = $fs['data'] ?? [];
$notes = $fs['notes'] ?? [];
$summary = $fs['summary'] ?? [];
$prevSummary = $fs['previous_summary'] ?? [];
$entityCategory = $fs['entity_category'] ?? 'corporate';
$isFirstYear = $fs['is_first_year'] ?? true;

$rows = [];
$addRow = function ($label, $noteRef, $current, $previous) use (&$rows) {
    $rows[] = [$label, $noteRef, (float)$current, $isFirstYear ? null : (float)$previous];
};

if ($entityCategory === 'corporate') {
    $addRow('EQUITY & LIABILITIES', '', '', '');
    $addRow('  Shareholders\' Funds', '', '', '');
    $addRow('    Share Capital', '1', $summary['share_capital'] ?? 0, $prevSummary['share_capital'] ?? '');
    $addRow('    Reserves & Surplus', '2', $summary['reserves'] ?? 0, $prevSummary['reserves'] ?? '');
    $addRow('  Non-Current Liabilities', '', '', '');
    $addRow('    Long-term Borrowings', '3', $summary['lt_borrowings'] ?? 0, $prevSummary['lt_borrowings'] ?? '');
    $addRow('    Deferred Tax', '4', $summary['deferred_tax'] ?? 0, $prevSummary['deferred_tax'] ?? '');
    $addRow('  Current Liabilities', '', '', '');
    $addRow('    Short-term Borrowings', '5', $summary['st_borrowings'] ?? 0, $prevSummary['st_borrowings'] ?? '');
    $addRow('    Trade Payables', '6', $summary['trade_payables'] ?? 0, $prevSummary['trade_payables'] ?? '');
    $addRow('ASSETS', '', '', '');
    $addRow('  Non-Current Assets', '', '', '');
    $addRow('    PPE', '7', $summary['ppe'] ?? 0, $prevSummary['ppe'] ?? '');
    $addRow('    Intangibles', '8', $summary['intangibles'] ?? 0, $prevSummary['intangibles'] ?? '');
    $addRow('  Current Assets', '', '', '');
    $addRow('    Inventories', '9', $summary['inventories'] ?? 0, $prevSummary['inventories'] ?? '');
    $addRow('    Trade Receivables', '10', $summary['trade_receivables'] ?? 0, $prevSummary['trade_receivables'] ?? '');
    $addRow('    Cash & Bank', '11', $summary['cash'] ?? 0, $prevSummary['cash'] ?? '');
    $addRow('TOTAL', '', $summary['total_assets'] ?? 0, $prevSummary['total_assets'] ?? '');
} else {
    $sections = $notes['sections'] ?? [];
    foreach ($sections as $section) {
        $lines = $section['lines'] ?? [];
        $addRow($section['title'] ?? '', '', '', '');
        foreach ($lines as $line) {
            $addRow('  ' . ($line['label'] ?? ''), '', $line['current'] ?? 0, $line['previous'] ?? '');
        }
        $addRow('  Total', '', $section['current_total'] ?? 0, $section['previous_total'] ?? '');
    }
}
?>
<script>window.__fsSpreadsheetData = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<div id="fsHandsontableContainer"></div>
