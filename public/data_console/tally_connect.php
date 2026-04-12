<?php
require_once '../../app/context_check.php';
require_once '../../xml_engine/tally_connector.php';
require_once '../../config/database.php';
require_once '../../app/helpers/xml_sanitizer.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page_title = "Tally Connect";
require_once __DIR__ . '/../layouts/header.php';

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$fy_label = $_SESSION['fy_name'] ?? '';
$selectedCompanyName = $_SESSION['company_name'] ?? 'Not Selected';
$pageError = $_SESSION['error'] ?? '';

$stmt = $pdo->prepare("SELECT ledger_fetched, mapping_completed, tally_fetched FROM workflow_status WHERE company_id=? AND fy_id=?");
$stmt->execute([$company_id, $fy_id]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$mappingDone = (int) ($workflow['mapping_completed'] ?? 0);
$tallyFetched = (int) ($workflow['tally_fetched'] ?? 0);
$tallyContext = fetchTallyLiveContext();
$tallyConnected = $tallyContext !== null;

$selectedCompanyNormalized = strtolower(trim(preg_replace('/\s+/', ' ', (string) $selectedCompanyName)));
$liveCompanyNormalized = strtolower(trim(preg_replace('/\s+/', ' ', (string) ($tallyContext['company_name'] ?? ''))));
$companyMismatch = $tallyContext && $liveCompanyNormalized !== '' && $selectedCompanyNormalized !== '' && $selectedCompanyNormalized !== $liveCompanyNormalized;
$hasLiveCompanyName = $tallyContext && $liveCompanyNormalized !== '';
$hasLivePeriod = $tallyContext && !empty($tallyContext['period_from']) && !empty($tallyContext['period_to']);
?>

<div class="page-title">Tally Integration</div>

<?php if ($tallyConnected): ?>
    <div class="success">✅ Tally Connected</div>
<?php else: ?>
    <div class="error-box">
        <p>Tally is not reachable right now. Check that Tally is running with XML over HTTP enabled on port 9000, then retry.</p>
    </div>
<?php endif; ?>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($selectedCompanyName) ?></strong><br>
    FY: <strong><?= htmlspecialchars($fy_label) ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    Trial balance will be fetched directly for the active FY. Make sure mapping is complete before starting the fetch.
</div>

<?php if ($tallyFetched === 1): ?>
    <div class="card" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Trial balance is already fetched for this company and financial year. Do you want to continue with review/reports, or fetch the trial balance again?
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>dashboard_report.php">Go to Reports</a>
        </div>
    </div>
<?php endif; ?>

<?php if ($pageError !== ''): ?>
    <div class="error-box" style="margin-bottom:20px;">
        <p><?= htmlspecialchars($pageError) ?></p>
    </div>
<?php endif; ?>

<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= $tallyConnected ? 'Connected' : 'Offline' ?></div>
        <div class="summary-label">Tally Status</div>
    </div>
    <?php if ($hasLiveCompanyName): ?>
        <div class="summary-card">
            <div class="summary-number" style="font-size:1.1rem;"><?= htmlspecialchars($tallyContext['company_name']) ?></div>
            <div class="summary-label">Current Tally Company</div>
        </div>
    <?php endif; ?>
    <?php if ($hasLivePeriod): ?>
        <div class="summary-card">
            <div class="summary-number" style="font-size:1rem;"><?= htmlspecialchars($tallyContext['period_from'] . ' to ' . $tallyContext['period_to']) ?></div>
            <div class="summary-label">Current Tally Period</div>
        </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom:20px;">
    Selected app FY: <strong><?= htmlspecialchars($fy_label) ?></strong><br>
    <?php if ($hasLiveCompanyName && $hasLivePeriod): ?>
        Live Tally company: <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong><br>
        Live Tally books appear open from <strong><?= htmlspecialchars($tallyContext['period_from']) ?></strong> to <strong><?= htmlspecialchars($tallyContext['period_to']) ?></strong>.
    <?php elseif ($hasLiveCompanyName): ?>
        Live Tally company: <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong><br>
        Tally is reachable, but the current books period could not be read from the live session.
    <?php elseif ($hasLivePeriod): ?>
        Live Tally books appear open from <strong><?= htmlspecialchars($tallyContext['period_from']) ?></strong> to <strong><?= htmlspecialchars($tallyContext['period_to']) ?></strong>.<br>
        Tally is reachable, but the current company name could not be read from the live session.
    <?php else: ?>
        Tally is reachable, but the current company name and books period could not be read from the live session.
    <?php endif; ?>
</div>

<?php if ($companyMismatch): ?>
    <div class="error-box">
        <p>Selected company in e-BAL is <strong><?= htmlspecialchars($selectedCompanyName) ?></strong>, but the live company open in Tally is <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong>. Review this carefully before fetching the trial balance.</p>
    </div>
<?php endif; ?>

<?php if (!$mappingDone): ?>
    <div class="error-box">
        <p>Complete ledger mapping before fetching the trial balance.</p>
    </div>
<?php endif; ?>

<form method="post" action="<?= BASE_URL ?>data_console/fetch_process.php">

    <input type="hidden" name="confirmed" value="1">
    <input type="hidden" name="live_company_name" value="<?= htmlspecialchars($tallyContext['company_name'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="live_period_from" value="<?= htmlspecialchars($tallyContext['period_from'] ?? '', ENT_QUOTES) ?>">
    <input type="hidden" name="live_period_to" value="<?= htmlspecialchars($tallyContext['period_to'] ?? '', ENT_QUOTES) ?>">
    <?php if ($companyMismatch): ?>
        <label style="display:flex; align-items:flex-start; gap:10px; margin:16px 0;">
            <input type="checkbox" name="company_mismatch_confirmed" value="1" required>
            <span>I confirm that I still want to fetch the trial balance from the live Tally company <strong><?= htmlspecialchars($tallyContext['company_name']) ?></strong> even though it does not match the selected e-BAL company <strong><?= htmlspecialchars($selectedCompanyName) ?></strong>.</span>
        </label>
    <?php endif; ?>

    <button type="submit" class="btn" <?= (!$mappingDone || !$tallyConnected) ? 'disabled' : '' ?>><?= $tallyFetched === 1 ? 'Re-fetch Trial Balance' : 'Fetch Trial Balance' ?></button>

</form>

<?php unset($_SESSION['error']); ?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
