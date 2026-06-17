<?php
$llpNetProfit = max(($data['pbr'] ?? 0), 0);
$prevLlpNetProfit = max(($data['prev_pbr'] ?? 0), 0);
?>

<section class="report-page notes-shell" id="notes-to-accounts">
<h2 class="report-page-title">Notes to Accounts (LLP)</h2>
<p class="report-page-subtitle notes-cover">Detailed disclosures supporting the LLP financial statements.</p>

<div class="note-block">
<h3 class="note-heading" id="note-capital-movement">Capital Account Movement</h3>
<table class="note-table" border="1" width="100%">
    <thead>
    <tr><th>Particulars</th><th class="figure">Current Year</th><th class="figure">Previous Year</th></tr>
    </thead>
    <tbody>
    <tr><td>Opening Capital</td><td class="figure"><?= format_inr($data['prev_capital']) ?></td><td class="figure"><?= format_inr(0) ?></td></tr>
    <tr><td>Add: Capital Introduced</td><td class="figure"><?= format_inr($data['capital_introduced'] ?? 0) ?></td><td class="figure"><?= format_inr($data['prev_capital_introduced'] ?? 0) ?></td></tr>
    <tr><td>Add: Net Profit</td><td class="figure"><?= format_inr(max($data['pat'], 0)) ?></td><td class="figure"><?= format_inr(max($data['prev_pat'], 0)) ?></td></tr>
    <tr><td>Less: Drawings</td><td class="figure"><?= format_inr($data['drawings'] ?? 0) ?></td><td class="figure"><?= format_inr($data['prev_drawings'] ?? 0) ?></td></tr>
    <?php if ($data['pat'] < 0): ?>
    <tr><td>Less: Net Loss</td><td class="figure"><?= format_inr(abs($data['pat'])) ?></td><td class="figure"><?= format_inr(abs($data['prev_pat'])) ?></td></tr>
    <?php endif; ?>
    <tr><td><strong>Closing Capital</strong></td><td class="figure"><strong><?= format_inr($data['capital']) ?></strong></td><td class="figure"><strong><?= format_inr($data['prev_capital']) ?></strong></td></tr>
    </tbody>
</table>
</div>

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
<li>Accrual basis</li>
<li>Revenue recognition</li>
<li>Depreciation policy</li>
</ul>
</section>
