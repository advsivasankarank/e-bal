<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/password_reset_helper.php';
require_once __DIR__ . '/../app/helpers/security_helper.php';

ensurePasswordResetTables($pdo);

// Redirect to index if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$message = '';
$messageType = ''; // 'success' or 'error'
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '') {
        $message = 'Please enter your email address.';
        $messageType = 'error';
    } else {
        $result = requestPasswordReset($pdo, $email, BASE_URL);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'error';
        $submitted = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(440px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:28px; }
        .auth-card h1 { margin:0 0 8px; font-size:28px; }
        .auth-card p { margin:0 0 18px; color:#64748b; line-height:1.6; font-size:14px; }
        .auth-card label { display:block; margin-bottom:6px; font-weight:600; font-size:14px; }
        .auth-card input { width:100%; box-sizing:border-box; padding:12px 14px; border:1px solid #cfd8e3; border-radius:10px; margin-bottom:14px; font-size:14px; }
        .auth-button { width:100%; border:0; border-radius:10px; background:#0f766e; color:#fff; font-size:15px; font-weight:700; padding:12px 16px; cursor:pointer; margin-top:10px; }
        .auth-button:hover { background:#115e59; }
        .auth-error { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; font-size:13px; }
        .auth-success { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#dcfce7; border:1px solid #86efac; color:#166534; font-size:13px; }
        .auth-help { margin-top:18px; padding:14px; border-radius:12px; background:#f8fbff; border:1px solid #d8e2ef; font-size:12px; color:#475569; line-height:1.6; }
        .auth-link { color:#0f766e; text-decoration:none; font-weight:600; }
        .auth-link:hover { text-decoration:underline; }
        .info-box { background:#f0f9ff; border:1px solid #bae6fd; padding:12px 14px; border-radius:8px; margin-bottom:16px; font-size:13px; color:#0c4a6e; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <h1>Forgot Password?</h1>
            <p>Enter your email address and we'll send you a link to reset your password.</p>

            <?php if ($message !== ''): ?>
                <div class="auth-<?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($submitted && $messageType === 'success'): ?>
                <div class="info-box">
                    <strong>Check your email:</strong> We've sent a password reset link to your inbox. The link will expire in 2 hours for security. If you don't see the email, check your spam folder.
                </div>
            <?php else: ?>
                <form method="post">
                    <?= csrfInput() ?>
                    <label for="email">Email Address</label>
                    <input 
                        id="email" 
                        name="email" 
                        type="email" 
                        autocomplete="email"
                        placeholder="your.email@example.com"
                        required
                    >

                    <button class="auth-button" type="submit">Send Reset Link</button>
                </form>
            <?php endif; ?>

            <div class="auth-help">
                <strong>Remember your password?</strong> <a href="<?= BASE_URL ?>login.php" class="auth-link">Back to Login</a>
                <br><br>
                <strong>Need help?</strong> Contact support at <?= htmlspecialchars(getenv('COMPANY_SUPPORT_EMAIL') ?: 'support@ebal.etaxadv.com') ?>
            </div>
        </div>
    </div>
</body>
</html>
