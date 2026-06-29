<?php
/**
 * e-BAL V2 — Application Shell Header
 *
 * Renders:
 *   - <!DOCTYPE html> and <head>
 *   - Topbar (brand, context, user)
 *   - Sidebar (4 sections + footer)
 *   - Opens <main> content area
 *
 * Session variables read:
 *   - $_SESSION['user_id']
 *   - $_SESSION['user_name']
 *   - $_SESSION['user_role']
 *   - $_SESSION['company_id']
 *   - $_SESSION['company_name']
 *   - $_SESSION['fy_id']
 *   - $_SESSION['fy_name']
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../app/middleware/license_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
require_once __DIR__ . '/../components/ui.php';

/* Activate V2 mode so any included V1 module uses V2 shell */
$_SESSION['v2_mode'] = true;

if (!isset($page_title)) {
    $page_title = 'e-BAL';
}

/* ---- Read session values ---- */
$v2UserId      = (int) ($_SESSION['user_id'] ?? 0);
$v2UserName    = (string) ($_SESSION['user_name'] ?? 'User');
$v2UserRole    = (string) ($_SESSION['user_role'] ?? '');
$v2CompanyId   = (int) ($_SESSION['company_id'] ?? 0);
$v2CompanyName = (string) ($_SESSION['company_name'] ?? '');
$v2FyId        = (int) ($_SESSION['fy_id'] ?? 0);
$v2FyName      = (string) ($_SESSION['fy_name'] ?? '');

/* ---- Query company entity type ---- */
$v2CompanyCategory = '';
$v2EntityLabel = '';
if ($v2CompanyId > 0) {
    try {
        $v2CoStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
        $v2CoStmt->execute([$v2CompanyId]);
        $v2CompanyCategory = (string) ($v2CoStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $v2CompanyCategory = '';
    }
}

/* Map category to display label */
$v2EntityMap = [
    'corporate'        => 'Company',
    'llp'              => 'LLP',
    'non_corporate'    => 'Company',
    'noncorporate'     => 'Company',
    'partnership'      => 'Partnership',
    'proprietorship'   => 'Proprietorship',
    'trust'            => 'Trust',
    'society'          => 'Society',
    'individual'       => 'Individual',
    'huf'              => 'HUF',
    'public_limited'   => 'Company',
    'private_limited'  => 'Company',
    'opc'              => 'Company',
];
$v2EntityLabel = $v2EntityMap[strtolower($v2CompanyCategory)] ?? ucfirst(str_replace('_', ' ', $v2CompanyCategory));

/* ---- Determine active section ---- */
$v2CurrentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$v2CurrentDir    = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));

$v2ActiveSection = 'entities';
if ($v2CurrentScript === 'dashboard_company.php') {
    $v2ActiveSection = 'entities';
} elseif ($v2CurrentScript === 'my_assignments.php' || $v2CurrentScript === 'entity_home.php') {
    $v2ActiveSection = 'assignments';
} elseif ($v2CurrentDir === 'data' || $v2CurrentScript === 'data') {
    $v2ActiveSection = 'data';
} elseif ($v2CurrentDir === 'statements' || $v2CurrentScript === 'statements') {
    $v2ActiveSection = 'statements';
} elseif ($v2CurrentDir === 'review' || $v2CurrentScript === 'review') {
    $v2ActiveSection = 'review';
} elseif ($v2CurrentDir === 'deliverables' || $v2CurrentScript === 'deliverables') {
    $v2ActiveSection = 'deliverables';
} elseif ($v2CurrentScript === 'reports.php') {
    $v2ActiveSection = 'reports';
} elseif ($v2CurrentScript === 'settings.php') {
    $v2ActiveSection = 'settings';
}

/* ---- CSS version for cache busting ---- */
$v2CssPath = __DIR__ . '/../asset/css/app_v2.css';
$v2CssVersion = file_exists($v2CssPath) ? (string) filemtime($v2CssPath) : (string) time();
$v2JsPath = __DIR__ . '/../asset/js/app_v2.js';
$v2JsVersion = file_exists($v2JsPath) ? (string) filemtime($v2JsPath) : (string) time();
$bridgeCssPath = __DIR__ . '/../asset/css/bridge_connectivity.css';
$bridgeCssVersion = file_exists($bridgeCssPath) ? (string) filemtime($bridgeCssPath) : (string) time();
$bridgeJsPath = __DIR__ . '/../asset/js/bridge_connectivity.js';
$bridgeJsVersion = file_exists($bridgeJsPath) ? (string) filemtime($bridgeJsPath) : (string) time();
$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') { $bridgeUrl = 'http://127.0.0.1:9123'; }

/* ---- Avatar initials ---- */
$v2Initials = strtoupper(substr($v2UserName, 0, 2));
if (strlen($v2Initials) < 2 && strlen($v2UserName) > 1) {
    $v2Initials = strtoupper(substr($v2UserName, 0, 2));
}

/* ---- Role label mapping ---- */
$v2RoleLabels = [
    'admin' => 'Administrator',
    'superadmin' => 'Super Admin',
    'staff' => 'Staff',
    'manager' => 'Manager',
    'partner' => 'Partner',
    'viewer' => 'Viewer',
];
$v2RoleLabel = $v2RoleLabels[strtolower($v2UserRole)] ?? ucfirst($v2UserRole);

/* ---- Last login ---- */
$v2LastLogin = '';
try {
    $llStmt = $pdo->prepare("SELECT last_login_at FROM users WHERE id = ?");
    $llStmt->execute([$v2UserId]);
    $v2LastLogin = (string) ($llStmt->fetchColumn() ?: '');
} catch (Throwable $e) {
    $v2LastLogin = '';
}

/* ---- Sidebar nav definition ---- */
$v2NavItems = [
    ['section' => 'entities',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>', 'label' => 'Entity Dashboard', 'href' => BASE_URL . 'dashboard_company.php'],
    ['section' => 'assignments','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>', 'label' => 'My Assignments', 'href' => BASE_URL . 'my_assignments.php'],
    ['section' => 'data',        'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>', 'label' => 'Data Centre',     'href' => BASE_URL . 'data/'],
    ['section' => 'statements',  'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>', 'label' => 'Financials',    'href' => BASE_URL . 'statements/financials.php'],
    ['section' => 'review',      'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>', 'label' => 'Review',        'href' => BASE_URL . 'review/'],
    ['section' => 'deliverables','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>', 'label' => 'Deliverables',  'href' => BASE_URL . 'deliverables/'],
];

$v2FooterItems = [
    ['section' => 'reports',    'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>', 'label' => 'Reports', 'href' => BASE_URL . 'reports.php'],
    ['section' => 'settings', 'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>', 'label' => 'Settings', 'href' => BASE_URL . 'settings.php'],
    ['section' => '',         'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>', 'label' => 'Logout', 'href' => BASE_URL . 'logout.php', 'isFooter' => true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app_v2.css?v=<?= htmlspecialchars($v2CssVersion) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/components.css?v=<?= file_exists(__DIR__ . '/../asset/css/components.css') ? (string) filemtime(__DIR__ . '/../asset/css/components.css') : (string) time() ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/bridge_connectivity.css?v=<?= htmlspecialchars($bridgeCssVersion) ?>">
    <?php if ($v2ActiveSection === 'assignments' || $v2ActiveSection === 'entities'): ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/workspace_launcher.css?v=<?= filemtime(__DIR__ . '/../asset/css/workspace_launcher.css') ?>">
    <?php endif; ?>
</head>
<body>

<!-- TOPBAR -->
<header class="v2-topbar">

    <!-- Left: Brand + Toggle -->
    <div class="v2-brand">
        <div class="v2-logo">eB</div>
        <span class="v2-brand-name">e-BAL</span>
    </div>
    <button class="v2-sidebar-toggle" type="button" title="Toggle sidebar" aria-label="Toggle sidebar"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg></button>

    <!-- Center: Company Context -->
    <div class="v2-context">
        <?php if ($v2CompanyId > 0): ?>
            <div class="v2-context-company">
                <span class="v2-context-company-name" title="<?= htmlspecialchars($v2CompanyName) ?>">
                    <?= htmlspecialchars($v2CompanyName) ?>
                </span>
                <div class="v2-context-company-meta">
                    <?php if ($v2EntityLabel !== ''): ?>
                        <span class="v2-context-entity"><?= htmlspecialchars($v2EntityLabel) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <span class="v2-context-sep"></span>

            <span class="v2-context-fy">
                FY <?= htmlspecialchars($v2FyName ?: 'Not selected') ?>
            </span>
        <?php else: ?>
            <div class="v2-context-company">
                <span class="v2-context-company-name" style="color: var(--muted); font-weight: 400;">
                    Select an Assignment
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right: User Profile Dropdown -->
    <div class="v2-user" id="v2-user-menu">
        <!-- Notification Bell -->
        <button class="v2-notif-bell" type="button" id="v2-notif-btn" title="Notifications" aria-label="Notifications">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span class="v2-notif-badge" id="v2-notif-count" style="display:none;">0</span>
        </button>

        <!-- Bridge Status Widget -->
        <div class="bc-status-widget" id="bc-status-widget" title="Smart Bridge connectivity">
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

        <!-- Profile Trigger -->
        <button class="v2-profile-trigger" type="button" id="v2-profile-trigger" aria-expanded="false" aria-haspopup="true">
            <div class="v2-avatar" title="<?= htmlspecialchars($v2UserName) ?>"><?= $v2Initials ?></div>
            <div class="v2-user-info">
                <span class="v2-user-name"><?= htmlspecialchars($v2UserName) ?></span>
                <span class="v2-user-role"><?= htmlspecialchars($v2RoleLabel) ?></span>
            </div>
            <svg class="v2-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </button>

        <!-- Dropdown Menu -->
        <div class="v2-dropdown" id="v2-dropdown" role="menu">
            <!-- Profile Card -->
            <div class="v2-dropdown-profile">
                <div class="v2-dropdown-avatar"><?= $v2Initials ?></div>
                <div class="v2-dropdown-profile-info">
                    <div class="v2-dropdown-name"><?= htmlspecialchars($v2UserName) ?></div>
                    <div class="v2-dropdown-role"><?= htmlspecialchars($v2RoleLabel) ?></div>
                    <?php if ($v2LastLogin): ?>
                    <div class="v2-dropdown-login">Last login: <?= htmlspecialchars(date('d-M-Y g:i A', strtotime($v2LastLogin))) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="v2-dropdown-divider"></div>

            <!-- Section: Account -->
            <div class="v2-dropdown-section">
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>settings.php" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    My Profile
                </a>
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>settings.php?tab=preferences" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Settings
                </a>
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>forgot_password.php" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Change Password
                </a>
            </div>

            <div class="v2-dropdown-divider"></div>

            <!-- Section: Workspace -->
            <div class="v2-dropdown-section">
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>my_assignments.php" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    My Entities
                </a>
                <?php if ($v2CompanyId > 0): ?>
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>company_dashboard/financial_year.php" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Switch Financial Year
                </a>
                <?php endif; ?>
            </div>

            <div class="v2-dropdown-divider"></div>

            <!-- Section: Help -->
            <div class="v2-dropdown-section">
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>support.php" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Help & Support
                </a>
                <a class="v2-dropdown-item" href="<?= BASE_URL ?>landing.php" target="_blank" role="menuitem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    About e-BAL
                </a>
            </div>

            <div class="v2-dropdown-divider"></div>

            <!-- Logout -->
            <a class="v2-dropdown-item v2-dropdown-logout" href="<?= BASE_URL ?>logout.php" role="menuitem">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Sign Out
            </a>
        </div>
    </div>

</header>

<!-- BRIDGE CONNECTIVITY PANEL (dropdown) -->
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
                <span class="bc-dot bc-dot--red" data-status-kind="bridge"></span>
                Checking
            </span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Tally Status</span>
            <span class="bc-status-value bc-status-val" data-status-kind="tally">
                <span class="bc-dot bc-dot--red" data-status-kind="tally"></span>
                Checking
            </span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Version</span>
            <span class="bc-status-value"><span id="bc-panel-version" class="bc-version-text">—</span></span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Uptime</span>
            <span class="bc-status-value"><span id="bc-panel-uptime" class="bc-version-text">—</span></span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Last Sync</span>
            <span class="bc-status-value"><span id="bc-panel-last-sync" class="bc-version-text">Never</span></span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Last Upload</span>
            <span class="bc-status-value"><span id="bc-panel-last-upload" class="bc-version-text">None</span></span>
        </div>
        <div class="bc-status-row">
            <span class="bc-status-label">Active Company</span>
            <span class="bc-status-value" style="font-size:.75rem;"><?= htmlspecialchars($v2CompanyName ?: '—') ?></span>
        </div>
        <div class="bc-panel-actions" style="flex-wrap:wrap;">
            <button class="bc-btn bc-btn--primary" type="button" id="bc-btn-refresh">Refresh</button>
            <button class="bc-btn" type="button" id="bc-btn-companies">Fetch Companies</button>
        </div>
        <div class="bc-panel-actions" style="flex-wrap:wrap;margin-top:6px;">
            <button class="bc-btn" type="button" id="bc-btn-ledgers">Fetch Ledgers</button>
            <button class="bc-btn" type="button" id="bc-btn-tb">Fetch TB</button>
        </div>
    </div>
</div>

<!-- BRIDGE LAUNCH PANEL (workspace block overlay) -->
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

<!-- MOBILE OVERLAY (hidden by default) -->
<div class="v2-mobile-overlay"></div>

<!-- LAYOUT -->
<div class="v2-layout">

    <!-- SIDEBAR -->
    <aside class="v2-sidebar">
        <nav class="v2-sidebar-nav">

            <div class="v2-nav-label">Workspace</div>

            <?php foreach ($v2NavItems as $item): ?>
                <a class="v2-nav-item <?= $v2ActiveSection === $item['section'] ? 'active' : '' ?>"
                   href="<?= $item['href'] ?>"
                   title="<?= htmlspecialchars($item['label']) ?>">
                    <span class="v2-nav-icon"><?= $item['icon'] ?></span>
                    <span class="v2-nav-text"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <div class="v2-nav-divider"></div>

            <div class="v2-nav-label">User</div>

            <?php foreach ($v2FooterItems as $item): ?>
                <a class="v2-nav-item <?= $item['isFooter'] ?? false ? 'v2-nav-footer' : '' ?> <?= $v2ActiveSection === ($item['section'] ?? '') ? 'active' : '' ?>"
                   href="<?= $item['href'] ?>"
                   title="<?= htmlspecialchars($item['label']) ?>">
                    <span class="v2-nav-icon"><?= $item['icon'] ?></span>
                    <span class="v2-nav-text"><?= htmlspecialchars($item['label']) ?></span>
                </a>
            <?php endforeach; ?>

        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="v2-main">
        <div class="v2-content">
