<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/demo_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Demo user purge on logout */
$logoutUserId = (int) ($_SESSION['user_id'] ?? 0);
$isDemoLogout = !empty($_SESSION['is_demo']);
$demoStatus = (string) ($_SESSION['demo_status'] ?? '');

if ($isDemoLogout && $logoutUserId > 0 && $demoStatus !== 'upgrade_pending' && $demoStatus !== 'paid_active') {
    try {
        purgeDemoUserData($pdo, $logoutUserId);
    } catch (Throwable $e) {
        // Non-critical — still allow logout
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

header('Location: ' . BASE_URL . 'login.php');
exit;
