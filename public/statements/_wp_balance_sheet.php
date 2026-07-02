<?php
/**
 * Working Paper: Balance Sheet
 * Per-statement computation sheet for BS tab
 * Expects: $fs, $data, $notes, $isFirstYear, $isCorporate, $entitySubcategory, $companyName, $fyName
 */
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
if (!isset($fs)) return;
$company_meta = $fs['company_meta'] ?? [];
$summary = $fs['summary'] ?? [];
$prevSummary = $fs['previous_summary'] ?? [];
$prev = function ($key) use ($prevSummary) { return $prevSummary[$key] ?? ''; };
$cur = function ($key) use ($summary) { return $summary[$key] ?? 0; };
$diff = function ($key) use ($summary, $prevSummary) {
    $c = (float)($summary[$key] ?? 0);
    $p = (float)($prevSummary[$key] ?? 0);
    return $c - $p;
};
$fmt = function ($v) { return $v === '' ? '--' : format_inr_number((float)$v); };
$hasPrev = !$isFirstYear;
$wpCompanyName = $companyName ?? ($company_meta['company_name'] ?? 'Company');
$wpFy = $fyName ?? '';
$wpGenTime = date('d M Y H:i:s');
?>

<!-- Working Paper Header -->
<div class="ebal-wp-header">
    <div class="ebal-wp-badge">e-BAL Working Paper</div>
    <div class="ebal-wp-meta">
        <strong><?= htmlspecialchars($wpCompanyName) ?></strong> &middot;
        FY: <?= htmlspecialchars($wpFy) ?> &middot;
        Generated: <?= $wpGenTime ?>
    </div>
</div>

<div class="fs-wp-section">
    <h4>1. Share Capital Movement</h4>
    <div class="fs-wp-derivation">Opening + Issued during year - Buyback = Closing</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><th>Amount</th></tr></thead>
        <tbody>
            <?php if ($isCorporate): ?>
            <tr><td>Authorised Capital</td><td class="amount"><?= $fmt($cur('share_capital_authorised')) ?></td></tr>
            <tr><td>Issued Capital</td><td class="amount"><?= $fmt($cur('share_capital_issued')) ?></td></tr>
            <tr><td>Paid-up Capital</td><td class="amount"><?= $fmt($cur('share_capital_paidup')) ?></td></tr>
            <?php else: ?>
            <tr><td>Opening Capital</td><td class="amount"><?= $fmt($prev('capital')) ?></td></tr>
            <tr><td>Capital Introduced</td><td class="amount"><?= $fmt($cur('capital_introduced') ?? 0) ?></td></tr>
            <tr><td>Net Profit (Loss)</td><td class="amount"><?= $fmt($cur('net_profit') ?? 0) ?></td></tr>
            <tr><td>Drawings</td><td class="amount"><?= $fmt($cur('drawings') ?? 0) ?></td></tr>
            <tr><td>Closing Capital</td><td class="amount"><?= $fmt($cur('capital')) ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="fs-wp-section">
    <h4>2. Reserves & Surplus (Corporate)</h4>
    <div class="fs-wp-derivation">P&L Opening + Profit After Tax - Dividends = P&L Closing</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><?php if ($hasPrev): ?><th>Previous Year</th><?php endif; ?><th>Current Year</th><th>Change</th></tr></thead>
        <tbody>
            <tr>
                <td>Opening P&L Balance</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('reserves')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('reserves')) ?></td>
                <td class="amount"><?= $fmt($diff('reserves')) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="fs-wp-section">
    <h4>3. Borrowings Reconciliation</h4>
    <div class="fs-wp-derivation">Opening + New Borrowings - Repayments = Closing</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><?php if ($hasPrev): ?><th>Previous</th><?php endif; ?><th>Current</th><th>Change</th></tr></thead>
        <tbody>
            <tr>
                <td>Long-term Borrowings</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('lt_borrowings')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('lt_borrowings')) ?></td>
                <td class="amount"><?= $fmt($diff('lt_borrowings')) ?></td>
            </tr>
            <tr>
                <td>Short-term Borrowings</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('st_borrowings')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('st_borrowings')) ?></td>
                <td class="amount"><?= $fmt($diff('st_borrowings')) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="fs-wp-section">
    <h4>4. BS Identity Tie-Out</h4>
    <div class="fs-wp-derivation">Total Equity + Liabilities = Total Assets</div>
    <table class="fs-wp-table">
        <thead><tr><th>Side</th><?php if ($hasPrev): ?><th>Previous</th><?php endif; ?><th>Current</th></tr></thead>
        <tbody>
            <tr>
                <td>Total Equity & Liabilities</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('total_equity_liabilities') ?? $prev('total_assets') ?? '') ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('total_equity_liabilities') ?? $cur('total_assets') ?? 0) ?></td>
            </tr>
            <tr>
                <td>Total Assets</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('total_assets') ?? '') ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('total_assets') ?? 0) ?></td>
            </tr>
        </tbody>
        <tfoot>
            <tr><td>Difference</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td class="amount" style="color: <?= abs((float)($fs['validation']['current_balance_difference'] ?? 0)) < 0.01 ? '#16a34a' : '#dc2626' ?>"><?= $fmt($fs['validation']['current_balance_difference'] ?? 0) ?></td></tr>
        </tfoot>
    </table>
</div>
