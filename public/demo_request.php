<?php
/**
 * e-BAL — Demo Access Request Form
 * Public page: collects contact details, creates demo lead + user, sends credentials.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/security_helper.php';
require_once __DIR__ . '/../app/helpers/demo_helper.php';

ensureDemoTables($pdo);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $name = trim((string) ($_POST['name'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $addressCity = trim((string) ($_POST['address_city'] ?? ''));
    $professionType = trim((string) ($_POST['profession_type'] ?? ''));
    $firmCompanyName = trim((string) ($_POST['firm_company_name'] ?? ''));

    // Validation
    if ($name === '') {
        $error = 'Name is required.';
    } elseif ($mobile === '') {
        $error = 'Mobile number is required.';
    } elseif ($email === '') {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^[6-9]\d{9}$/', preg_replace('/[\s\-\+]/', '', $mobile))) {
        $error = 'Please enter a valid 10-digit Indian mobile number.';
    } else {
        try {
            $result = handleDemoRequest($pdo, $name, $mobile, $email, $addressCity, $professionType, $firmCompanyName);

            // Send credentials email
            $emailSent = sendDemoCredentialsEmail($pdo, $email, $result['password'], $name);

            // Log the user in automatically
            session_regenerate_id(true);
            $_SESSION['user_id'] = $result['user_id'];
            $_SESSION['user_name'] = $name;
            $_SESSION['user_role'] = 'staff';
            $_SESSION['is_demo'] = true;
            $_SESSION['demo_status'] = 'credentials_sent';

            $success = 'Your e-BAL demo access has been created. Login credentials have been sent to your email address. Please check your inbox and login to explore e-BAL.';
        } catch (Throwable $e) {
            $error = 'An error occurred while creating your demo access. Please try again.';
            if (function_exists('appLog')) {
                appLog('ERROR', 'Demo request failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Start Demo | e-BAL</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>asset/css/app.css">
    <style>
        body { min-height:100vh; margin:0; background:linear-gradient(180deg, #eef7f7 0%, #f7fbff 100%); font-family:Arial, sans-serif; color:#0f172a; }
        .auth-shell { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px 16px; }
        .auth-card { width:min(500px, 100%); background:#fff; border:1px solid #d8e2ef; border-radius:18px; box-shadow:0 24px 50px rgba(15, 23, 42, 0.08); padding:28px; }
        .auth-card h1 { margin:0 0 4px; font-size:26px; color:#12355B; }
        .auth-card .subtitle { margin:0 0 18px; color:#64748b; font-size:14px; line-height:1.5; }
        .auth-card label { display:block; margin-bottom:4px; font-weight:600; font-size:13px; }
        .auth-card input, .auth-card select { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #cfd8e3; border-radius:8px; margin-bottom:12px; font-size:14px; }
        .auth-card .row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .auth-card .row { grid-template-columns:1fr; } }
        .auth-button { width:100%; border:0; border-radius:10px; background:#047857; color:#fff; font-size:15px; font-weight:700; padding:12px 16px; cursor:pointer; transition:background .2s; }
        .auth-button:hover { background:#065F46; }
        .auth-error { margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; font-size:13px; }
        .auth-success { margin-bottom:16px; padding:14px; border-radius:10px; background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-size:13px; line-height:1.6; }
        .auth-links { margin-top:16px; text-align:center; font-size:13px; color:#64748b; }
        .auth-links a { color:#047857; text-decoration:none; font-weight:600; }
        .auth-links a:hover { text-decoration:underline; }
        .form-note { font-size:11px; color:#94a3b8; margin-top:-8px; margin-bottom:12px; }
        .demo-badge { display:inline-block; background:rgba(4,120,87,0.1); color:#047857; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px; }
    </style>
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="demo-badge">Free Demo</div>
            <h1>Start Your e-BAL Demo</h1>
            <p class="subtitle">Explore the full e-BAL workflow with a free 24-hour demo. No credit card required.</p>

            <?php if ($error !== ''): ?>
                <div class="auth-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="auth-success">
                    <?= htmlspecialchars($success) ?>
                    <div style="margin-top:12px;">
                        <a href="<?= BASE_URL ?>login.php" class="auth-button" style="display:inline-block;width:auto;padding:10px 24px;text-decoration:none;">Login to e-BAL</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="post">
                    <?= csrfInput() ?>

                    <label for="name">Full Name *</label>
                    <input id="name" name="name" type="text" required placeholder="e.g. Rajesh Kumar" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">

                    <div class="row">
                        <div>
                            <label for="mobile">Mobile Number *</label>
                            <input id="mobile" name="mobile" type="tel" required placeholder="9876543210" maxlength="12" value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>">
                        </div>
                        <div>
                            <label for="email">Email Address *</label>
                            <input id="email" name="email" type="email" required placeholder="name@firm.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <label for="address_city">City / Address <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                    <input id="address_city" name="address_city" type="text" placeholder="e.g. Mumbai" value="<?= htmlspecialchars($_POST['address_city'] ?? '') ?>">

                    <div class="row">
                        <div>
                            <label for="profession_type">Profession Type <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                            <select id="profession_type" name="profession_type">
                                <option value="">Select...</option>
                                <option value="Chartered Accountant / CA Firm" <?= ($_POST['profession_type'] ?? '') === 'Chartered Accountant / CA Firm' ? 'selected' : '' ?>>Chartered Accountant / CA Firm</option>
                                <option value="Tax Consultant" <?= ($_POST['profession_type'] ?? '') === 'Tax Consultant' ? 'selected' : '' ?>>Tax Consultant</option>
                                <option value="Company / Business Owner" <?= ($_POST['profession_type'] ?? '') === 'Company / Business Owner' ? 'selected' : '' ?>>Company / Business Owner</option>
                                <option value="Accounts Team" <?= ($_POST['profession_type'] ?? '') === 'Accounts Team' ? 'selected' : '' ?>>Accounts Team</option>
                                <option value="Student / Trainee" <?= ($_POST['profession_type'] ?? '') === 'Student / Trainee' ? 'selected' : '' ?>>Student / Trainee</option>
                                <option value="Other" <?= ($_POST['profession_type'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div>
                            <label for="firm_company_name">Firm / Company Name <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                            <input id="firm_company_name" name="firm_company_name" type="text" placeholder="e.g. Kumar & Associates" value="<?= htmlspecialchars($_POST['firm_company_name'] ?? '') ?>">
                        </div>
                    </div>

                    <p class="form-note">Your contact details will be retained for demo support and sales follow-up.</p>

                    <button class="auth-button" type="submit">Create Demo Access</button>
                </form>
            <?php endif; ?>

            <div class="auth-links">
                Already have an account? <a href="<?= BASE_URL ?>login.php">Login</a>
                &middot;
                <a href="<?= BASE_URL ?>landing.php">Back to e-BAL</a>
            </div>
        </div>
    </div>
</body>
</html>
