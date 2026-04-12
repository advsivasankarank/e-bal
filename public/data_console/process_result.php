<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';

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
require_once __DIR__ . '/../layouts/header.php';
?>

<div class="page-title">Data Processing Summary</div>

<div class="card" style="padding:20px; margin-top:20px;">
    <?php if ($error): ?>
        <h3 style="color:red;">❌ Error</h3>
        <p><?= htmlspecialchars($error) ?></p>
    <?php else: ?>
        <h3>✅ Process Completed</h3>
        <p><strong>Total Ledgers:</strong> <?= (int)$stats['total'] ?></p>
        <p><strong>Total Debit:</strong> ₹<?= number_format((float)$stats['dr_total'], 2) ?></p>
        <p><strong>Total Credit:</strong> ₹<?= number_format((float)$stats['cr_total'], 2) ?></p>
        <p><strong>Source:</strong> <?= strtoupper($stats['type']) ?></p>
    <?php endif; ?>
</div>

<br>

<div>
    <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">Continue</a>
    <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php">Re-fetch Trial Balance</a>
    <a class="btn" href="mapping_console.php">Mapping Console</a>
    <a class="btn" href="<?= BASE_URL ?>dashboard_report.php">Go to Reports</a>
    <a class="btn" href="<?= BASE_URL ?>dashboard_data.php">Back to Dashboard</a>
</div>

<?php
unset($_SESSION['process_stats'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer.php';
?>
