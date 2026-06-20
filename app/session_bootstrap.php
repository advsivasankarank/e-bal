<?php
/**
 * Session Bootstrap — HARDENED
 *
 * Phase 7: Session Hardening
 * - Secure session start with httponly, samesite, secure flags
 * - CSRF token generation
 * - Session timeout validation (8 hours)
 * - Authentication guard with public allowlist
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/helpers/security_helper.php';

secureSessionStart();
ensureCsrfToken();

/* HARDENED: Session timeout — expire after 8 hours of inactivity */
if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > 28800) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
$_SESSION['_last_activity'] = time();

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicAllowList = [
    'index.php',
    'login.php',
    'logout.php',
    'bridge_download.php',
    'support.php',
];

if (!in_array($currentScript, $publicAllowList, true)) {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}
