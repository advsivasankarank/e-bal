<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';

$page_title = "Dashboard Report";
require_once __DIR__ . '/layouts/header_v2.php';

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$entityStmt = $pdo->prepare("SELECT category FROM companies WHERE id=?");
$entityStmt->execute([$company_id]);
$entityCategory = strtolower(str_replace(['-', ' '], '_', (string) $entityStmt->fetchColumn()));

$stmt = $pdo->prepare("SELECT tally_fetched FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$reportReady = (int) ($workflow['tally_fetched'] ?? 0) === 1;
?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Reports']
]) ?>

<?= uiPageHero('Dashboard Report') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
    'entity_type' => '',
    'profile' => 0,
    'status' => $reportReady ? 'Reports Ready' : 'Setup Required',
    'edit_url' => '',
]) ?>

<?= uiAlert('Use the reporting dashboard after data import and mapping are complete. Open the required statement or note directly from here.', 'info') ?>

<?php if (!$reportReady): ?>
    <?= uiAlert('Reports are locked until the trial balance is fetched for the active company and financial year.', 'warning') ?>
<?php endif; ?>

<?= uiActionCards([
    [
        'label' => 'Reconciliation Console',
        'desc' => 'Validate trial balance totals, unmapped ledgers, profit transfer, balance sheet build, and reconciliation difference before finalising reports.',
        'href' => $reportReady ? BASE_URL . 'reconciliation_console.php?company_id=' . (int) $company_id : '#',
        'disabled' => !$reportReady,
    ],
    [
        'label' => 'Balance Sheet',
        'desc' => 'Generate the balance sheet view for the active company and selected financial year.',
        'href' => $reportReady ? BASE_URL . 'reports.php#balance-sheet' : '#',
        'disabled' => !$reportReady,
    ],
    [
        'label' => 'Profit & Loss',
        'desc' => 'Generate the profit and loss statement using the imported and mapped trial balance.',
        'href' => $reportReady ? BASE_URL . 'reports.php#profit-loss' : '#',
        'disabled' => !$reportReady,
    ],
    [
        'label' => 'Notes To Accounts',
        'desc' => 'Open the category-aware financial statements page and continue into notes and disclosure schedules from the same reporting flow.',
        'href' => $reportReady ? BASE_URL . 'reports.php#notes-to-accounts' : '#',
        'disabled' => !$reportReady,
    ],
    [
        'label' => 'Directors Report',
        'desc' => 'Prepare the corporate directors report after the financial statements are ready, with support for AI-assisted drafting.',
        'href' => ($reportReady && $entityCategory === 'corporate') ? BASE_URL . 'directors_report.php' : '#',
        'disabled' => !$reportReady || $entityCategory !== 'corporate',
    ],
]) ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
