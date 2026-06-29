<?php
/**
 * e-BAL V2 — Tally Online Console
 *
 * Live Tally import workflow.
 * Handles missing FY gracefully with inline selector.
 */
$page_title = "Tally Online Console";

require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/helpers/financial_year_helper.php';

/* Handle FY selection POST before any output */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['context_action']) && $_POST['context_action'] === 'select_fy') {
    requireCsrfToken();
    $selFyId = (int) ($_POST['fy_id'] ?? 0);
    if ($selFyId > 0 && hasCompanyContext()) {
        $fy = findFinancialYearById($pdo, $selFyId, $_SESSION['company_id']);
        if ($fy) {
            $_SESSION['fy_id'] = $fy['id'];
            $_SESSION['fy_name'] = $fy['fy_label'];
        }
    }
    /* Redirect back to self to re-render with new context */
    header("Location: " . BASE_URL . "data_console/tally_online.php");
    exit;
}

requireFullContext();
require_once __DIR__ . '/../layouts/header_v2.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

/* FETCH WORKFLOW STATUS */
$stmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$wf = $stmt->fetch(PDO::FETCH_ASSOC);

$ledgerFetched = $wf['ledger_fetched'] ?? 0;
$mappingDone   = $wf['mapping_completed'] ?? 0;
$tallyFetched  = $wf['tally_fetched'] ?? 0;

$nextProcessLabel = 'Sync Ledgers';
$nextProcessUrl = BASE_URL . 'data_console/connector.php?bridge=1';
$nextProcessHelp = 'Start the ledger sync to bring the live Tally ledger master into e-BAL.';

if ((int) $ledgerFetched === 1 && (int) $mappingDone !== 1) {
    $nextProcessLabel = 'Go to Mapping Console';
    $nextProcessUrl = BASE_URL . 'data_console/mapping_console.php';
    $nextProcessHelp = 'Ledger sync is complete. Review and confirm the schedule mapping as the next process.';
} elseif ((int) $mappingDone === 1 && (int) $tallyFetched !== 1) {
    $nextProcessLabel = 'Fetch Trial Balance';
    $nextProcessUrl = BASE_URL . 'data_console/tally_connect.php?bridge=1';
    $nextProcessHelp = 'Mapping is complete. Continue to the live trial balance fetch step.';
} elseif ((int) $tallyFetched === 1) {
    $nextProcessLabel = 'Review Trial Balance';
    $nextProcessUrl = BASE_URL . 'data_console/trial_balance_preview.php';
    $nextProcessHelp = 'Trial balance is already imported. Review the note placement before moving into reports.';
}
?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Tally Online'],
]) ?>

<?= uiPageHero('Tally Online Console', 'Follow the sequence from top to bottom. First sync the ledger master from Tally, then complete mapping, and finally fetch the trial balance.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?= uiWorkspaceStart() ?>

<?php if ((int) $tallyFetched === 1): ?>
    <?= uiAlert('The full online flow is already completed for this company and financial year. Continue to trial balance review, or re-run the online flow.', 'success') ?>
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?= uiButton('Continue to TB Review', BASE_URL . 'data_console/trial_balance_preview.php', 'primary') ?>
        <?= uiButton('Re-sync Online', BASE_URL . 'data_console/connector.php?bridge=1', 'outline') ?>
    </div>
<?php elseif ((int) $mappingDone === 1): ?>
    <?= uiAlert('Mapping is already completed. Continue to fetch the trial balance, or re-sync the ledger master.', 'info') ?>
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?= uiButton('Fetch Trial Balance', BASE_URL . 'data_console/tally_connect.php?bridge=1', 'primary') ?>
        <?= uiButton('Re-sync Ledgers', BASE_URL . 'data_console/connector.php?bridge=1', 'outline') ?>
    </div>
<?php elseif ((int) $ledgerFetched === 1): ?>
    <?= uiAlert('Ledger sync is already completed. Continue to mapping, or re-sync for fresh data.', 'info') ?>
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?= uiButton('Go to Mapping', BASE_URL . 'data_console/mapping_console.php', 'primary') ?>
        <?= uiButton('Re-sync Ledgers', BASE_URL . 'data_console/connector.php?bridge=1', 'outline') ?>
    </div>
<?php endif; ?>

<!-- Workflow Steps -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px;">
    <a href="<?= BASE_URL ?>data_console/connector.php?bridge=1" style="background:var(--panel);border:2px solid var(--brand);border-radius:var(--radius-lg);padding:20px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:8px;transition:all .15s;" onmouseover="this.style.boxShadow='var(--shadow)'" onmouseout="this.style.boxShadow='none'">
        <div style="font-size:.82rem;font-weight:700;color:var(--brand);">Step 0 — Sync</div>
        <div style="font-size:.82rem;color:var(--muted);">Verify the live Tally bridge and push fresh ledger XML into the application.</div>
        <div style="margin-top:auto;font-size:.78rem;color:var(--brand);font-weight:600;">🔄 Click to Sync →</div>
    </a>
    <a href="<?= BASE_URL ?>data_console/ledger_fetch.php" style="background:var(--panel);border:2px solid <?= $ledgerFetched ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:20px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:8px;transition:all .15s;<?= $ledgerFetched ? 'background:#f0fdf4;' : '' ?>" onmouseover="this.style.boxShadow='var(--shadow)'" onmouseout="this.style.boxShadow='none'">
        <div style="font-size:.82rem;font-weight:700;color:<?= $ledgerFetched ? 'var(--success)' : 'var(--text)' ?>;">Step 1 — Ledger</div>
        <div style="font-size:.82rem;color:var(--muted);">Pull the ledger master list with parent groups for the active company and FY.</div>
        <div style="margin-top:auto;"><?= $ledgerFetched ? uiStatusBadge('Completed', 'success') : uiStatusBadge('Pending', 'default') ?></div>
    </a>
    <a href="<?= $ledgerFetched ? BASE_URL . 'data_console/mapping_console.php' : '#' ?>" style="background:var(--panel);border:2px solid <?= $mappingDone ? 'var(--success)' : ($ledgerFetched ? 'var(--brand)' : 'var(--border)') ?>;border-radius:var(--radius-lg);padding:20px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:8px;transition:all .15s;<?= $mappingDone ? 'background:#f0fdf4;' : '' ?><?= !$ledgerFetched ? 'opacity:.5;pointer-events:none;' : '' ?>" onmouseover="this.style.boxShadow='var(--shadow)'" onmouseout="this.style.boxShadow='none'">
        <div style="font-size:.82rem;font-weight:700;color:<?= $mappingDone ? 'var(--success)' : ($ledgerFetched ? 'var(--brand)' : 'var(--text)') ?>;">Step 2 — Mapping</div>
        <div style="font-size:.82rem;color:var(--muted);">Review suggestions, correct schedule heads, and confirm the ledger mapping set.</div>
        <div style="margin-top:auto;"><?= $mappingDone ? uiStatusBadge('Completed', 'success') : ($ledgerFetched ? uiStatusBadge('Ready', 'brand') : uiStatusBadge('Locked', 'default')) ?></div>
    </a>
</div>

<!-- Next Process -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:20px;">
    <div>
        <div style="font-size:.85rem;font-weight:700;color:var(--text);margin-bottom:2px;">Next Process</div>
        <div style="font-size:.82rem;color:var(--muted);"><?= htmlspecialchars($nextProcessHelp) ?></div>
    </div>
    <?= uiButton(htmlspecialchars($nextProcessLabel), htmlspecialchars($nextProcessUrl), 'primary') ?>
</div>

<div style="margin-bottom:20px;">
    <?= uiButton('← Back', 'javascript:history.back()', 'outline') ?>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
