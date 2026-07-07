<?php
/**
 * e-BAL — Demo Consent & Data Purge Notice
 * Shown after demo login, before workspace access.
 * Acceptance starts the 24-hour demo timer.
 */
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/demo_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// If not a demo user, redirect to dashboard
if (!isDemoUser($pdo)) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

// If consent already accepted, redirect to dashboard
if (isDemoConsentAccepted($pdo)) {
    // Check if demo expired
    if (isDemoExpired($pdo)) {
        header('Location: ' . BASE_URL . 'demo_expired.php');
        exit;
    }
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../app/helpers/security_helper.php';
    requireCsrfToken();

    $consent = (string) ($_POST['consent'] ?? '');
    if ($consent !== '1') {
        $error = 'You must accept the consent notice to proceed.';
    } else {
        $accepted = acceptDemoConsent($pdo, $userId);
        if ($accepted) {
            $_SESSION['demo_consent_accepted'] = true;
            $_SESSION['demo_status'] = 'active';
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        } else {
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Consent | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(520px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:32px; }
        .auth-card h1 { margin:0 0 4px; font-size:24px; color:#12355B; }
        .auth-card .subtitle { margin:0 0 18px; color:#64748b; font-size:14px; }
        .notice-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; margin-bottom:18px; }
        .notice-box h3 { margin:0 0 8px; font-size:15px; color:#12355B; }
        .notice-box p { margin:0 0 10px; font-size:13px; color:#475569; line-height:1.6; }
        .notice-box p:last-child { margin-bottom:0; }
        .consent-check { display:flex; align-items:flex-start; gap:10px; margin:16px 0 18px; padding:14px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; }
        .consent-check input[type="checkbox"] { margin-top:2px; flex-shrink:0; width:16px; height:16px; }
        .consent-check label { font-size:13px; color:#166534; line-height:1.5; cursor:pointer; }
        .auth-button { width:100%; border:0; border-radius:10px; background:#047857; color:#fff; font-size:15px; font-weight:700; padding:12px 16px; cursor:pointer; transition:background .2s; }
        .auth-button:hover { background:#065F46; }
        .auth-button:disabled { background:#94a3b8; cursor:not-allowed; }
        .auth-error { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; font-size:13px; }
        .demo-badge { display:inline-block; background:rgba(4,120,87,0.1); color:#047857; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="demo-badge">Demo Mode</div>
            <h1>Welcome to e-BAL Demo</h1>
            <p class="subtitle">Please review and accept the following before proceeding.</p>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="notice-box">
                <h3>Data &amp; Privacy Notice</h3>
                <p>Your contact details have been saved for demo support and sales follow-up.</p>
                <p>Any entity, financial year, uploaded data, financial statements, reports or files created during this demo session will be <strong>automatically deleted</strong> when you logout or when the demo expires, for your data security and privacy.</p>
                <p>Demo access is valid for <strong>24 hours</strong> from your first login.</p>
                <p>You may now explore e-BAL.</p>
            </div>

            <form method="post">
                <?= csrfInput() ?>

                <div class="consent-check">
                    <input type="checkbox" id="consent" name="consent" value="1" required>
                    <label for="consent">I understand that my contact details will be retained for demo support and sales follow-up, and that all financial/demo working data entered during this session will be deleted on logout or expiry.</label>
                </div>

                <button class="auth-button" type="submit">Proceed to Demo Workspace</button>
            </form>
        </div>
    </div>
</body>
</html>
