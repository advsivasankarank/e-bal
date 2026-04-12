<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
requireFullContext();

$page_title = "Dashboard Data";
require_once __DIR__ . '/layouts/header.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

$stmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$ledgerFetched = (int) ($workflow['ledger_fetched'] ?? 0);
$mappingDone = (int) ($workflow['mapping_completed'] ?? 0);
$tallyFetched = (int) ($workflow['tally_fetched'] ?? 0);
?>

<div class="page-title">Dashboard Data</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= $ledgerFetched ?></div>
        <div class="summary-label">Ledger Master Ready</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= $mappingDone ?></div>
        <div class="summary-label">Mapping Complete</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= $tallyFetched ?></div>
        <div class="summary-label">Trial Balance Imported</div>
    </div>
</div>

<div class="card section-card">
    This dashboard is the operational center for imports. Use it to open the Tally console, complete mapping, and continue trial balance processing without losing the active company and FY context.
</div>

<div class="tile-container">
    <div class="tile is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>data_console/tally_console.php">
        <h3>Tally Console</h3>
        <p>Choose between live Tally sync and offline XML upload based on how you want to import data.</p>
        <div class="status">Open Console</div>
    </div>

    <div class="tile <?= !$ledgerFetched ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $ledgerFetched ? ' data-nav="' . BASE_URL . 'data_console/mapping_console.php"' : '' ?>>
        <h3>Mapping Console</h3>
        <p>Map imported ledgers to schedule heads and review the data before final trial balance import.</p>
        <div class="status"><?= $ledgerFetched ? ($mappingDone ? 'Completed' : 'Ready To Map') : 'Ledger Import Required' ?></div>
    </div>

    <div class="tile <?= !$mappingDone ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $mappingDone ? ' data-nav="' . BASE_URL . 'data_console/tally_connect.php"' : '' ?>>
        <h3>Trial Balance Import</h3>
        <p>Fetch live trial balance or continue the import workflow once every mapping row is complete.</p>
        <div class="status"><?= $mappingDone ? ($tallyFetched ? 'Imported' : 'Ready To Fetch') : 'Complete Mapping First' ?></div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
