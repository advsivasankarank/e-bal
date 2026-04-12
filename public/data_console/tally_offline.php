<?php
$page_title = "Offline Mode";

require_once '../../app/context_check.php';
require_once '../../config/database.php';
requireFullContext();
require_once __DIR__ . '/../layouts/header.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

/*
|--------------------------------------------------------------------------
| WORKFLOW STATUS
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$wf = $stmt->fetch(PDO::FETCH_ASSOC);

$ledgerFetched = $wf['ledger_fetched'] ?? 0;
$mappingDone   = $wf['mapping_completed'] ?? 0;
$tallyFetched  = $wf['tally_fetched'] ?? 0;

$nextProcessLabel = 'Upload Ledger XML';
$nextProcessUrl = BASE_URL . 'data_console/xml_ledger_upload.php';
$nextProcessHelp = 'Start by uploading the ledger master XML so the mapping base can be created.';

if ((int) $ledgerFetched === 1 && (int) $mappingDone !== 1) {
    $nextProcessLabel = 'Go to Mapping Console';
    $nextProcessUrl = BASE_URL . 'data_console/mapping_console.php';
    $nextProcessHelp = 'Ledger import is complete. Review and confirm the schedule mapping as the next process.';
} elseif ((int) $mappingDone === 1 && (int) $tallyFetched !== 1) {
    $nextProcessLabel = 'Upload Trial Balance XML';
    $nextProcessUrl = BASE_URL . 'data_console/xml_tb_upload.php';
    $nextProcessHelp = 'Mapping is complete. Continue to upload the trial balance XML.';
} elseif ((int) $tallyFetched === 1) {
    $nextProcessLabel = 'Review Trial Balance';
    $nextProcessUrl = BASE_URL . 'data_console/trial_balance_preview.php';
    $nextProcessHelp = 'Trial balance is already imported. Review the note placement before moving into reports.';
}
?>

<div class="page-title">Offline XML Console</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    Use this path when you have XML exports from Tally. Upload the ledger master first, complete mapping, and then upload the trial balance XML.
</div>

<?php if ((int) $tallyFetched === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            The full offline flow is already completed for this company and financial year. Continue to trial balance review, or upload a fresh XML set if you need to re-run the process.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/xml_ledger_upload.php">Re-sync Offline</a>
        </div>
    </div>
<?php elseif ((int) $mappingDone === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Mapping is already completed. Continue to upload the trial balance XML, or upload the ledger XML again if you want to refresh the base data.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/xml_tb_upload.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/xml_ledger_upload.php">Re-sync Ledgers</a>
        </div>
    </div>
<?php elseif ((int) $ledgerFetched === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Ledger import is already completed. Continue to mapping, or upload the ledger XML again if you want fresh base data.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/mapping_console.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/xml_ledger_upload.php">Re-sync Ledgers</a>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<div class="tile-container">

    <!-- STEP 1: LEDGER XML UPLOAD -->
    <div class="tile <?= $ledgerFetched ? 'completed' : '' ?>"
        onclick="location.href='<?= BASE_URL ?>data_console/xml_ledger_upload.php'">

        <h3>Step 1</h3>
        <p>Upload the ledger master export so the application can build the mapping base.</p>

        <div class="status">
            <?= $ledgerFetched ? '✅ Completed' : '⏳ Pending' ?>
        </div>
    </div>

    <!-- STEP 2: MAPPING -->
    <div class="tile <?= !$ledgerFetched ? 'disabled' : ($mappingDone ? 'completed' : '') ?>"
        onclick="<?= $ledgerFetched ? "location.href='".BASE_URL."data_console/mapping_console.php'" : '' ?>">

        <h3>Step 2</h3>
        <p>Confirm schedule mapping for the imported ledgers before trial balance import.</p>

        <div class="status">
            <?= !$ledgerFetched ? '🔒 Locked' : ($mappingDone ? '✅ Completed' : '⏳ Pending') ?>
        </div>
    </div>

    <!-- STEP 3: TRIAL BALANCE XML -->
    <div class="tile <?= !$mappingDone ? 'disabled' : ($tallyFetched ? 'completed' : '') ?>"
        onclick="<?= $mappingDone ? "location.href='".BASE_URL."data_console/xml_tb_upload.php'" : '' ?>">

        <h3>Step 3</h3>
        <p>Upload the trial balance XML for the selected company and active financial year.</p>

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

<?php
unset($_SESSION['success'], $_SESSION['error']);
?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
