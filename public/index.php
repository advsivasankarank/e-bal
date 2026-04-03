<?php
require_once __DIR__ . '/../layouts/header.php';
require_once __DIR__ . '/../app/bootstrap.php';

$sql = "
SELECT c.id as company_id, c.company_name, fy.id as fy_id, fy.fy_start, fy.fy_end, ws.*
FROM companies c
JOIN financial_years fy ON fy.company_id = c.id
LEFT JOIN workflow_status ws ON ws.company_id = c.id AND ws.fy_id = fy.id
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* KPI CALC */
$total = count($rows);
$completed = 0;
$pending = 0;
$mapping = 0;

foreach ($rows as $r) {
    if ($r['reports_generated']) $completed++;
    elseif ($r['mapping_completed']) $mapping++;
    else $pending++;
}
?>

<h2>Dashboard</h2>

<div class="card-grid">
    <div class="card"><h3>Total Jobs</h3><p><?php echo $total; ?></p></div>
    <div class="card"><h3>Completed</h3><p><?php echo $completed; ?></p></div>
    <div class="card"><h3>Pending</h3><p><?php echo $pending; ?></p></div>
    <div class="card"><h3>Needs Mapping</h3><p><?php echo $mapping; ?></p></div>
</div>

<table>
<tr>
<th>Company</th>
<th>Financial Year</th>
<th>Status</th>
<th>Progress</th>
<th>Action</th>
</tr>

<?php foreach ($rows as $r):

$progress = (
    ($r['tally_fetched'] ?? 0) +
    ($r['mapping_completed'] ?? 0) +
    ($r['verified'] ?? 0) +
    ($r['reports_generated'] ?? 0)
) * 25;

$status = "pending";
$label = "Pending";

if ($r['reports_generated']) { $status = "done"; $label = "Completed"; }
elseif ($r['mapping_completed']) { $status = "mapping"; $label = "Mapping Done"; }
elseif ($r['tally_fetched']) { $status = "fetched"; $label = "Data Fetched"; }

?>

<tr>
<td><?php echo $r['company_name']; ?></td>
<td><?php echo $r['fy_start']." to ".$r['fy_end']; ?></td>

<td><span class="badge <?php echo $status; ?>"><?php echo $label; ?></span></td>

<td>
<div class="progress-bar">
<div class="progress-fill" style="width:<?php echo $progress; ?>%"></div>
</div>
<?php echo $progress; ?>%
</td>

<td>

<?php if (!$r['tally_fetched']): ?>
<a class="btn primary" href="tally_fetch.php?company_id=<?php echo $r['company_id']; ?>&fy_id=<?php echo $r['fy_id']; ?>">Fetch</a>

<?php elseif (!$r['mapping_completed']): ?>
<a class="btn warning" href="mapping_console.php">Map</a>

<?php else: ?>
<a class="btn success" href="generate_pdf.php">PDF</a>
<?php endif; ?>

</td>
</tr>

<?php endforeach; ?>
</table>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>