<?php
$page_title = "Tally Console";
require_once '../../app/context_check.php';
requireFullContext();
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Tally Console</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    Pick the mode that matches how you want to bring data in. Live mode talks to Tally directly, while offline mode accepts XML exports you already downloaded.
</div>

<div class="tile-container">

    <div class="tile" onclick="location.href='tally_online.php'">
        <h3>Online Mode</h3>
        <p>Connect to live Tally, sync ledger master, and fetch trial balance against the active financial year.</p>
        <div class="status">Use Live Tally</div>
    </div>

    <div class="tile" onclick="location.href='tally_offline.php'">
        <h3>Offline Mode</h3>
        <p>Upload exported XML files for ledger master and trial balance when direct Tally access is not available.</p>
        <div class="status">Use XML Upload</div>
    </div>

</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
