<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../layouts/header.php';

$preCompanyId = intval($_GET['company_id'] ?? 0);
$preFyId      = intval($_GET['fy_id'] ?? 0);

$companies = $pdo->query("SELECT id, company_name FROM companies ORDER BY company_name")
                 ->fetchAll(PDO::FETCH_ASSOC);

$preFyStart = '';
$preFyEnd   = '';

if ($preFyId) {
    $stmt = $pdo->prepare("SELECT fy_start, fy_end FROM financial_years WHERE id = ?");
    $stmt->execute([$preFyId]);
    $fy = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($fy) {
        $preFyStart = $fy['fy_start'];
        $preFyEnd   = $fy['fy_end'];
    }
}
?>

<h2>Import Trial Balance XML</h2>

<?php if (empty($companies)): ?>
    <p>No companies found. <a href="company_create.php">Create a company</a> first.</p>
<?php else: ?>

<form method="post" action="xml_import_process.php" enctype="multipart/form-data">

<table class="form-table" style="width:auto;background:none;box-shadow:none;border-radius:0;">
<tr>
<td style="border:none;padding:10px 0;font-weight:bold;">Company</td>
<td style="border:none;padding:10px 16px;">
<select name="company_id" required style="padding:8px;width:280px;">
<option value="">-- Select Company --</option>
<?php foreach ($companies as $c): ?>
<option value="<?php echo $c['id']; ?>"
    <?php if ($preCompanyId === $c['id']) echo 'selected'; ?>>
    <?php echo htmlspecialchars($c['company_name']); ?>
</option>
<?php endforeach; ?>
</select>
</td>
</tr>

<tr>
<td style="border:none;padding:10px 0;font-weight:bold;">FY Start Date</td>
<td style="border:none;padding:10px 16px;">
<input type="date" name="fy_start" required
       value="<?php echo htmlspecialchars($preFyStart); ?>"
       style="padding:8px;width:280px;">
</td>
</tr>

<tr>
<td style="border:none;padding:10px 0;font-weight:bold;">FY End Date</td>
<td style="border:none;padding:10px 16px;">
<input type="date" name="fy_end" required
       value="<?php echo htmlspecialchars($preFyEnd); ?>"
       style="padding:8px;width:280px;">
</td>
</tr>

<tr>
<td style="border:none;padding:10px 0;font-weight:bold;">Trial Balance XML</td>
<td style="border:none;padding:10px 16px;">
<input type="file" name="xml_file" accept=".xml" required style="padding:4px;">
<p style="margin:4px 0 0;color:#888;font-size:12px;">
    Export from Tally: Gateway → Display → Trial Balance → Export (XML format)
</p>
</td>
</tr>
</table>

<br>
<button type="submit" class="btn primary" style="padding:10px 24px;font-size:14px;">
    Import &amp; Classify
</button>
&nbsp;
<a href="index.php" class="btn" style="background:#6c757d;padding:10px 24px;font-size:14px;">
    Cancel
</a>

</form>

<?php endif; ?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
