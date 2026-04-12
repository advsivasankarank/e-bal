<?php
// Based on ICAI Guidance Note structure
?>

<h2 id="balance-sheet">Balance Sheet (Non-Corporate) as at <?= htmlspecialchars($data['date']) ?></h2>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td colspan="4"><b>Owners' Funds</b></td></tr>
<tr><td>Capital</td><td><a href="#note-1"><?= $data['note_refs']['Capital'] ?? 1 ?></a></td><td class="figure"><?= format_inr($data['capital']) ?></td><td class="figure"><?= format_inr($data['prev_capital']) ?></td></tr>
<tr><td>Reserves</td><td></td><td class="figure"><?= format_inr($data['reserves']) ?></td><td class="figure"><?= format_inr($data['prev_reserves']) ?></td></tr>

<tr><td colspan="4"><b>Liabilities</b></td></tr>
<tr><td>Borrowings</td><td><a href="#note-2"><?= $data['note_refs']['Borrowings'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['borrowings']) ?></td><td class="figure"><?= format_inr($data['prev_borrowings']) ?></td></tr>
<tr><td>Payables</td><td><a href="#note-3"><?= $data['note_refs']['Payables'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['payables']) ?></td><td class="figure"><?= format_inr($data['prev_payables']) ?></td></tr>

<tr><td colspan="4"><b>Assets</b></td></tr>
<tr><td>Fixed Assets</td><td><a href="#note-4"><?= $data['note_refs']['Fixed Assets'] ?? 4 ?></a></td><td class="figure"><?= format_inr($data['fixed_assets']) ?></td><td class="figure"><?= format_inr($data['prev_fixed_assets']) ?></td></tr>
<tr><td>Inventory</td><td><a href="#note-5"><?= $data['note_refs']['Inventory'] ?? 5 ?></a></td><td class="figure"><?= format_inr($data['inventory']) ?></td><td class="figure"><?= format_inr($data['prev_inventory']) ?></td></tr>
<tr><td>Receivables</td><td><a href="#note-6"><?= $data['note_refs']['Receivables'] ?? 6 ?></a></td><td class="figure"><?= format_inr($data['receivables']) ?></td><td class="figure"><?= format_inr($data['prev_receivables']) ?></td></tr>
<tr><td>Cash</td><td><a href="#note-7"><?= $data['note_refs']['Cash and Bank'] ?? 7 ?></a></td><td class="figure"><?= format_inr($data['cash']) ?></td><td class="figure"><?= format_inr($data['prev_cash']) ?></td></tr>

<tr><td><b>TOTAL</b></td><td></td><td class="figure"><?= format_inr($data['total']) ?></td><td class="figure"><?= format_inr($data['prev_total']) ?></td></tr>
</table>

<br>

<h2 id="profit-loss">Statement of Profit & Loss</h2>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><th class="figure">Previous</th></tr>

<tr><td>Revenue</td><td><a href="#note-8"><?= $data['note_refs']['Revenue'] ?? 8 ?></a></td><td class="figure"><?= format_inr($data['revenue']) ?></td><td class="figure"><?= format_inr($data['prev_revenue']) ?></td></tr>
<tr><td>Other Income</td><td></td><td class="figure"><?= format_inr($data['other_income']) ?></td><td class="figure"><?= format_inr($data['prev_other_income']) ?></td></tr>

<tr><td>Expenses</td><td><a href="#note-9"><?= $data['note_refs']['Expenses'] ?? 9 ?></a></td><td class="figure"><?= format_inr($data['expenses']) ?></td><td class="figure"><?= format_inr($data['prev_expenses']) ?></td></tr>

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
