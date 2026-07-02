<?php
/**
 * Working Paper: Notes to Accounts
 * Per-statement derivation sheet for Notes tab
 * Expects: $fs, $notes, $isFirstYear, $entitySubcategory
 */
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
if (!isset($fs) || !isset($notes)) return;
$sections = $notes['sections'] ?? [];
$fmt = function ($v) { return $v === '' || $v === null ? '--' : format_inr_number((float)$v); };
$hasPrev = !$isFirstYear;
?>
<?php if (empty($sections)): ?>
<div class="fs-wp-section">
    <h4>No Note Data Available</h4>
    <div class="fs-wp-derivation">Complete ledger mapping and trial balance import to generate note derivations.</div>
</div>
<?php else: ?>
<?php foreach ($sections as $section):
    $noteNo = $section['note_no'] ?? 0;
    $title = $section['title'] ?? 'Untitled';
    $lines = $section['lines'] ?? [];
    $currentTotal = $section['current_total'] ?? 0;
    $previousTotal = $section['previous_total'] ?? 0;
?>
<div class="fs-wp-section">
    <h4>Note <?= htmlspecialchars($noteNo) ?>: <?= htmlspecialchars($title) ?> - Derivation</h4>
    <div class="fs-wp-derivation">Source: Ledger balances mapped to schedule codes for this note</div>
    <table class="fs-wp-table">
        <thead>
            <tr>
                <th>Line Item</th>
                <?php if ($hasPrev): ?><th>Previous Year</th><?php endif; ?>
                <th>Current Year</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line):
                $label = $line['label'] ?? '';
                $current = $line['current'] ?? 0;
                $previous = $line['previous'] ?? '';
            ?>
            <tr>
                <td><?= htmlspecialchars($label) ?></td>
                <?php if ($hasPrev): ?><td class="amount"><?= $fmt($previous) ?></td><?php endif; ?>
                <td class="amount"><?= $fmt($current) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <?php if ($hasPrev): ?><td class="amount"><strong><?= $fmt($previousTotal) ?></strong></td><?php endif; ?>
                <td class="amount"><strong><?= $fmt($currentTotal) ?></strong></td>
            </tr>
        </tfoot>
    </table>
</div>
<?php endforeach; ?>
<?php endif; ?>
