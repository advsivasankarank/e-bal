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
?>

<?php if ($isTrading): ?>
<section class="report-page" id="trading-account">
<h2 class="report-page-title">Trading Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td colspan="3"><b>Dr. Side</b></td></tr>
<tr><td>Opening Stock</td><td class="figure"><?= format_inr($data['opening_stock_val']) ?></td><td class="figure"><?= format_inr($data['prev_opening_stock_val']) ?></td></tr>
<tr><td>Purchases</td><td class="figure"><?= format_inr($data['purchases']) ?></td><td class="figure"><?= format_inr($data['prev_purchases']) ?></td></tr>
<tr><td>Direct Expenses</td><td class="figure"><?= format_inr($data['direct_expenses']) ?></td><td class="figure"><?= format_inr($data['prev_direct_expenses']) ?></td></tr>
<?php
$grossProfit = $data['sales'] + $data['closing_stock_val'] - $data['opening_stock_val'] - $data['purchases'] - $data['direct_expenses'];
$prevGrossProfit = $data['prev_sales'] + $data['prev_closing_stock_val'] - $data['prev_opening_stock_val'] - $data['prev_purchases'] - $data['prev_direct_expenses'];
$isGrossProfit = $grossProfit >= 0;
$isPrevGrossProfit = $prevGrossProfit >= 0;
?>
<?php if (!$isGrossProfit): ?>
<tr><td><b>Gross Loss c/d</b></td><td class="figure"><b><?= format_inr(abs($grossProfit)) ?></b></td><td class="figure"><b><?= format_inr(abs($prevGrossProfit)) ?></b></td></tr>
<?php endif; ?>

<tr><td colspan="3"><b>Cr. Side</b></td></tr>
<tr><td>Sales</td><td class="figure"><?= format_inr($data['sales']) ?></td><td class="figure"><?= format_inr($data['prev_sales']) ?></td></tr>
<tr><td>Closing Stock</td><td class="figure"><?= format_inr($data['closing_stock_val']) ?></td><td class="figure"><?= format_inr($data['prev_closing_stock_val']) ?></td></tr>
<?php if ($isGrossProfit): ?>
<tr><td><b>Gross Profit c/d</b></td><td class="figure"><b><?= format_inr($grossProfit) ?></b></td><td class="figure"><b><?= format_inr($prevGrossProfit) ?></b></td></tr>
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
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

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
<tr><td><b>Gross Profit b/d</b></td><td></td><td class="figure"><?= format_inr(max($grossProfit, 0)) ?></td><td class="figure"><?= format_inr(max($prevGrossProfit, 0)) ?></td></tr>
<?php if ($grossProfit < 0): ?>
<tr><td>Gross Loss b/d</td><td></td><td class="figure"><?= format_inr(abs($grossProfit)) ?></td><td class="figure"><?= format_inr(abs($prevGrossProfit)) ?></td></tr>
<?php endif; ?>
<tr><td>Other Income</td><td><a href="#note-<?= $data['note_refs']['Revenue'] ?? 12 ?>"><?= $data['note_refs']['Revenue'] ?? 12 ?></a></td><td class="figure"><?= format_inr($otherIncome) ?></td><td class="figure"><?= format_inr($prevOtherIncome) ?></td></tr>
<tr><td colspan="4"><b>Expenses</b></td></tr>
<tr><td>&emsp;Employee Cost</td><td><a href="#note-<?= $data['note_refs']['Expenses'] ?? 13 ?>"><?= $data['note_refs']['Expenses'] ?? 13 ?></a></td><td class="figure"><?= format_inr($empCost) ?></td><td class="figure"><?= format_inr($prevEmpCost) ?></td></tr>
<tr><td>&emsp;Finance Cost</td><td></td><td class="figure"><?= format_inr($finCost) ?></td><td class="figure"><?= format_inr($prevFinCost) ?></td></tr>
<tr><td>&emsp;Depreciation</td><td></td><td class="figure"><?= format_inr($depr) ?></td><td class="figure"><?= format_inr($prevDepr) ?></td></tr>
<tr><td>&emsp;Other Expenses</td><td></td><td class="figure"><?= format_inr($othExp) ?></td><td class="figure"><?= format_inr($prevOthExp) ?></td></tr>
<tr><td><b>Net Profit / (Loss)</b></td><td></td><td class="figure"><b><?= format_inr($netProfit) ?></b></td><td class="figure"><b><?= format_inr($prevNetProfit) ?></b></td></tr>

<?php else: ?>

<tr><td>Revenue</td><td><a href="#note-<?= $data['note_refs']['Revenue'] ?? 12 ?>"><?= $data['note_refs']['Revenue'] ?? 12 ?></a></td><td class="figure"><?= format_inr($data['revenue']) ?></td><td class="figure"><?= format_inr($data['prev_revenue']) ?></td></tr>

<tr><td>Expenses</td><td><a href="#note-<?= $data['note_refs']['Expenses'] ?? 13 ?>"><?= $data['note_refs']['Expenses'] ?? 13 ?></a></td><td class="figure"><?= format_inr($data['expenses']) ?></td><td class="figure"><?= format_inr($data['prev_expenses']) ?></td></tr>

<?php $netProfit = $data['revenue'] - $data['expenses']; ?>
<?php $prevNetProfit = $data['prev_revenue'] - $data['prev_expenses']; ?>
<tr><td><b>Net Surplus / (Deficit)</b></td><td></td><td class="figure"><b><?= format_inr($netProfit) ?></b></td><td class="figure"><b><?= format_inr($prevNetProfit) ?></b></td></tr>

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
<section class="report-page" id="receipts-payments">
<h2 class="report-page-title">Receipts &amp; Payments Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Receipts</th><th class="figure">Current</th><th class="figure">Previous</th><th>Payments</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr>
    <td>Opening Cash / Bank</td>
    <td class="figure"><?= format_inr($data['prev_cash']) ?></td>
    <td class="figure"><?= format_inr(0) ?></td>
    <td>Administrative Expenses</td>
    <td class="figure"><?= format_inr($data['administrative_expenses']) ?></td>
    <td class="figure"><?= format_inr($data['prev_administrative_expenses']) ?></td>
</tr>
<tr>
    <td>Donations</td>
    <td class="figure"><?= format_inr($data['donations']) ?></td>
    <td class="figure"><?= format_inr($data['prev_donations']) ?></td>
    <td>Programme Expenses</td>
    <td class="figure"><?= format_inr($data['programme_expenses']) ?></td>
    <td class="figure"><?= format_inr($data['prev_programme_expenses']) ?></td>
</tr>
<tr>
    <td>Grants</td>
    <td class="figure"><?= format_inr($data['grants']) ?></td>
    <td class="figure"><?= format_inr($data['prev_grants']) ?></td>
    <td>Other Expenses</td>
    <td class="figure"><?= format_inr($data['expenses']) ?></td>
    <td class="figure"><?= format_inr($data['prev_expenses']) ?></td>
</tr>
<tr>
    <td>Membership Fees</td>
    <td class="figure"><?= format_inr($data['membership_fees']) ?></td>
    <td class="figure"><?= format_inr($data['prev_membership_fees']) ?></td>
    <td></td>
    <td class="figure"></td>
    <td class="figure"></td>
</tr>
<tr>
    <td>Other Receipts</td>
    <td class="figure"><?= format_inr($data['revenue']) ?></td>
    <td class="figure"><?= format_inr($data['prev_revenue']) ?></td>
    <td></td>
    <td class="figure"></td>
    <td class="figure"></td>
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
    <td class="figure"><b><?= format_inr($prevTotalReceipts) ?></b></td>
    <td><b>Total Payments</b></td>
    <td class="figure"><b><?= format_inr($totalPayments) ?></b></td>
    <td class="figure"><b><?= format_inr($prevTotalPayments) ?></b></td>
</tr>
<tr>
    <td><b>Closing Cash / Bank</b></td>
    <td class="figure"><b><?= format_inr($closingCash) ?></b></td>
    <td class="figure"><b><?= format_inr($data['prev_cash']) ?></b></td>
    <td></td>
    <td class="figure"></td>
    <td class="figure"></td>
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
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td>Opening Capital</td><td class="figure"><?= format_inr($data['prev_capital']) ?></td><td class="figure"><?= format_inr(0) ?></td></tr>
<tr><td>Add: Capital Introduced</td><td class="figure"><?= format_inr($data['capital_introduced']) ?></td><td class="figure"><?= format_inr($data['prev_capital_introduced']) ?></td></tr>
<tr><td>Add: Net Profit</td><td class="figure"><?= format_inr(max($netProfit, 0)) ?></td><td class="figure"><?= format_inr(max($prevNetProfit, 0)) ?></td></tr>
<tr><td>Less: Drawings</td><td class="figure"><?= format_inr($data['drawings']) ?></td><td class="figure"><?= format_inr($data['prev_drawings']) ?></td></tr>
<?php if ($netProfit < 0): ?>
<tr><td>Less: Net Loss</td><td class="figure"><?= format_inr(abs($netProfit)) ?></td><td class="figure"><?= format_inr(abs($prevNetProfit)) ?></td></tr>
<?php endif; ?>
<tr><td><b>Closing Capital</b></td><td class="figure"><b><?= format_inr($data['capital']) ?></b></td><td class="figure"><b><?= format_inr($data['prev_capital']) ?></b></td></tr>
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
<p class="report-page-subtitle">All amounts are presented in Indian Rupees.</p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td colspan="4"><b>EQUITY AND LIABILITIES</b></td></tr>

<tr><td><b>Capital / Corpus Fund</b></td><td></td><td></td><td></td></tr>
<?php if ($isRnp && $data['corpus_fund'] > 0): ?>
<tr><td>Corpus Fund</td><td><a href="#note-<?= $data['note_refs']['Capital'] ?? 1 ?>"><?= $data['note_refs']['Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['corpus_fund']) ?></td><td class="figure"><?= format_inr($data['prev_corpus_fund']) ?></td></tr>
<?php endif; ?>
<tr><td>Capital Account</td><td><a href="#note-<?= $data['note_refs']['Capital'] ?? 1 ?>"><?= $data['note_refs']['Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['capital']) ?></td><td class="figure"><?= format_inr($data['prev_capital']) ?></td></tr>

<tr><td><b>Liabilities</b></td><td></td><td></td><td></td></tr>
<tr><td>Borrowings</td><td><a href="#note-<?= $data['note_refs']['Borrowings'] ?? 2 ?>"><?= $data['note_refs']['Borrowings'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['borrowings']) ?></td><td class="figure"><?= format_inr($data['prev_borrowings']) ?></td></tr>
<tr><td>Payables</td><td><a href="#note-<?= $data['note_refs']['Payables'] ?? 3 ?>"><?= $data['note_refs']['Payables'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['payables']) ?></td><td class="figure"><?= format_inr($data['prev_payables']) ?></td></tr>
<tr><td>Other Current Liabilities</td><td><a href="#note-<?= $data['note_refs']['Current Liabilities'] ?? 4 ?>"><?= $data['note_refs']['Current Liabilities'] ?? 4 ?></a></td><td class="figure"><?= format_inr($data['current_liabilities']) ?></td><td class="figure"><?= format_inr($data['prev_current_liabilities']) ?></td></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_liabilities']) ?></td><td class="figure"><?= format_inr($data['prev_total_liabilities']) ?></td></tr>

<tr><td colspan="4"><b>ASSETS</b></td></tr>

<tr><td>Fixed Assets</td><td><a href="#note-<?= $data['note_refs']['Fixed Assets'] ?? 5 ?>"><?= $data['note_refs']['Fixed Assets'] ?? 5 ?></a></td><td class="figure"><?= format_inr($data['fixed_assets']) ?></td><td class="figure"><?= format_inr($data['prev_fixed_assets']) ?></td></tr>
<tr><td>Loans</td><td><a href="#note-<?= $data['note_refs']['Loans'] ?? 6 ?>"><?= $data['note_refs']['Loans'] ?? 6 ?></a></td><td class="figure"><?= format_inr($data['loans']) ?></td><td class="figure"><?= format_inr($data['prev_loans']) ?></td></tr>
<tr><td>Investments</td><td><a href="#note-<?= $data['note_refs']['Investments'] ?? 7 ?>"><?= $data['note_refs']['Investments'] ?? 7 ?></a></td><td class="figure"><?= format_inr($data['investments']) ?></td><td class="figure"><?= format_inr($data['prev_investments']) ?></td></tr>
<tr><td>Inventory</td><td><a href="#note-<?= $data['note_refs']['Inventory'] ?? 8 ?>"><?= $data['note_refs']['Inventory'] ?? 8 ?></a></td><td class="figure"><?= format_inr($data['inventory']) ?></td><td class="figure"><?= format_inr($data['prev_inventory']) ?></td></tr>
<tr><td>Receivables</td><td><a href="#note-<?= $data['note_refs']['Receivables'] ?? 9 ?>"><?= $data['note_refs']['Receivables'] ?? 9 ?></a></td><td class="figure"><?= format_inr($data['receivables']) ?></td><td class="figure"><?= format_inr($data['prev_receivables']) ?></td></tr>
<tr><td>Cash &amp; Bank</td><td><a href="#note-<?= $data['note_refs']['Cash and Bank'] ?? 10 ?>"><?= $data['note_refs']['Cash and Bank'] ?? 10 ?></a></td><td class="figure"><?= format_inr($data['cash']) ?></td><td class="figure"><?= format_inr($data['prev_cash']) ?></td></tr>
<tr><td>Other Current Assets</td><td><a href="#note-<?= $data['note_refs']['Other Current Assets'] ?? 11 ?>"><?= $data['note_refs']['Other Current Assets'] ?? 11 ?></a></td><td class="figure"><?= format_inr($data['other_current_assets']) ?></td><td class="figure"><?= format_inr($data['prev_other_current_assets']) ?></td></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_assets']) ?></td><td class="figure"><?= format_inr($data['prev_total_assets']) ?></td></tr>
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
