<?php
$page_title = "Offline XML Import";
require_once '../../app/context_check.php';
requireFullContext();
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Offline XML Import</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<form method="post" action="xml_ledger_upload.php" enctype="multipart/form-data">
    <div class="form-group">
        <label>Upload Ledger XML</label>
        <input type="file" name="ledger_xml" accept=".xml,text/xml,application/xml" required>
    </div>
    <button type="submit" class="btn">Upload Ledger</button>
</form>

<br>

<form method="post" action="xml_tb_upload.php" enctype="multipart/form-data">
    <div class="form-group">
        <label>Upload Trial Balance XML</label>
        <input type="file" name="tb_xml" accept=".xml,text/xml,application/xml" required>
    </div>
    <button type="submit" class="btn">Upload TB</button>
</form>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
