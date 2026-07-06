<?php
// Expected: $data = associative array from SQL
// $company_meta = company reporting meta (name, cin, registered_address, etc.)
// Example: $data['share_capital'], $data['inventory'], etc.
// Previous Year column is ALWAYS shown — use ₹0.00 if no prior data.
$prevHNote = '<th class="figure previous-year">Previous Year</th>';
$reportSubtitle = '';
if (!function_exists('pv')) { function pv($val) { return '<td class="figure previous-year">' . \format_inr($val ?? 0) . '</td>'; } }
if (!function_exists('pvRaw')) { function pvRaw($val) { return '<td class="figure previous-year">' . ($val ?? '') . '</td>'; } }

$ebalCompanyName = $company_meta['company_name'] ?? '';
$ebalCin = $company_meta['cin'] ?? '';
$ebalAddress = $company_meta['registered_address'] ?? '';
?>

<section class="report-page" id="balance-sheet">

<!-- Company Header for Balance Sheet -->
<div class="ebal-stat-header">
    <div class="ebal-company-name"><?= htmlspecialchars($ebalCompanyName ?: 'Company Name Not Configured') ?></div>
    <?php if ($ebalAddress): ?>
    <div class="ebal-company-detail">Registered Office: <?= htmlspecialchars($ebalAddress) ?></div>
    <?php else: ?>
    <div class="ebal-company-detail ebal-placeholder">Registered Office: Not configured</div>
    <?php endif; ?>
    <?php if ($ebalCin): ?>
    <div class="ebal-company-detail">CIN: <?= htmlspecialchars($ebalCin) ?></div>
    <?php else: ?>
    <div class="ebal-company-detail ebal-placeholder">CIN: Not configured</div>
    <?php endif; ?>
</div>

<h2 class="report-page-title">Balance Sheet as at <?= htmlspecialchars($data['date']) ?></h2>
<p class="report-page-subtitle">(Figures in Indian Rupees unless otherwise stated)</p>

<table class="statement-table" border="1" width="100%" cellpadding="5">
<tr><th class="particulars">Particulars</th><th class="note-col">Note</th><th class="figure current-year">Current Year</th><?= $prevHNote ?></tr>

<tr><td colspan="4"><b>I. EQUITY AND LIABILITIES</b></td></tr>

<tr><td><b>Shareholders' Funds</b></td><td></td><td></td><td></td></tr>
<tr><td>Share Capital</td><td class="note-col"><a href="#note-1"><?= $data['note_refs']['Share Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['share_capital']) ?></td><?= pv($data['prev_share_capital']) ?></tr>
<tr><td>Reserves & Surplus</td><td class="note-col"><a href="#note-2"><?= $data['note_refs']['Reserves & Surplus'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['reserves']) ?></td><?= pv($data['prev_reserves']) ?></tr>

<tr><td><b>Non-Current Liabilities</b></td><td></td><td></td><td></td></tr>
<tr><td>Long-term Borrowings</td><td class="note-col"><a href="#note-3"><?= $data['note_refs']['Borrowings'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['lt_borrowings']) ?></td><?= pv($data['prev_lt_borrowings']) ?></tr>
<tr><td>Deferred Tax</td><td class="note-col"><a href="#note-4"><?= $data['note_refs']['Deferred Tax'] ?? 4 ?></a></td><td class="figure"><?= format_inr($data['deferred_tax']) ?></td><?= pv($data['prev_deferred_tax']) ?></tr>
<tr><td>Other Non-Current Liabilities</td><td class="note-col"><a href="#note-5"><?= $data['note_refs']['Other Non-Current Liabilities'] ?? 5 ?></a></td><td class="figure"><?= format_inr($data['other_non_current_liabilities']) ?></td><?= pv($data['prev_other_non_current_liabilities']) ?></tr>
<tr><td>Long-Term Provisions</td><td class="note-col"><a href="#note-6"><?= $data['note_refs']['Long-Term Provisions'] ?? 6 ?></a></td><td class="figure"><?= format_inr($data['long_term_provisions']) ?></td><?= pv($data['prev_long_term_provisions']) ?></tr>

<tr><td><b>Current Liabilities</b></td><td></td><td></td><td></td></tr>
<tr><td>Short-Term Borrowings</td><td class="note-col"><a href="#note-7"><?= $data['note_refs']['Short-Term Borrowings'] ?? 7 ?></a></td><td class="figure"><?= format_inr($data['st_borrowings']) ?></td><?= pv($data['prev_st_borrowings']) ?></tr>
<tr><td>Trade Payables</td><td class="note-col"><a href="#note-8"><?= $data['note_refs']['Trade Payables'] ?? 8 ?></a></td><td class="figure"><?= format_inr($data['trade_payables']) ?></td><?= pv($data['prev_trade_payables']) ?></tr>
<tr><td>Other Current Liabilities</td><td class="note-col"><a href="#note-9"><?= $data['note_refs']['Other Current Liabilities'] ?? 9 ?></a></td><td class="figure"><?= format_inr($data['other_current_liabilities']) ?></td><?= pv($data['prev_other_current_liabilities']) ?></tr>
<tr><td>Short-Term Provisions</td><td class="note-col"><a href="#note-10"><?= $data['note_refs']['Short-Term Provisions'] ?? 10 ?></a></td><td class="figure"><?= format_inr($data['short_term_provisions']) ?></td><?= pv($data['prev_short_term_provisions']) ?></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_liabilities']) ?></td><?= pv($data['prev_total_liabilities']) ?></tr>

<tr><td colspan="4"><b>II. ASSETS</b></td></tr>

<tr><td><b>Non-Current Assets</b></td><td></td><td></td><td></td></tr>
<tr><td>Property, Plant & Equipment</td><td class="note-col"><a href="#note-11"><?= $data['note_refs']['Property, Plant & Equipment'] ?? 11 ?></a></td><td class="figure"><?= format_inr($data['fixed_assets']) ?></td><?= pv($data['prev_fixed_assets']) ?></tr>
<tr><td>Intangible Assets</td><td class="note-col"><a href="#note-12"><?= $data['note_refs']['Intangible Assets'] ?? 12 ?></a></td><td class="figure"><?= format_inr($data['intangible_assets']) ?></td><?= pv($data['prev_intangible_assets']) ?></tr>
<tr><td>Investments</td><td class="note-col"><a href="#note-13"><?= $data['note_refs']['Investments'] ?? 13 ?></a></td><td class="figure"><?= format_inr($data['investments']) ?></td><?= pv($data['prev_investments']) ?></tr>
<tr><td>Loans</td><td class="note-col"><a href="#note-14"><?= $data['note_refs']['Loans'] ?? 14 ?></a></td><td class="figure"><?= format_inr($data['loans']) ?></td><?= pv($data['prev_loans']) ?></tr>
<tr><td>Other Financial Assets</td><td class="note-col"><a href="#note-15"><?= $data['note_refs']['Other Financial Assets'] ?? 15 ?></a></td><td class="figure"><?= format_inr($data['other_financial_assets']) ?></td><?= pv($data['prev_other_financial_assets']) ?></tr>

<tr><td><b>Current Assets</b></td><td></td><td></td><td></td></tr>
<tr><td>Inventories</td><td class="note-col"><a href="#note-16"><?= $data['note_refs']['Inventories'] ?? 16 ?></a></td><td class="figure"><?= format_inr($data['inventory']) ?></td><?= pv($data['prev_inventory']) ?></tr>
<tr><td>Trade Receivables</td><td class="note-col"><a href="#note-17"><?= $data['note_refs']['Trade Receivables'] ?? 17 ?></a></td><td class="figure"><?= format_inr($data['receivables']) ?></td><?= pv($data['prev_receivables']) ?></tr>
<tr><td>Cash & Cash Equivalents</td><td class="note-col"><a href="#note-18"><?= $data['note_refs']['Cash & Cash Equivalents'] ?? 18 ?></a></td><td class="figure"><?= format_inr($data['cash']) ?></td><?= pv($data['prev_cash']) ?></tr>
<tr><td>Other Current Assets</td><td class="note-col"><a href="#note-19"><?= $data['note_refs']['Other Current Assets'] ?? 19 ?></a></td><td class="figure"><?= format_inr($data['other_current_assets']) ?></td><?= pv($data['prev_other_current_assets']) ?></tr>

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
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Director 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Director') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Director 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Director') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>

<section class="report-page" id="profit-loss">

<!-- Company Header for P&L -->
<div class="ebal-stat-header">
    <div class="ebal-company-name"><?= htmlspecialchars($ebalCompanyName ?: 'Company Name Not Configured') ?></div>
    <?php if ($ebalAddress): ?>
    <div class="ebal-company-detail">Registered Office: <?= htmlspecialchars($ebalAddress) ?></div>
    <?php else: ?>
    <div class="ebal-company-detail ebal-placeholder">Registered Office: Not configured</div>
    <?php endif; ?>
    <?php if ($ebalCin): ?>
    <div class="ebal-company-detail">CIN: <?= htmlspecialchars($ebalCin) ?></div>
    <?php else: ?>
    <div class="ebal-company-detail ebal-placeholder">CIN: Not configured</div>
    <?php endif; ?>
</div>

<h2 class="report-page-title">Statement of Profit &amp; Loss</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.</p>

<table class="statement-table" border="1" width="100%" cellpadding="5">
<tr><th class="particulars">Particulars</th><th class="note-col">Note</th><th class="figure current-year">Current Year</th><?= $prevHNote ?></tr>

<tr><td>Revenue</td><td class="note-col"><a href="#note-20"><?= $data['note_refs']['Revenue from Operations'] ?? 20 ?></a></td><td class="figure"><?= format_inr($data['revenue']) ?></td><?= pv($data['prev_revenue']) ?></tr>
<tr><td>Other Income</td><td class="note-col"><a href="#note-21"><?= $data['note_refs']['Other Income'] ?? 21 ?></a></td><td class="figure"><?= format_inr($data['other_income']) ?></td><?= pv($data['prev_other_income']) ?></tr>

<tr><td><b>Total Income</b></td><td></td><td class="figure"><?= format_inr($data['total_income']) ?></td><?= pv($data['prev_total_income']) ?></tr>

<tr><td>Cost of Materials Consumed</td><td class="note-col"><a href="#note-22"><?= $data['note_refs']['Cost of Materials Consumed'] ?? 22 ?></a></td><td class="figure"><?= format_inr($data['materials']) ?></td><?= pv($data['prev_materials']) ?></tr>
<tr><td>Purchase of Stock-in-Trade</td><td class="note-col"><a href="#note-23"><?= $data['note_refs']['Purchase of Stock-in-Trade'] ?? 23 ?></a></td><td class="figure"><?= format_inr($data['purchase_stock']) ?></td><?= pv($data['prev_purchase_stock']) ?></tr>
<tr><td>Changes in Inventory</td><td class="note-col"><a href="#note-24"><?= $data['note_refs']['Changes in Inventory'] ?? 24 ?></a></td><td class="figure"><?= format_inr($data['inventory_change']) ?></td><?= pv($data['prev_inventory_change']) ?></tr>
<tr><td>Employee Benefits Expense</td><td class="note-col"><a href="#note-25"><?= $data['note_refs']['Employee Benefits Expense'] ?? 25 ?></a></td><td class="figure"><?= format_inr($data['employee_cost']) ?></td><?= pv($data['prev_employee_cost']) ?></tr>
<tr><td>Finance Cost</td><td class="note-col"><a href="#note-26"><?= $data['note_refs']['Finance Cost'] ?? 26 ?></a></td><td class="figure"><?= format_inr($data['finance_cost']) ?></td><?= pv($data['prev_finance_cost']) ?></tr>
<tr><td>Depreciation &amp; Amortisation</td><td class="note-col"><a href="#note-27"><?= $data['note_refs']['Depreciation & Amortisation'] ?? 27 ?></a></td><td class="figure"><?= format_inr($data['depreciation']) ?></td><?= pv($data['prev_depreciation']) ?></tr>
<tr><td>Other Expenses</td><td class="note-col"><a href="#note-28"><?= $data['note_refs']['Other Expenses'] ?? 28 ?></a></td><td class="figure"><?= format_inr($data['other_expenses']) ?></td><?= pv($data['prev_other_expenses']) ?></tr>

<tr><td><b>Total Expenses</b></td><td></td><td class="figure"><?= format_inr($data['expenses']) ?></td><?= pv($data['prev_expenses']) ?></tr>

<tr><td><b>Profit Before Tax</b></td><td></td><td class="figure"><?= format_inr($data['pbt']) ?></td><?= pv($data['prev_pbt']) ?></tr>
<tr><td>Tax</td><td></td><td class="figure"><?= format_inr($data['tax']) ?></td><?= pv($data['prev_tax']) ?></tr>

<tr><td><b>Profit After Tax</b></td><td></td><td class="figure"><?= format_inr($data['pat']) ?></td><?= pv($data['prev_pat']) ?></tr>
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
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Director 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Director') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Director 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Director') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>

<!-- e-BAL Footer Branding -->
<div class="ebal-footer-brand">Generated by e-BAL Financial Reporting Workspace</div>
</section>
