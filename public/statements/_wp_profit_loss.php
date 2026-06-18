<?php
/**
 * Working Paper: Profit & Loss / Income & Expenditure
 * Per-statement computation sheet for P&L tab
 * Expects: $fs, $data, $notes, $isFirstYear, $isCorporate, $entitySubcategory
 */
if (!isset($fs)) return;
$summary = $fs['summary'] ?? [];
$prevSummary = $fs['previous_summary'] ?? [];
$prev = function ($key) use ($prevSummary) { return $prevSummary[$key] ?? ''; };
$cur = function ($key) use ($summary) { return $summary[$key] ?? 0; };
$diff = function ($key) use ($summary, $prevSummary) {
    $c = (float)($summary[$key] ?? 0);
    $p = (float)($prevSummary[$key] ?? 0);
    return $c - $p;
};
$fmt = function ($v) { return $v === '' ? '--' : number_format((float)$v, 2); };
$hasPrev = !$isFirstYear;
$isTrust = in_array($entitySubcategory ?? '', ['trust', 'society'], true);
?>
<div class="fs-wp-section">
    <h4>1. Revenue Recognition</h4>
    <div class="fs-wp-derivation">Gross Sales - Returns - Discounts = Net Revenue</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><?php if ($hasPrev): ?><th>Previous</th><?php endif; ?><th>Current</th><th>Change</th></tr></thead>
        <tbody>
            <tr>
                <td>Revenue from Operations</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('revenue')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('revenue')) ?></td>
                <td class="amount"><?= $fmt($diff('revenue')) ?></td>
            </tr>
            <tr>
                <td>Other Income</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('other_income')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('other_income')) ?></td>
                <td class="amount"><?= $fmt($diff('other_income')) ?></td>
            </tr>
        </tbody>
        <tfoot>
            <tr><td>Total Income</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td></td><td class="amount"><?= $fmt(($cur('revenue') ?? 0) + ($cur('other_income') ?? 0)) ?></td></tr>
        </tfoot>
    </table>
</div>

<div class="fs-wp-section">
    <h4>2. Cost of Materials Consumed</h4>
    <div class="fs-wp-derivation">Opening RM + Purchases - Closing RM = Consumed</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><th>Amount</th></tr></thead>
        <tbody>
            <tr><td>Opening Raw Materials</td><td class="amount"><?= $fmt($cur('opening_raw_materials') ?? $prev('closing_raw_materials') ?? 0) ?></td></tr>
            <tr><td>Add: Purchases</td><td class="amount"><?= $fmt($cur('purchases') ?? 0) ?></td></tr>
            <tr><td>Less: Closing Raw Materials</td><td class="amount"><?= $fmt($cur('closing_raw_materials') ?? 0) ?></td></tr>
        </tbody>
        <tfoot>
            <tr><td>Materials Consumed</td><td class="amount"><?= $fmt($cur('materials_consumed') ?? 0) ?></td></tr>
        </tfoot>
    </table>
</div>

<div class="fs-wp-section">
    <h4>3. Employee Benefits</h4>
    <div class="fs-wp-derivation">Salary + PF + Gratuity + Leave Encashment</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><?php if ($hasPrev): ?><th>Previous</th><?php endif; ?><th>Current</th></tr></thead>
        <tbody>
            <tr>
                <td>Employee Benefit Expense</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('employee_cost')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('employee_cost')) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="fs-wp-section">
    <h4>4. Depreciation & Amortisation</h4>
    <div class="fs-wp-derivation">Asset class x Rate x Months = Depreciation</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><?php if ($hasPrev): ?><th>Previous</th><?php endif; ?><th>Current</th></tr></thead>
        <tbody>
            <tr>
                <td>Depreciation</td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($prev('depreciation')) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($cur('depreciation')) ?></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="fs-wp-section">
    <h4>5. PAT Tie-Out</h4>
    <div class="fs-wp-derivation">Revenue - Total Expenses = Profit Before Tax - Tax = Profit After Tax</div>
    <table class="fs-wp-table">
        <thead><tr><th>Component</th><?php if ($hasPrev): ?><th>Previous</th><?php endif; ?><th>Current</th></tr></thead>
        <tbody>
            <tr><td>Total Income</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td class="amount"><?= $fmt(($cur('revenue') ?? 0) + ($cur('other_income') ?? 0)) ?></td></tr>
            <tr><td>Total Expenses</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td class="amount"><?= $fmt($cur('total_expenses') ?? 0) ?></td></tr>
            <tr><td>Profit Before Tax</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td class="amount"><?= $fmt($cur('profit_before_tax') ?? 0) ?></td></tr>
            <tr><td>Tax Expense</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td class="amount"><?= $fmt($cur('tax') ?? 0) ?></td></tr>
        </tbody>
        <tfoot>
            <tr><td>Profit After Tax</td><?php if ($hasPrev): ?><td></td><?php endif; ?><td class="amount"><?= $fmt($cur('profit_after_tax') ?? 0) ?></td></tr>
        </tfoot>
    </table>
</div>
