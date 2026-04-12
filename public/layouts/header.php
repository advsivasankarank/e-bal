<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../app/middleware/license_check.php';

if (!isset($page_title)) $page_title = "e-BAL";

$appCssPath = __DIR__ . '/../asset/css/app.css';
$appCssVersion = file_exists($appCssPath) ? (string) filemtime($appCssPath) : (string) time();

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navItems = [
    ['label' => 'Main', 'href' => BASE_URL . 'index.php', 'active' => in_array($currentScript, ['index.php', 'dashboard_main.php'], true)],
    ['label' => 'Company', 'href' => BASE_URL . 'dashboard_company.php', 'active' => str_contains($currentScript, 'company_') || $currentScript === 'dashboard_company.php'],
    ['label' => 'Data', 'href' => BASE_URL . 'dashboard_data.php', 'active' => str_contains($currentScript, 'tally_') || str_contains($currentScript, 'mapping_') || str_contains($currentScript, 'trial_balance_') || $currentScript === 'dashboard_data.php'],
    ['label' => 'Reports', 'href' => BASE_URL . 'dashboard_report.php', 'active' => in_array($currentScript, ['dashboard_report.php', 'reports.php', 'directors_report.php', 'reconciliation_console.php'], true)],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title) ?> | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css?v=<?= htmlspecialchars($appCssVersion) ?>">
</head>
<body>

<div class="topbar">
    <div class="brand-block">
        <div class="logo">e-BAL</div>
        <div class="brand-tagline">Structured balance sheet workflow for financial reporting teams</div>
    </div>

    <div class="nav" aria-label="Primary">
        <?php foreach ($navItems as $index => $item): ?>
            <a class="nav-link <?= $item['active'] ? 'is-active' : '' ?>" href="<?= $item['href'] ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php if ($index < count($navItems) - 1): ?>
                <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
            <?php endif; ?>
        <?php endforeach; ?>
        <span class="nav-sep" aria-hidden="true">&rsaquo;</span>
        <a class="nav-link" href="javascript:history.back()">Back</a>
    </div>

    <div class="user">
        <span>Workspace Admin</span>
    </div>
</div>

<div class="page-wrapper">
