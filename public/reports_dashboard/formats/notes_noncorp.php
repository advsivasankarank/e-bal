<?php
?>

<h2 id="notes-to-accounts">NOTES TO ACCOUNTS</h2>

<?php foreach (($notes['sections'] ?? []) as $index => $section): ?>
    <h3 id="note-<?= $index + 1 ?>">Note <?= $index + 1 ?>: <?= htmlspecialchars($section['title']) ?></h3>
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
<?php endforeach; ?>

<h3>Accounting Policies</h3>
<ul>
<li>Accrual system</li>
<li>Going concern assumption</li>
<li>Consistency principle</li>
</ul>
