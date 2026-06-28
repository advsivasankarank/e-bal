<?php
$isFirstYear = (bool) ($isFirstYear ?? false);
if (!function_exists('pv')) { function pv($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . \format_inr($val) . '</td>'; } }
if (!function_exists('pvRaw')) { function pvRaw($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . $val . '</td>'; } }
$corpus = (float) ($data['corpus_fund'] ?? 0);
$prevCorpus = (float) ($data['prev_corpus_fund'] ?? 0);
$generalFund = (float) ($data['capital'] ?? 0) - $corpus;
$prevGeneralFund = (float) ($data['prev_capital'] ?? 0) - $prevCorpus;
$societyIncome = (float) ($data['revenue'] ?? 0) + (float) ($data['donations'] ?? 0) + (float) ($data['grants'] ?? 0) + (float) ($data['membership_fees'] ?? 0) + (float) ($data['other_income'] ?? 0);
$prevSocietyIncome = (float) ($data['prev_revenue'] ?? 0) + (float) ($data['prev_donations'] ?? 0) + (float) ($data['prev_grants'] ?? 0) + (float) ($data['prev_membership_fees'] ?? 0) + (float) ($data['prev_other_income'] ?? 0);
$societyExpenditure = (float) ($data['employee_cost'] ?? 0) + (float) ($data['administrative_expenses'] ?? 0) + (float) ($data['programme_expenses'] ?? 0) + (float) ($data['finance_cost'] ?? 0) + (float) ($data['depreciation'] ?? 0) + (float) ($data['other_expenses'] ?? 0) + (float) ($data['expenses'] ?? 0);
$prevSocietyExpenditure = (float) ($data['prev_employee_cost'] ?? 0) + (float) ($data['prev_administrative_expenses'] ?? 0) + (float) ($data['prev_programme_expenses'] ?? 0) + (float) ($data['prev_finance_cost'] ?? 0) + (float) ($data['prev_depreciation'] ?? 0) + (float) ($data['prev_other_expenses'] ?? 0) + (float) ($data['prev_expenses'] ?? 0);
$netSurplus = $societyIncome - $societyExpenditure;
$prevNetSurplus = $prevSocietyIncome - $prevSocietyExpenditure;
?>

<section class="report-page notes-shell" id="notes-to-accounts">
<h2 class="report-page-title">Notes to Accounts</h2>
<p class="report-page-subtitle notes-cover">Detailed disclosures forming part of the Society financial statements.</p>

<?php if ($corpus > 0 || $generalFund > 0): ?>
<div class="note-block">
<h3 class="note-heading" id="note-capital-fund">Note 1: Accumulated Fund</h3>
<table class="note-table" border="1" width="100%">
    <thead>
    <tr><th>Particulars</th><th class="figure">Current Year</th><?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous Year</th><?php endif; ?></tr>
    </thead>
    <tbody>
    <?php if ($corpus > 0): ?>
    <tr><td>Corpus / Capital Fund</td><td class="figure"><?= format_inr($corpus) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($prevCorpus) ?></td><?php endif; ?></tr>
    <?php endif; ?>
    <?php if ($generalFund > 0): ?>
    <tr><td>General Fund / Accumulated Surplus</td><td class="figure"><?= format_inr($generalFund) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($prevGeneralFund) ?></td><?php endif; ?></tr>
    <?php endif; ?>
    <tr><td>Add: Net Surplus / (Deficit) for the year</td><td class="figure"><?= format_inr($netSurplus) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($prevNetSurplus) ?></td><?php endif; ?></tr>
    </tbody>
    <tfoot>
    <tr><td><strong>Total Accumulated Fund</strong></td><td class="figure"><strong><?= format_inr($corpus + $generalFund + $netSurplus) ?></strong></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><strong><?= format_inr($prevCorpus + $prevGeneralFund + $prevNetSurplus) ?></strong></td><?php endif; ?></tr>
    </tfoot>
</table>
</div>
<?php endif; ?>

<?php foreach (($notes['sections'] ?? []) as $index => $section): ?>
    <?php if (($section['title'] ?? '') === 'Capital') continue; ?>
    <div class="note-block">
    <?php $noteNo = (int) ($section['note_no'] ?? ($index + 2)); ?>
    <h3 class="note-heading" id="note-<?= $noteNo ?>">Note <?= $noteNo ?>: <?= htmlspecialchars((string) ($section['title'] ?? ('Note ' . $noteNo))) ?></h3>
    <table class="note-table" border="1" width="100%">
        <thead>
        <tr><th>Ledger / Particulars</th><th class="figure">Current Year</th><?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous Year</th><?php endif; ?></tr>
        </thead>
        <tbody>
        <?php foreach (($section['lines'] ?? []) as $line): ?>
            <tr>
                <td><?= htmlspecialchars($line['label']) ?></td>
                <td class="figure"><?= format_inr((float) ($line['current'] ?? 0)) ?></td>
                <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($line['previous'] ?? 0)) ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td><strong>Total</strong></td>
            <td class="figure"><strong><?= format_inr((float) ($section['current_total'] ?? 0)) ?></strong></td>
            <?php if (!$isFirstYear): ?><td class="figure" data-prev><strong><?= format_inr((float) ($section['previous_total'] ?? 0)) ?></strong></td><?php endif; ?>
        </tr>
        </tfoot>
    </table>
    </div>
<?php endforeach; ?>

<h3 class="note-heading">Significant Accounting Policies</h3>
<ul class="note-policy-list">
<li>Basis of accounting: The Society follows the accrual system of accounting, except in respect of membership subscriptions and certain grants which are recognised on receipt basis.</li>
<li>Basis of preparation: The financial statements have been prepared under the historical cost convention and in accordance with the applicable accounting standards issued by the Institute of Chartered Accountants of India (ICAI).</li>
<li>Revenue recognition: Membership subscriptions are recognised on an accrual basis. Grants-in-aid and donations are recognised as income when the right to receive is established.</li>
<li>Fixed assets: Fixed assets are stated at cost less accumulated depreciation. Depreciation is provided on the straight-line method / written down value method based on the estimated useful life of the assets.</li>
<li>Valuation of investments: Investments are carried at cost. Provision for diminution is made if the decline is other than temporary in nature.</li>
<li>Fund accounting: The Society maintains separate funds for specified objects. Restricted funds are applied only towards the objectives for which they are created.</li>
</ul>

<h3 class="note-heading">Society Information</h3>
<table class="note-table" border="1" width="100%">
    <tr><td>Name of Society</td><td><?= htmlspecialchars($company_meta['company_name'] ?? '') ?></td></tr>
    <tr><td>Registration Details</td><td><?= htmlspecialchars($company_meta['signatory_1_id_no'] ?? '') ?></td></tr>
</table>
</section>
