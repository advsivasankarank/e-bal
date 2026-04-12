<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';

$page_title = "Dashboard Report";
require_once __DIR__ . '/layouts/header.php';

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

<div class="page-title">Dashboard Report</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card section-card">
    Use the reporting dashboard after data import and mapping are complete. Open the required statement or note directly from here.
</div>

<?php if (!$reportReady): ?>
    <div class="error-box section-card">
        <p>Reports are locked until the trial balance is fetched for the active company and financial year.</p>
    </div>
<?php endif; ?>

<div class="tile-container">
    <div class="tile <?= !$reportReady ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $reportReady ? ' data-nav="' . BASE_URL . 'reconciliation_console.php?company_id=' . (int) $company_id . '"' : '' ?>>
        <h3>Reconciliation Console</h3>
        <p>Validate trial balance totals, unmapped ledgers, profit transfer, balance sheet build, and reconciliation difference before finalising reports.</p>
        <div class="status"><?= $reportReady ? 'Open Console' : 'Trial Balance Required' ?></div>
    </div>

    <div class="tile <?= !$reportReady ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $reportReady ? ' data-nav="' . BASE_URL . 'reports.php#balance-sheet"' : '' ?>>
        <h3>Balance Sheet</h3>
        <p>Generate the balance sheet view for the active company and selected financial year.</p>
        <div class="status"><?= $reportReady ? 'Open Statement' : 'Trial Balance Required' ?></div>
    </div>

    <div class="tile <?= !$reportReady ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $reportReady ? ' data-nav="' . BASE_URL . 'reports.php#profit-loss"' : '' ?>>
        <h3>Profit & Loss</h3>
        <p>Generate the profit and loss statement using the imported and mapped trial balance.</p>
        <div class="status"><?= $reportReady ? 'Open Statement' : 'Trial Balance Required' ?></div>
    </div>

    <div class="tile <?= !$reportReady ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= $reportReady ? ' data-nav="' . BASE_URL . 'reports.php#notes-to-accounts"' : '' ?>>
        <h3>Notes To Accounts</h3>
        <p>Open the category-aware financial statements page and continue into notes and disclosure schedules from the same reporting flow.</p>
        <div class="status"><?= $reportReady ? 'Open Reports' : 'Trial Balance Required' ?></div>
    </div>

    <div class="tile <?= (!$reportReady || $entityCategory !== 'corporate') ? 'disabled' : 'is-clickable' ?>" tabindex="0" role="link"<?= ($reportReady && $entityCategory === 'corporate') ? ' data-nav="' . BASE_URL . 'directors_report.php"' : '' ?>>
        <h3>Directors Report</h3>
        <p>Prepare the corporate directors report after the financial statements are ready, with support for AI-assisted drafting.</p>
        <div class="status">
            <?php if (!$reportReady): ?>
                Trial Balance Required
            <?php elseif ($entityCategory !== 'corporate'): ?>
                Corporate Only
            <?php else: ?>
                Build Draft
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
