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

require_once __DIR__ . '/layouts/header_v2.php';
?>

<style>
:root {
    --font-sans: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    --bg: #f1f5f9; --panel: #fff; --border: #e2e8f0;
    --text: #0f172a; --muted: #64748b; --brand: #0f4c81;
    --success: #16a34a; --warning: #d97706; --danger: #dc2626;
    --radius: 10px; --shadow: 0 1px 3px rgba(0,0,0,0.08);
}

.kpi-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
}
.kpi-card .num { font-size: 2rem; font-weight: 700; color: var(--brand); line-height: 1; }
.kpi-card .lbl { font-size: 0.8rem; color: var(--muted); margin-top: 4px; }
.kpi-card .trend { font-size: 0.75rem; font-weight: 600; margin-top: 6px; }
.kpi-card .trend.up { color: var(--success); }
.kpi-card .trend.down { color: var(--danger); }
.kpi-card .kpi-icon { position: absolute; top: 16px; right: 16px; font-size: 1.6rem; opacity: 0.15; }

.admin-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }

.card-box {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: var(--shadow);
    overflow: hidden;
}
.card-box .card-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.card-box .card-header h3 { font-size: 0.95rem; margin: 0; }
.card-box .card-body { padding: 16px 18px; }

.chart-container { margin-bottom: 16px; }
.chart-bar-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    font-size: 0.8rem;
}
.chart-bar-row .label {
    width: 70px;
    text-align: right;
    color: var(--muted);
    flex-shrink: 0;
}
.chart-bar-row .bar-track {
    flex: 1;
    height: 24px;
    background: var(--bg);
    border-radius: 6px;
    overflow: hidden;
}
.chart-bar-row .bar-track .bar-fill {
    height: 100%;
    border-radius: 6px;
    transition: width 0.5s;
}
.chart-bar-row .bar-value {
    width: 70px;
    text-align: right;
    font-weight: 600;
}
.chart-legend {
    display: flex;
    gap: 16px;
    font-size: 0.75rem;
    color: var(--muted);
    margin-top: 8px;
}
.chart-legend span { display: flex; align-items: center; gap: 4px; }
.chart-legend .swatch { width: 10px; height: 10px; border-radius: 2px; }

.activity-item {
    display: flex;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
}
.activity-item:last-child { border-bottom: 0; }
.activity-item .dot {
    width: 8px; height: 8px; border-radius: 50%;
    margin-top: 5px; flex-shrink: 0;
}
.activity-item .dot.create { background: var(--success); }
.activity-item .dot.import { background: #2563eb; }
.activity-item .dot.export { background: var(--warning); }
.activity-item .dot.alert { background: var(--danger); }
.activity-item .act-text { flex: 1; }
.activity-item .act-text .highlight { font-weight: 600; }
.activity-item .act-time {
    font-size: 0.75rem;
    color: var(--muted);
    white-space: nowrap;
}

.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
    padding: 10px 12px; text-align: left;
    font-size: 0.75rem; font-weight: 600;
    color: var(--muted); text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1px solid var(--border);
    background: #fafbfc;
}
.data-table td {
    padding: 10px 12px; font-size: 0.85rem;
    border-bottom: 1px solid var(--border);
}
.data-table .badge {
    display: inline-block; font-size: 0.7rem;
    padding: 2px 8px; border-radius: 999px; font-weight: 600;
}
.data-table .badge.active { background: #dcfce7; color: var(--success); }
.data-table .badge.expiring { background: #fffbeb; color: var(--warning); }
.data-table .badge.expired { background: #fef2f2; color: var(--danger); }
.data-table .badge.trial { background: #eff6ff; color: #2563eb; }

.action-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.action-btn {
    padding: 14px; border: 1px solid var(--border); border-radius: 10px;
    text-align: center; cursor: pointer; font-size: 0.85rem; font-weight: 500;
    transition: all 0.15s; background: #fafbfc; text-decoration: none; display: block; color: inherit;
}
.action-btn:hover { border-color: var(--brand); background: #f8faff; }
.action-btn .icon { font-size: 1.2rem; margin-bottom: 4px; }

.sys-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.sys-item {
    border-radius: 10px; padding: 14px; text-align: center; border: 1px solid;
}
.sys-item .sys-num { font-size: 1.4rem; font-weight: 700; }
.sys-item .sys-lbl { font-size: 0.8rem; color: var(--muted); }
.sys-item.good { background: #f0fdf4; border-color: #bbf7d0; }
.sys-item.good .sys-num { color: var(--success); }
.sys-item.warn { background: #fffbeb; border-color: #fde68a; }
.sys-item.warn .sys-num { color: var(--warning); }
.sys-item.bad { background: #fef2f2; border-color: #fecaca; }
.sys-item.bad .sys-num { color: var(--danger); }

.full-table-wrap { width: 100%; overflow-x: auto; }
.pill { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:700; }
.pill-paid, .pill-active { background:#dcfce7; color:var(--success); }
.pill-pending { background:#fffbeb; color:var(--warning); }
.pill-waived { background:#eff6ff; color:#2563eb; }
.pill-refunded, .pill-expired { background:#fef2f2; color:var(--danger); }

@media (max-width: 1024px) {
    .kpi-row { grid-template-columns: repeat(2, 1fr); }
    .admin-grid { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .kpi-row { grid-template-columns: 1fr; }
}
</style>

<?= uiBreadcrumb([
    ['label' => 'Admin', 'href' => BASE_URL . 'superadmin_dashboard.php'],
    ['label' => 'Dashboard']
]) ?>

<?= uiPageHero('Admin Dashboard', 'System overview and monitoring · Superadmin access') ?>

<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-icon">&#127962;</div>
        <div class="num"><?= (int) $summary['companies'] ?></div>
        <div class="lbl">Active Companies</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">&#128273;</div>
        <div class="num"><?= (int) $summary['active_licenses'] ?></div>
        <div class="lbl">Active Licenses</div>
        <div class="trend up"><?= (int) $summary['expiring_soon'] ?> expiring in 30 days</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">&#128228;</div>
        <div class="num"><?= (int) $summary['client_admins'] ?></div>
        <div class="lbl">Client Admins</div>
        <div class="trend up"><?= (int) $summary['workspace_users'] ?> workspace users</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">&#128190;</div>
        <div class="num">Rs.<?= number_format((int) $revenueSummary['total_revenue'], 0) ?></div>
        <div class="lbl">Total Revenue</div>
        <div class="trend up">Rs.<?= number_format((int) $revenueSummary['current_year_revenue'], 0) ?> this year</div>
    </div>
</div>

<div class="admin-grid">
    <div>
        <div class="card-box" style="margin-bottom:20px;">
            <div class="card-header">
                <h3>Monthly Revenue</h3>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <?php if ($monthlyRevenue === []): ?>
                        <div style="text-align:center;padding:20px;color:var(--muted);">No paid billing entries available yet.</div>
                    <?php else: ?>
                        <?php foreach ($monthlyRevenue as $month): ?>
                            <?php
                            $revenue = (int) ($month['revenue'] ?? 0);
                            $width = $maxRevenue > 0 ? max(6, (int) round(($revenue / $maxRevenue) * 100)) : 0;
                            ?>
                            <div class="chart-bar-row">
                                <span class="label"><?= htmlspecialchars((string) $month['month_label']) ?></span>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width:<?= $width ?>%;background:linear-gradient(90deg,var(--brand),var(--success));"></div>
                                </div>
                                <span class="bar-value">Rs.<?= number_format($revenue) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-box">
            <div class="card-header">
                <h3>Recent Billing Activity</h3>
                <a href="<?= BASE_URL ?>workspace_admin.php" style="font-size:0.8rem;color:var(--brand);text-decoration:none;">View all</a>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if ($recentActivity === []): ?>
                    <div style="padding:16px 18px;color:var(--muted);font-size:0.85rem;">No billing activity recorded yet.</div>
                <?php else: ?>
                    <?php foreach (array_slice($recentActivity, 0, 6) as $activity): ?>
                        <?php
                        $status = (string) ($activity['status'] ?? '');
                        $dotType = 'create';
                        if ($status === 'paid') { $dotType = 'import'; }
                        elseif ($status === 'refunded') { $dotType = 'alert'; }
                        elseif ($status === 'pending') { $dotType = 'export'; }
                        ?>
                        <div class="activity-item" style="padding:10px 18px;">
                            <span class="dot <?= $dotType ?>"></span>
                            <div class="act-text">
                                <span class="highlight"><?= htmlspecialchars((string) ($activity['user_name'] ?? '')) ?></span>
                                &mdash; <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($activity['plan'] ?? '')))) ?>
                                &middot; Rs.<?= number_format((int) ($activity['price_inr'] ?? 0)) ?>
                                <span class="pill <?= 'pill-' . $status ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                            </div>
                            <span class="act-time"><?= htmlspecialchars((string) ($activity['billed_at'] ?? '')) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <div class="card-box" style="margin-bottom:20px;">
            <div class="card-header">
                <h3>License Overview</h3>
                <a class="btn" href="<?= BASE_URL ?>workspace_admin.php" style="padding:4px 12px;font-size:0.75rem;min-height:auto;">+ Assign License</a>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="full-table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Client Admin</th>
                                <th>Plan</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($clientAdmins === []): ?>
                                <tr><td colspan="3" style="text-align:center;color:var(--muted);">No client admin accounts created yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($clientAdmins as $clientAdmin):
                                    $adminPlan = getUserPlan((int) $clientAdmin['id'], $pdo);
                                    $planName = $adminPlan ? htmlspecialchars(ucwords(str_replace('_', ' ', $adminPlan['plan_name']))) : 'No plan';
                                    $badgeClass = 'trial';
                                    if ($adminPlan) {
                                        $badgeClass = match ($adminPlan['status']) {
                                            'active' => 'active',
                                            'expiring' => 'expiring',
                                            'expired' => 'expired',
                                            default => 'trial',
                                        };
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars((string) $clientAdmin['name']) ?><br>
                                            <span style="color:var(--muted);font-size:0.75rem;"><?= htmlspecialchars((string) $clientAdmin['email']) ?></span>
                                        </td>
                                        <td>
                                            <span style="font-size:0.85rem;"><?= $planName ?></span>
                                        </td>
                                        <td><span class="badge <?= $badgeClass ?>"><?= ucfirst($badgeClass) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card-box" style="margin-bottom:20px;">
            <div class="card-header"><h3>Quick Actions</h3></div>
            <div class="card-body">
                <div class="action-grid">
                    <a class="action-btn" href="<?= BASE_URL ?>company_dashboard/company_create.php">
                        <div class="icon">&#127962;</div>
                        <div>Create Company</div>
                    </a>
                    <a class="action-btn" href="<?= BASE_URL ?>workspace_admin.php">
                        <div class="icon">&#128273;</div>
                        <div>Assign License</div>
                    </a>
                    <a class="action-btn" href="<?= BASE_URL ?>dashboard_main.php">
                        <div class="icon">&#128202;</div>
                        <div>Usage Report</div>
                    </a>
                    <a class="action-btn" href="<?= BASE_URL ?>workspace_admin.php">
                        <div class="icon">&#128138;</div>
                        <div>Health Check</div>
                    </a>
                </div>
            </div>
        </div>

        <div class="card-box">
            <div class="card-header"><h3>System Health</h3></div>
            <div class="card-body">
                <div class="sys-grid">
                    <div class="sys-item good">
                        <div class="sys-num"><?= (int) $summary['active_licenses'] ?></div>
                        <div class="sys-lbl">Active Licenses</div>
                    </div>
                    <div class="sys-item good">
                        <div class="sys-num"><?= (int) $summary['workspace_users'] ?></div>
                        <div class="sys-lbl">Workspace Users</div>
                    </div>
                    <div class="sys-item <?= (int) ($summary['expired_licenses'] ?? 0) > 0 ? 'warn' : 'good' ?>">
                        <div class="sys-num"><?= (int) ($summary['expired_licenses'] ?? 0) ?></div>
                        <div class="sys-lbl">Expired Licenses</div>
                    </div>
                    <div class="sys-item <?= (int) ($summary['expiring_soon'] ?? 0) > 3 ? 'warn' : 'good' ?>">
                        <div class="sys-num"><?= (int) ($summary['expiring_soon'] ?? 0) ?></div>
                        <div class="sys-lbl">Expiring in 30 days</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
