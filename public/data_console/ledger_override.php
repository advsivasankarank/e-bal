<?php
require_once '../../app/context_check.php';
require_once '../../config/database.php';
requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

$stmt = $pdo->prepare("
    SELECT t.ledger_name, t.parent_group, lm.mapped_code
    FROM tally_ledgers t
    LEFT JOIN ledger_mapping lm
        ON lm.ledger_name=t.ledger_name
        AND lm.company_id=t.company_id
        AND lm.fy_id=t.fy_id
    WHERE t.company_id=? AND t.fy_id=?
");
$stmt->execute([$company_id,$fy_id]);
$rows = $stmt->fetchAll();
?>

<form method="post" action="save_override.php">
<table border="1">
<tr><th>Ledger</th><th>Group</th><th>Override</th></tr>

<?php foreach ($rows as $r): ?>
<tr>
<td><?= htmlspecialchars($r['ledger_name']) ?></td>
<td><?= htmlspecialchars($r['parent_group']) ?></td>
<td>
    <input type="text" name="override[<?= htmlspecialchars($r['ledger_name']) ?>]" 
           value="<?= htmlspecialchars($r['mapped_code'] ?? '') ?>">
</td>
</tr>
<?php endforeach; ?>
</table>

<button type="submit">Save Overrides</button>
</form>