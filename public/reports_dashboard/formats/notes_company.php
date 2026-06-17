<?php
$isFirstYear = (bool) ($isFirstYear ?? false);
function pv($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . \format_inr($val) . '</td>'; }
function pvRaw($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . $val . '</td>'; }
// $notes = array with structured values
?>

<section class="report-page notes-shell" id="notes-to-accounts">
<h2 class="report-page-title">Notes to Accounts</h2>
<p class="report-page-subtitle notes-cover">Detailed disclosures supporting the Balance Sheet and Statement of Profit &amp; Loss.</p>

<?php foreach (($notes['sections'] ?? []) as $index => $section): ?>
    <div class="note-block">
    <h3 class="note-heading" id="note-<?= (int) ($section['note_no'] ?? ($index + 1)) ?>">Note <?= (int) ($section['note_no'] ?? ($index + 1)) ?>: <?= htmlspecialchars($section['title']) ?></h3>

    <?php if (($section['custom_type'] ?? '') === 'inventory_change'): ?>
        <?php
        $opening = $section['opening'] ?? [];
        $closing = $section['closing'] ?? [];
        $openingPrev = $section['previous_opening'] ?? [];
        $closingPrev = $section['previous_closing'] ?? [];
        $openingTotal = array_sum($opening);
        $closingTotal = array_sum($closing);
        $openingPrevTotal = array_sum($openingPrev);
        $closingPrevTotal = array_sum($closingPrev);
        $finishedGoodsChange = (float) ($opening['finished_goods'] ?? 0) - (float) ($closing['finished_goods'] ?? 0);
        $wipChange = (float) ($opening['work_in_progress'] ?? 0) - (float) ($closing['work_in_progress'] ?? 0);
        $stockTradeChange = (float) ($opening['stock_in_trade'] ?? 0) - (float) ($closing['stock_in_trade'] ?? 0);
        $finishedGoodsPrevChange = (float) ($openingPrev['finished_goods'] ?? 0) - (float) ($closingPrev['finished_goods'] ?? 0);
        $wipPrevChange = (float) ($openingPrev['work_in_progress'] ?? 0) - (float) ($closingPrev['work_in_progress'] ?? 0);
        $stockTradePrevChange = (float) ($openingPrev['stock_in_trade'] ?? 0) - (float) ($closingPrev['stock_in_trade'] ?? 0);
        ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th>Particulars</th>
                <th class="figure">Current Year</th>
                <?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous Year</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <tr><td><b>Opening Stock</b></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= format_inr((float) ($opening['finished_goods'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($openingPrev['finished_goods'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= format_inr((float) ($opening['work_in_progress'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($openingPrev['work_in_progress'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= format_inr((float) ($opening['stock_in_trade'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($openingPrev['stock_in_trade'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td><b>Total Opening Stock</b></td><td class="figure"><?= format_inr($openingTotal) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($openingPrevTotal) ?></td><?php endif; ?></tr>

            <tr><td><b>Closing Stock</b></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= format_inr((float) ($closing['finished_goods'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($closingPrev['finished_goods'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= format_inr((float) ($closing['work_in_progress'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($closingPrev['work_in_progress'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= format_inr((float) ($closing['stock_in_trade'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr((float) ($closingPrev['stock_in_trade'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td><b>Total Closing Stock</b></td><td class="figure"><?= format_inr($closingTotal) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($closingPrevTotal) ?></td><?php endif; ?></tr>
            <tr><td><b>Net Change - Finished Goods</b></td><td class="figure"><?= format_inr($finishedGoodsChange) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($finishedGoodsPrevChange) ?></td><?php endif; ?></tr>
            <tr><td><b>Net Change - Work-in-Progress</b></td><td class="figure"><?= format_inr($wipChange) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($wipPrevChange) ?></td><?php endif; ?></tr>
            <tr><td><b>Net Change - Stock-in-Trade</b></td><td class="figure"><?= format_inr($stockTradeChange) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= format_inr($stockTradePrevChange) ?></td><?php endif; ?></tr>
            </tbody>
            <tfoot>
            <tr><td><b>Net Change (Opening - Closing)</b></td><td class="figure"><b><?= format_inr((float) ($section['current_total'] ?? 0)) ?></b></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><b><?= format_inr((float) ($section['previous_total'] ?? 0)) ?></b></td><?php endif; ?></tr>
            </tfoot>
        </table>
    <?php else: ?>
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
    <?php endif; ?>
    </div>
<?php endforeach; ?>

<h3>Significant Accounting Policies</h3>
<ul class="note-policy-list">
<li>Accrual basis of accounting</li>
<li>Historical cost convention</li>
<li>Depreciation method</li>
<li>Revenue recognition</li>
</ul>
</section>
