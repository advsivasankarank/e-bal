<?php
/**
 * e-BAL — Demo Upgrade Request Form
 * Allows demo users to request upgrade to paid plan.
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

if (!isDemoUser($pdo)) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$user = getUserById($pdo, $userId);
$lead = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM demo_leads WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    // Non-critical
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $preferredPlan = trim((string) ($_POST['preferred_plan'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $retainData = ($_POST['retain_demo_data'] ?? '1') === '1';

    try {
        submitUpgradeRequest($pdo, $userId, $preferredPlan, $message, $retainData);
        $success = 'Your upgrade request has been received. Our team will contact you shortly.';
    } catch (Throwable $e) {
        $error = 'An error occurred. Please try again.';
        if (function_exists('appLog')) {
            appLog('ERROR', 'Upgrade request failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upgrade to Paid | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(500px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:28px; }
        .auth-card h1 { margin:0 0 4px; font-size:22px; color:#12355B; }
        .auth-card .subtitle { margin:0 0 18px; color:#64748b; font-size:14px; line-height:1.5; }
        .auth-card label { display:block; margin-bottom:4px; font-weight:600; font-size:13px; }
        .auth-card input, .auth-card select, .auth-card textarea { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cfd8e3; border-radius:8px; margin-bottom:12px; font-size:14px; font-family:inherit; }
        .auth-card textarea { resize:vertical; min-height:80px; }
        .auth-card .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .auth-card .row { grid-template-columns:1fr; } }
        .auth-button { width:100%; border:0; border-radius:10px; background:#C69214; color:#fff; font-size:15px; font-weight:700; padding:12px 16px; cursor:pointer; transition:background .2s; }
        .auth-button:hover { background:#A67A0F; }
        .auth-error { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; font-size:13px; }
        .auth-success { margin-bottom:16px; padding:14px; border-radius:10px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:13px; line-height:1.6; }
        .auth-links { margin-top:16px; text-align:center; font-size:13px; color:#64748b; }
        .auth-links a { color:#047857; text-decoration:none; font-weight:600; }
        .demo-badge { display:inline-block; background:rgba(198,146,20,0.1); color:#C69214; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px; }
        .retain-group { display:flex; gap:12px; margin-bottom:12px; }
        .retain-group label { font-weight:400; cursor:pointer; display:flex; align-items:center; gap:4px; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="demo-badge">Upgrade</div>
            <h1>Upgrade to Paid Plan</h1>
            <p class="subtitle">Submit your upgrade request and our team will contact you to complete the activation.</p>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="auth-success"><?= htmlspecialchars($success) ?></div>
                <div class="auth-links">
                    <a href="<?= BASE_URL ?>index.php">Continue Exploring Demo</a>
                </div>
            <?php else: ?>
                <form method="post">
                    <?= csrfInput() ?>

                    <div class="row">
                        <div>
                            <label>Name</label>
                            <input type="text" value="<?= htmlspecialchars($lead['name'] ?? $user['name'] ?? '') ?>" disabled>
                        </div>
                        <div>
                            <label>Mobile</label>
                            <input type="text" value="<?= htmlspecialchars($lead['mobile'] ?? '') ?>" disabled>
                        </div>
                    </div>

                    <div class="row">
                        <div>
                            <label>Email</label>
                            <input type="email" value="<?= htmlspecialchars($lead['email'] ?? $user['email'] ?? '') ?>" disabled>
                        </div>
                        <div>
                            <label>Firm / Company</label>
                            <input type="text" value="<?= htmlspecialchars($lead['firm_company_name'] ?? '') ?>" disabled>
                        </div>
                    </div>

                    <label for="preferred_plan">Preferred Plan <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                    <select id="preferred_plan" name="preferred_plan">
                        <option value="">Select a plan...</option>
                        <option value="base">e-BAL Base (₹7,499/yr)</option>
                        <option value="pro">e-BAL Pro (₹14,999/yr)</option>
                        <option value="elite">e-BAL Elite (₹29,999/yr)</option>
                    </select>

                    <label for="message">Message / Requirement <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                    <textarea id="message" name="message" placeholder="Tell us about your requirements..."></textarea>

                    <label>Retain Demo Data?</label>
                    <div class="retain-group">
                        <label><input type="radio" name="retain_demo_data" value="1" checked> Yes, retain temporarily until follow-up</label>
                        <label><input type="radio" name="retain_demo_data" value="0"> No, I'm starting fresh</label>
                    </div>

                    <button class="auth-button" type="submit">Submit Upgrade Request</button>
                </form>
            <?php endif; ?>

            <div class="auth-links">
                <a href="<?= BASE_URL ?>index.php">Back to Demo</a>
            </div>
        </div>
    </div>
</body>
</html>
