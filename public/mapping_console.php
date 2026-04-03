<?php
session_start();

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/core/mapping_engine.php';
require_once __DIR__ . '/../layouts/header.php';

if (empty($_SESSION['classified_data'])) {
    echo "<h3>Please fetch Tally data first.</h3>";
    exit;
}

$data = $_SESSION['classified_data'];
$company_id = $_SESSION['company_id'] ?? 1;

$heads = $pdo->query("SELECT * FROM schedule_heads")->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Mapping Console</h2>

<form method="post" action="save_mapping.php">

<table>
<tr>
<th>Ledger</th>
<th>Amount</th>
<th>Suggested</th>
<th>Mapping</th>
</tr>

<?php foreach ($data as $row):

$existing = getMapping($pdo, $company_id, $row['name']);
$suggest = suggestMapping($row['name']);
?>

<tr>
<td><?php echo $row['name']; ?></td>
<td><?php echo number_format($row['amount'],2); ?></td>

<td style="color:green;"><?php echo $suggest; ?></td>

<td>
<select name="mapping[<?php echo $row['name']; ?>]">

<option value="">--Select--</option>

<?php foreach ($heads as $h): ?>
<option value="<?php echo $h['code']; ?>"
<?php if ($existing == $h['code'] || $suggest == $h['code']) echo 'selected'; ?>>
<?php echo $h['main_head']." - ".$h['sub_head']; ?>
</option>
<?php endforeach; ?>

</select>
</td>

</tr>

<?php endforeach; ?>

</table>

<br>

<button class="btn success">Save Mapping</button>

</form>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>