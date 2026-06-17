<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/fy_closure_helper.php';
require_once '../../app/engines/fs_engine.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);
$userId     = (int) ($_SESSION['user_id'] ?? 0);

if ($company_id <= 0 || $fy_id <= 0) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

ensureFYClosureSchema($pdo);

$status = getFYStatus($pdo, $company_id, $fy_id);
$closureInfo = getFYClosureInfo($pdo, $company_id, $fy_id);
$prevFYId = getPreviousFYId($pdo, $company_id, $fy_id);
$nextFYId = getNextFYId($pdo, $company_id, $fy_id);
$auditLog = getFYClosureAuditLog($pdo, $company_id, $fy_id);
$hasPrevData = $prevFYId !== null && getFYStatus($pdo, $company_id, $prevFYId) === 'closed';
$canClose = userCanCloseFY($pdo, $userId);

$snapshotSummary = [];
$validationFailures = [];
$comparativeIssues = [];

if ($status === 'closed') {
    $snapshotSummary = getClosingSnapshotSummary($pdo, $company_id, $fy_id);
} else {
    $validationFailures = getValidationFailures($pdo, $company_id, $fy_id);
    if (empty($validationFailures)) {
        $validationFailures = validateFYClosure($pdo, $company_id, $fy_id);
    }
}

if ($hasPrevData) {
    $comparativeIssues = buildComparativeValidation($pdo, $company_id, $fy_id);
}

$page_title = "Financial Year Closure";
require_once __DIR__ . '/../layouts/header.php';

$statusLabels = ['draft' => 'Draft', 'finalized' => 'Finalized', 'closed' => 'Closed'];
$statusColors = ['draft' => '#667085', 'finalized' => '#b54708', 'closed' => '#067647'];
$currentStatusLabel = $statusLabels[$status] ?? 'Unknown';
$currentStatusColor = $statusColors[$status] ?? '#667085';

$companyStmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyName = (string) $companyStmt->fetchColumn();
$fyStmt = $pdo->prepare("SELECT fy_label FROM financial_years WHERE id = ? AND company_id = ?");
$fyStmt->execute([$fy_id, $company_id]);
$fyLabel = (string) $fyStmt->fetchColumn();
?>

<div class="page-title">Financial Year Closure</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($companyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fyLabel) ?></strong>
</div>

<?php if (!empty($_SESSION['closure_message'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['closure_message']) ?></p></div>
    <?php unset($_SESSION['closure_message']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['closure_error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['closure_error']) ?></p></div>
    <?php unset($_SESSION['closure_error']); ?>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <strong style="font-size:18px;">Status: <span style="color:<?= $currentStatusColor ?>;"><?= $currentStatusLabel ?></span></strong>
            <?php if ($status === 'closed' && !empty($closureInfo['closed_at'])): ?>
                <div style="margin-top:4px; color:#667085;">
                    Closed on <?= date('d-M-Y H:i', strtotime((string) $closureInfo['closed_at'])) ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <?php if ($status !== 'closed' && $canClose): ?>
                <form method="post" action="fy_closure_process.php" onsubmit="return confirm('Are you sure you want to close this financial year? This action cannot be undone without reopening.');">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '') ?>">
                    <button type="submit" class="btn" style="background:#d92d20; color:#fff;">Close Financial Year</button>
                </form>
            <?php endif; ?>
            <?php if ($status === 'closed' && $canClose): ?>
                <form method="post" action="fy_closure_process.php" onsubmit="return confirm('Reopening will invalidate carry-forward balances in subsequent years. Continue?');">
                    <input type="hidden" name="action" value="reopen">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['_csrf_token'] ?? '') ?>">
                    <button type="submit" class="btn" style="background:#b54708; color:#fff;">Reopen Financial Year</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($status === 'closed' && !empty($snapshotSummary)): ?>
<div class="card" style="margin-bottom:20px;">
    <strong>Closing Balance Summary</strong>
    <div style="margin-top:12px; overflow-x:auto;">
        <table border="1" cellpadding="6" cellspacing="0" width="100%">
            <tr>
                <th>Schedule Head</th>
                <th class="figure">Amount (₹)</th>
            </tr>
            <?php foreach ($snapshotSummary as $code => $amount): ?>
                <tr>
                    <td><?= htmlspecialchars(scheduleCodeLabel($code)) ?></td>
                    <td class="figure"><?= number_format($amount, 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight:bold;">
                <td>Total</td>
                <td class="figure"><?= number_format(array_sum($snapshotSummary), 2) ?></td>
            </tr>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($status !== 'closed'): ?>
    <?php
    $errors = array_filter($validationFailures, fn($f) => $f['severity'] === 'error');
    $warnings = array_filter($validationFailures, fn($f) => $f['severity'] === 'warning');
    ?>
    <?php if (!empty($errors)): ?>
        <div class="card" style="margin-bottom:20px; border-left:4px solid #d92d20;">
            <strong style="color:#d92d20;">Validation Errors</strong>
            <p style="margin:8px 0 0; color:#667085;">These issues must be resolved before closure.</p>
            <ul style="margin:12px 0 0 18px; color:#b42318;">
                <?php foreach ($errors as $e): ?>
                    <li style="margin-bottom:6px;"><?= htmlspecialchars($e['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($warnings)): ?>
        <div class="card" style="margin-bottom:20px; border-left:4px solid #b54708;">
            <strong style="color:#b54708;">Warnings</strong>
            <ul style="margin:8px 0 0 18px; color:#667085;">
                <?php foreach ($warnings as $w): ?>
                    <li style="margin-bottom:4px;"><?= htmlspecialchars($w['message']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (empty($errors) && empty($warnings)): ?>
        <div class="card" style="margin-bottom:20px; border-left:4px solid #067647;">
            <strong style="color:#067647;">All Checks Passed</strong>
            <p style="margin:4px 0 0; color:#667085;">This financial year is ready for closure.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($hasPrevData && !empty($comparativeIssues['issues'])): ?>
<div class="card" style="margin-bottom:20px; border-left:4px solid #b54708;">
    <strong>Comparative Reconciliation Issues</strong>
    <ul style="margin:8px 0 0 18px; color:#b42318;">
        <?php foreach ($comparativeIssues['issues'] as $issue): ?>
            <li><?= htmlspecialchars($issue) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if (!empty($auditLog)): ?>
<div class="card" style="margin-bottom:20px;">
    <strong>Audit Trail</strong>
    <div style="margin-top:12px; overflow-x:auto;">
        <table border="1" cellpadding="6" cellspacing="0" width="100%">
            <tr>
                <th>Action</th>
                <th>Performed By</th>
                <th>Date &amp; Time</th>
                <th>Reason / Notes</th>
            </tr>
            <?php foreach ($auditLog as $log): ?>
                <tr>
                    <td><strong><?= htmlspecialchars(ucfirst((string) ($log['action'] ?? ''))) ?></strong></td>
                    <td><?= htmlspecialchars((string) ($log['performed_by'] ?? '—')) ?></td>
                    <td><?= htmlspecialchars(date('d-M-Y H:i', strtotime((string) ($log['performed_at'] ?? '')))) ?></td>
                    <td><?= htmlspecialchars((string) ($log['reason'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;">
    <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">View Trial Balance</a>
    <a class="btn" href="<?= BASE_URL ?>reports.php">View Reports</a>
</div>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
