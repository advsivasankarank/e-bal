<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
/* HARDENED: Removed ensurePlanTables() and ensureFYClosureSchema() from login page.
   DDL is now in migration script only. */

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } else {
        $user = getUserByEmail($pdo, $email);
        if (!$user || !password_verify($password, (string) $user['password'])) {
            $error = 'Invalid login credentials.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = (string) $user['name'];
            $_SESSION['user_role'] = (string) $user['role'];

            // Record last login time for client activity tracking
            try {
                $loginStmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
                $loginStmt->execute([(int) $user['id']]);
            } catch (Throwable $e) {
                // Silently fail - column may not exist yet
            }

            if (($user['role'] ?? '') === 'superadmin') {
                header('Location: ' . BASE_URL . 'superadmin/index.php');
            } else {
                header('Location: ' . BASE_URL . 'index.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(440px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:28px; }
        .auth-card h1 { margin:0 0 8px; font-size:30px; }
        .auth-card p { margin:0 0 18px; color:#64748b; line-height:1.6; }
        .auth-card label { display:block; margin-bottom:6px; font-weight:600; }
        .auth-card input { width:100%; box-sizing:border-box; padding:12px 14px; border:1px solid #cfd8e3; border-radius:10px; margin-bottom:14px; }
        .auth-button { width:100%; border:0; border-radius:10px; background:#0f766e; color:#fff; font-size:15px; font-weight:700; padding:12px 16px; cursor:pointer; }
        .auth-button:hover { background:#115e59; }
        .auth-help { margin-top:18px; padding:14px; border-radius:12px; background:#f8fbff; border:1px solid #d8e2ef; font-size:13px; color:#475569; }
        .auth-error { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <h1>e-BAL Login</h1>
            <p>Sign in to access your structured balance sheet workspace.</p>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrfInput() ?>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" required>

                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <button class="auth-button" type="submit">Login</button>
            </form>

            <div class="auth-help">
                <a href="<?= BASE_URL ?>forgot_password.php" style="color:#0f766e; text-decoration:none; font-weight:600;">Forgot your password?</a>
            </div>
        </div>
    </div>
</body>
</html>
