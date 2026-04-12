<?php
require_once '../../config/database.php';
require_once '../../app/workflow_engine.php';
ensureWorkflowColumns();
include __DIR__ . '/../layouts/header.php';

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$filterTitle = 'All Companies';
$activeFyId = (int) ($_SESSION['fy_id'] ?? 0);
$workflowJoin = " LEFT JOIN workflow_status ws ON ws.company_id = c.id" . ($activeFyId > 0 ? " AND ws.fy_id = {$activeFyId}" : '');

$sql = "
    SELECT
        c.*,
        COALESCE(MAX(ws.ledger_fetched), 0) AS ledger_fetched,
        COALESCE(MAX(ws.mapping_completed), 0) AS mapping_completed,
        COALESCE(MAX(ws.tally_fetched), 0) AS tally_fetched
    FROM companies c
    {$workflowJoin}
";

$where = '';
switch ($statusFilter) {
    case 'pending':
        $where = " WHERE c.id NOT IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE mapping_completed = 1 AND tally_fetched = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Pending Companies';
        break;
    case 'completed':
        $where = " WHERE c.id IN (
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
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE ledger_fetched = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Ledger Sync Completed';
        break;
    case 'mapping':
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE mapping_completed = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Mapping Completed';
        break;
    case 'trial_balance':
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE tally_fetched = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Trial Balance Completed';
        break;
    case 'notes':
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE notes_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Notes Prepared';
        break;
    case 'profit_loss':
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE profit_loss_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Profit and Loss Prepared';
        break;
    case 'balance_sheet':
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE balance_sheet_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Balance Sheet Prepared';
        break;
    case 'directors_report':
        $where = " WHERE c.id IN (
            SELECT DISTINCT company_id
            FROM workflow_status
            WHERE directors_report_prepared = 1" . ($activeFyId > 0 ? " AND fy_id = {$activeFyId}" : '') . "
        )";
        $filterTitle = 'Directors Report Prepared';
        break;
    case 'reports':
        $where = " WHERE c.id IN (
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

$sql .= $where . " GROUP BY c.id ORDER BY c.id DESC";
$stmt = $pdo->query($sql);
$companies = $stmt->fetchAll();

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
        return ['label' => 'Fetch Trial Balance', 'href' => $base . 'company_dashboard/company_select.php?company_id=' . (int) $company['id'] . '&next=data_console/tally_connect.php'];
    }

    return ['label' => 'Open Reports', 'href' => $base . 'company_dashboard/company_select.php?company_id=' . (int) $company['id'] . '&next=dashboard_report.php'];
}
?>

<div class="page-title"><?= htmlspecialchars($filterTitle) ?></div>

<style>
.company-table { width:100%; border-collapse:collapse; }
.company-table th, .company-table td { border:1px solid #dbe3ef; padding:10px 12px; vertical-align:top; }
.company-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.company-actions a,
.company-actions button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:88px;
    padding:7px 12px;
    border-radius:8px;
    border:1px solid #cfd8e3;
    background:#f8fbff;
    color:#1e4f91;
    text-decoration:none;
    cursor:pointer;
    font:inherit;
    line-height:1.2;
}
.company-actions a.primary {
    background:#1e5aa8;
    border-color:#1e5aa8;
    color:#fff;
}
.company-actions a.warn,
.company-actions button.warn {
    color:#a2431f;
    border-color:#e6c7bb;
    background:#fff8f5;
}
.company-actions form { display:inline-flex; margin:0; }
</style>

<?php if (isset($_GET['success'])): ?>
    <div class="success-box"><p>Company created successfully.</p></div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="success-box"><p>Company updated successfully.</p></div>
<?php endif; ?>

<?php if (isset($_GET['deleted'])): ?>
    <div class="success-box"><p>Company deleted successfully.</p></div>
<?php endif; ?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_company'): ?>
    <div class="error-box"><p>Invalid company selected for deletion.</p></div>
<?php endif; ?>

<a href="company_create.php" class="btn">+ Add Company</a>

<div class="card" style="margin:14px 0;">
    <strong>View</strong>:
    <a href="company_list.php?status=active">Active</a> |
    <a href="company_list.php?status=pending">Pending</a> |
    <a href="company_list.php?status=completed">Completed</a> |
    <a href="company_list.php?status=ledger_sync">Ledger Sync</a> |
    <a href="company_list.php?status=mapping">Mapping</a> |
    <a href="company_list.php?status=trial_balance">Trial Balance</a> |
    <a href="company_list.php?status=notes">Notes</a> |
    <a href="company_list.php?status=profit_loss">Profit and Loss</a> |
    <a href="company_list.php?status=balance_sheet">Balance Sheet</a> |
    <a href="company_list.php?status=directors_report">Directors Report</a> |
    <a href="company_list.php?status=reports">Reports</a>
</div>

<table class="company-table">
    <tr>
        <th>Name</th>
        <th>Category</th>
        <th>CIN / LLP Code</th>
        <th>Status</th>
        <th>Actions</th>
    </tr>

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
            <?php if ((int) ($c['tally_fetched'] ?? 0) === 1): ?>
                Reports Ready
            <?php elseif ((int) ($c['mapping_completed'] ?? 0) === 1): ?>
                Trial Balance Pending
            <?php elseif ((int) ($c['ledger_fetched'] ?? 0) === 1): ?>
                Mapping Pending
            <?php else: ?>
                Ledger Sync Pending
            <?php endif; ?>
        </td>
        <td>
            <div class="company-actions">
                <a class="primary" href="<?= htmlspecialchars($continue['href']) ?>"><?= htmlspecialchars($continue['label']) ?></a>
                <a href="company_select.php?company_id=<?= (int) $c['id'] ?>">Select</a>
                <a href="company_edit.php?id=<?= (int) $c['id'] ?>">Edit</a>
                <form method="post" action="company_delete.php" onsubmit="return confirm('Delete this company?')">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="warn" type="submit">Delete</button>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
