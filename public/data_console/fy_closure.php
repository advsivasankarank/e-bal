<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/fy_closure_helper.php';
require_once '../../app/engines/fs_engine.php';
require_once '../../app/helpers/figure_helper.php';

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

$sourceInvalidated = false;
$sourceInvalidatedAt = null;
if ($status !== 'closed') {
    $invStmt = $pdo->prepare("SELECT invalidated_at FROM fy_opening_balance_sources WHERE company_id = ? AND fy_id = ? AND invalidated_by IS NOT NULL LIMIT 1");
    $invStmt->execute([$company_id, $fy_id]);
    $sourceInvalidatedAt = $invStmt->fetchColumn();
    $sourceInvalidated = $sourceInvalidatedAt !== false;
}

$affectedSubsequentFYs = [];
if ($status === 'closed') {
    $fyStmt = $pdo->prepare("SELECT id, fy_label, status FROM financial_years WHERE company_id = ? AND fy_start > (SELECT fy_start FROM financial_years WHERE id = ?) ORDER BY fy_start");
    $fyStmt->execute([$company_id, $fy_id]);
    foreach ($fyStmt->fetchAll(PDO::FETCH_ASSOC) as $fy) {
        if ($fy['status'] !== 'draft') {
            $affectedSubsequentFYs[] = $fy;
        }
    }
}

$page_title = "Financial Year Closure";
require_once __DIR__ . '/../layouts/header_v2.php';

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

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'dashboard_data.php'],
    ['label' => 'FY Closure']
]) ?>

<?= uiPageHero('Financial Year Closure') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy'      => $fyLabel,
]) ?>

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
                    <?= csrfInput() ?>
                    <button type="submit" class="btn" style="background:#d92d20; color:#fff;">Close Financial Year</button>
                </form>
            <?php endif; ?>
            <?php if ($status === 'closed' && $canClose):
                $reopenWarning = 'Reopening will invalidate carry-forward balances';
                $listFYs = array_map(fn($f) => $f['fy_label'] . ' (status: ' . $f['status'] . ')', $affectedSubsequentFYs);
                if (!empty($listFYs)) {
                    $reopenWarning .= ' in: ' . implode(', ', $listFYs);
                }
                $reopenWarning .= '. Continue?';
            ?>
                <form method="post" action="fy_closure_process.php" onsubmit="return confirm(<?= json_encode($reopenWarning) ?>);">
                    <input type="hidden" name="action" value="reopen">
                    <?= csrfInput() ?>
                    <button type="submit" class="btn" style="background:#b54708; color:#fff;">Reopen Financial Year</button>
                </form>
                <?php if ($status === 'closed' && $canClose && !empty($affectedSubsequentFYs)): ?>
                    <div style="width:100%; margin-top:8px; padding:8px 12px; background:#fff3cd; border-radius:4px; font-size:13px;">
                        <strong>⚠ Subsequent FYs affected by reopening:</strong>
                        <ul style="margin:4px 0 0 16px;">
                            <?php foreach ($affectedSubsequentFYs as $afy): ?>
                                <li><?= htmlspecialchars($afy['fy_label']) ?> (<?= htmlspecialchars($afy['status']) ?>)
                                    <form method="post" action="fy_closure_process.php" style="display:inline;">
                                        <input type="hidden" name="action" value="regenerate_snapshot">
                                        <input type="hidden" name="target_fy_id" value="<?= (int) $afy['id'] ?>">
                                        <?= csrfInput() ?>
                                        <button type="submit" class="btn" style="padding:2px 8px; font-size:11px; background:#157347; color:#fff;">Regen Snapshot</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($sourceInvalidated): ?>
    <div class="card" style="margin-bottom:20px; border-left:4px solid #b54708;">
        <strong style="color:#b54708;">⚠ Opening Balances Invalidated</strong>
        <p style="margin:8px 0 0; color:#667085;">
            The opening balances for this financial year were invalidated on <?= htmlspecialchars(date('d-M-Y H:i', strtotime((string) $sourceInvalidatedAt))) ?> due to the reopening of the previous financial year.
            Comparative figures may be inaccurate until the previous year is re-closed and this year's opening balances are refreshed.
        </p>
    </div>
<?php endif; ?>

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
                    <td class="figure"><?= format_inr_number($amount) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="font-weight:bold;">
                <td>Total</td>
                <td class="figure"><?= format_inr_number(array_sum($snapshotSummary)) ?></td>
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

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
