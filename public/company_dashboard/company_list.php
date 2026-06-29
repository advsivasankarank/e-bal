<?php
require_once '../../app/session_bootstrap.php';
require_once '../../config/database.php';
require_once '../../app/workflow_engine.php';
require_once '../../app/helpers/plan_helper.php';
include __DIR__ . '/../layouts/header_v2.php';

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$filterTitle = 'All Companies';
$activeFyId = (int) ($_SESSION['fy_id'] ?? 0);
$workflowJoin = " LEFT JOIN workflow_status ws ON ws.company_id = c.id" . ($activeFyId > 0 ? " AND ws.fy_id = {$activeFyId}" : '');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = $userId > 0 ? getOwnerUserId($pdo, $userId) : 0;
$conditions = [];

if ($ownerId > 0) {
    $conditions[] = "c.owner_user_id = {$ownerId}";
}

$sql = "
    SELECT
        c.*,
        COALESCE(MAX(ws.ledger_fetched), 0) AS ledger_fetched,
        COALESCE(MAX(ws.mapping_completed), 0) AS mapping_completed,
        COALESCE(MAX(ws.tally_fetched), 0) AS tally_fetched
    FROM companies c
    {$workflowJoin}
";

switch ($statusFilter) {
    case 'pending':
        $conditions[] = "c.id NOT IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE mapping_completed = 1 AND tally_fetched = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Pending Companies';
        break;
    case 'completed':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE ledger_fetched = 1
              AND mapping_completed = 1
              AND tally_fetched = 1
              AND notes_prepared = 1
              AND profit_loss_prepared = 1
              AND balance_sheet_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Completed Companies';
        break;
    case 'ledger_sync':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE ledger_fetched = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Ledger Sync Completed';
        break;
    case 'mapping':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE mapping_completed = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Mapping Completed';
        break;
    case 'trial_balance':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE tally_fetched = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Trial Balance Completed';
        break;
    case 'notes':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE notes_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Notes Prepared';
        break;
    case 'profit_loss':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE profit_loss_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Profit and Loss Prepared';
        break;
    case 'balance_sheet':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE balance_sheet_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Balance Sheet Prepared';
        break;
    case 'directors_report':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE directors_report_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Directors Report Prepared';
        break;
    case 'reports':
        $conditions[] = "c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE (
                notes_prepared = 1
                OR profit_loss_prepared = 1
                OR balance_sheet_prepared = 1
                OR directors_report_prepared = 1
            )" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Reports Ready';
        break;
    case 'active':
        $filterTitle = 'Active Companies';
        break;
}

$where = $conditions !== [] ? ' WHERE ' . implode(' AND ', $conditions) : '';
$sql .= $where . " GROUP BY c.id ORDER BY c.id DESC";
$stmt = $pdo->query($sql);
$companies = $stmt->fetchAll();

/* Count KPIs */
$totalCount = count($companies);
$activeCount = 0;
$pendingCount = 0;
$completedCount = 0;
foreach ($companies as $co) {
    if ((int) ($co['tally_fetched'] ?? 0) === 1
        && (int) ($co['mapping_completed'] ?? 0) === 1
        && (int) ($co['ledger_fetched'] ?? 0) === 1
        && (int) ($co['notes_prepared'] ?? 0) === 1
        && (int) ($co['profit_loss_prepared'] ?? 0) === 1
        && (int) ($co['balance_sheet_prepared'] ?? 0) === 1
    ) {
        $completedCount++;
    } elseif ((int) ($co['ledger_fetched'] ?? 0) === 1 || (int) ($co['mapping_completed'] ?? 0) === 1) {
        $activeCount++;
    } else {
        $pendingCount++;
    }
}

function companyContinueLink(array $company): array
{
    $base = defined('BASE_URL') ? BASE_URL : '/e-bal/public/';

    if ((int) ($company['ledger_fetched'] ?? 0) !== 1) {
        return ['label' => 'Sync Ledgers', 'href' => $base . 'company_dashboard/company_select.php?company_id=' . (int) $company['id'] . '&next=data_console/tally_console.php'];
    }

    if ((int) ($company['mapping_completed'] ?? 0) !== 1) {
        return ['label' => 'Continue Mapping', 'href' => $base . 'company_dashboard/company_select.php?company_id=' . (int) $company['id'] . '&next=data_console/mapping_console.php'];
    }

    if ((int) ($company['tally_fetched'] ?? 0) !== 1) {
        return ['label' => 'Fetch Trial Balance', 'href' => $base . 'company_dashboard/company_select.php?company_id=' . (int) $company['id'] . '&next=data_console/tally_connect.php?bridge=1'];
    }

    return ['label' => 'Open Reports', 'href' => $base . 'company_dashboard/company_select.php?company_id=' . (int) $company['id'] . '&next=dashboard_report.php'];
}
?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Companies']
]) ?>

<?= uiPageHero($filterTitle, 'Manage and track all entities in your workspace') ?>

<?= uiKpiCards([
    ['label' => 'Total Companies', 'value' => $totalCount, 'color' => 'var(--brand)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=active'],
    ['label' => 'Active', 'value' => $activeCount, 'color' => 'var(--success)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=active'],
    ['label' => 'Pending', 'value' => $pendingCount, 'color' => 'var(--warning)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=pending'],
    ['label' => 'Completed', 'value' => $completedCount, 'color' => 'var(--brand)', 'href' => BASE_URL . 'company_dashboard/company_list.php?status=completed'],
]) ?>

<?php if (isset($_GET['success'])): ?>
    <?= uiAlert('Entity created successfully. You can now complete the profile from the Edit page.', 'success') ?>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <?= uiAlert('Company updated successfully.', 'success') ?>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <?= uiAlert('Company deleted successfully.', 'success') ?>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_company'): ?>
    <?= uiAlert('Invalid company selected for deletion.', 'error') ?>
<?php endif; ?>

<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
    <?= uiButton('Create Entity', BASE_URL . 'company_dashboard/company_create.php', 'primary', '+') ?>
</div>

<div class="ui-section-card" style="margin-bottom:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Filters</div>
    </div>
    <div class="ui-section-card-body" style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php
        $filters = [
            'active' => 'Active', 'pending' => 'Pending', 'completed' => 'Completed',
            'ledger_sync' => 'Ledger Sync', 'mapping' => 'Mapping', 'trial_balance' => 'Trial Balance',
            'notes' => 'Notes', 'profit_loss' => 'Profit & Loss', 'balance_sheet' => 'Balance Sheet',
            'directors_report' => 'Directors Report', 'reports' => 'Reports'
        ];
        foreach ($filters as $key => $label):
            $isActive = $statusFilter === $key;
        ?>
            <a href="<?= BASE_URL ?>company_dashboard/company_list.php?status=<?= $key ?>"
               class="v2-btn <?= $isActive ? 'v2-btn--primary' : 'v2-btn--outline' ?>"
               style="font-size:.78rem;"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($companies)): ?>
    <?= uiEmptyState('🏢', 'No Companies Found', 'Create your first entity to get started.', 'Create Entity', BASE_URL . 'company_dashboard/company_create.php') ?>
<?php else: ?>
    <?= uiTableStart(['Name', 'Category', 'CIN / LLP Code', 'Profile', 'Status', 'Actions']) ?>
    <?php foreach ($companies as $c): $continue = companyContinueLink($c); ?>
    <tr>
        <td><?= htmlspecialchars($c['name'] ?? '') ?></td>
        <td><?= strtoupper($c['category'] ?? '') ?></td>
        <td>
            <?php
            $category = $c['category'] ?? '';
            $cin = $c['cin'] ?? '';
            $llp = $c['llp_code'] ?? '';

            if ($category === 'corporate') {
                echo htmlspecialchars($cin);
            } elseif ($category === 'llp') {
                echo htmlspecialchars($llp);
            } else {
                echo '-';
            }
            ?>
        </td>
        <td>
            <?php
            $pct = (int) ($c['profile_completeness'] ?? 0);
            $barColor = $pct >= 80 ? '#047857' : ($pct >= 40 ? '#f59e0b' : '#dc2626');
            ?>
            <div style="display:flex;align-items:center;gap:6px;">
                <div style="width:50px;height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;">
                    <div style="width:<?= $pct ?>%;height:100%;background:<?= $barColor ?>;border-radius:3px;"></div>
                </div>
                <span style="font-size:.75rem;font-weight:600;color:<?= $barColor ?>;"><?= $pct ?>%</span>
            </div>
        </td>
        <td>
            <?php if ((int) ($c['tally_fetched'] ?? 0) === 1): ?>
                <?= uiStatusBadge('Reports Ready', 'success') ?>
            <?php elseif ((int) ($c['mapping_completed'] ?? 0) === 1): ?>
                <?= uiStatusBadge('Trial Balance Pending', 'warning') ?>
            <?php elseif ((int) ($c['ledger_fetched'] ?? 0) === 1): ?>
                <?= uiStatusBadge('Mapping Pending', 'default') ?>
            <?php else: ?>
                <?= uiStatusBadge('Ledger Sync Pending', 'default') ?>
            <?php endif; ?>
        </td>
        <td>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <?= uiButton($continue['label'], $continue['href'], 'primary') ?>
                <?= uiButton('Select', BASE_URL . 'company_dashboard/company_select.php?company_id=' . (int) $c['id'], 'outline') ?>
                <?= uiButton('Edit', BASE_URL . 'company_dashboard/company_edit.php?id=' . (int) $c['id'], 'outline') ?>
                <form method="post" action="company_delete.php" onsubmit="return confirm('Delete this company?')" style="display:inline-flex;margin:0;">
                    <?= csrfInput() ?>
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <?= uiButton('Delete', '', 'danger') ?>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?= uiTableEnd() ?>
<?php endif; ?>

<?php include __DIR__ . '/../layouts/footer_v2.php'; ?>
