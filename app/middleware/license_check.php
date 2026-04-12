<?php

require_once __DIR__ . '/../session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../helpers/plan_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicAllowList = ['upgrade.php'];

if (in_array($currentScript, $publicAllowList, true)) {
    return;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    return;
}

$plan = getUserPlan($userId, $pdo);
if (!$plan || ($plan['status'] ?? 'expired') !== 'active') {
    $_SESSION['error'] = 'Your license has expired or is not active. Please upgrade to continue.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}
