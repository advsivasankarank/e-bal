<?php
// Expected: $data = associative array from SQL
// Example: $data['share_capital'], $data['inventory'], etc.
?>

<h2 id="balance-sheet">Balance Sheet as at <?= htmlspecialchars($data['date']) ?></h2>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td colspan="4"><b>I. EQUITY AND LIABILITIES</b></td></tr>

<tr><td><b>Shareholders' Funds</b></td><td></td><td></td><td></td></tr>
<tr><td>Share Capital</td><td><a href="#note-1"><?= $data['note_refs']['Share Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['share_capital']) ?></td><td class="figure"><?= format_inr($data['prev_share_capital']) ?></td></tr>
<tr><td>Reserves & Surplus</td><td><a href="#note-2"><?= $data['note_refs']['Reserves & Surplus'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['reserves']) ?></td><td class="figure"><?= format_inr($data['prev_reserves']) ?></td></tr>

<tr><td><b>Non-Current Liabilities</b></td><td></td><td></td><td></td></tr>
<tr><td>Long-term Borrowings</td><td><a href="#note-3"><?= $data['note_refs']['Borrowings'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['lt_borrowings']) ?></td><td class="figure"><?= format_inr($data['prev_lt_borrowings']) ?></td></tr>
<tr><td>Deferred Tax</td><td><a href="#note-4"><?= $data['note_refs']['Deferred Tax'] ?? 4 ?></a></td><td class="figure"><?= format_inr($data['deferred_tax']) ?></td><td class="figure"><?= format_inr($data['prev_deferred_tax']) ?></td></tr>
<tr><td>Other Non-Current Liabilities</td><td><a href="#note-5"><?= $data['note_refs']['Other Non-Current Liabilities'] ?? 5 ?></a></td><td class="figure"><?= format_inr($data['other_non_current_liabilities']) ?></td><td class="figure"><?= format_inr($data['prev_other_non_current_liabilities']) ?></td></tr>
<tr><td>Long-Term Provisions</td><td><a href="#note-6"><?= $data['note_refs']['Long-Term Provisions'] ?? 6 ?></a></td><td class="figure"><?= format_inr($data['long_term_provisions']) ?></td><td class="figure"><?= format_inr($data['prev_long_term_provisions']) ?></td></tr>

<tr><td><b>Current Liabilities</b></td><td></td><td></td><td></td></tr>
<tr><td>Short-Term Borrowings</td><td><a href="#note-7"><?= $data['note_refs']['Short-Term Borrowings'] ?? 7 ?></a></td><td class="figure"><?= format_inr($data['st_borrowings']) ?></td><td class="figure"><?= format_inr($data['prev_st_borrowings']) ?></td></tr>
<tr><td>Trade Payables</td><td><a href="#note-8"><?= $data['note_refs']['Trade Payables'] ?? 8 ?></a></td><td class="figure"><?= format_inr($data['trade_payables']) ?></td><td class="figure"><?= format_inr($data['prev_trade_payables']) ?></td></tr>
<tr><td>Other Current Liabilities</td><td><a href="#note-9"><?= $data['note_refs']['Other Current Liabilities'] ?? 9 ?></a></td><td class="figure"><?= format_inr($data['other_current_liabilities']) ?></td><td class="figure"><?= format_inr($data['prev_other_current_liabilities']) ?></td></tr>
<tr><td>Short-Term Provisions</td><td><a href="#note-10"><?= $data['note_refs']['Short-Term Provisions'] ?? 10 ?></a></td><td class="figure"><?= format_inr($data['short_term_provisions']) ?></td><td class="figure"><?= format_inr($data['prev_short_term_provisions']) ?></td></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_liabilities']) ?></td><td class="figure"><?= format_inr($data['prev_total_liabilities']) ?></td></tr>

<tr><td colspan="4"><b>II. ASSETS</b></td></tr>

<tr><td><b>Non-Current Assets</b></td><td></td><td></td><td></td></tr>
<tr><td>Property, Plant & Equipment</td><td><a href="#note-11"><?= $data['note_refs']['Property, Plant & Equipment'] ?? 11 ?></a></td><td class="figure"><?= format_inr($data['fixed_assets']) ?></td><td class="figure"><?= format_inr($data['prev_fixed_assets']) ?></td></tr>
<tr><td>Intangible Assets</td><td><a href="#note-12"><?= $data['note_refs']['Intangible Assets'] ?? 12 ?></a></td><td class="figure"><?= format_inr($data['intangible_assets']) ?></td><td class="figure"><?= format_inr($data['prev_intangible_assets']) ?></td></tr>
<tr><td>Investments</td><td><a href="#note-13"><?= $data['note_refs']['Investments'] ?? 13 ?></a></td><td class="figure"><?= format_inr($data['investments']) ?></td><td class="figure"><?= format_inr($data['prev_investments']) ?></td></tr>
<tr><td>Loans</td><td><a href="#note-14"><?= $data['note_refs']['Loans'] ?? 14 ?></a></td><td class="figure"><?= format_inr($data['loans']) ?></td><td class="figure"><?= format_inr($data['prev_loans']) ?></td></tr>
<tr><td>Other Financial Assets</td><td><a href="#note-15"><?= $data['note_refs']['Other Financial Assets'] ?? 15 ?></a></td><td class="figure"><?= format_inr($data['other_financial_assets']) ?></td><td class="figure"><?= format_inr($data['prev_other_financial_assets']) ?></td></tr>

<tr><td><b>Current Assets</b></td><td></td><td></td><td></td></tr>
<tr><td>Inventories</td><td><a href="#note-16"><?= $data['note_refs']['Inventories'] ?? 16 ?></a></td><td class="figure"><?= format_inr($data['inventory']) ?></td><td class="figure"><?= format_inr($data['prev_inventory']) ?></td></tr>
<tr><td>Trade Receivables</td><td><a href="#note-17"><?= $data['note_refs']['Trade Receivables'] ?? 17 ?></a></td><td class="figure"><?= format_inr($data['receivables']) ?></td><td class="figure"><?= format_inr($data['prev_receivables']) ?></td></tr>
<tr><td>Cash & Cash Equivalents</td><td><a href="#note-18"><?= $data['note_refs']['Cash & Cash Equivalents'] ?? 18 ?></a></td><td class="figure"><?= format_inr($data['cash']) ?></td><td class="figure"><?= format_inr($data['prev_cash']) ?></td></tr>
<tr><td>Other Current Assets</td><td><a href="#note-19"><?= $data['note_refs']['Other Current Assets'] ?? 19 ?></a></td><td class="figure"><?= format_inr($data['other_current_assets']) ?></td><td class="figure"><?= format_inr($data['prev_other_current_assets']) ?></td></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total_assets']) ?></td><td class="figure"><?= format_inr($data['prev_total_assets']) ?></td></tr>
</table>

<br>

<h2 id="profit-loss">Statement of Profit & Loss</h2>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td>Revenue</td><td><a href="#note-20"><?= $data['note_refs']['Revenue from Operations'] ?? 20 ?></a></td><td class="figure"><?= format_inr($data['revenue']) ?></td><td class="figure"><?= format_inr($data['prev_revenue']) ?></td></tr>
<tr><td>Other Income</td><td><a href="#note-21"><?= $data['note_refs']['Other Income'] ?? 21 ?></a></td><td class="figure"><?= format_inr($data['other_income']) ?></td><td class="figure"><?= format_inr($data['prev_other_income']) ?></td></tr>

<tr><td><b>Total Income</b></td><td></td><td class="figure"><?= format_inr($data['total_income']) ?></td><td class="figure"><?= format_inr($data['prev_total_income']) ?></td></tr>

<tr><td>Cost of Materials Consumed</td><td><a href="#note-22"><?= $data['note_refs']['Cost of Materials Consumed'] ?? 22 ?></a></td><td class="figure"><?= format_inr($data['materials']) ?></td><td class="figure"><?= format_inr($data['prev_materials']) ?></td></tr>
<tr><td>Purchase of Stock-in-Trade</td><td><a href="#note-23"><?= $data['note_refs']['Purchase of Stock-in-Trade'] ?? 23 ?></a></td><td class="figure"><?= format_inr($data['purchase_stock']) ?></td><td class="figure"><?= format_inr($data['prev_purchase_stock']) ?></td></tr>
<tr><td>Changes in Inventory</td><td><a href="#note-24"><?= $data['note_refs']['Changes in Inventory'] ?? 24 ?></a></td><td class="figure"><?= format_inr($data['inventory_change']) ?></td><td class="figure"><?= format_inr($data['prev_inventory_change']) ?></td></tr>
<tr><td>Employee Benefits Expense</td><td><a href="#note-25"><?= $data['note_refs']['Employee Benefits Expense'] ?? 25 ?></a></td><td class="figure"><?= format_inr($data['employee_cost']) ?></td><td class="figure"><?= format_inr($data['prev_employee_cost']) ?></td></tr>
<tr><td>Finance Cost</td><td><a href="#note-26"><?= $data['note_refs']['Finance Cost'] ?? 26 ?></a></td><td class="figure"><?= format_inr($data['finance_cost']) ?></td><td class="figure"><?= format_inr($data['prev_finance_cost']) ?></td></tr>
<tr><td>Depreciation &amp; Amortisation</td><td><a href="#note-27"><?= $data['note_refs']['Depreciation & Amortisation'] ?? 27 ?></a></td><td class="figure"><?= format_inr($data['depreciation']) ?></td><td class="figure"><?= format_inr($data['prev_depreciation']) ?></td></tr>
<tr><td>Other Expenses</td><td><a href="#note-28"><?= $data['note_refs']['Other Expenses'] ?? 28 ?></a></td><td class="figure"><?= format_inr($data['other_expenses']) ?></td><td class="figure"><?= format_inr($data['prev_other_expenses']) ?></td></tr>

<tr><td><b>Total Expenses</b></td><td></td><td class="figure"><?= format_inr($data['expenses']) ?></td><td class="figure"><?= format_inr($data['prev_expenses']) ?></td></tr>

<tr><td><b>Profit Before Tax</b></td><td></td><td class="figure"><?= format_inr($data['pbt']) ?></td><td class="figure"><?= format_inr($data['prev_pbt']) ?></td></tr>
<tr><td>Tax</td><td></td><td class="figure"><?= format_inr($data['tax']) ?></td><td class="figure"><?= format_inr($data['prev_tax']) ?></td></tr>

<tr><td><b>Profit After Tax</b></td><td></td><td class="figure"><?= format_inr($data['pat']) ?></td><td class="figure"><?= format_inr($data['prev_pat']) ?></td></tr>
</table>

<br><br>

<table width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr>
    <td style="width:50%; vertical-align:top; border:0; padding:0 20px 0 0;">
        <strong>For Statutory Auditors</strong><br><br><br><br>
        <?= htmlspecialchars($company_meta['auditor_firm'] ?? '') ?><br>
        <?= htmlspecialchars($company_meta['auditor_name'] ?? 'Authorised Signatory') ?>
    </td>
    <td style="width:50%; vertical-align:top; border:0; padding:0; text-align:right;">
        <table width="100%" style="border:0; border-collapse:collapse;">
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
