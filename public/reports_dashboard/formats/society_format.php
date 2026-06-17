<?php
$isFirstYear = (bool) ($isFirstYear ?? false);
$prevH = $isFirstYear ? '' : '<th class="figure" data-prev>Previous</th>';
$prevHNote = $isFirstYear ? '' : '<th class="figure" data-prev>Previous</th>';
$prevColspan = $isFirstYear ? 2 : 3;
$prevColspan4 = $isFirstYear ? 3 : 4;
$reportSubtitle = $isFirstYear ? ' (First Year Financial Statements)' : '';
function pv($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . \format_inr($val) . '</td>'; }
function pvRaw($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . $val . '</td>'; }

$societyIncome = (float) ($data['revenue'] ?? 0) + (float) ($data['donations'] ?? 0) + (float) ($data['grants'] ?? 0) + (float) ($data['membership_fees'] ?? 0) + (float) ($data['other_income'] ?? 0);
$prevSocietyIncome = (float) ($data['prev_revenue'] ?? 0) + (float) ($data['prev_donations'] ?? 0) + (float) ($data['prev_grants'] ?? 0) + (float) ($data['prev_membership_fees'] ?? 0) + (float) ($data['prev_other_income'] ?? 0);
$societyExpenditure = (float) ($data['employee_cost'] ?? 0) + (float) ($data['administrative_expenses'] ?? 0) + (float) ($data['programme_expenses'] ?? 0) + (float) ($data['finance_cost'] ?? 0) + (float) ($data['depreciation'] ?? 0) + (float) ($data['other_expenses'] ?? 0) + (float) ($data['expenses'] ?? 0);
$prevSocietyExpenditure = (float) ($data['prev_employee_cost'] ?? 0) + (float) ($data['prev_administrative_expenses'] ?? 0) + (float) ($data['prev_programme_expenses'] ?? 0) + (float) ($data['prev_finance_cost'] ?? 0) + (float) ($data['prev_depreciation'] ?? 0) + (float) ($data['prev_other_expenses'] ?? 0) + (float) ($data['prev_expenses'] ?? 0);
$netSurplus = $societyIncome - $societyExpenditure;
$prevNetSurplus = $prevSocietyIncome - $prevSocietyExpenditure;

$corpus = (float) ($data['corpus_fund'] ?? 0);
$prevCorpus = (float) ($data['prev_corpus_fund'] ?? 0);
$generalFund = (float) ($data['capital'] ?? 0) - $corpus;
$prevGeneralFund = (float) ($data['prev_capital'] ?? 0) - $prevCorpus;

$openCash = (float) ($data['prev_cash'] ?? 0);
$closeCash = (float) ($data['cash'] ?? 0);
$receiptsTotal = $societyIncome;
$prevReceiptsTotal = $prevSocietyIncome;
$paymentsTotal = $societyExpenditure;
$prevPaymentsTotal = $prevSocietyExpenditure;
?>

<section class="report-page" id="receipts-payments">
<h2 class="report-page-title">Receipts &amp; Payments Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Receipts</th><th class="figure">Current</th><?= $prevH ?><th>Payments</th><th class="figure">Current</th><?= $prevH ?></tr>

<tr>
    <td>Opening Cash &amp; Bank Balances</td>
    <td class="figure"><?= format_inr($openCash) ?></td>
    <?= pv(0) ?>
    <td>Administrative Expenses</td>
    <td class="figure"><?= format_inr($data['administrative_expenses']) ?></td>
    <?= pv($data['prev_administrative_expenses']) ?>
</tr>
<tr>
    <td>Membership Subscriptions</td>
    <td class="figure"><?= format_inr($data['membership_fees']) ?></td>
    <?= pv($data['prev_membership_fees']) ?>
    <td>Programme / Project Expenses</td>
    <td class="figure"><?= format_inr($data['programme_expenses']) ?></td>
    <?= pv($data['prev_programme_expenses']) ?>
</tr>
<tr>
    <td>Grants-in-Aid Received</td>
    <td class="figure"><?= format_inr($data['grants']) ?></td>
    <?= pv($data['prev_grants']) ?>
    <td>Employee Costs</td>
    <td class="figure"><?= format_inr($data['employee_cost']) ?></td>
    <?= pv($data['prev_employee_cost']) ?>
</tr>
<tr>
    <td>Donations / Contributions</td>
    <td class="figure"><?= format_inr($data['donations']) ?></td>
    <?= pv($data['prev_donations']) ?>
    <td>Finance Costs</td>
    <td class="figure"><?= format_inr($data['finance_cost']) ?></td>
    <?= pv($data['prev_finance_cost']) ?>
</tr>
<tr>
    <td>Other Receipts</td>
    <td class="figure"><?= format_inr($data['revenue']) ?></td>
    <?= pv($data['prev_revenue']) ?>
    <td>Depreciation</td>
    <td class="figure"><?= format_inr($data['depreciation']) ?></td>
    <?= pv($data['prev_depreciation']) ?>
</tr>
<tr>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
    <td>Other Payments</td>
    <td class="figure"><?= format_inr($data['other_expenses']) ?></td>
    <?= pv($data['prev_other_expenses']) ?>
</tr>
<tr>
    <td><b>Total Receipts (excluding opening)</b></td>
    <td class="figure"><b><?= format_inr($receiptsTotal) ?></b></td>
    <?= pvRaw('<b>' . format_inr($prevReceiptsTotal) . '</b>') ?>
    <td><b>Total Payments</b></td>
    <td class="figure"><b><?= format_inr($paymentsTotal) ?></b></td>
    <?= pvRaw('<b>' . format_inr($prevPaymentsTotal) . '</b>') ?>
</tr>
<tr>
    <td><b>Total Available (Opening + Receipts)</b></td>
    <td class="figure"><b><?= format_inr($openCash + $receiptsTotal) ?></b></td>
    <?= pvRaw('<b>' . format_inr($prevReceiptsTotal) . '</b>') ?>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
</tr>
<tr>
    <td><b>Closing Cash &amp; Bank Balances</b></td>
    <td class="figure"><b><?= format_inr($closeCash) ?></b></td>
    <?= pvRaw('<b>' . format_inr($closeCash) . '</b>') ?>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
</tr>
</table>

<table class="signature-table" width="100%" style="border:0; border-collapse:collapse; margin-top:40px;">
<tr><td style="border:0; text-align:right;">
    <strong>For <?= htmlspecialchars($company_meta['entity_type'] ?? 'Society') ?></strong><br><br><br><br>
    <?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Authorised Signatory') ?><br>
    <div class="signature-caption">Authorised Signatory</div>
</td></tr>
</table>
</section>

<section class="report-page" id="income-expenditure">
<h2 class="report-page-title">Income &amp; Expenditure Account</h2>
<p class="report-page-subtitle">For the year ended <?= htmlspecialchars($data['date']) ?>.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Income</th><th class="figure">Current</th><?= $prevH ?><th>Expenditure</th><th class="figure">Current</th><?= $prevH ?></tr>

<tr>
    <td>Membership Subscriptions</td>
    <td class="figure"><?= format_inr($data['membership_fees']) ?></td>
    <?= pv($data['prev_membership_fees']) ?>
    <td>Programme / Project Expenses</td>
    <td class="figure"><?= format_inr($data['programme_expenses']) ?></td>
    <?= pv($data['prev_programme_expenses']) ?>
</tr>
<tr>
    <td>Grants-in-Aid</td>
    <td class="figure"><?= format_inr($data['grants']) ?></td>
    <?= pv($data['prev_grants']) ?>
    <td>Administrative Expenses</td>
    <td class="figure"><?= format_inr($data['administrative_expenses']) ?></td>
    <?= pv($data['prev_administrative_expenses']) ?>
</tr>
<tr>
    <td>Donations / Contributions</td>
    <td class="figure"><?= format_inr($data['donations']) ?></td>
    <?= pv($data['prev_donations']) ?>
    <td>Employee Costs</td>
    <td class="figure"><?= format_inr($data['employee_cost']) ?></td>
    <?= pv($data['prev_employee_cost']) ?>
</tr>
<tr>
    <td>Other Income</td>
    <td class="figure"><?= format_inr($data['other_income']) ?></td>
    <?= pv($data['prev_other_income']) ?>
    <td>Finance Costs</td>
    <td class="figure"><?= format_inr($data['finance_cost']) ?></td>
    <?= pv($data['prev_finance_cost']) ?>
</tr>
<tr>
    <td>Other Receipts</td>
    <td class="figure"><?= format_inr($data['revenue']) ?></td>
    <?= pv($data['prev_revenue']) ?>
    <td>Depreciation</td>
    <td class="figure"><?= format_inr($data['depreciation']) ?></td>
    <?= pv($data['prev_depreciation']) ?>
</tr>
<tr>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
    <td>Other Expenditure</td>
    <td class="figure"><?= format_inr($data['other_expenses']) ?></td>
    <?= pv($data['prev_other_expenses']) ?>
</tr>

<?php
$otherExpTotal = (float) ($data['expenses'] ?? 0) - (float) ($data['programme_expenses'] ?? 0) - (float) ($data['administrative_expenses'] ?? 0) - (float) ($data['employee_cost'] ?? 0) - (float) ($data['finance_cost'] ?? 0) - (float) ($data['depreciation'] ?? 0) - (float) ($data['other_expenses'] ?? 0);
$prevOtherExpTotal = (float) ($data['prev_expenses'] ?? 0) - (float) ($data['prev_programme_expenses'] ?? 0) - (float) ($data['prev_administrative_expenses'] ?? 0) - (float) ($data['prev_employee_cost'] ?? 0) - (float) ($data['prev_finance_cost'] ?? 0) - (float) ($data['prev_depreciation'] ?? 0) - (float) ($data['prev_other_expenses'] ?? 0);
if ($otherExpTotal > 0 || $prevOtherExpTotal > 0):
?>
<tr>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
    <td>Other Expenses</td>
    <td class="figure"><?= format_inr($otherExpTotal) ?></td>
    <?= pv($prevOtherExpTotal) ?>
</tr>
<?php endif; ?>

<tr>
    <td><b>Total Income</b></td>
    <td class="figure"><b><?= format_inr($societyIncome) ?></b></td>
    <?= pvRaw('<b>' . format_inr($prevSocietyIncome) . '</b>') ?>
    <td><b>Total Expenditure</b></td>
    <td class="figure"><b><?= format_inr($societyExpenditure) ?></b></td>
    <?= pvRaw('<b>' . format_inr($prevSocietyExpenditure) . '</b>') ?>
</tr>

<?php if ($netSurplus >= 0): ?>
<tr>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
    <td><b>Net Surplus transferred to Accumulated Fund</b></td>
    <td class="figure"><b><?= format_inr($netSurplus) ?></b></td>
    <?= pvRaw('<b>' . format_inr($prevNetSurplus) . '</b>') ?>
</tr>
<?php else: ?>
<tr>
    <td><b>Net Deficit transferred to Accumulated Fund</b></td>
    <td class="figure"><b><?= format_inr(abs($netSurplus)) ?></b></td>
    <?= pvRaw('<b>' . format_inr(abs($prevNetSurplus)) . '</b>') ?>
    <td></td>
    <td class="figure"></td>
    <td<?= $isFirstYear ? '' : ' data-prev' ?>></td>
</tr>
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
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Member 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Governing Body Member') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Member 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Governing Body Member') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    Signature
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>

<section class="report-page" id="balance-sheet">
<h2 class="report-page-title">Balance Sheet as at <?= htmlspecialchars($data['date']) ?></h2>
<p class="report-page-subtitle">All amounts are presented in Indian Rupees.<?= $reportSubtitle ?></p>

<table border="1" width="100%" cellpadding="5">
<tr><th>Particulars</th><th>Note</th><th class="figure">Current</th><?= $prevHNote ?></tr>

<tr><td colspan="<?= $prevColspan4 ?>"><b>ACCUMULATED FUND AND LIABILITIES</b></td></tr>

<tr><td><b>Accumulated Fund</b></td><td></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
<?php if ($corpus > 0): ?>
<tr><td>&emsp;Corpus / Capital Fund</td><td><a href="#note-capital-fund">1</a></td><td class="figure"><?= format_inr($corpus) ?></td><?= pv($prevCorpus) ?></tr>
<?php endif; ?>
<?php if ($generalFund > 0): ?>
<tr><td>&emsp;General Fund / Accumulated Surplus</td><td><a href="#note-capital-fund">1</a></td><td class="figure"><?= format_inr($generalFund) ?></td><?= pv($prevGeneralFund) ?></tr>
<?php endif; ?>
<tr><td><b>Total Accumulated Fund</b></td><td></td><td class="figure"><b><?= format_inr($corpus + $generalFund) ?></b></td><?= pvRaw('<b>' . format_inr($prevCorpus + $prevGeneralFund) . '</b>') ?></tr>

<tr><td><b>Liabilities</b></td><td></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
<tr><td>&emsp;Borrowings</td><td><a href="#note-<?= $data['note_refs']['Borrowings'] ?? 2 ?>"><?= $data['note_refs']['Borrowings'] ?? 2 ?></a></td><td class="figure"><?= format_inr($data['borrowings']) ?></td><?= pv($data['prev_borrowings']) ?></tr>
<tr><td>&emsp;Payables</td><td><a href="#note-<?= $data['note_refs']['Payables'] ?? 3 ?>"><?= $data['note_refs']['Payables'] ?? 3 ?></a></td><td class="figure"><?= format_inr($data['payables']) ?></td><?= pv($data['prev_payables']) ?></tr>
<tr><td>&emsp;Other Current Liabilities</td><td><a href="#note-<?= $data['note_refs']['Current Liabilities'] ?? 4 ?>"><?= $data['note_refs']['Current Liabilities'] ?? 4 ?></a></td><td class="figure"><?= format_inr($data['current_liabilities']) ?></td><?= pv($data['prev_current_liabilities']) ?></tr>

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
                    <strong><?= htmlspecialchars($company_meta['signatory_1_name'] ?? 'Member 1') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_1_designation'] ?? 'Governing Body Member') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
                <td style="width:50%; border:0; padding:0 0 0 20px; text-align:right; vertical-align:top;">
                    <strong><?= htmlspecialchars($company_meta['signatory_2_name'] ?? 'Member 2') ?></strong><br>
                    <?= htmlspecialchars($company_meta['signatory_2_designation'] ?? 'Governing Body Member') ?><br>
                    <?= htmlspecialchars($company_meta['signatory_2_id_no'] ?? '') ?><br><br><br>
                    <div class="signature-caption">Signature</div>
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</section>
