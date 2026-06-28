<?php
$page_title = 'Settings';
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layouts/header_v2.php';

$activeTab = trim((string) ($_GET['tab'] ?? 'profile'));
$userName = (string) ($_SESSION['user_name'] ?? 'User');
$userRole = (string) ($_SESSION['user_role'] ?? '');
?>

<div class="v2-dw-header">
    <a href="<?= BASE_URL ?>my_assignments.php" class="v2-dw-back">&larr; My Assignments</a>
    <h1 class="v2-dw-title">Settings</h1>
    <p class="v2-dw-subtitle">Account, workspace, and application preferences</p>
</div>

<div class="v2-dw-stats">
    <div class="v2-dw-stat">
        <span class="v2-dw-stat-num"><?= htmlspecialchars(substr($userName, 0, 1)) ?></span>
        <span class="v2-dw-stat-lbl"><?= htmlspecialchars($userName) ?></span>
    </div>
    <div class="v2-dw-stat">
        <span class="v2-dw-stat-num">Role</span>
        <span class="v2-dw-stat-lbl"><?= htmlspecialchars($userRole ?: 'User') ?></span>
    </div>
</div>

<div class="v2-dw-panels">
    <div class="v2-dw-panel active">
        <div class="v2-dw-panel-header">
            <div class="v2-dw-panel-icon">&#9881;</div>
            <div class="v2-dw-panel-info">
                <h2><?= $activeTab === 'preferences' ? 'Preferences' : 'Profile' ?></h2>
                <p>Production settings shell. Administrative configuration remains controlled by workspace admin pages.</p>
            </div>
        </div>
        <div class="v2-dw-panel-detail">
            <span class="v2-dw-panel-detail-text">Use the sidebar to continue workflow navigation. Account-level edits are intentionally limited in this release.</span>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
