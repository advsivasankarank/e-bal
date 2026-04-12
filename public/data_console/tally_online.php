<?php
$page_title = "Tally Online Console";

require_once '../../app/context_check.php';
require_once '../../config/database.php';
requireFullContext();
require_once __DIR__ . '/../layouts/header.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

/*
|--------------------------------------------------------------------------
| FETCH WORKFLOW STATUS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$wf = $stmt->fetch(PDO::FETCH_ASSOC);

$ledgerFetched = $wf['ledger_fetched'] ?? 0;
$mappingDone   = $wf['mapping_completed'] ?? 0;
$tallyFetched  = $wf['tally_fetched'] ?? 0;

$nextProcessLabel = 'Sync Ledgers';
$nextProcessUrl = BASE_URL . 'data_console/connector.php';
$nextProcessHelp = 'Start the ledger sync to bring the live Tally ledger master into e-BAL.';

if ((int) $ledgerFetched === 1 && (int) $mappingDone !== 1) {
    $nextProcessLabel = 'Go to Mapping Console';
    $nextProcessUrl = BASE_URL . 'data_console/mapping_console.php';
    $nextProcessHelp = 'Ledger sync is complete. Review and confirm the schedule mapping as the next process.';
} elseif ((int) $mappingDone === 1 && (int) $tallyFetched !== 1) {
    $nextProcessLabel = 'Fetch Trial Balance';
    $nextProcessUrl = BASE_URL . 'data_console/tally_connect.php';
    $nextProcessHelp = 'Mapping is complete. Continue to the live trial balance fetch step.';
} elseif ((int) $tallyFetched === 1) {
    $nextProcessLabel = 'Review Trial Balance';
    $nextProcessUrl = BASE_URL . 'data_console/trial_balance_preview.php';
    $nextProcessHelp = 'Trial balance is already imported. Review the note placement before moving into reports.';
}
?>

<div class="page-title">
    Tally Online Console
</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    Follow the sequence from top to bottom. First sync the ledger master from Tally, then complete mapping, and finally fetch the trial balance for the selected FY.
</div>

<?php if ((int) $tallyFetched === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            The full online flow is already completed for this company and financial year. Continue to trial balance review, or re-run the online flow if you need a fresh sync.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/connector.php">Re-sync Online</a>
        </div>
    </div>
<?php elseif ((int) $mappingDone === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Mapping is already completed. Continue to fetch the trial balance, or re-sync the ledger master if you want to refresh the base data.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/connector.php">Re-sync Ledgers</a>
        </div>
    </div>
<?php elseif ((int) $ledgerFetched === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Ledger sync is already completed. Continue to mapping, or re-sync the ledger master if you want fresh ledger data from Tally.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/connector.php">Re-sync Ledgers</a>
        </div>
    </div>
<?php endif; ?>

<div class="tile-container">
<!-- STEP 0: CONNECTOR SYNC -->
<div class="tile"
    onclick="location.href='<?= BASE_URL ?>data_console/connector.php'">

    <h3>Step 0</h3>
    <p>Verify the live Tally bridge and push fresh ledger XML into the application safely.</p>

    <div class="status">
        🔄 Click to Sync
    </div>
</div>

    <!-- STEP 1: LEDGER FETCH -->
    <div class="tile <?= $ledgerFetched ? 'completed' : '' ?>"
        onclick="location.href='<?= BASE_URL ?>data_console/ledger_fetch.php'">

        <h3>Step 1</h3>
        <p>Pull the ledger master list with parent groups for the active company and financial year.</p>

        <div class="status">
            <?= $ledgerFetched ? '✅ Completed' : '⏳ Pending' ?>
        </div>
    </div>

    <!-- STEP 2: MAPPING -->
    <div class="tile <?= !$ledgerFetched ? 'disabled' : ($mappingDone ? 'completed' : '') ?>"
        onclick="<?= $ledgerFetched ? "location.href='".BASE_URL."data_console/mapping_console.php'" : '' ?>">

        <h3>Step 2</h3>
        <p>Review suggestions, correct schedule heads, and confirm the ledger mapping set.</p>

        <div class="status">
            <?= !$ledgerFetched ? '🔒 Locked' : ($mappingDone ? '✅ Completed' : '⏳ Pending') ?>
        </div>
    </div>

    <!-- STEP 3: TRIAL BALANCE -->
    <div class="tile <?= !$mappingDone ? 'disabled' : ($tallyFetched ? 'completed' : '') ?>"
        onclick="<?= $mappingDone ? "location.href='".BASE_URL."data_console/tally_connect.php'" : '' ?>">

        <h3>Step 3</h3>
        <p>Fetch live trial balance data from Tally once every ledger is mapped correctly.</p>

        <div class="status">
            <?= !$mappingDone ? '🔒 Locked' : ($tallyFetched ? '✅ Completed' : '⏳ Pending') ?>
        </div>
    </div>

</div>

<div class="card" style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
    <div>
        <strong>Next Process</strong><br>
        <?= htmlspecialchars($nextProcessHelp) ?>
    </div>
    <div>
        <a class="btn" href="<?= htmlspecialchars($nextProcessUrl) ?>"><?= htmlspecialchars($nextProcessLabel) ?></a>
    </div>
</div>

<!-- BACK BUTTON -->
<div style="margin-top:20px;">
    <button onclick="history.back()" class="btn">← Back</button>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
