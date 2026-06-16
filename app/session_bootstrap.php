<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/helpers/security_helper.php';

secureSessionStart();
ensureCsrfToken();

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicAllowList = [
    'login.php',
    'logout.php',
];

if (!in_array($currentScript, $publicAllowList, true)) {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}
