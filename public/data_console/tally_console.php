<?php
$page_title = "Tally Console";
require_once '../../app/context_check.php';
requireFullContext();
require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Tally Import'],
]) ?>

<?= uiPageHero('Tally Console', 'Pick the mode that matches how you want to bring data in.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?= uiWorkspaceStart() ?>

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

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
