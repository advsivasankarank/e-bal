<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../app/middleware/license_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';

if (!isset($page_title)) {
    $page_title = 'e-BAL';
}

$appCssPath = __DIR__ . '/../asset/css/app.css';
$appCssVersion = file_exists($appCssPath) ? (string) filemtime($appCssPath) : (string) time();

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
