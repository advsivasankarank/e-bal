<?php
$isFirstYear = (bool) ($isFirstYear ?? false);
if (!function_exists('pv')) { function pv($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . \format_inr($val) . '</td>'; } }
if (!function_exists('pvRaw')) { function pvRaw($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . $val . '</td>'; } }
$llpNetProfit = max(($data['pbr'] ?? 0), 0);
$prevLlpNetProfit = max(($data['prev_pbr'] ?? 0), 0);
?>

<section class="report-page notes-shell" id="notes-to-accounts">
<h2 class="report-page-title">Notes to Accounts (LLP)</h2>
<p class="report-page-subtitle notes-cover">Detailed disclosures supporting the LLP financial statements.</p>

<?php $partnerScheduleRows = $notes['partner_capital_schedule']['rows'] ?? []; ?>
<?php if (!empty($partnerScheduleRows)): ?>
<div class="note-block">
<h3 class="note-heading" id="note-capital-movement">Note 3a: Partners' Capital Account</h3>
<table class="note-table" border="1" width="100%">
    <thead>
    <tr>
        <th>Partner</th><th class="figure">Share %</th><th class="figure">Opening</th>
        <th class="figure">Introduced</th><th class="figure">Remuneration</th><th class="figure">Interest</th>
        <th class="figure">Withdrawals</th><th class="figure">Share of Profit</th><th class="figure">Closing</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($partnerScheduleRows as $pRow): ?>
    <tr>
        <td><?= htmlspecialchars($pRow['partner_name']) ?></td>
        <td class="figure"><?= number_format((float) $pRow['share_percentage'], 2) ?>%</td>
        <td class="figure"><?= format_inr((float) $pRow['opening_balance']) ?></td>
        <td class="figure"><?= format_inr((float) $pRow['capital_introduced']) ?></td>
        <td class="figure"><?= format_inr((float) $pRow['remuneration']) ?></td>
        <td class="figure"><?= format_inr((float) $pRow['interest_on_capital']) ?></td>
        <td class="figure"><?= format_inr((float) $pRow['withdrawals']) ?></td>
        <td class="figure"><?= format_inr((float) $pRow['share_of_profit']) ?></td>
        <td class="figure"><strong><?= format_inr((float) $pRow['closing_balance']) ?></strong></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <?php $pTotals = $notes['partner_capital_schedule']['totals'] ?? null; if ($pTotals): ?>
    <tfoot>
    <tr>
        <td><strong>Total</strong></td>
        <td class="figure"><strong><?= number_format((float) $pTotals['share_percentage'], 2) ?>%</strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['opening_balance']) ?></strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['capital_introduced']) ?></strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['remuneration']) ?></strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['interest_on_capital']) ?></strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['withdrawals']) ?></strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['share_of_profit']) ?></strong></td>
        <td class="figure"><strong><?= format_inr((float) $pTotals['closing_balance']) ?></strong></td>
    </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>
<?php else: ?>
<div class="note-block">
<h3 class="note-heading" id="note-capital-movement">Capital Account Movement</h3>
<p style="font-size:0.85em;color:#64748b;margin:0 0 8px;">Aggregate view — add partners via the Partners' Capital Schedule page for a per-partner breakup (ICAI Note 3a format).</p>
<table class="note-table" border="1" width="100%">
    <thead>
    <tr><th>Particulars</th><th class="figure">Current Year</th><?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous Year</th><?php endif; ?></tr>
    </thead>
    <tbody>
    <tr><td>Opening Capital</td><td class="figure"><?= format_inr($data['prev_capital']) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr(0) ?></td><?php endif; ?></tr>
    <tr><td>Add: Capital Introduced</td><td class="figure"><?= format_inr($data['capital_introduced'] ?? 0) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_capital_introduced'] ?? 0) ?></td><?php endif; ?></tr>
    <tr><td>Add: Net Profit</td><td class="figure"><?= format_inr(max($data['pat'], 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr(max($data['prev_pat'], 0)) ?></td><?php endif; ?></tr>
    <tr><td>Less: Drawings</td><td class="figure"><?= format_inr($data['drawings'] ?? 0) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($data['prev_drawings'] ?? 0) ?></td><?php endif; ?></tr>
    <?php if ($data['pat'] < 0): ?>
    <tr><td>Less: Net Loss</td><td class="figure"><?= format_inr(abs($data['pat'])) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr(abs($data['prev_pat'])) ?></td><?php endif; ?></tr>
    <?php endif; ?>
    <tr><td><strong>Closing Capital</strong></td><td class="figure"><strong><?= format_inr($data['capital']) ?></strong></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><strong><?= format_inr($data['prev_capital']) ?></strong></td><?php endif; ?></tr>
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

<h3>Accounting Policies</h3>
<ul class="note-policy-list">
<li>Accrual basis</li>
<li>Revenue recognition</li>
<li>Depreciation policy</li>
</ul>
</section>
