<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';

/* V2 mode: delegate to V2 shell when flag is set */
if (!empty($_SESSION['v2_mode'])) {
    require_once __DIR__ . '/header_v2.php';
    return;
}

require_once __DIR__ . '/../../app/middleware/license_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';

if (!isset($page_title)) {
    $page_title = 'e-BAL';
}

$appCssPath = __DIR__ . '/../asset/css/app.css';
$appCssVersion = file_exists($appCssPath) ? (string) filemtime($appCssPath) : (string) time();
$bridgeCssPath = __DIR__ . '/../asset/css/bridge_connectivity.css';
$bridgeCssVersion = file_exists($bridgeCssPath) ? (string) filemtime($bridgeCssPath) : (string) time();
$bridgeJsPath = __DIR__ . '/../asset/js/bridge_connectivity.js';
$bridgeJsVersion = file_exists($bridgeJsPath) ? (string) filemtime($bridgeJsPath) : (string) time();
$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') { $bridgeUrl = 'http://127.0.0.1:9123'; }

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$defaultNavItems = [
    ['label' => 'Main', 'href' => BASE_URL . 'index.php', 'active' => in_array($currentScript, ['index.php', 'dashboard_main.php'], true)],
    ['label' => 'Company', 'href' => BASE_URL . 'dashboard_company.php', 'active' => str_contains($currentScript, 'company_') || $currentScript === 'dashboard_company.php'],
    ['label' => 'Data', 'href' => BASE_URL . 'dashboard_data.php', 'active' => str_contains($currentScript, 'tally_') || str_contains($currentScript, 'mapping_') || str_contains($currentScript, 'trial_balance_') || $currentScript === 'dashboard_data.php'],
    ['label' => 'Reports', 'href' => BASE_URL . 'dashboard_report.php', 'active' => in_array($currentScript, ['dashboard_report.php', 'reports.php', 'directors_report.php', 'reconciliation_console.php'], true)],
];

$headerPlanUsage = null;
$headerUserId = (int) ($_SESSION['user_id'] ?? 0);
$headerUserName = (string) ($_SESSION['user_name'] ?? 'User');
$headerUserRole = (string) ($_SESSION['user_role'] ?? '');
$headerIsSuperAdmin = false;

if ($headerUserId > 0) {
    $headerPlanUsage = getPlanUsage($pdo, $headerUserId);
    $headerIsSuperAdmin = isSuperAdmin($pdo, $headerUserId);
}

$isOwnershipPage = in_array($currentScript, ['superadmin_dashboard.php', 'workspace_admin.php'], true);
$primaryNavItems = $defaultNavItems;
if ($headerIsSuperAdmin && $isOwnershipPage) {
    $primaryNavItems = [
        ['label' => 'Dashboard', 'href' => BASE_URL . 'superadmin_dashboard.php', 'active' => $currentScript === 'superadmin_dashboard.php'],
        ['label' => 'Licensing', 'href' => BASE_URL . 'workspace_admin.php', 'active' => $currentScript === 'workspace_admin.php'],
    ];
}

$enableSidebar = !empty($showSidebar) && !$headerIsSuperAdmin;
$bodyClass = $headerIsSuperAdmin && $isOwnershipPage ? 'ownership-shell' : ($enableSidebar ? 'workspace-shell sidebar-shell' : 'workspace-shell');
$topbarClass = $headerIsSuperAdmin && $isOwnershipPage ? 'topbar topbar-superadmin' : 'topbar';
$tagline = $headerIsSuperAdmin && $isOwnershipPage
    ? 'Commercial control center for subscriptions, licensing, and revenue'
    : 'Structured balance sheet workflow for financial reporting teams';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css?v=<?= htmlspecialchars($appCssVersion) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/bridge_connectivity.css?v=<?= htmlspecialchars($bridgeCssVersion) ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">

<div class="<?= htmlspecialchars($topbarClass) ?>">
    <div class="brand-block">
        <div class="logo">e-BAL</div>
        <div class="brand-tagline"><?= htmlspecialchars($tagline) ?></div>
    </div>

    <div class="nav" aria-label="Primary">
        <?php foreach ($primaryNavItems as $index => $item): ?>
            <a class="nav-link <?= $item['active'] ? 'is-active' : '' ?>" href="<?= $item['href'] ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php if ($index < count($primaryNavItems) - 1): ?>
                <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$headerIsSuperAdmin || !$isOwnershipPage): ?>
            <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
            <a class="nav-link" href="<?= BASE_URL ?>dashboard_main.php" onclick="if (window.history.length > 1) { history.back(); return false; }">Back</a>
        <?php endif; ?>
    </div>

    <div class="user">
        <!-- Bridge Status Widget (V1) -->
        <div class="bc-status-widget" id="bc-status-widget" title="Smart Bridge connectivity" style="margin-right:12px;">
            <div class="bc-indicator">
                <span class="bc-dot bc-dot--red" data-status-kind="bridge"></span>
                <span class="bc-label">Bridge</span>
                <span class="bc-status-val" data-status-kind="bridge">Checking</span>
            </div>
            <div class="bc-indicator">
                <span class="bc-dot bc-dot--red" data-status-kind="tally"></span>
                <span class="bc-label">Tally</span>
                <span class="bc-status-val" data-status-kind="tally">Checking</span>
            </div>
        </div>

        <?php if ($headerPlanUsage && (!$headerIsSuperAdmin || !$isOwnershipPage)): ?>
            <span class="user-meta user-plan">
                <?= htmlspecialchars((string) $headerPlanUsage['plan_name']) ?>
            </span>
        <?php endif; ?>
        <span class="user-meta">
            <?= htmlspecialchars($headerUserName) ?><?= $headerUserRole !== '' ? ' | ' . htmlspecialchars(ucfirst($headerUserRole)) : '' ?>
        </span>
        <?php if ($headerIsSuperAdmin && !$isOwnershipPage): ?>
            <a class="nav-link" href="<?= BASE_URL ?>superadmin_dashboard.php">Superadmin Dashboard</a>
            <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
            <a class="nav-link" href="<?= BASE_URL ?>workspace_admin.php">Licensing Console</a>
            <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
        <?php endif; ?>
        <?php if (!$headerIsSuperAdmin || !$isOwnershipPage): ?>
            <a class="nav-link" href="<?= BASE_URL ?>upgrade.php">Upgrade Plan</a>
            <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
        <?php endif; ?>
        <a class="nav-link" href="<?= BASE_URL ?>logout.php">Logout</a>
    </div>
</div>

<!-- BRIDGE CONNECTIVITY PANEL (V1) -->
<div class="bc-panel-overlay" id="bc-panel-overlay"></div>
<div class="bc-panel" id="bc-panel" style="display:none;">
    <div class="bc-panel-header">
        <h3>e-BAL Connectivity</h3>
        <button class="bc-panel-close" id="bc-panel-close" type="button" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="bc-panel-body">
        <div class="bc-status-row">
            <span class="bc-status-label">Bridge Status</span>
            <span class="bc-status-value bc-status-val" data-status-kind="bridge">
                <span class="bc-dot bc-dot--red" data-status-kind="bridge"></span> Checking
            </span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Bridge Version</span>
            <span class="bc-status-value"><span id="bc-launch-version" class="bc-version-text">—</span></span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Tally Status</span>
            <span class="bc-status-value bc-status-val" data-status-kind="tally">
                <span class="bc-dot bc-dot--red" data-status-kind="tally"></span> Checking
            </span>
        </div>
        <div class="bc-panel-actions">
            <button class="bc-btn bc-btn--primary" type="button" onclick="ebalBridge.check()">Test Connection</button>
            <button class="bc-btn" type="button" onclick="ebalBridge.openLaunch()">Launch Bridge</button>
        </div>
    </div>
</div>

<!-- BRIDGE LAUNCH PANEL (V1) -->
<div class="bc-launch-overlay" id="bc-launch-overlay">
    <div class="bc-launch-card">
        <div class="bc-launch-header">
            <div class="bc-launch-icon">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
            </div>
            <h2 class="bc-launch-title">e-BAL Smart Bridge Required</h2>
            <p class="bc-launch-subtitle">This workspace requires e-BAL Smart Bridge to communicate with Tally.</p>
        </div>
        <div class="bc-launch-body">
            <div class="bc-launch-status">
                <span class="bc-launch-status-label">Bridge Status</span>
                <span class="bc-launch-status-value" id="bc-launch-status-val">
                    <span class="bc-dot bc-dot--red"></span> Offline
                </span>
            </div>
            <div class="bc-launch-actions">
                <button class="bc-launch-btn bc-launch-btn--start" id="bc-start-btn" type="button">Start Smart Bridge</button>
                <button class="bc-launch-btn bc-launch-btn--retry" id="bc-retry-btn" type="button">Retry Connection</button>
                <button class="bc-btn" type="button" id="bc-launch-close" style="margin-top:4px;">Cancel</button>
            </div>
            <div class="bc-launch-progress" id="bc-launch-progress">
                <div class="bc-launch-progress-text">
                    <span class="bc-spinner"></span>
                    <span id="bc-retry-text">Starting Smart Bridge...</span>
                </div>
            </div>
            <div class="bc-launch-success" id="bc-launch-success">
                Bridge Connected &#10003;
            </div>
        </div>
    </div>
</div>

<?php if ($enableSidebar): ?>
<?php
$sidebarSections = [
    'Data Operations' => [
        ['label' => 'TB Import & Mapping', 'href' => BASE_URL . 'data_console/trial_balance_preview.php', 'icon' => '&#128228;'],
        ['label' => 'Mapping Workbench', 'href' => BASE_URL . 'data_console/mapping_workbench.php', 'icon' => '&#128451;'],
        ['label' => 'Validation Console', 'href' => BASE_URL . 'reconciliation_console.php', 'icon' => '&#9878;'],
        ['label' => 'Financial Statements', 'href' => BASE_URL . 'reports.php', 'icon' => '&#128202;'],
        ['label' => 'Export Centre', 'href' => BASE_URL . 'export_centre.php', 'icon' => '&#128228;'],
    ],
    'Company' => [
        ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php', 'icon' => '&#127968;'],
        ['label' => 'Company Settings', 'href' => BASE_URL . 'dashboard_company.php', 'icon' => '&#9881;'],
    ],
    'Account & Support' => [
        ['label' => 'Upgrade Plan', 'href' => BASE_URL . 'upgrade.php', 'icon' => '&#128176;'],
        ['label' => 'Invoice History', 'href' => BASE_URL . 'invoice_history.php', 'icon' => '&#128196;'],
        ['label' => 'Smart Bridge Download', 'href' => BASE_URL . 'bridge_download.php', 'icon' => '&#128229;'],
        ['label' => 'Support Centre', 'href' => BASE_URL . 'support.php', 'icon' => '&#128221;'],
    ],
];
?>
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">eB</div>
        <div class="sidebar-title">e-BAL</div>
    </div>
    <nav class="sidebar-nav">
        <?php foreach ($sidebarSections as $sectionName => $sectionItems): ?>
            <div class="sidebar-section-label"><?= htmlspecialchars($sectionName) ?></div>
            <?php foreach ($sectionItems as $sItem): ?>
                <?php
                $sActive = basename($sItem['href'] ?? '') === $currentScript;
                ?>
                <a class="sidebar-link <?= $sActive ? 'active' : '' ?>" href="<?= $sItem['href'] ?>">
                    <span class="sidebar-icon"><?= $sItem['icon'] ?></span>
                    <?= htmlspecialchars($sItem['label']) ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>
<?php endif; ?>

<div class="page-wrapper">
