<?php
// Previous Year column is ALWAYS shown — use ₹0.00 if no prior data.
if (!function_exists('pv')) { function pv($val) { return '<td class="figure previous-year">' . \format_inr($val ?? 0) . '</td>'; } }
if (!function_exists('pvRaw')) { function pvRaw($val) { return '<td class="figure previous-year">' . ($val ?? '') . '</td>'; } }

$ebalCompanyName = $company_meta['company_name'] ?? '';
$ebalCin = $company_meta['cin'] ?? '';
$ebalAddress = $company_meta['registered_address'] ?? '';
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
    /* Share Capital with share-count/shareholder detail is not empty even if the
       rupee lines happen to be zero (e.g. face value entered but no amount yet) */
    if (($section['custom_type'] ?? '') === 'share_capital') {
        if (!empty($section['share_classes']) || !empty($section['shareholders_above_5pct'])) {
            $isEmpty = false;
        }
    }
    /* Manual disclosure notes (Contingent Liabilities, Commitments, MSME,
       Related Party) always carry text -- default boilerplate at minimum --
       so they're never "empty" even though they have no rupee lines. */
    if (($section['custom_type'] ?? '') === 'manual_disclosure') {
        $isEmpty = trim((string) ($section['disclosure_text'] ?? '')) === '';
    }
?>
    <div class="note-block" id="note-<?= $noteNo ?>">
    <h3 class="note-heading">
        Note <?= $noteNo ?>: <?= htmlspecialchars($title) ?>
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
                <th class="particulars">Particulars</th>
                <th class="figure current-year">Current Year</th>
                <th class="figure previous-year">Previous Year</th>
            </tr>
            </thead>
            <tbody>
            <tr><td><b>Opening Stock</b></td><td></td><td></td></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= \format_inr((float) ($opening['finished_goods'] ?? 0)) ?></td><td class="figure previous-year"><?= \format_inr((float) ($openingPrev['finished_goods'] ?? 0)) ?></td></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= \format_inr((float) ($opening['work_in_progress'] ?? 0)) ?></td><td class="figure previous-year"><?= \format_inr((float) ($openingPrev['work_in_progress'] ?? 0)) ?></td></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= \format_inr((float) ($opening['stock_in_trade'] ?? 0)) ?></td><td class="figure previous-year"><?= \format_inr((float) ($openingPrev['stock_in_trade'] ?? 0)) ?></td></tr>
            <tr><td><b>Total Opening Stock</b></td><td class="figure"><?= \format_inr($openingTotal) ?></td><td class="figure previous-year"><?= \format_inr($openingPrevTotal) ?></td></tr>

            <tr><td><b>Closing Stock</b></td><td></td><td></td></tr>
            <tr><td>Finished Goods</td><td class="figure"><?= \format_inr((float) ($closing['finished_goods'] ?? 0)) ?></td><td class="figure previous-year"><?= \format_inr((float) ($closingPrev['finished_goods'] ?? 0)) ?></td></tr>
            <tr><td>Work-in-Progress</td><td class="figure"><?= \format_inr((float) ($closing['work_in_progress'] ?? 0)) ?></td><td class="figure previous-year"><?= \format_inr((float) ($closingPrev['work_in_progress'] ?? 0)) ?></td></tr>
            <tr><td>Stock-in-Trade</td><td class="figure"><?= \format_inr((float) ($closing['stock_in_trade'] ?? 0)) ?></td><td class="figure previous-year"><?= \format_inr((float) ($closingPrev['stock_in_trade'] ?? 0)) ?></td></tr>
            <tr><td><b>Total Closing Stock</b></td><td class="figure"><?= \format_inr($closingTotal) ?></td><td class="figure previous-year"><?= \format_inr($closingPrevTotal) ?></td></tr>
            </tbody>
            <tfoot>
            <tr><td><b>Net Change (Opening - Closing)</b></td><td class="figure"><b><?= \format_inr((float) ($section['current_total'] ?? 0)) ?></b></td><td class="figure previous-year"><b><?= \format_inr((float) ($section['previous_total'] ?? 0)) ?></b></td></tr>
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
                <th class="particulars">Particulars</th>
                <th class="figure current-year">Current Year</th>
                <th class="figure previous-year">Previous Year</th>
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
                <td class="figure previous-year"><?= \format_inr((float) ($line['previous'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            <tr>
                <td>Opening balance in Profit and Loss Account</td>
                <td class="figure"><?= \format_inr($openingBal) ?></td>
                <td class="figure previous-year"><?= \format_inr($prevOpeningBal) ?></td>
            </tr>
            <tr>
                <td>Add: Profit / (Loss) for the year</td>
                <td class="figure"><?= \format_inr($movement) ?></td>
                <td class="figure previous-year"><?= \format_inr($prevMovement) ?></td>
            </tr>
            <tr>
                <td>Closing balance in Profit and Loss Account</td>
                <td class="figure"><?= \format_inr($closingBal) ?></td>
                <td class="figure previous-year"><?= \format_inr($prevClosingBal) ?></td>
            </tr>
            </tbody>
            <tfoot>
            <tr><td><b>Total</b></td><td class="figure"><b><?= \format_inr($currentTotal) ?></b></td><td class="figure previous-year"><b><?= \format_inr($previousTotal) ?></b></td></tr>
            </tfoot>
        </table>
    <?php elseif (($section['custom_type'] ?? '') === 'manual_disclosure'): ?>
        <p><?= nl2br(htmlspecialchars((string) ($section['disclosure_text'] ?? ''))) ?></p>
    <?php elseif (($section['custom_type'] ?? '') === 'depreciation_schedule'): ?>
        <?php $schedule = $section['schedule'] ?? ['by_category' => [], 'totals' => []]; ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th class="particulars" rowspan="2">Asset Category</th>
                <th class="figure" colspan="4">Gross Block</th>
                <th class="figure" colspan="3">Accumulated Depreciation</th>
                <th class="figure" rowspan="2">Net Block<br>(Current Year)</th>
            </tr>
            <tr>
                <th class="figure">Opening</th>
                <th class="figure">Additions</th>
                <th class="figure">Disposals</th>
                <th class="figure">Closing</th>
                <th class="figure">Opening</th>
                <th class="figure">For the Year</th>
                <th class="figure">Closing</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (($schedule['by_category'] ?? []) as $cat): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($cat['category'] ?? '')) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['opening_gross_block'] ?? 0)) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['additions'] ?? 0)) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['disposals'] ?? 0)) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['closing_gross_block'] ?? 0)) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['opening_accumulated_depreciation'] ?? 0)) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['depreciation_for_year'] ?? 0)) ?></td>
                <td class="figure"><?= \format_inr((float) ($cat['closing_accumulated_depreciation'] ?? 0)) ?></td>
                <td class="figure"><b><?= \format_inr((float) ($cat['closing_wdv'] ?? 0)) ?></b></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['opening_gross_block'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['additions'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['disposals'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['closing_gross_block'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['opening_accumulated_depreciation'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['depreciation_for_year'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['closing_accumulated_depreciation'] ?? 0)) ?></strong></td>
                <td class="figure"><strong><?= \format_inr((float) ($schedule['totals']['closing_wdv'] ?? 0)) ?></strong></td>
            </tr>
            </tfoot>
        </table>
        <p style="font-size:0.85rem;color:#64748b;">Depreciation computed under Schedule II to the Companies Act, 2013 (Straight-Line or Written-Down Value method, per asset category), with pro-rata charge for assets acquired or disposed during the year. See asset_register.php for the underlying asset-level detail.</p>
    <?php elseif (($section['custom_type'] ?? '') === 'eps'): ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th class="particulars">Particulars</th>
                <th class="figure current-year">Current Year</th>
                <th class="figure previous-year">Previous Year</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $i => $line): ?>
                <tr>
                    <td><?= htmlspecialchars($line['label'] ?? '') ?></td>
                    <?php if ($i === 1): ?>
                        <td class="figure"><?= number_format((float) ($line['current'] ?? 0), 0) ?></td>
                        <td class="figure previous-year"><?= number_format((float) ($line['previous'] ?? 0), 0) ?></td>
                    <?php else: ?>
                        <td class="figure"><?= \format_inr((float) ($line['current'] ?? 0)) ?></td>
                        <td class="figure previous-year"><?= \format_inr((float) ($line['previous'] ?? 0)) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="font-size:0.85rem;color:#64748b;">Weighted average number of equity shares is approximated as the simple average of opening and closing equity share counts for the year, in the absence of exact issue/buy-back dates.</p>
    <?php else: ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th class="particulars">Ledger / Particulars</th>
                <th class="figure current-year">Current Year</th>
                <th class="figure previous-year">Previous Year</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= htmlspecialchars($line['label'] ?? '') ?></td>
                    <td class="figure"><?= \format_inr((float) ($line['current'] ?? 0)) ?></td>
                    <td class="figure previous-year"><?= \format_inr((float) ($line['previous'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td class="figure"><strong><?= \format_inr($currentTotal) ?></strong></td>
                <td class="figure previous-year"><strong><?= \format_inr($previousTotal) ?></strong></td>
            </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <?php if (($section['custom_type'] ?? '') === 'share_capital'): ?>
        <?php
        $shareClasses = $section['share_classes'] ?? [];
        $shareholdersAbove5Pct = $section['shareholders_above_5pct'] ?? [];
        $totalAuthorisedShares = array_sum(array_column($shareClasses, 'authorised_shares'));
        $totalAuthorisedAmount = array_sum(array_column($shareClasses, 'authorised_amount'));
        $totalClosingShares = array_sum(array_column($shareClasses, 'closing_shares'));
        $totalClosingAmount = array_sum(array_column($shareClasses, 'closing_amount'));
        $prevTotalAuthorisedShares = array_sum(array_column($shareClasses, 'previous_authorised_shares'));
        $prevTotalAuthorisedAmount = array_sum(array_column($shareClasses, 'previous_authorised_amount'));
        $prevTotalClosingShares = array_sum(array_column($shareClasses, 'previous_closing_shares'));
        $prevTotalClosingAmount = array_sum(array_column($shareClasses, 'previous_closing_amount'));
        ?>
        <?php if (!empty($shareClasses)): ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th class="particulars">Share Type</th>
                <th class="figure">Face Value</th>
                <th class="figure" colspan="2">Authorised (Current Year)</th>
                <th class="figure" colspan="2">Issued, Subscribed &amp; Paid-up (Current Year)</th>
                <th class="figure" colspan="2">Issued, Subscribed &amp; Paid-up (Previous Year)</th>
            </tr>
            <tr>
                <th class="particulars"></th>
                <th class="figure"></th>
                <th class="figure">No. of Shares</th>
                <th class="figure">Amount</th>
                <th class="figure">No. of Shares</th>
                <th class="figure">Amount</th>
                <th class="figure">No. of Shares</th>
                <th class="figure">Amount</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($shareClasses as $cls): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($cls['share_type'] ?? '')) ?></td>
                <td class="figure"><?= \format_inr((float) ($cls['face_value'] ?? 0)) ?></td>
                <td class="figure"><?= number_format((float) ($cls['authorised_shares'] ?? 0), 0) ?></td>
                <td class="figure"><?= \format_inr((float) ($cls['authorised_amount'] ?? 0)) ?></td>
                <td class="figure"><?= number_format((float) ($cls['closing_shares'] ?? 0), 0) ?></td>
                <td class="figure"><?= \format_inr((float) ($cls['closing_amount'] ?? 0)) ?></td>
                <td class="figure previous-year"><?= number_format((float) ($cls['previous_closing_shares'] ?? 0), 0) ?></td>
                <td class="figure previous-year"><?= \format_inr((float) ($cls['previous_closing_amount'] ?? 0)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td><b>Total</b></td>
                <td class="figure"></td>
                <td class="figure"><b><?= number_format($totalAuthorisedShares, 0) ?></b></td>
                <td class="figure"><b><?= \format_inr($totalAuthorisedAmount) ?></b></td>
                <td class="figure"><b><?= number_format($totalClosingShares, 0) ?></b></td>
                <td class="figure"><b><?= \format_inr($totalClosingAmount) ?></b></td>
                <td class="figure previous-year"><b><?= number_format($prevTotalClosingShares, 0) ?></b></td>
                <td class="figure previous-year"><b><?= \format_inr($prevTotalClosingAmount) ?></b></td>
            </tr>
            </tfoot>
        </table>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th class="particulars">Reconciliation of Number of Shares</th>
                <?php foreach ($shareClasses as $cls): ?>
                <th class="figure"><?= htmlspecialchars((string) ($cls['share_type'] ?? '')) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>Shares Outstanding at the Beginning of the Year</td>
                <?php foreach ($shareClasses as $cls): ?>
                <td class="figure"><?= number_format((float) ($cls['opening_shares'] ?? 0), 0) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td>Add: Issued During the Year</td>
                <?php foreach ($shareClasses as $cls): ?>
                <td class="figure"><?= number_format((float) ($cls['issued_during_year'] ?? 0), 0) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td>Less: Bought Back During the Year</td>
                <?php foreach ($shareClasses as $cls): ?>
                <td class="figure"><?= number_format((float) ($cls['bought_back_during_year'] ?? 0), 0) ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <td><b>Shares Outstanding at the End of the Year</b></td>
                <?php foreach ($shareClasses as $cls): ?>
                <td class="figure"><b><?= number_format((float) ($cls['closing_shares'] ?? 0), 0) ?></b></td>
                <?php endforeach; ?>
            </tr>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if (!empty($shareholdersAbove5Pct)): ?>
        <table class="note-table" border="1" width="100%" cellpadding="5">
            <thead>
            <tr>
                <th class="particulars">Shareholder Holding &gt;5% of Shares</th>
                <th class="figure">Shares Held</th>
                <th class="figure">% Holding</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($shareholdersAbove5Pct as $shareholder): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($shareholder['name'] ?? '')) ?></td>
                <td class="figure"><?= number_format((float) ($shareholder['shares'] ?? 0), 0) ?></td>
                <td class="figure"><?= number_format((float) ($shareholder['percent'] ?? 0), 2) ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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

<?php
require_once __DIR__ . '/../../../app/reports/accounting_policies.php';
$depreciationInfo = null;
foreach (($notes['sections'] ?? []) as $section) {
    if (($section['custom_type'] ?? '') === 'depreciation_schedule') {
        $depreciationInfo = [
            'methods_used' => $section['schedule']['methods_used'] ?? [],
            'has_excel_import' => $section['schedule']['has_excel_import'] ?? false,
        ];
        break;
    }
}
?>
<h3 class="note-heading">Significant Accounting Policies</h3>
<ol class="note-policy-list">
<?php foreach (getAccountingPolicies('corporate', $depreciationInfo) as $policy): ?>
<li><?= htmlspecialchars($policy) ?></li>
<?php endforeach; ?>
</ol>
</section>
