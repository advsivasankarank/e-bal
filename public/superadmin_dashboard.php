<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

$page_title = 'Superadmin Dashboard';
ensurePlanTables($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (!isSuperAdmin($pdo, $userId)) {
    $_SESSION['error'] = 'Only superadmin can access the superadmin dashboard.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}

$summary = getSuperAdminSummary($pdo);
$revenueSummary = getRevenueSummary($pdo);
$monthlyRevenue = getRevenueByMonth($pdo, 12);
$recentActivity = getRecentLicenseActivity($pdo, 12);
$clientAdmins = listClientAdmins($pdo);
$maxRevenue = 0;

foreach ($monthlyRevenue as $row) {
    $maxRevenue = max($maxRevenue, (int) ($row['revenue'] ?? 0));
}

require_once __DIR__ . '/layouts/header.php';
?>

<div class="page-title">Superadmin Dashboard</div>

<style>
.superadmin-shell { display:flex; flex-direction:column; gap:18px; }
.metric-grid {
    display:grid;
    grid-template-columns:repeat(4, minmax(180px, 1fr));
    gap:16px;
}
.metric-card {
    background:linear-gradient(180deg, #ffffff 0%, #f7fcfb 100%);
    border:1px solid #d8e2ef;
    border-radius:18px;
    padding:18px;
    box-shadow:var(--shadow);
}
.metric-label { color:#64748b; font-size:13px; text-transform:uppercase; letter-spacing:0.06em; }
.metric-value { margin-top:10px; font-size:34px; font-weight:700; color:#0f172a; }
.metric-note { margin-top:10px; color:#47605d; font-size:13px; line-height:1.5; }
.superadmin-grid {
    display:grid;
    grid-template-columns:1.4fr 1fr;
    gap:18px;
}
.revenue-bars { display:flex; flex-direction:column; gap:12px; margin-top:14px; }
.revenue-row { display:grid; grid-template-columns:96px 1fr 110px; gap:12px; align-items:center; }
.revenue-label { color:#47605d; font-size:13px; font-weight:700; }
.revenue-track { height:12px; border-radius:999px; background:#e7f3f1; overflow:hidden; }
.revenue-fill { height:100%; border-radius:999px; background:linear-gradient(90deg, #0f766e 0%, #19a39b 100%); }
.revenue-value { text-align:right; font-weight:700; color:#0f172a; font-size:13px; }
.mini-grid { display:grid; grid-template-columns:repeat(2, minmax(140px, 1fr)); gap:12px; margin-top:14px; }
.mini-card { border:1px solid #d8e2ef; border-radius:14px; padding:14px; background:#f8fbff; }
.mini-card strong { display:block; font-size:22px; color:#0f172a; margin-top:8px; }
.admin-table { width:100%; border-collapse:collapse; margin-top:14px; }
.admin-table th, .admin-table td { border:1px solid #dbe3ef; padding:10px 12px; text-align:left; vertical-align:top; }
.admin-table th { background:#eff6fb; color:#334155; }
.pill { display:inline-block; padding:5px 10px; border-radius:999px; font-size:12px; font-weight:700; }
.pill-paid, .pill-active { background:#e8f8ef; color:#16663b; }
.pill-pending { background:#fff6da; color:#8a6500; }
.pill-waived { background:#e9f0ff; color:#214f9f; }
.pill-refunded, .pill-expired { background:#fdeceb; color:#9f2d21; }
.plan-list { margin:12px 0 0 18px; line-height:1.8; }
.dashboard-actions { display:flex; gap:12px; flex-wrap:wrap; margin-top:14px; }
@media (max-width: 1024px) {
    .metric-grid { grid-template-columns:repeat(2, minmax(180px, 1fr)); }
    .superadmin-grid { grid-template-columns:1fr; }
}
@media (max-width: 720px) {
    .metric-grid, .mini-grid { grid-template-columns:1fr; }
    .revenue-row { grid-template-columns:1fr; }
    .revenue-value { text-align:left; }
}
</style>

<div class="superadmin-shell">
    <div class="metric-grid">
        <div class="metric-card">
            <div class="metric-label">Client Admins</div>
            <div class="metric-value"><?= (int) $summary['client_admins'] ?></div>
            <div class="metric-note">Number of client workspaces currently under commercial control.</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Active Licenses</div>
            <div class="metric-value"><?= (int) $summary['active_licenses'] ?></div>
            <div class="metric-note">Current active subscriptions across Starter, Professional, and Pro Plus.</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Companies Managed</div>
            <div class="metric-value"><?= (int) $summary['companies'] ?></div>
            <div class="metric-note">Total companies currently created across all subscribed workspaces.</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Expiring in 30 Days</div>
            <div class="metric-value"><?= (int) $summary['expiring_soon'] ?></div>
            <div class="metric-note">Renewal pipeline to watch closely for follow-up and retention.</div>
        </div>
    </div>

    <div class="superadmin-grid">
        <div class="card section-card">
            <strong>Revenue Generated Period Wise</strong><br>
            <div class="mini-grid">
                <div class="mini-card">
                    <span>Total Paid Revenue</span>
                    <strong>Rs.<?= number_format((int) $revenueSummary['total_revenue']) ?></strong>
                </div>
                <div class="mini-card">
                    <span>Current Year</span>
                    <strong>Rs.<?= number_format((int) $revenueSummary['current_year_revenue']) ?></strong>
                </div>
                <div class="mini-card">
                    <span>Current Month</span>
                    <strong>Rs.<?= number_format((int) $revenueSummary['current_month_revenue']) ?></strong>
                </div>
                <div class="mini-card">
                    <span>Last 90 Days</span>
                    <strong>Rs.<?= number_format((int) $revenueSummary['last_90_days_revenue']) ?></strong>
                </div>
            </div>

            <div class="revenue-bars">
                <?php if ($monthlyRevenue === []): ?>
                    <div class="metric-note">No paid billing entries are available yet. Revenue will appear only when billing is recorded as paid.</div>
                <?php else: ?>
                    <?php foreach ($monthlyRevenue as $month): ?>
                        <?php
                        $revenue = (int) ($month['revenue'] ?? 0);
                        $width = $maxRevenue > 0 ? max(8, (int) round(($revenue / $maxRevenue) * 100)) : 0;
                        ?>
                        <div class="revenue-row">
                            <div class="revenue-label"><?= htmlspecialchars((string) $month['month_label']) ?></div>
                            <div class="revenue-track">
                                <div class="revenue-fill" style="width: <?= $width ?>%;"></div>
                            </div>
                            <div class="revenue-value">Rs.<?= number_format($revenue) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card section-card">
            <strong>Commercial Snapshot</strong>
            <ul class="plan-list">
                <?php if ($summary['plan_mix'] === []): ?>
                    <li>No active plans yet.</li>
                <?php else: ?>
                    <?php foreach ($summary['plan_mix'] as $planMix): ?>
                        <li><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $planMix['plan']))) ?>: <?= (int) $planMix['total'] ?> active</li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <div class="mini-grid">
                <div class="mini-card">
                    <span>Workspace Users</span>
                    <strong><?= (int) $summary['workspace_users'] ?></strong>
                </div>
                <div class="mini-card">
                    <span>Expired Licenses</span>
                    <strong><?= (int) $summary['expired_licenses'] ?></strong>
                </div>
            </div>
            <div class="dashboard-actions">
                <a class="btn" href="<?= BASE_URL ?>workspace_admin.php">Open Licensing Console</a>
            </div>
        </div>
    </div>

    <div class="card section-card">
        <strong>Client Admin Accounts</strong>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($clientAdmins === []): ?>
                    <tr><td colspan="3">No client admin accounts created yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($clientAdmins as $clientAdmin): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $clientAdmin['name']) ?></td>
                            <td><?= htmlspecialchars((string) $clientAdmin['email']) ?></td>
                            <td><?= htmlspecialchars((string) $clientAdmin['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card section-card">
        <strong>Recent Billing Activity</strong>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Plan</th>
                    <th>Amount</th>
                    <th>Billing Type</th>
                    <th>Status</th>
                    <th>Billed On</th>
                    <th>Recorded</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentActivity === []): ?>
                    <tr><td colspan="7">No billing activity recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentActivity as $activity): ?>
                        <?php
                        $statusClass = 'pill-pending';
                        $status = (string) ($activity['status'] ?? '');
                        if ($status === 'paid') {
                            $statusClass = 'pill-paid';
                        } elseif ($status === 'waived') {
                            $statusClass = 'pill-waived';
                        } elseif ($status === 'refunded') {
                            $statusClass = 'pill-refunded';
                        }
                        ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars((string) $activity['user_name']) ?><br>
                                <span style="color:#64748b; font-size:12px;"><?= htmlspecialchars((string) $activity['user_email']) ?></span>
                            </td>
                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $activity['plan']))) ?></td>
                            <td>Rs.<?= number_format((int) ($activity['price_inr'] ?? 0)) ?></td>
                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($activity['transaction_type'] ?? 'renewal')))) ?></td>
                            <td>
                                <span class="pill <?= $statusClass ?>">
                                    <?= htmlspecialchars(ucfirst($status)) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars((string) ($activity['billed_at'] ?? '')) ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($activity['created_at'] ?? '')) ?>
                                <?php if (!empty($activity['notes'])): ?>
                                    <br><span style="color:#64748b; font-size:12px;"><?= htmlspecialchars((string) $activity['notes']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
