<?php
/**
 * e-BAL Platform Control Centre
 * Executive Two-Column Dashboard
 */
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
require_once __DIR__ . '/../../app/helpers/health_check_helper.php';

ensurePlanTables($pdo);
ensureUserActivityColumns($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { header('Location: ' . BASE_URL . 'login.php'); exit; }
if (!isSuperAdmin($pdo, $userId)) { header('Location: ' . BASE_URL . 'index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pipeline') {
    requireCsrfToken();
    $pipeline = [
        'enquiries' => max(0, (int)($_POST['enquiries'] ?? 0)),
        'followups' => max(0, (int)($_POST['followups'] ?? 0)),
        'demos' => max(0, (int)($_POST['demos'] ?? 0)),
        'proposals' => max(0, (int)($_POST['proposals'] ?? 0)),
        'converted' => max(0, (int)($_POST['converted'] ?? 0)),
        'lost' => max(0, (int)($_POST['lost'] ?? 0)),
    ];
    saveBusinessPipeline($pipeline);
    header('Location: ' . BASE_URL . 'superadmin/index.php');
    exit;
}

$summary = getSuperAdminSummary($pdo);
$revenueSummary = getRevenueSummary($pdo);
$revenueByMonth = getRevenueByMonth($pdo, 6);
$pipeline = getBusinessPipeline();
$renewalForecast = getRenewalForecast($pdo);
$licenseAttention = getLicenseAttentionCounts($pdo);
$clientActivity = getClientActivityStats($pdo);
$recentActivity = getRecentBusinessActivity($pdo, 5);
$health = checkAllSystemHealth($pdo);

$totalActivePrice = 0;
$stmt = $pdo->query("SELECT SUM(p.price_inr) as total FROM licenses l JOIN plans p ON p.code = l.plan WHERE l.status = 'active'");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$totalActivePrice = (int)($row['total'] ?? 0);
$mrr = round($totalActivePrice / 12);
$arr = $totalActivePrice;
$maxRevenue = max(1, max(array_column($revenueByMonth, 'revenue')));

$activeClients = (int)($summary['client_admins'] ?? 0);
$activeLicenses = (int)($summary['active_licenses'] ?? 0);
$trialLicenses = (int)($summary['trial_licenses'] ?? 0);
$renewalsDue = (int)($licenseAttention['expiring_30'] ?? 0);
$healthScore = $health['score'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform Control Centre | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/platform_control_centre.css?v=<?= filemtime(__DIR__ . '/../asset/css/platform_control_centre.css') ?>">
</head>
<body class="pcc-page">

<div class="pcc-header">
    <h1>e-BAL Platform Control Centre</h1>
    <span><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?> &middot; <a href="<?= BASE_URL ?>logout.php">Logout</a></span>
</div>

<div class="pcc-container">

<!-- ROW 1: KPI Cards -->
<div class="pcc-kpi-row">
    <div class="pcc-kpi">
        <div class="pcc-kpi-label">Active Clients</div>
        <div class="pcc-kpi-value"><?= $activeClients ?></div>
    </div>
    <div class="pcc-kpi">
        <div class="pcc-kpi-label">Active Licenses</div>
        <div class="pcc-kpi-value"><?= $activeLicenses ?></div>
    </div>
    <div class="pcc-kpi">
        <div class="pcc-kpi-label">Trial Licenses</div>
        <div class="pcc-kpi-value gold"><?= $trialLicenses ?></div>
    </div>
    <div class="pcc-kpi">
        <div class="pcc-kpi-label">Renewals Due</div>
        <div class="pcc-kpi-value" style="color:var(--pcc-gold)"><?= $renewalsDue ?></div>
    </div>
    <div class="pcc-kpi">
        <div class="pcc-kpi-label">Monthly Revenue</div>
        <div class="pcc-kpi-value teal">Rs. <?= number_format($mrr) ?></div>
    </div>
    <div class="pcc-kpi">
        <div class="pcc-kpi-label">Platform Health</div>
        <div class="pcc-kpi-value" style="color:<?= $healthScore >= 80 ? 'var(--pcc-success)' : ($healthScore >= 50 ? 'var(--pcc-gold)' : 'var(--pcc-danger)') ?>"><?= $healthScore ?>%</div>
    </div>
</div>

<!-- Snapshot Strip -->
<div class="pcc-snapshot">
    <span><?= $activeClients ?></span> Clients
    <span class="sep">&bull;</span>
    <span><?= $activeLicenses ?></span> Licenses
    <span class="sep">&bull;</span>
    <span>Rs. <?= number_format($mrr) ?></span> MRR
    <span class="sep">&bull;</span>
    <span><?= $renewalsDue ?></span> Renewals Due
    <span class="sep">&bull;</span>
    Health <span><?= $healthScore ?>%</span>
</div>

<!-- Dashboard Grid -->
<div class="pcc-dashboard">

    <!-- ROW 2 LEFT: Pipeline -->
    <div class="pcc-card">
        <div class="pcc-card-head">
            <h3>Business Development Pipeline</h3>
            <button class="pcc-configure-btn" id="pipeline-toggle">Configure</button>
        </div>
        <div class="pcc-card-body">
            <?php
            $stages = [
                ['key' => 'enquiries', 'label' => 'Enquiries'],
                ['key' => 'followups', 'label' => 'Follow-ups'],
                ['key' => 'demos', 'label' => 'Demos'],
                ['key' => 'proposals', 'label' => 'Proposals'],
                ['key' => 'converted', 'label' => 'Converted'],
                ['key' => 'lost', 'label' => 'Lost'],
            ];
            $maxP = max(1, $pipeline['enquiries'] ?? 1);
            foreach ($stages as $s):
                $c = $pipeline[$s['key']] ?? 0;
                $p = $maxP > 0 ? round(($c / $maxP) * 100) : 0;
            ?>
            <div class="pcc-pipeline-bar">
                <span class="pcc-pipeline-label"><?= $s['label'] ?></span>
                <div class="pcc-pipeline-track">
                    <div class="pcc-pipeline-fill" style="width:<?= $p ?>%"></div>
                </div>
                <span class="pcc-pipeline-count"><?= $c ?></span>
                <span class="pcc-pipeline-pct"><?= $p ?>%</span>
            </div>
            <?php endforeach; ?>
            <div id="pipeline-form" style="display:none;">
                <form method="post">
                    <?= csrfInput() ?>
                    <input type="hidden" name="action" value="update_pipeline">
                    <div class="pcc-pipeline-form">
                        <?php foreach ($stages as $s): ?>
                        <div class="fg">
                            <label><?= $s['label'] ?></label>
                            <input type="number" name="<?= $s['key'] ?>" value="<?= $pipeline[$s['key']] ?? 0 ?>" min="0">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:8px"><button type="submit" class="pcc-configure-btn">Save</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- ROW 2 RIGHT: Renewal Forecast -->
    <div class="pcc-card">
        <div class="pcc-card-head"><h3>Renewal Forecast</h3></div>
        <div class="pcc-card-body">
            <div class="pcc-forecast-grid">
                <?php foreach ([30, 60, 90] as $d): ?>
                <div class="pcc-forecast-card">
                    <div class="pcc-forecast-period">Next <?= $d ?> Days</div>
                    <div class="pcc-forecast-amount">Rs. <?= number_format($renewalForecast[$d]['revenue'] ?? 0) ?></div>
                    <div class="pcc-forecast-count"><?= $renewalForecast[$d]['count'] ?? 0 ?> licenses</div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ROW 3 LEFT: Revenue Analytics -->
    <div class="pcc-card">
        <div class="pcc-card-head"><h3>Revenue Analytics</h3></div>
        <div class="pcc-card-body">
            <div class="pcc-revenue-grid">
                <div>
                    <div class="pcc-revenue-item"><span class="pcc-revenue-label">MRR</span><span class="pcc-revenue-value">Rs. <?= number_format($mrr) ?></span></div>
                    <div class="pcc-revenue-item"><span class="pcc-revenue-label">ARR</span><span class="pcc-revenue-value">Rs. <?= number_format($arr) ?></span></div>
                    <div class="pcc-revenue-item"><span class="pcc-revenue-label">Collections</span><span class="pcc-revenue-value">Rs. <?= number_format($revenueSummary['total_revenue'] ?? 0) ?></span></div>
                    <div class="pcc-revenue-item"><span class="pcc-revenue-label">Outstanding</span><span class="pcc-revenue-value">Rs. <?= number_format($revenueSummary['last_90_days_revenue'] ?? 0) ?></span></div>
                </div>
                <div>
                    <?php foreach ($revenueByMonth as $m): ?>
                    <div class="pcc-chart-row">
                        <span class="pcc-chart-month"><?= date('M', strtotime($m['month_key'] . '-01')) ?></span>
                        <div class="pcc-chart-track">
                            <div class="pcc-chart-fill" style="width:<?= $maxRevenue > 0 ? round(($m['revenue'] / $maxRevenue) * 100) : 0 ?>%"></div>
                        </div>
                        <span class="pcc-chart-value">Rs. <?= number_format($m['revenue']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 3 RIGHT: License Attention -->
    <div class="pcc-card">
        <div class="pcc-card-head"><h3>License Attention Centre</h3></div>
        <div class="pcc-card-body">
            <div class="pcc-license-grid">
                <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-license-tile warning">
                    <div class="pcc-license-count"><?= $licenseAttention['expiring_30'] ?? 0 ?></div>
                    <div class="pcc-license-label">Expiring 30d</div>
                </a>
                <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-license-tile danger">
                    <div class="pcc-license-count"><?= $licenseAttention['expiring_15'] ?? 0 ?></div>
                    <div class="pcc-license-label">Expiring 15d</div>
                </a>
                <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-license-tile danger">
                    <div class="pcc-license-count"><?= $licenseAttention['expired'] ?? 0 ?></div>
                    <div class="pcc-license-label">Expired</div>
                </a>
                <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-license-tile warning">
                    <div class="pcc-license-count"><?= $licenseAttention['trial_ending'] ?? 0 ?></div>
                    <div class="pcc-license-label">Trial Ending</div>
                </a>
                <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-license-tile danger">
                    <div class="pcc-license-count"><?= $licenseAttention['suspended'] ?? 0 ?></div>
                    <div class="pcc-license-label">Suspended</div>
                </a>
                <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-license-tile ok">
                    <div class="pcc-license-count"><?= $licenseAttention['pending'] ?? 0 ?></div>
                    <div class="pcc-license-label">Pending</div>
                </a>
            </div>
        </div>
    </div>

    <!-- ROW 4 LEFT: Client Activity -->
    <div class="pcc-card">
        <div class="pcc-card-head"><h3>Client Activity</h3></div>
        <div class="pcc-card-body">
            <div class="pcc-activity-grid">
                <div class="pcc-activity-item green">
                    <div class="pcc-activity-count"><?= $clientActivity['active_week'] ?? 0 ?></div>
                    <div class="pcc-activity-label">Active This Week</div>
                </div>
                <div class="pcc-activity-item green">
                    <div class="pcc-activity-count"><?= $clientActivity['active_month'] ?? 0 ?></div>
                    <div class="pcc-activity-label">Active This Month</div>
                </div>
                <div class="pcc-activity-item amber">
                    <div class="pcc-activity-count"><?= $clientActivity['inactive_30'] ?? 0 ?></div>
                    <div class="pcc-activity-label">Inactive &gt; 30d</div>
                </div>
                <div class="pcc-activity-item red">
                    <div class="pcc-activity-count"><?= $clientActivity['inactive_60'] ?? 0 ?></div>
                    <div class="pcc-activity-label">Inactive &gt; 60d</div>
                </div>
                <div class="pcc-activity-item grey">
                    <div class="pcc-activity-count"><?= $clientActivity['never_logged'] ?? 0 ?></div>
                    <div class="pcc-activity-label">Never Logged In</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ROW 4 RIGHT: Recent Activity -->
    <div class="pcc-card">
        <div class="pcc-card-head"><h3>Recent Activity</h3></div>
        <div class="pcc-card-body" style="padding:0 16px">
            <?php if (empty($recentActivity)): ?>
            <div style="text-align:center;padding:20px;color:var(--pcc-muted);font-size:0.82rem;">No recent activity</div>
            <?php else: ?>
            <?php foreach ($recentActivity as $act):
                $dot = match(true) {
                    str_contains($act['label'], 'converted') => 'conversion',
                    str_contains($act['label'], 'renewed') => 'renewal',
                    str_contains($act['label'], 'upgraded') => 'upgrade',
                    str_contains($act['label'], 'expired') => 'expired',
                    default => 'enquiry',
                };
                $dateStr = $act['date'] ? date('d M, g:i A', strtotime($act['date'])) : '';
            ?>
            <div class="pcc-feed-item">
                <span class="pcc-feed-dot <?= $dot ?>"></span>
                <span class="pcc-feed-label"><?= htmlspecialchars($act['label']) ?></span>
                <span class="pcc-feed-date"><?= $dateStr ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>workspace_admin.php" class="pcc-view-all">View All Activity &rarr;</a>
        </div>
    </div>

    <!-- ROW 5: Platform Health (full width) -->
    <div class="pcc-card pcc-full">
        <div class="pcc-card-head">
            <h3>Platform Health</h3>
            <button class="pcc-configure-btn" onclick="window.location.reload()">Refresh</button>
        </div>
        <div class="pcc-card-body">
            <div class="pcc-health-row">
                <?php
                $healthLabels = ['database'=>'Database','license'=>'License Engine','export'=>'Export Engine','tally_bridge'=>'Tally Bridge','email'=>'Email','backup'=>'Backup','storage'=>'Storage'];
                foreach ($health['checks'] as $key => $check):
                ?>
                <div class="pcc-health-chip">
                    <span class="pcc-health-dot <?= $check['status'] ?>"></span>
                    <?= $healthLabels[$key] ?? $key ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div><!-- /pcc-dashboard -->

</div><!-- /pcc-container -->

<script>
document.getElementById('pipeline-toggle')?.addEventListener('click', function() {
    var f = document.getElementById('pipeline-form');
    if (f) f.style.display = f.style.display === 'none' ? '' : 'none';
});
</script>

</body>
</html>
