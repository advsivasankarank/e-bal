<?php

require_once __DIR__ . '/../session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../helpers/plan_helper.php';

$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicAllowList = ['upgrade.php', 'login.php', 'logout.php', 'forgot_password.php', 'reset_password.php', 'demo_consent.php', 'demo_upgrade.php'];

if (in_array($currentScript, $publicAllowList, true)) {
    return;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    return;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    appLog('CRITICAL', 'license_check.php: PDO not initialized', ['script' => $_SERVER['SCRIPT_NAME'] ?? 'unknown']);
    return;
}

if (isSuperAdmin($pdo, $userId)) {
    return;
}

/* Demo users bypass license check */
if (isset($_SESSION['is_demo']) && $_SESSION['is_demo']) {
    return;
}
try {
    $demoCheckStmt = $pdo->prepare("SELECT is_demo FROM users WHERE id = ?");
    $demoCheckStmt->execute([$userId]);
    if ((int) ($demoCheckStmt->fetchColumn() ?: 0) === 1) {
        $_SESSION['is_demo'] = true;
        return;
    }
} catch (Throwable $e) {
    // Column may not exist yet
}

ensureGracePeriodSchema($pdo);

$license = getActiveLicense($pdo, $userId);
if (!$license) {
    $_SESSION['error'] = 'Your license has expired. Please upgrade to continue.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}

$licenseId = (int) $license['id'];
$licenseStatus = getLicenseStatus($pdo, $licenseId);

// If license just expired, apply grace period (first time only)
if ($licenseStatus === 'expired') {
    $wasApplied = applyGracePeriod($pdo, $licenseId);
    if ($wasApplied) {
        $licenseStatus = 'grace_period'; // Now in grace period after applying
    }
}

// Check final status
if ($licenseStatus === 'active') {
    // All good, continue
    return;
} elseif ($licenseStatus === 'grace_period') {
    // Allow access but set warning flag for dashboard
    $_SESSION['license_grace_warning'] = true;
    return;
} else {
    // Fully expired
    $_SESSION['error'] = 'Your subscription has expired. Please renew to continue.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}
