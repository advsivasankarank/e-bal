<?php
$isFirstYear = (bool) ($isFirstYear ?? false);
if (!function_exists('pv')) { function pv($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . \format_inr($val) . '</td>'; } }
if (!function_exists('pvRaw')) { function pvRaw($val) { global $isFirstYear; if ($isFirstYear) return ''; return '<td class="figure" data-prev>' . $val . '</td>'; } }

$ebalCompanyName = $company_meta['company_name'] ?? '';
$ebalCin = $company_meta['cin'] ?? '';
$ebalAddress = $company_meta['registered_address'] ?? '';
$prevHNote = $isFirstYear ? '' : '<th class="figure" data-prev>Previous</th>';
?>

<section class="report-page notes-shell" id="notes-to-accounts">

<!-- Company Header for Notes -->
<div class="ebal-stat-header ebal-stat-header--compact">
    <div class="ebal-company-name"><?= htmlspecialchars($ebalCompanyName ?: 'Company Name Not Configured') ?></div>
    <?php if ($ebalCin): ?>
    <div class="ebal-company-detail">CIN: <?= htmlspecialchars($ebalCin) ?></div>
    <?php else: ?>
    <div class="ebal-company-detail ebal-placeholder">CIN: Not configured</div>
    <?php endif; ?>
</div>

<h2 class="report-page-title">Notes to Financial Statements</h2>
<p class="report-page-subtitle notes-cover">Detailed disclosures supporting the Balance Sheet and Statement of Profit &amp; Loss.</p>

<?php if (empty($notes['sections'] ?? [])): ?>
    <div class="ebal-empty-note">No reportable balance / disclosure for the year.</div>
<?php else: ?>
<?php foreach (($notes['sections'] ?? []) as $index => $section):
    $noteNo = (int) ($section['note_no'] ?? ($index + 1));
    $title = $section['title'] ?? 'Note ' . $noteNo;
    $lines = $section['lines'] ?? [];
    $currentTotal = (float) ($section['current_total'] ?? 0);
    $previousTotal = (float) ($section['previous_total'] ?? 0);
    $isBranchDiv = ($section['custom_type'] ?? '') === 'branch_divisions';

    /* Check if this is a truly empty note */
    $isEmpty = true;
    foreach ($lines as $line) {
        if ((float) ($line['current'] ?? 0) != 0.0 || (float) ($line['previous'] ?? 0) != 0.0) {
            $isEmpty = false;
            break;
        }
    }
    /* Note 2 (Other Equity) with movement data is not empty */
    if (($section['custom_type'] ?? '') === 'other_equity' && abs($currentTotal) > 0.001) {
        $isEmpty = false;
    }
    /* Branch/Divisions with non-zero balance is not empty */
    if ($isBranchDiv && abs($currentTotal) > 0.001) {
        $isEmpty = false;
    }
?>
    <div class="note-block" id="note-<?= $noteNo ?>">
    <h3 class="note-heading">
        Note <?= $noteNo ?>: <?= htmlspecialchars($title) ?>
        <span class="note-collapse-icon">&#9660;</span>
    </h3>

    <?php if ($isEmpty): ?>
        <p class="ebal-empty-note">No reportable balance / disclosure for the year.</p>
    <?php elseif (($section['custom_type'] ?? '') === 'inventory_change'): ?>
        <?php
        $opening = $section['opening'] ?? [];
        $closing = $section['closing'] ?? [];
        $openingPrev = $section['previous_opening'] ?? [];
        $closingPrev = $section['previous_closing'] ?? [];
        $openingTotal = array_sum($opening);
        $closingTotal = array_sum($closing);
        $openingPrevTotal = array_sum($openingPrev);
        $closingPrevTotal = array_sum($closingPrev);
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
            <tr><td>Finished Goods</td><td class="figure"><?= \format_inr((float) ($opening['finished_goods'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($openingPrev['finished_goods'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= \format_inr((float) ($opening['work_in_progress'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($openingPrev['work_in_progress'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= \format_inr((float) ($opening['stock_in_trade'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($openingPrev['stock_in_trade'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td><b>Total Opening Stock</b></td><td class="figure"><?= \format_inr($openingTotal) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr($openingPrevTotal) ?></td><?php endif; ?></tr>

            <tr><td><b>Closing Stock</b></td><td></td><?php if (!$isFirstYear): ?><td data-prev></td><?php endif; ?></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= \format_inr((float) ($closing['finished_goods'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($closingPrev['finished_goods'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= \format_inr((float) ($closing['work_in_progress'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($closingPrev['work_in_progress'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= \format_inr((float) ($closing['stock_in_trade'] ?? 0)) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($closingPrev['stock_in_trade'] ?? 0)) ?></td><?php endif; ?></tr>
            <tr><td><b>Total Closing Stock</b></td><td class="figure"><?= \format_inr($closingTotal) ?></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr($closingPrevTotal) ?></td><?php endif; ?></tr>
            </tbody>
            <tfoot>
            <tr><td><b>Net Change (Opening - Closing)</b></td><td class="figure"><b><?= \format_inr((float) ($section['current_total'] ?? 0)) ?></b></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><b><?= \format_inr((float) ($section['previous_total'] ?? 0)) ?></b></td><?php endif; ?></tr>
            </tfoot>
        </table>
    <?php elseif (($section['custom_type'] ?? '') === 'other_equity' && isset($section['opening_balance'])): ?>
        <?php
        /* Note 2: Reserves & Surplus — Movement schedule */
        $openingBal = (float) ($section['opening_balance'] ?? 0);
        $movement = (float) ($section['movement'] ?? 0);
        $closingBal = $openingBal + $movement;
        $prevOpeningBal = (float) ($section['previous_opening_balance'] ?? 0);
        $prevMovement = (float) ($section['previous_movement'] ?? 0);
        $prevClosingBal = $prevOpeningBal + $prevMovement;
        /* Show only non-P&L reserve lines + the P&L movement schedule */
        $hasOtherReserves = false;
        foreach ($lines as $line) {
            if (stripos($line['label'] ?? '', 'profit') === false && stripos($line['label'] ?? '', 'p&l') === false && stripos($line['label'] ?? '', 'opening balance') === false && stripos($line['label'] ?? '', 'closing balance') === false && stripos($line['label'] ?? '', 'profit') === false && (float) ($line['current'] ?? 0) != 0.0) {
                $hasOtherReserves = true;
                break;
            }
        }
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
            <?php if ($hasOtherReserves): ?>
            <?php foreach ($lines as $line):
                $label = $line['label'] ?? '';
                if (stripos($label, 'opening balance') !== false || stripos($label, 'closing balance') !== false || stripos($label, 'profit') !== false || stripos($label, 'p&l') !== false) continue;
                if ((float) ($line['current'] ?? 0) == 0.0 && (float) ($line['previous'] ?? 0) == 0.0) continue;
            ?>
            <tr>
                <td><?= htmlspecialchars($label) ?></td>
                <td class="figure"><?= \format_inr((float) ($line['current'] ?? 0)) ?></td>
                <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($line['previous'] ?? 0)) ?></td><?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            <tr>
                <td>Opening balance in Profit and Loss Account</td>
                <td class="figure"><?= \format_inr($openingBal) ?></td>
                <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr($prevOpeningBal) ?></td><?php endif; ?>
            </tr>
            <tr>
                <td>Add: Profit / (Loss) for the year</td>
                <td class="figure"><?= \format_inr($movement) ?></td>
                <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr($prevMovement) ?></td><?php endif; ?>
            </tr>
            <tr>
                <td>Closing balance in Profit and Loss Account</td>
                <td class="figure"><?= \format_inr($closingBal) ?></td>
                <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr($prevClosingBal) ?></td><?php endif; ?>
            </tr>
            </tbody>
            <tfoot>
            <tr><td><b>Total</b></td><td class="figure"><b><?= \format_inr($currentTotal) ?></b></td><?php if (!$isFirstYear): ?><td class="figure" data-prev><b><?= \format_inr($previousTotal) ?></b></td><?php endif; ?></tr>
            </tfoot>
        </table>
    <?php else: ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th>Ledger / Particulars</th>
                <th class="figure">Current Year</th>
                <?php if (!$isFirstYear): ?><th class="figure" data-prev>Previous Year</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= htmlspecialchars($line['label'] ?? '') ?></td>
                    <td class="figure"><?= \format_inr((float) ($line['current'] ?? 0)) ?></td>
                    <?php if (!$isFirstYear): ?><td class="figure" data-prev><?= \format_inr((float) ($line['previous'] ?? 0)) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="figure"><strong><?= \format_inr($currentTotal) ?></strong></td>
                <?php if (!$isFirstYear): ?><td class="figure" data-prev><strong><?= \format_inr($previousTotal) ?></strong></td><?php endif; ?>
            </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if (!empty($section['disclosure'])): ?>
    <div class="ebal-disclosure-box">
        <strong>Disclosure:</strong> <?= htmlspecialchars($section['disclosure']) ?>
    </div>
    <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php endif; ?>

<!-- e-BAL Footer Branding -->
<div class="ebal-footer-brand">Generated by e-BAL Financial Reporting Workspace</div>

<h3>Significant Accounting Policies</h3>
<ul class="note-policy-list">
<li>Accrual basis of accounting</li>
<li>Historical cost convention</li>
<li>Depreciation method</li>
<li>Revenue recognition</li>
</ul>
</section>
