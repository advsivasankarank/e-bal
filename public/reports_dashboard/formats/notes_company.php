<?php
// $notes = array with structured values
?>

<h2 id="notes-to-accounts">NOTES TO ACCOUNTS</h2>

<?php foreach (($notes['sections'] ?? []) as $index => $section): ?>
    <h3 id="note-<?= (int) ($section['note_no'] ?? ($index + 1)) ?>">Note <?= (int) ($section['note_no'] ?? ($index + 1)) ?>: <?= htmlspecialchars($section['title']) ?></h3>

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
        <table border="1" width="100%" cellpadding="5">
            <tr>
                <th>Particulars</th>
                <th class="figure">Current Year</th>
                <th class="figure">Previous Year</th>
            </tr>
            <tr><td><b>Opening Stock</b></td><td></td><td></td></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= format_inr((float) ($opening['finished_goods'] ?? 0)) ?></td><td class="figure"><?= format_inr((float) ($openingPrev['finished_goods'] ?? 0)) ?></td></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= format_inr((float) ($opening['work_in_progress'] ?? 0)) ?></td><td class="figure"><?= format_inr((float) ($openingPrev['work_in_progress'] ?? 0)) ?></td></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= format_inr((float) ($opening['stock_in_trade'] ?? 0)) ?></td><td class="figure"><?= format_inr((float) ($openingPrev['stock_in_trade'] ?? 0)) ?></td></tr>
            <tr><td><b>Total Opening Stock</b></td><td class="figure"><?= format_inr($openingTotal) ?></td><td class="figure"><?= format_inr($openingPrevTotal) ?></td></tr>

            <tr><td><b>Closing Stock</b></td><td></td><td></td></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= format_inr((float) ($closing['finished_goods'] ?? 0)) ?></td><td class="figure"><?= format_inr((float) ($closingPrev['finished_goods'] ?? 0)) ?></td></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= format_inr((float) ($closing['work_in_progress'] ?? 0)) ?></td><td class="figure"><?= format_inr((float) ($closingPrev['work_in_progress'] ?? 0)) ?></td></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= format_inr((float) ($closing['stock_in_trade'] ?? 0)) ?></td><td class="figure"><?= format_inr((float) ($closingPrev['stock_in_trade'] ?? 0)) ?></td></tr>
            <tr><td><b>Total Closing Stock</b></td><td class="figure"><?= format_inr($closingTotal) ?></td><td class="figure"><?= format_inr($closingPrevTotal) ?></td></tr>
            <tr><td><b>Net Change - Finished Goods</b></td><td class="figure"><?= format_inr($finishedGoodsChange) ?></td><td class="figure"><?= format_inr($finishedGoodsPrevChange) ?></td></tr>
            <tr><td><b>Net Change - Work-in-Progress</b></td><td class="figure"><?= format_inr($wipChange) ?></td><td class="figure"><?= format_inr($wipPrevChange) ?></td></tr>
            <tr><td><b>Net Change - Stock-in-Trade</b></td><td class="figure"><?= format_inr($stockTradeChange) ?></td><td class="figure"><?= format_inr($stockTradePrevChange) ?></td></tr>
            <tr><td><b>Net Change (Opening - Closing)</b></td><td class="figure"><b><?= format_inr((float) ($section['current_total'] ?? 0)) ?></b></td><td class="figure"><b><?= format_inr((float) ($section['previous_total'] ?? 0)) ?></b></td></tr>
        </table>
    <?php else: ?>
        <table border="1" width="100%">
            <tr><th>Ledger / Particulars</th><th class="figure">Current Year</th><th class="figure">Previous Year</th></tr>
            <?php foreach (($section['lines'] ?? []) as $line): ?>
                <tr>
                    <td><?= htmlspecialchars($line['label']) ?></td>
                    <td class="figure"><?= format_inr((float) ($line['current'] ?? 0)) ?></td>
                    <td class="figure"><?= format_inr((float) ($line['previous'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Total</strong></td>
                <td class="figure"><strong><?= format_inr((float) ($section['current_total'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= format_inr((float) ($section['previous_total'] ?? 0)) ?></strong></td>
            </tr>
        </table>
    <?php endif; ?>
<?php endforeach; ?>

<h3>Significant Accounting Policies</h3>
<ul>
<li>Accrual basis of accounting</li>
<li>Historical cost convention</li>
<li>Depreciation method</li>
<li>Revenue recognition</li>
</ul>
