<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

$page_title = "Dashboard Main";
require_once __DIR__ . '/layouts/header.php';

ensureWorkflowColumns();

$totalCompanies = (int) $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$activeFyId = (int) ($_SESSION['fy_id'] ?? 0);
$fyScope = $activeFyId > 0 ? " AND ws.fy_id = {$activeFyId}" : '';
$mappedCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.mapping_completed = 1
      {$fyScope}
")->fetchColumn();
$importedCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.tally_fetched = 1
      {$fyScope}
")->fetchColumn();
$completedCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.mapping_completed = 1
      AND ws.tally_fetched = 1
")->fetchColumn();
$pendingCompanies = max($totalCompanies - $completedCompanies, 0);
$pendingSetup = max($totalCompanies - $importedCount, 0);
$reportReady = !empty($_SESSION['company_id']) && !empty($_SESSION['fy_id']);
$planUsage = null;
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId > 0) {
    $planUsage = getPlanUsage($pdo, $userId);
}
$ledgerSyncCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.ledger_fetched = 1
      {$fyScope}
")->fetchColumn();
$mappingCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.mapping_completed = 1
      {$fyScope}
")->fetchColumn();
$trialBalanceCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.tally_fetched = 1
      {$fyScope}
")->fetchColumn();
$notesCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.notes_prepared = 1
      {$fyScope}
")->fetchColumn();
$profitLossCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.profit_loss_prepared = 1
      {$fyScope}
")->fetchColumn();
$balanceSheetCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.balance_sheet_prepared = 1
      {$fyScope}
")->fetchColumn();
$directorsReportCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.directors_report_prepared = 1
      {$fyScope}
      AND LOWER(REPLACE(REPLACE(c.category, '-', '_'), ' ', '_')) = 'corporate'
")->fetchColumn();
$completedCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.ledger_fetched = 1
      {$fyScope}
      AND ws.mapping_completed = 1
      AND ws.tally_fetched = 1
      AND ws.notes_prepared = 1
      AND ws.profit_loss_prepared = 1
      AND ws.balance_sheet_prepared = 1
      AND (
        LOWER(REPLACE(REPLACE(c.category, '-', '_'), ' ', '_')) <> 'corporate'
        OR ws.directors_report_prepared = 1
      )
")->fetchColumn();
$pendingCompanies = max($totalCompanies - $completedCompanies, 0);

if ($reportReady) {
    $readyStmt = $pdo->prepare("SELECT tally_fetched FROM workflow_status WHERE company_id=? AND fy_id=?");
    $readyStmt->execute([$_SESSION['company_id'], $_SESSION['fy_id']]);
    $reportReady = (int) $readyStmt->fetchColumn() === 1;
}
?>

<div class="page-title">Dashboard Main</div>

<div class="summary-bar">
    <div class="summary-card is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=active">
        <div class="summary-number"><?= $totalCompanies ?></div>
        <div class="summary-label">Active Companies</div>
    </div>
    <div class="summary-card is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=pending">
        <div class="summary-number"><?= $pendingCompanies ?></div>
        <div class="summary-label">Pending Companies</div>
    </div>
    <div class="summary-card is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=completed">
        <div class="summary-number"><?= $completedCompanies ?></div>
        <div class="summary-label">Completed Companies</div>
    </div>
</div>

<?php if ($planUsage): ?>
    <div class="card section-card">
        <strong>Plan Status</strong><br>
        <?= htmlspecialchars($planUsage['plan_name']) ?> &middot; Expires <?= htmlspecialchars($planUsage['expires_at']) ?><br>
        Companies: <?= (int) $planUsage['companies_used'] ?> / <?= (int) $planUsage['company_limit'] ?> &middot;
        Users: <?= (int) $planUsage['users_used'] ?> / <?= (int) $planUsage['user_limit'] ?> &middot;
        AI: <?= $planUsage['ai_enabled'] ? 'Enabled' : 'Disabled' ?>
        <div style="margin-top:8px;">
            <a class="btn" href="<?= BASE_URL ?>upgrade.php">Manage Plan</a>
        </div>
    </div>
<?php endif; ?>

<div class="active-info">
    Active Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    Active FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="workflow-panel">
    <h2 class="workflow-heading">Key Workflow Status</h2>
    <div class="workflow-copy"><?= $activeFyId > 0 ? 'Overall progress across all active companies for the selected financial year.' : 'Overall progress across all active companies.' ?></div>
    <div class="workflow-grid">
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=active">
            <strong>Active</strong>
            <span class="<?= $totalCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $totalCompanies ?> active</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=pending">
            <strong>Pending</strong>
            <span class="<?= $pendingCompanies > 0 ? 'workflow-wait' : 'workflow-done' ?>"><?= $pendingCompanies ?> pending</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=completed">
            <strong>Completed</strong>
            <span class="<?= $completedCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $completedCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=ledger_sync">
            <strong>Ledger Sync</strong>
            <span class="<?= $ledgerSyncCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $ledgerSyncCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=mapping">
            <strong>Mapping</strong>
            <span class="<?= $mappingCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $mappingCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=trial_balance">
            <strong>Trial Balance Fetch</strong>
            <span class="<?= $trialBalanceCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $trialBalanceCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=notes">
            <strong>Notes</strong>
            <span class="<?= $notesCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $notesCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=profit_loss">
            <strong>Profit and Loss</strong>
            <span class="<?= $profitLossCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $profitLossCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=balance_sheet">
            <strong>Balance Sheet</strong>
            <span class="<?= $balanceSheetCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $balanceSheetCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=directors_report">
            <strong>Directors Report</strong>
            <span class="<?= $directorsReportCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $directorsReportCompanies ?> completed</span>
        </div>
        <div class="workflow-step is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>company_dashboard/company_list.php?status=completed">
            <strong>Completed</strong>
            <span class="<?= $completedCompanies > 0 ? 'workflow-done' : 'workflow-wait' ?>"><?= $completedCompanies ?> completed</span>
        </div>
    </div>
</div>

<div class="card section-card">
    <strong>How to move through the app</strong><br>
    Start in the company dashboard to select the working company and financial year. Then open the data dashboard to import ledger and trial balance data. Once mapping is complete, continue to reports.
</div>

<div class="tile-container">
    <div class="tile is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>dashboard_company.php">
        <h3>Dashboard Company</h3>
        <p>Create companies, choose the active company, and switch the working financial year.</p>
        <div class="status">Manage Context</div>
    </div>

    <div class="tile is-clickable" tabindex="0" role="link" data-nav="<?= BASE_URL ?>dashboard_data.php">
        <h3>Dashboard Data</h3>
        <p>Import ledger master, complete mapping, and fetch or upload trial balance data.</p>
        <div class="status">Process Data</div>
    </div>

    <div class="tile <?= !$reportReady ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $reportReady ? ' data-nav="' . BASE_URL . 'dashboard_report.php"' : '' ?>>
        <h3>Dashboard Report</h3>
        <p>Generate statements, notes, and final outputs once the data pipeline is complete.</p>
        <div class="status"><?= $reportReady ? 'Build Reports' : 'Trial Balance Required' ?></div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
