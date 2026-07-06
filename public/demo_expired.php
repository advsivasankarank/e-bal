<?php
/**
 * e-BAL — Demo Expired Page
 * Shown when a demo user's 24-hour access has expired.
 */
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/demo_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$isDemo = isDemoUser($pdo);
$expired = isDemoExpired($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Expired | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(500px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:32px; text-align:center; }
        .auth-card h1 { margin:0 0 8px; font-size:24px; color:#12355B; }
        .auth-card p { margin:0 0 16px; color:#64748b; font-size:14px; line-height:1.6; }
        .expired-icon { font-size:48px; margin-bottom:12px; }
        .demo-badge { display:inline-block; background:rgba(220,38,38,0.1); color:#dc2626; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px; }
        .btn-group { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:20px; }
        .btn-primary { display:inline-block; padding:10px 24px; background:#047857; color:#fff; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; border:0; cursor:pointer; transition:background .2s; }
        .btn-primary:hover { background:#065F46; }
        .btn-outline { display:inline-block; padding:10px 24px; background:transparent; color:#047857; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; border:2px solid #047857; cursor:pointer; transition:all .2s; }
        .btn-outline:hover { background:#047857; color:#fff; }
        .btn-gold { display:inline-block; padding:10px 24px; background:#C69214; color:#fff; border-radius:8px; text-decoration:none; font-weight:600; font-size:14px; border:0; cursor:pointer; transition:background .2s; }
        .btn-gold:hover { background:#A67A0F; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="demo-badge">Demo Expired</div>
            <div class="expired-icon">&#9200;</div>
            <h1>Your e-BAL Demo Access Has Expired</h1>
            <p>Your demo workspace data has been deleted as per our demo data security policy.</p>
            <p>To continue using e-BAL, please request an upgrade or contact our sales team.</p>
            <div class="btn-group">
                <a href="<?= BASE_URL ?>demo_upgrade.php" class="btn-gold">Upgrade to Paid</a>
                <a href="https://etaxadv.com/contact" class="btn-outline">Contact Sales</a>
                <a href="<?= BASE_URL ?>demo_request.php" class="btn-primary">Request Fresh Demo</a>
            </div>
        </div>
    </div>
</body>
</html>
