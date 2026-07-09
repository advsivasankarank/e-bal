<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../app/helpers/figure_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$stats = $_SESSION['process_stats'] ?? [
    'total'    => 0,
    'dr_total' => 0,
    'cr_total' => 0,
    'type'     => 'xml'
];

$error = $_SESSION['error'] ?? null;

$page_title = "Processing Result";
require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Process Result'],
]) ?>

<?= uiPageHero('Data Processing Summary', 'Review the results of your data processing operation.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?= uiWorkspaceStart() ?>

<div class="card" style="padding:20px; margin-top:20px;">
    <?php if ($error): ?>
        <h3 style="color:red;">❌ Error</h3>
        <p><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <h3>✅ Process Completed</h3>
        <p><strong>Total Ledgers:</strong> <?= (int)$stats['total'] ?></p>
        <p><strong>Total Debit:</strong> ₹<?= format_inr_number((float)$stats['dr_total']) ?></p>
        <p><strong>Total Credit:</strong> ₹<?= format_inr_number((float)$stats['cr_total']) ?></p>
        <p><strong>Source:</strong> <?= strtoupper($stats['type']) ?></p>
    <?php endif; ?>
</div>

<br>

<div>
    <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">Continue</a>
    <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php?bridge=1">Re-fetch Trial Balance</a>
    <a class="btn" href="mapping_console.php">Mapping Workbench</a>
    <a class="btn" href="<?= BASE_URL ?>dashboard_report.php">Go to Reports</a>
    <a class="btn" href="<?= BASE_URL ?>dashboard_data.php">Back to Dashboard</a>
</div>

<?= uiWorkspaceEnd() ?>

<?php
unset($_SESSION['process_stats'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
