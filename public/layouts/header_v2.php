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
    'corporate'     => 'Corporate',
    'llp'           => 'LLP',
    'non_corporate' => 'Non-Corporate',
    'noncorporate'  => 'Non-Corporate',
    'partnership'   => 'Partnership',
    'proprietorship'=> 'Proprietorship',
    'trust'         => 'Trust',
    'society'       => 'Society',
];
$v2EntityLabel = $v2EntityMap[strtolower($v2CompanyCategory)] ?? ucfirst(str_replace('_', ' ', $v2CompanyCategory));

/* ---- Determine active section ---- */
$v2CurrentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$v2CurrentDir    = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));

$v2ActiveSection = 'assignments';
if ($v2CurrentDir === 'data' || $v2CurrentScript === 'data') {
    $v2ActiveSection = 'data';
} elseif ($v2CurrentDir === 'statements' || $v2CurrentScript === 'statements') {
    $v2ActiveSection = 'statements';
} elseif ($v2CurrentDir === 'review' || $v2CurrentScript === 'review') {
    $v2ActiveSection = 'review';
} elseif ($v2CurrentDir === 'deliverables' || $v2CurrentScript === 'deliverables') {
    $v2ActiveSection = 'deliverables';
} elseif ($v2CurrentScript === 'settings.php') {
    $v2ActiveSection = 'settings';
}

/* ---- CSS version for cache busting ---- */
$v2CssPath = __DIR__ . '/../asset/css/app_v2.css';
$v2CssVersion = file_exists($v2CssPath) ? (string) filemtime($v2CssPath) : (string) time();
$v2JsPath = __DIR__ . '/../asset/js/app_v2.js';
$v2JsVersion = file_exists($v2JsPath) ? (string) filemtime($v2JsPath) : (string) time();

/* ---- Avatar initials ---- */
$v2Initials = strtoupper(substr($v2UserName, 0, 2));
if (strlen($v2Initials) < 2 && strlen($v2UserName) > 1) {
    $v2Initials = strtoupper(substr($v2UserName, 0, 2));
}

/* ---- Sidebar nav definition ---- */
$v2NavItems = [
    ['section' => 'assignments', 'icon' => '🏠', 'label' => 'My Assignments', 'href' => BASE_URL . 'my_assignments.php'],
    ['section' => 'data',        'icon' => '📥', 'label' => 'Data',          'href' => BASE_URL . 'data/'],
    ['section' => 'statements',  'icon' => '📊', 'label' => 'Financials',    'href' => BASE_URL . 'statements/'],
    ['section' => 'review',      'icon' => '✅', 'label' => 'Review',        'href' => BASE_URL . 'review/'],
    ['section' => 'deliverables','icon' => '📤', 'label' => 'Deliverables',  'href' => BASE_URL . 'deliverables/'],
];

$v2FooterItems = [
    ['section' => 'settings', 'icon' => '⚙️', 'label' => 'Settings', 'href' => BASE_URL . 'settings.php'],
    ['section' => '',         'icon' => '↩',  'label' => 'Back to V1', 'href' => BASE_URL . 'index.php', 'isFooter' => true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app_v2.css?v=<?= htmlspecialchars($v2CssVersion) ?>">
</head>
<body>

<!-- TOPBAR -->
<header class="v2-topbar">

    <!-- Left: Brand + Toggle -->
    <div class="v2-brand">
        <div class="v2-logo">eB</div>
        <span class="v2-brand-name">e-BAL</span>
    </div>
    <button class="v2-sidebar-toggle" type="button" title="Toggle sidebar" aria-label="Toggle sidebar">☰</button>

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
                    No company selected
                </span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right: User -->
    <div class="v2-user">
        <div class="v2-user-info">
            <span class="v2-user-name"><?= htmlspecialchars($v2UserName) ?></span>
            <?php if ($v2UserRole !== ''): ?>
                <span class="v2-user-role"><?= htmlspecialchars($v2UserRole) ?></span>
            <?php endif; ?>
        </div>
        <div class="v2-avatar" title="<?= htmlspecialchars($v2UserName) ?>"><?= $v2Initials ?></div>
    </div>

</header>

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

            <div class="v2-nav-label">Account</div>

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
