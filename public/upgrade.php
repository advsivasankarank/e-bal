<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/layouts/header.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$plan = $userId > 0 ? getUserPlan($userId, $pdo) : null;
$usage = $userId > 0 ? getPlanUsage($pdo, $userId) : null;
$plans = $pdo->query("SELECT * FROM plans ORDER BY price_inr ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="page-title">Upgrade Plan</div>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<div class="card section-card">
    <strong>Current Plan</strong><br>
    <?php if ($plan): ?>
        <?= htmlspecialchars($plan['plan_name']) ?> (expires <?= htmlspecialchars($plan['expires_at']) ?>)
        <div style="margin-top:10px;">
            Companies: <?= (int) ($usage['companies_used'] ?? 0) ?> / <?= (int) ($usage['company_limit'] ?? 0) ?><br>
            Users: <?= (int) ($usage['users_used'] ?? 0) ?> / <?= (int) ($usage['user_limit'] ?? 0) ?><br>
            AI: <?= !empty($usage['ai_enabled']) ? 'Enabled' : 'Disabled' ?>
        </div>
    <?php else: ?>
        No active license found.
    <?php endif; ?>
</div>

<div class="card section-card">
    <strong>Available Plans</strong>
    <div class="tile-container" style="margin-top:16px;">
        <?php foreach ($plans as $planRow): ?>
            <div class="tile">
                <h3><?= htmlspecialchars($planRow['name']) ?></h3>
                <p>₹<?= number_format((int) $planRow['price_inr']) ?>/year</p>
                <div class="status">
                    Companies: <?= htmlspecialchars((string) $planRow['company_limit']) ?><br>
                    Users: <?= htmlspecialchars((string) $planRow['user_limit']) ?><br>
                    AI: <?= (int) $planRow['ai_enabled'] === 1 ? 'Enabled' : 'Disabled' ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p style="margin-top:14px; color:#667085;">Contact support or your admin to upgrade the plan. This page only displays current plan status.</p>
</div>

<?php
unset($_SESSION['error']);
require_once __DIR__ . '/layouts/footer.php';
?>
