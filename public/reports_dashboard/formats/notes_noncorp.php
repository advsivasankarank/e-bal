<?php
$isRnp = in_array($data['entity_subcategory'] ?? '', ['trust', 'society'], true);
$isTrading = in_array($data['entity_subcategory'] ?? '', ['proprietorship', 'partnership', 'llp'], true);
?>

<section class="report-page notes-shell" id="notes-to-accounts">
<h2 class="report-page-title">Notes to Accounts</h2>
<p class="report-page-subtitle notes-cover">Detailed disclosures supporting the non-corporate financial statements.</p>

<?php if ($isRnp && ($data['corpus_fund'] ?? 0) != 0): ?>
<div class="note-block">
<h3 class="note-heading" id="note-corpus">Corpus / Capital Fund Schedule</h3>
<table class="note-table" border="1" width="100%">
    <thead>
    <tr><th>Particulars</th><th class="figure">Current Year</th><th class="figure">Previous Year</th></tr>
    </thead>
    <tbody>
    <tr><td>Corpus Fund</td><td class="figure"><?= format_inr($data['corpus_fund']) ?></td><td class="figure"><?= format_inr($data['prev_corpus_fund']) ?></td></tr>
    <tr><td>Capital Account</td><td class="figure"><?= format_inr($data['capital']) ?></td><td class="figure"><?= format_inr($data['prev_capital']) ?></td></tr>
    </tbody>
    <tfoot>
    <tr><td><strong>Total</strong></td><td class="figure"><strong><?= format_inr($data['corpus_fund'] + $data['capital']) ?></strong></td><td class="figure"><strong><?= format_inr($data['prev_corpus_fund'] + $data['prev_capital']) ?></strong></td></tr>
    </tfoot>
</table>
</div>
<?php endif; ?>

<?php if ($isTrading): ?>
<div class="note-block">
<h3 class="note-heading" id="note-capital-movement">Capital Account Movement</h3>
<table class="note-table" border="1" width="100%">
    <thead>
    <tr><th>Particulars</th><th class="figure">Current Year</th><th class="figure">Previous Year</th></tr>
    </thead>
    <tbody>
    <tr><td>Opening Capital</td><td class="figure"><?= format_inr($data['prev_capital']) ?></td><td class="figure"><?= format_inr(0) ?></td></tr>
    <tr><td>Add: Capital Introduced</td><td class="figure"><?= format_inr($data['capital_introduced']) ?></td><td class="figure"><?= format_inr($data['prev_capital_introduced']) ?></td></tr>
    <tr><td>Less: Drawings</td><td class="figure"><?= format_inr($data['drawings']) ?></td><td class="figure"><?= format_inr($data['prev_drawings']) ?></td></tr>
<?php
$grossPft = $data['sales'] + $data['closing_stock_val'] - $data['opening_stock_val'] - $data['purchases'] - $data['direct_expenses'];
$prevGrossPft = $data['prev_sales'] + $data['prev_closing_stock_val'] - $data['prev_opening_stock_val'] - $data['prev_purchases'] - $data['prev_direct_expenses'];
$netProfit_val = ($isTrading ? max($grossPft, 0) : 0) + $data['revenue'] - $data['expenses'] - ($isTrading && $grossPft < 0 ? abs($grossPft) : 0);
$prevNetProfit_val = ($isTrading ? max($prevGrossPft, 0) : 0) + $data['prev_revenue'] - $data['prev_expenses'] - ($isTrading && $prevGrossPft < 0 ? abs($prevGrossPft) : 0);
?>
    <tr><td>Add: Net Profit</td><td class="figure"><?= format_inr(max($netProfit_val, 0)) ?></td><td class="figure"><?= format_inr(max($prevNetProfit_val, 0)) ?></td></tr>
<?php if ($netProfit_val < 0): ?>
    <tr><td>Less: Net Loss</td><td class="figure"><?= format_inr(abs($netProfit_val)) ?></td><td class="figure"><?= format_inr(abs($prevNetProfit_val)) ?></td></tr>
<?php endif; ?>
    <tr><td><strong>Closing Capital</strong></td><td class="figure"><strong><?= format_inr($data['capital']) ?></strong></td><td class="figure"><strong><?= format_inr($data['prev_capital']) ?></strong></td></tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php foreach (($notes['sections'] ?? []) as $index => $section): ?>
    <div class="note-block">
    <?php $noteNo = (int) ($section['note_no'] ?? ($index + 1)); ?>
    <h3 class="note-heading" id="note-<?= $noteNo ?>">Note <?= $noteNo ?>: <?= htmlspecialchars((string) ($section['title'] ?? ('Note ' . $noteNo))) ?></h3>
    <table class="note-table" border="1" width="100%">
        <thead>
        <tr><th>Ledger / Particulars</th><th class="figure">Current Year</th><th class="figure">Previous Year</th></tr>
        </thead>
        <tbody>
        <?php foreach (($section['lines'] ?? []) as $line): ?>
            <tr>
                <td><?= htmlspecialchars($line['label']) ?></td>
                <td class="figure"><?= format_inr((float) ($line['current'] ?? 0)) ?></td>
                <td class="figure"><?= format_inr((float) ($line['previous'] ?? 0)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td><strong>Total</strong></td>
            <td class="figure"><strong><?= format_inr((float) ($section['current_total'] ?? 0)) ?></strong></td>
            <td class="figure"><strong><?= format_inr((float) ($section['previous_total'] ?? 0)) ?></strong></td>
        </tr>
        </tfoot>
    </table>
    </div>
<?php endforeach; ?>

<h3>Accounting Policies</h3>
<ul class="note-policy-list">
<li>Accrual system</li>
<li>Going concern assumption</li>
<li>Consistency principle</li>
</ul>
</section>
