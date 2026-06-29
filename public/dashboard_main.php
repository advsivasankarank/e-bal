<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

$page_title = "Dashboard Main";
require_once __DIR__ . '/layouts/header_v2.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = $userId > 0 ? getOwnerUserId($pdo, $userId) : 0;
$companyScope = $ownerId > 0 ? " AND c.owner_user_id = {$ownerId}" : '';
$totalCompanies = $userId > 0 ? countCompaniesForUser($pdo, $userId) : (int) $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$activeFyId = (int) ($_SESSION['fy_id'] ?? 0);
$fyScope = $activeFyId > 0 ? " AND ws.fy_id = {$activeFyId}" : '';
$mappedCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.mapping_completed = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$importedCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.tally_fetched = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$completedCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.mapping_completed = 1
      AND ws.tally_fetched = 1
      {$companyScope}
")->fetchColumn();
$pendingCompanies = max($totalCompanies - $completedCompanies, 0);
$pendingSetup = max($totalCompanies - $importedCount, 0);
$reportReady = !empty($_SESSION['company_id']) && !empty($_SESSION['fy_id']);
$planUsage = null;
if ($userId > 0) {
    $planUsage = getPlanUsage($pdo, $userId);
}
$ledgerSyncCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.ledger_fetched = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$mappingCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.mapping_completed = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$trialBalanceCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.tally_fetched = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$notesCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.notes_prepared = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$profitLossCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.profit_loss_prepared = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$balanceSheetCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.balance_sheet_prepared = 1
      {$companyScope}
      {$fyScope}
")->fetchColumn();
$directorsReportCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    INNER JOIN companies cx ON cx.id = ws.company_id
    WHERE ws.directors_report_prepared = 1
      {$companyScope}
      {$fyScope}
      AND LOWER(REPLACE(REPLACE(cx.category, '-', '_'), ' ', '_')) = 'corporate'
")->fetchColumn();
$completedCompanies = (int) $pdo->query("
    SELECT COUNT(DISTINCT ws.company_id)
    FROM workflow_status ws
    INNER JOIN companies c ON c.id = ws.company_id
    WHERE ws.ledger_fetched = 1
      {$companyScope}
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

<?= uiBreadcrumb([
    ['label' => 'Dashboard']
]) ?>

<?= uiPageHero('Dashboard Main', 'Overview of your workspace and workflow progress') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
    'entity_type' => '',
    'profile' => 0,
    'status' => $reportReady ? 'Reports Ready' : 'Setup Required',
    'edit_url' => '',
]) ?>

<?= uiKpiCards([
    ['label' => 'Total Companies', 'value' => $totalCompanies, 'color' => 'var(--brand)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=active'],
    ['label' => 'Pending Companies', 'value' => $pendingCompanies, 'color' => 'var(--warning)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=pending'],
    ['label' => 'Completed Companies', 'value' => $completedCompanies, 'color' => 'var(--success)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=completed'],
]) ?>

<?php if ($planUsage): ?>
    <div class="ui-section-card" style="margin-bottom:16px;">
        <div class="ui-section-card-header">
            <div class="ui-section-card-title">Plan Status</div>
        </div>
        <div class="ui-section-card-body">
            <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
                <div>
                    <div style="font-weight:600;color:#0f172a;"><?= htmlspecialchars($planUsage['plan_name']) ?></div>
                    <div style="font-size:.78rem;color:#64748b;">Expires <?= htmlspecialchars($planUsage['expires_at']) ?></div>
                </div>
                <div style="display:flex;gap:16px;font-size:.82rem;">
                    <span>Companies: <?= (int) $planUsage['companies_used'] ?> / <?= (int) $planUsage['company_limit'] ?></span>
                    <span>Users: <?= (int) $planUsage['users_used'] ?> / <?= (int) $planUsage['user_limit'] ?></span>
                    <span>AI: <?= $planUsage['ai_enabled'] ? 'Enabled' : 'Disabled' ?></span>
                </div>
                <?= uiButton('Manage Plan', BASE_URL . 'upgrade.php', 'outline', '', 'style="font-size:.78rem;"') ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="ui-section-card" style="margin-bottom:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Key Workflow Status</div>
    </div>
    <div class="ui-section-card-body">
        <p style="margin:0 0 14px;font-size:.82rem;color:var(--muted);"><?= $activeFyId > 0 ? 'Overall progress across all active companies for the selected financial year.' : 'Overall progress across all active companies.' ?></p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">
            <?php
            $workflowSteps = [
                ['label' => 'Active', 'count' => $totalCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=active'],
                ['label' => 'Pending', 'count' => $pendingCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=pending'],
                ['label' => 'Completed', 'count' => $completedCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=completed'],
                ['label' => 'Ledger Sync', 'count' => $ledgerSyncCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=ledger_sync'],
                ['label' => 'Mapping', 'count' => $mappingCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=mapping'],
                ['label' => 'Trial Balance', 'count' => $trialBalanceCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=trial_balance'],
                ['label' => 'Notes', 'count' => $notesCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=notes'],
                ['label' => 'Profit & Loss', 'count' => $profitLossCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=profit_loss'],
                ['label' => 'Balance Sheet', 'count' => $balanceSheetCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=balance_sheet'],
                ['label' => 'Directors Report', 'count' => $directorsReportCompanies, 'href' => BASE_URL . 'company_dashboard/company_list.php?status=directors_report'],
            ];
            foreach ($workflowSteps as $step):
                $isDone = $step['count'] > 0;
            ?>
            <a href="<?= $step['href'] ?>" class="ui-kpi-card" style="text-decoration:none;">
                <div class="ui-kpi-value" style="color:<?= $isDone ? 'var(--success)' : 'var(--muted)' ?>;font-size:1.1rem;"><?= $step['count'] ?></div>
                <div class="ui-kpi-label"><?= htmlspecialchars($step['label']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="ui-section-card" style="margin-bottom:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Quick Navigation</div>
    </div>
    <div class="ui-section-card-body">
        <p style="margin:0 0 14px;font-size:.82rem;color:var(--muted);">Start in the company dashboard to select the working company and financial year. Then open the data dashboard to import ledger and trial balance data. Once mapping is complete, continue to reports.</p>
        <?= uiActionCards([
            [
                'label' => 'Dashboard Company',
                'desc' => 'Create companies, choose the active company, and switch the working financial year.',
                'href' => BASE_URL . 'dashboard_company.php',
                'icon' => '🏢',
                'color' => 'var(--brand)',
            ],
            [
                'label' => 'Dashboard Data',
                'desc' => 'Import ledger master, complete mapping, and fetch or upload trial balance data.',
                'href' => BASE_URL . 'dashboard_data.php',
                'icon' => '📊',
                'color' => 'var(--success)',
            ],
            [
                'label' => 'Dashboard Report',
                'desc' => $reportReady ? 'Generate statements, notes, and final outputs once the data pipeline is complete.' : 'Trial Balance Required',
                'href' => $reportReady ? BASE_URL . 'dashboard_report.php' : '#',
                'icon' => '📋',
                'color' => $reportReady ? 'var(--brand)' : 'var(--muted)',
                'disabled' => !$reportReady,
            ],
        ]) ?>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
