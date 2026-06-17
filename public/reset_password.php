<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/password_reset_helper.php';
require_once __DIR__ . '/../app/helpers/security_helper.php';

ensurePasswordResetTables($pdo);

// Redirect to login if already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));
$message = '';
$messageType = ''; // 'error', 'success', or 'form'
$user = null;

if ($token === '') {
    $message = 'No reset token provided. Please use the link from your password reset email.';
    $messageType = 'error';
} else {
    // Validate token
    $user = validateResetToken($pdo, $token);
    if (!$user) {
        $message = 'Invalid or expired reset token. <a href="' . BASE_URL . 'forgot_password.php">Request a new password reset</a>.';
        $messageType = 'error';
    } else {
        $messageType = 'form';
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'form') {
    requireCsrfToken();
    $newPassword = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['password_confirm'] ?? '');

    if ($newPassword === '') {
        $message = 'Password is required.';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';
    } else {
        $result = resetPassword($pdo, $token, $newPassword);
        if ($result['success']) {
            $message = $result['message'];
            $messageType = 'success';
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(480px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:28px; }
        .auth-card h1 { margin:0 0 8px; font-size:28px; }
        .auth-card p { margin:0 0 18px; color:#64748b; line-height:1.6; font-size:14px; }
        .auth-card label { display:block; margin-bottom:6px; font-weight:600; font-size:14px; }
        .auth-card input { width:100%; box-sizing:border-box; padding:12px 14px; border:1px solid #cfd8e3; border-radius:10px; margin-bottom:14px; font-size:14px; }
        .auth-button { width:100%; border:0; border-radius:10px; background:#0f766e; color:#fff; font-size:15px; font-weight:700; padding:12px 16px; cursor:pointer; margin-top:10px; }
        .auth-button:hover { background:#115e59; }
        .auth-error { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; font-size:13px; }
        .auth-success { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#dcfce7; border:1px solid #86efac; color:#166534; font-size:13px; line-height:1.5; }
        .auth-help { margin-top:18px; padding:14px; border-radius:12px; background:#f8fbff; border:1px solid #d8e2ef; font-size:12px; color:#475569; line-height:1.6; }
        .auth-link { color:#0f766e; text-decoration:none; font-weight:600; }
        .auth-link:hover { text-decoration:underline; }
        .password-requirements { background:#f0f9ff; border:1px solid #bae6fd; padding:12px 14px; border-radius:8px; margin-bottom:16px; font-size:12px; color:#0c4a6e; }
        .password-requirements h4 { margin:0 0 8px; }
        .password-requirements ul { margin:0; padding-left:20px; }
        .password-requirements li { margin:4px 0; }
        .password-field-group { margin-bottom:18px; }
        .toggle-password { cursor:pointer; color:#0f766e; font-size:12px; font-weight:600; margin-top:4px; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <h1>Reset Your Password</h1>
            <p>Enter a new password to regain access to your account.</p>

            <?php if ($messageType === 'error'): ?>
                <div class="auth-error">
                    <?= $message ?>
                </div>
                <div class="auth-help">
                    <a href="<?= BASE_URL ?>login.php" class="auth-link">← Back to Login</a>
                </div>

            <?php elseif ($messageType === 'success'): ?>
                <div class="auth-success">
                    <strong>✓ Password reset successful!</strong><br>
                    <?= $message ?>
                </div>
                <div class="auth-help">
                    <a href="<?= BASE_URL ?>login.php" class="auth-link">→ Go to Login</a>
                </div>

            <?php elseif ($messageType === 'form'): ?>
                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li>At least 8 characters long</li>
                        <li>Contains at least one letter</li>
                        <li>Contains at least one number</li>
                        <li>Contains at least one special character (!@#$%^&*)</li>
                    </ul>
                </div>

                <form method="post">
                    <?= csrfInput() ?>
                    
                    <div class="password-field-group">
                        <label for="password">New Password</label>
                        <input 
                            id="password" 
                            name="password" 
                            type="password" 
                            autocomplete="new-password"
                            placeholder="Enter your new password"
                            required
                        >
                        <div class="toggle-password" onclick="togglePasswordVisibility('password')">
                            Show password
                        </div>
                    </div>

                    <div class="password-field-group">
                        <label for="password_confirm">Confirm Password</label>
                        <input 
                            id="password_confirm" 
                            name="password_confirm" 
                            type="password" 
                            autocomplete="new-password"
                            placeholder="Confirm your new password"
                            required
                        >
                        <div class="toggle-password" onclick="togglePasswordVisibility('password_confirm')">
                            Show password
                        </div>
                    </div>

                    <button class="auth-button" type="submit">Reset Password</button>
                </form>

                <div class="auth-help">
                    This reset link will expire in 2 hours for security. If it expires, <a href="<?= BASE_URL ?>forgot_password.php" class="auth-link">request a new one</a>.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function togglePasswordVisibility(fieldId) {
            const field = document.getElementById(fieldId);
            const link = event.target;
            if (field.type === 'password') {
                field.type = 'text';
                link.textContent = 'Hide password';
            } else {
                field.type = 'password';
                link.textContent = 'Show password';
            }
        }
    </script>
</body>
</html>
