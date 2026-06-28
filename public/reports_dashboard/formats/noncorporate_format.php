<?php
$isTrading = in_array($data['entity_subcategory'] ?? '', ['proprietorship', 'partnership', 'llp'], true);
$isRnp = in_array($data['entity_subcategory'] ?? '', ['trust', 'society'], true);

function ncPlTitle(string $sub): string {
    if (in_array($sub, ['trust', 'society'], true)) return 'Income &amp; Expenditure Account';
    return 'Profit &amp; Loss Account';
}
function ncPlFullTitle(string $sub): string {
    if (in_array($sub, ['trust', 'society'], true)) return 'Income &amp; Expenditure Account';
    return 'Trading &amp; Profit &amp; Loss Account';
}
$plTitle = ncPlTitle($data['entity_subcategory'] ?? '');
$plFullTitle = ncPlFullTitle($data['entity_subcategory'] ?? '');

$isFirstYear = (bool) ($isFirstYear ?? false);
$prevH = $isFirstYear ? '' : '<th class="figure" data-prev>Previous</th>';
$prevHNote = $isFirstYear ? '' : '<th class="figure" data-prev>Previous</th>';
$prevColspan = $isFirstYear ? 2 : 3;
$prevColspan4 = $isFirstYear ? 3 : 4;
$reportSubtitle = $isFirstYear ? ' (First Year Financial Statements)' : '';
if (!function_exists('pv')) { function pv($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . \format_inr($val) . '</td>'; } }
if (!function_exists('pvRaw')) { function pvRaw($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . $val . '</td>'; } }
function prevCell($html) { global $isFirstYear; return $isFirstYear ? '' : '<td class="figure" data-prev>' . $html . '</td>'; }
?>

<?php if ($isTrading): ?>
<section class="report-page" id="trading-account">
<h2 class="report-page-title">Trading Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th class="figure">Current</th><?= $prevH ?></tr>

<tr><td colspan="<?= $prevColspan ?>"><b>Dr. Side</b></td></tr>
<tr><td>Opening Stock</td><td class="figure"><?= format_inr($data['opening_stock_val']) ?></td><?= pv($data['prev_opening_stock_val']) ?></tr>
<tr><td>Purchases</td><td class="figure"><?= format_inr($data['purchases']) ?></td><?= pv($data['prev_purchases']) ?></tr>
<tr><td>Direct Expenses</td><td class="figure"><?= format_inr($data['direct_expenses']) ?></td><?= pv($data['prev_direct_expenses']) ?></tr>
<?php
$grossProfit = $data['sales'] + $data['closing_stock_val'] - $data['opening_stock_val'] - $data['purchases'] - $data['direct_expenses'];
$prevGrossProfit = $data['prev_sales'] + $data['prev_closing_stock_val'] - $data['prev_opening_stock_val'] - $data['prev_purchases'] - $data['prev_direct_expenses'];
$isGrossProfit = $grossProfit >= 0;
$isPrevGrossProfit = $prevGrossProfit >= 0;
?>
<?php if (!$isGrossProfit): ?>
<tr><td><b>Gross Loss c/d</b></td><td class="figure"><b><?= format_inr(abs($grossProfit)) ?></b></td><?= pvRaw('<b>' . format_inr(abs($prevGrossProfit)) . '</b>') ?></tr>
<?php endif; ?>

<tr><td colspan="<?= $prevColspan ?>"><b>Cr. Side</b></td></tr>
<tr><td>Sales</td><td class="figure"><?= format_inr($data['sales']) ?></td><?= pv($data['prev_sales']) ?></tr>
<tr><td>Closing Stock</td><td class="figure"><?= format_inr($data['closing_stock_val']) ?></td><?= pv($data['prev_closing_stock_val']) ?></tr>
<?php if ($isGrossProfit): ?>
<tr><td><b>Gross Profit c/d</b></td><td class="figure"><b><?= format_inr($grossProfit) ?></b></td><?= pvRaw('<b>' . format_inr($prevGrossProfit) . '</b>') ?></tr>
<?php endif; ?>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr><td style="border:0; text-align:right;">
    <strong>For <?= htmlspecialchars($company_meta['entity_type'] ?? 'Entity') ?></strong><br><br><br><br>
    <?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory') ?><br>
    <div class="signature-caption">Signature</div>
</td></tr>
</table>
</section>
<?php endif; ?>

<section class="report-page" id="profit-loss">
<h2 class="report-page-title"><?= $isTrading ? 'Profit &amp; Loss Account' : $plTitle ?></h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><?= $prevHNote ?></tr>

<?php if ($isTrading): ?>
<?php
$otherIncome = (float) ($data['other_income'] ?? 0);
$prevOtherIncome = (float) ($data['prev_other_income'] ?? 0);
$empCost = (float) ($data['employee_cost'] ?? 0);
$prevEmpCost = (float) ($data['prev_employee_cost'] ?? 0);
$finCost = (float) ($data['finance_cost'] ?? 0);
$prevFinCost = (float) ($data['prev_finance_cost'] ?? 0);
$depr = (float) ($data['depreciation'] ?? 0);
$prevDepr = (float) ($data['prev_depreciation'] ?? 0);
$othExp = (float) ($data['other_expenses'] ?? 0);
$prevOthExp = (float) ($data['prev_other_expenses'] ?? 0);
$totalIncome = max($grossProfit, 0) + $otherIncome;
$prevTotalIncome = max($prevGrossProfit, 0) + $prevOtherIncome;
$totalOpExp = $empCost + $finCost + $depr + $othExp;
$prevTotalOpExp = $prevEmpCost + $prevFinCost + $prevDepr + $prevOthExp;
$netProfit = $totalIncome - $totalOpExp;
$prevNetProfit = $prevTotalIncome - $prevTotalOpExp;
if ($grossProfit < 0): $netProfit = $otherIncome - $totalOpExp - abs($grossProfit); endif;
if ($prevGrossProfit < 0): $prevNetProfit = $prevOtherIncome - $prevTotalOpExp - abs($prevGrossProfit); endif;
?>
<tr><td><b>Gross Profit b/d</b></td><td></td><td class="figure"><?= format_inr(max($grossProfit, 0)) ?></td><?= pv(max($prevGrossProfit, 0)) ?></tr>
<?php if ($grossProfit < 0): ?>
<tr><td>Gross Loss b/d</td><td></td><td class="figure"><?= format_inr(abs($grossProfit)) ?></td><?= pv(abs($prevGrossProfit)) ?></tr>
<?php endif; ?>
<tr><td>Other Income</td><td><a href="#note-<?= $data['note_refs']['Revenue'] ?? 12 ?>"><?= $data['note_refs']['Revenue'] ?? 12 ?></a></td><td class="figure"><?= format_inr($otherIncome) ?></td><?= pv($prevOtherIncome) ?></tr>
<tr><td colspan="<?= $prevColspan4 ?>"><b>Expenses</b></td></tr>
<tr><td>&emsp;Employee Cost</td><td><a href="#note-<?= $data['note_refs']['Expenses'] ?? 13 ?>"><?= $data['note_refs']['Expenses'] ?? 13 ?></a></td><td class="figure"><?= format_inr($empCost) ?></td><?= pv($prevEmpCost) ?></tr>
<tr><td>&emsp;Finance Cost</td><td></td><td class="figure"><?= format_inr($finCost) ?></td><?= pv($prevFinCost) ?></tr>
<tr><td>&emsp;Depreciation</td><td></td><td class="figure"><?= format_inr($depr) ?></td><?= pv($prevDepr) ?></tr>
<tr><td>&emsp;Other Expenses</td><td></td><td class="figure"><?= format_inr($othExp) ?></td><?= pv($prevOthExp) ?></tr>
<tr><td><b>Net Profit / (Loss)</b></td><td></td><td class="figure"><b><?= format_inr($netProfit) ?></b></td><?= pvRaw('<b>' . format_inr($prevNetProfit) . '</b>') ?></tr>

<?php else: ?>

<tr><td>Revenue</td><td><a href="#note-<?= $data['note_refs']['Revenue'] ?? 12 ?>"><?= $data['note_refs']['Revenue'] ?? 12 ?></a></td><td class="figure"><?= format_inr($data['revenue']) ?></td><?= pv($data['prev_revenue']) ?></tr>
<tr><td>Expenses</td><td><a href="#note-<?= $data['note_refs']['Expenses'] ?? 13 ?>"><?= $data['note_refs']['Expenses'] ?? 13 ?></a></td><td class="figure"><?= format_inr($data['expenses']) ?></td><?= pv($data['prev_expenses']) ?></tr>

<?php $netProfit = $data['revenue'] - $data['expenses']; ?>
<?php $prevNetProfit = $data['prev_revenue'] - $data['prev_expenses']; ?>
<tr><td><b>Net Surplus / (Deficit)</b></td><td></td><td class="figure"><b><?= format_inr($netProfit) ?></b></td><?= pvRaw('<b>' . format_inr($prevNetProfit) . '</b>') ?></tr>

<?php endif; ?>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr>
    <td style="width:50%; vertical-align:top; border:0; padding:0 20px 0 0;">
        <strong>For Statutory Auditors</strong><br><br><br><br>
        <?= htmlspecialchars($company_meta['auditor_firm'] ?? '') ?><br>
        <?= htmlspecialchars($company_meta['auditor_name'] ?? 'Authorised Signatory') ?>
    </td>
    <td style="width:50%; vertical-align:top; border:0; padding:0; text-align:right;">
        <table class="signature-table" width="100%" style="border:0; border-collapse:collapse;">
            <tr>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Authorised Signatory') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Authorised Signatory 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Authorised Signatory') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>

<?php if ($isRnp): ?>
<?php
$prevCol6 = $isFirstYear ? 4 : 6; // 6-col R&P table
$prevCol6a = $isFirstYear ? 2 : 3;
?>
<section class="report-page" id="receipts-payments">
<h2 class="report-page-title">Receipts &amp; Payments Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Receipts</th><th class="figure">Current</th><?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous</th><?php endif; ?><th>Payments</th><th class="figure">Current</th><?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous</th><?php endif; ?></tr>

<tr>
    <td>Opening Cash / Bank</td>
    <td class="figure"><?= format_inr($data['prev_cash']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr(0) ?></td><?php endif; ?>
    <td>Administrative Expenses</td>
    <td class="figure"><?= format_inr($data['administrative_expenses']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_administrative_expenses']) ?></td><?php endif; ?>
</tr>
<tr>
    <td>Donations</td>
    <td class="figure"><?= format_inr($data['donations']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_donations']) ?></td><?php endif; ?>
    <td>Programme Expenses</td>
    <td class="figure"><?= format_inr($data['programme_expenses']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_programme_expenses']) ?></td><?php endif; ?>
</tr>
<tr>
    <td>Grants</td>
    <td class="figure"><?= format_inr($data['grants']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_grants']) ?></td><?php endif; ?>
    <td>Other Expenses</td>
    <td class="figure"><?= format_inr($data['expenses']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_expenses']) ?></td><?php endif; ?>
</tr>
<tr>
    <td>Membership Fees</td>
    <td class="figure"><?= format_inr($data['membership_fees']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_membership_fees']) ?></td><?php endif; ?>
    <td></td>
    <td class="figure"></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev></td><?php endif; ?>
</tr>
<tr>
    <td>Other Receipts</td>
    <td class="figure"><?= format_inr($data['revenue']) ?></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_revenue']) ?></td><?php endif; ?>
    <td></td>
    <td class="figure"></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev></td><?php endif; ?>
</tr>
<?php
$totalReceipts = $data['prev_cash'] + $data['donations'] + $data['grants'] + $data['membership_fees'] + $data['revenue'];
$prevTotalReceipts = $data['prev_cash'] + $data['prev_donations'] + $data['prev_grants'] + $data['prev_membership_fees'] + $data['prev_revenue'];
$totalPayments = $data['administrative_expenses'] + $data['programme_expenses'] + $data['expenses'];
$prevTotalPayments = $data['prev_administrative_expenses'] + $data['prev_programme_expenses'] + $data['prev_expenses'];
$closingCash = $data['cash'];
?>
<tr>
    <td><b>Total Receipts</b></td>
    <td class="figure"><b><?= format_inr($totalReceipts) ?></b></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><b><?= format_inr($prevTotalReceipts) ?></b></td><?php endif; ?>
    <td><b>Total Payments</b></td>
    <td class="figure"><b><?= format_inr($totalPayments) ?></b></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><b><?= format_inr($prevTotalPayments) ?></b></td><?php endif; ?>
</tr>
<tr>
    <td><b>Closing Cash / Bank</b></td>
    <td class="figure"><b><?= format_inr($closingCash) ?></b></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev><b><?= format_inr($data['prev_cash']) ?></b></td><?php endif; ?>
    <td></td>
    <td class="figure"></td>
    <?php if (!$isFirstYear): ?><td class="figure" data-prev></td><?php endif; ?>
</tr>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr><td style="border:0; text-align:right;">
    <strong>For <?= htmlspecialchars($company_meta['entity_type'] ?? 'Entity') ?></strong><br><br><br><br>
    <?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory') ?><br>
    <div class="signature-caption">Signature</div>
</td></tr>
</table>
</section>
<?php endif; ?>

<?php if ($isTrading): ?>
<section class="report-page" id="capital-account">
<h2 class="report-page-title">Capital Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th class="figure">Current</th><?= $prevH ?></tr>

<tr><td>Opening Capital</td><td class="figure"><?= format_inr($data['prev_capital']) ?></td><?= pv(0) ?></tr>
<tr><td>Add: Capital Introduced</td><td class="figure"><?= format_inr($data['capital_introduced']) ?></td><?= pv($data['prev_capital_introduced']) ?></tr>
<tr><td>Add: Net Profit</td><td class="figure"><?= format_inr(max($netProfit, 0)) ?></td><?= pv(max($prevNetProfit, 0)) ?></tr>
<tr><td>Less: Drawings</td><td class="figure"><?= format_inr($data['drawings']) ?></td><?= pv($data['prev_drawings']) ?></tr>
<?php if ($netProfit < 0): ?>
<tr><td>Less: Net Loss</td><td class="figure"><?= format_inr(abs($netProfit)) ?></td><?= pv(abs($prevNetProfit)) ?></tr>
<?php endif; ?>
<tr><td><b>Closing Capital</b></td><td class="figure"><b><?= format_inr($data['capital']) ?></b></td><?= pvRaw('<b>' . format_inr($data['prev_capital']) . '</b>') ?></tr>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr><td style="border:0; text-align:right;">
    <strong>For <?= htmlspecialchars($company_meta['entity_type'] ?? 'Entity') ?></strong><br><br><br><br>
    <?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory') ?><br>
    <div class="signature-caption">Signature</div>
</td></tr>
</table>
</section>
<?php endif; ?>

<section class="report-page" id="balance-sheet">
<h2 class="report-page-title">Balance Sheet as at <?= htmlspecialchars($data['date']) ?></h2>
<p class="report-page-subtitle">All amounts are presented in Indian Rupees.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><?= $prevHNote ?></tr>

<tr><td colspan="<?= $prevColspan4 ?>"><b>EQUITY AND LIABILITIES</b></td></tr>

<tr><td><b>Capital / Corpus Fund</b></td><td></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
<?php if ($isRnp && $data['corpus_fund'] > 0): ?>
<tr><td>Corpus Fund</td><td><a href="#note-<?= $data['note_refs']['Capital'] ?? 1 ?>"><?= $data['note_refs']['Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['corpus_fund']) ?></td><?= pv($data['prev_corpus_fund']) ?></tr>
<?php endif; ?>
<tr><td>Capital Account</td><td><a href="#note-<?= $data['note_refs']['Capital'] ?? 1 ?>"><?= $data['note_refs']['Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['capital']) ?></td><?= pv($data['prev_capital']) ?></tr>

<tr><td><b>Liabilities</b></td><td></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
<tr><td>Borrowings</td><td><a href="#note-<?= $data['note_refs']['Borrowings'] ?? 2 ?>"><?= $data['note_refs']['Borrowings'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['borrowings']) ?></td><?= pv($data['prev_borrowings']) ?></tr>
<tr><td>Payables</td><td><a href="#note-<?= $data['note_refs']['Payables'] ?? 3 ?>"><?= $data['note_refs']['Payables'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['payables']) ?></td><?= pv($data['prev_payables']) ?></tr>
<tr><td>Other Current Liabilities</td><td><a href="#note-<?= $data['note_refs']['Current Liabilities'] ?? 4 ?>"><?= $data['note_refs']['Current Liabilities'] ?? 4 ?></a></td><td class="figure"><?= format_inr($data['current_liabilities']) ?></td><?= pv($data['prev_current_liabilities']) ?></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_liabilities']) ?></td><?= pv($data['prev_total_liabilities']) ?></tr>

<tr><td colspan="<?= $prevColspan4 ?>"><b>ASSETS</b></td></tr>

<tr><td>Fixed Assets</td><td><a href="#note-<?= $data['note_refs']['Fixed Assets'] ?? 5 ?>"><?= $data['note_refs']['Fixed Assets'] ?? 5 ?></a></td><td class="figure"><?= format_inr($data['fixed_assets']) ?></td><?= pv($data['prev_fixed_assets']) ?></tr>
<tr><td>Loans</td><td><a href="#note-<?= $data['note_refs']['Loans'] ?? 6 ?>"><?= $data['note_refs']['Loans'] ?? 6 ?></a></td><td class="figure"><?= format_inr($data['loans']) ?></td><?= pv($data['prev_loans']) ?></tr>
<tr><td>Investments</td><td><a href="#note-<?= $data['note_refs']['Investments'] ?? 7 ?>"><?= $data['note_refs']['Investments'] ?? 7 ?></a></td><td class="figure"><?= format_inr($data['investments']) ?></td><?= pv($data['prev_investments']) ?></tr>
<tr><td>Inventory</td><td><a href="#note-<?= $data['note_refs']['Inventory'] ?? 8 ?>"><?= $data['note_refs']['Inventory'] ?? 8 ?></a></td><td class="figure"><?= format_inr($data['inventory']) ?></td><?= pv($data['prev_inventory']) ?></tr>
<tr><td>Receivables</td><td><a href="#note-<?= $data['note_refs']['Receivables'] ?? 9 ?>"><?= $data['note_refs']['Receivables'] ?? 9 ?></a></td><td class="figure"><?= format_inr($data['receivables']) ?></td><?= pv($data['prev_receivables']) ?></tr>
<tr><td>Cash &amp; Bank</td><td><a href="#note-<?= $data['note_refs']['Cash and Bank'] ?? 10 ?>"><?= $data['note_refs']['Cash and Bank'] ?? 10 ?></a></td><td class="figure"><?= format_inr($data['cash']) ?></td><?= pv($data['prev_cash']) ?></tr>
<tr><td>Other Current Assets</td><td><a href="#note-<?= $data['note_refs']['Other Current Assets'] ?? 11 ?>"><?= $data['note_refs']['Other Current Assets'] ?? 11 ?></a></td><td class="figure"><?= format_inr($data['other_current_assets']) ?></td><?= pv($data['prev_other_current_assets']) ?></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_assets']) ?></td><?= pv($data['prev_total_assets']) ?></tr>
</table>

<table class="signature-table signature-block" width="100%" style="border:0; border-collapse:collapse;">
<tr>
    <td style="width:50%; vertical-align:top; border:0; padding:0 20px 0 0;">
        <strong>For Statutory Auditors</strong><br><br><br><br>
        <?= htmlspecialchars($company_meta['auditor_firm'] ?? '') ?><br>
        <?= htmlspecialchars($company_meta['auditor_name'] ?? 'Authorised Signatory') ?>
        <div class="signature-caption">Authorised Signatory</div>
    </td>
    <td style="width:50%; vertical-align:top; border:0; padding:0; text-align:right;">
        <table class="signature-table" width="100%" style="border:0; border-collapse:collapse;">
            <tr>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Authorised Signatory') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Authorised Signatory 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Authorised Signatory') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>
