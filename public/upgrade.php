<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/razorpay_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$plan = $userId > 0 ? getUserPlan($userId, $pdo) : null;
$usage = $userId > 0 ? getPlanUsage($pdo, $userId) : null;
$plans = getPlanCatalog($pdo);
$canPay = $userId > 0 && isWorkspaceAdmin($pdo, $userId);
$razorpayEnabled = isRazorpayConfigured();
$recentPaymentLinks = [];
if ($userId > 0 && $razorpayEnabled) {
    try {
        $recentPaymentLinks = listRecentRazorpayPaymentLinks($pdo, $userId, 5);
    } catch (Throwable $e) {
        $recentPaymentLinks = [];
    }
}

$page_title = 'Upgrade Plan';
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Upgrade']
]) ?>

<?= uiPageHero('Upgrade Plan', 'Manage your subscription and billing') ?>

<?php if (!empty($_SESSION['error'])): ?>
    <?= uiAlert($_SESSION['error'], 'error') ?>
<?php endif; ?>

<style>
.upgrade-grid { display:grid; grid-template-columns:1.2fr 1fr; gap:18px; }
.upgrade-plans { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; }
.upgrade-plan { border:1px solid #d8e2ef; border-radius:14px; padding:18px; background:#fff; position:relative; }
.upgrade-plan.is-current { border-color:#0f766e; box-shadow:0 12px 28px rgba(15, 118, 110, 0.12); }
.upgrade-badge { position:absolute; top:14px; right:14px; background:#0f766e; color:#fff; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700; }
.upgrade-price { font-size:28px; font-weight:700; color:#0f172a; margin:8px 0; }
.upgrade-list { margin:12px 0 0 18px; line-height:1.7; }
.usage-grid { display:grid; grid-template-columns:repeat(3, minmax(120px, 1fr)); gap:12px; margin-top:14px; }
.usage-box { border:1px solid #d8e2ef; border-radius:12px; padding:14px; background:#f8fbff; }
.usage-box strong { display:block; font-size:22px; color:#0f172a; }
.plan-actions { margin-top:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.plan-pay-form { margin:0; }
.payment-table { width:100%; border-collapse:collapse; margin-top:16px; }
.payment-table th, .payment-table td { border:1px solid #dbe3ef; padding:10px 12px; text-align:left; }
.payment-table th { background:#eff6fb; color:#334155; }
@media (max-width: 920px) { .upgrade-grid, .usage-grid { grid-template-columns:1fr; } }
</style>

<div class="upgrade-grid">
    <div class="ui-section-card">
        <div class="ui-section-card-header">
            <div class="ui-section-card-title">Current Plan</div>
        </div>
        <div class="ui-section-card-body">
            <?php if ($plan && $usage): ?>
                <div style="margin-top:0; font-size:18px; font-weight:700; color:#0f172a;"><?= htmlspecialchars($plan['plan_name']) ?></div>
                <div style="color:#64748b; margin-top:4px;">Expires on <?= htmlspecialchars($plan['expires_at']) ?></div>
                <div class="usage-grid">
                    <div class="usage-box">
                        <span>Companies</span>
                        <strong><?= (int) $usage['companies_used'] ?> / <?= (int) $usage['company_limit'] ?></strong>
                    </div>
                    <div class="usage-box">
                        <span>Users</span>
                        <strong><?= (int) $usage['users_used'] ?> / <?= (int) $usage['user_limit'] ?></strong>
                    </div>
                    <div class="usage-box">
                        <span>AI</span>
                        <strong><?= !empty($usage['ai_enabled']) ? 'On' : 'Off' ?></strong>
                    </div>
                </div>
                <p style="margin-top:14px; color:#64748b;"><?= htmlspecialchars((string) ($usage['description_text'] ?? '')) ?></p>
            <?php else: ?>
                <p style="margin-top:12px;">No active license found yet for this workspace.</p>
            <?php endif; ?>

            <div style="margin-top:12px;">
                <?php if (!$razorpayEnabled): ?>
                    <?= uiStatusBadge('Payment gateway setup pending', 'warning') ?>
                <?php elseif (!$canPay): ?>
                    <?= uiStatusBadge('Only workspace admin can initiate payment', 'default') ?>
                <?php else: ?>
                    <?= uiStatusBadge('Online payment enabled', 'success') ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="ui-section-card">
        <div class="ui-section-card-header">
            <div class="ui-section-card-title">Payment Notes</div>
        </div>
        <div class="ui-section-card-body">
            <ul class="upgrade-list">
                <li>e-BAL Base: 1 user, 10 entities, annual price ₹7,499.</li>
                <li>e-BAL Pro: 10 users, 25 entities, annual price ₹14,999.</li>
                <li>e-BAL Elite: Unlimited users, unlimited entities, annual price ₹29,999.</li>
                <li>AI-assisted drafting is available from Pro upwards.</li>
            </ul>

            <?php if (!$razorpayEnabled): ?>
                <?= uiAlert('Razorpay is not configured yet. Set `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, and `RAZORPAY_WEBHOOK_SECRET` before enabling live payments.', 'warning') ?>
            <?php elseif (!$canPay): ?>
                <div style="margin-top:14px;padding:14px;background:#f8fbff;border:1px solid #d8e2ef;border-radius:12px;font-size:.82rem;color:#5b6b79;">
                    Staff users can review plan options here, but only workspace admins can pay and renew the subscription.
                </div>
            <?php else: ?>
                <div style="margin-top:14px;padding:14px;background:#f8fbff;border:1px solid #d8e2ef;border-radius:12px;font-size:.82rem;color:#5b6b79;">
                    Payment is collected through Razorpay Payment Links. After successful payment, the license is activated automatically.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="ui-section-card" style="margin-top:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Available Plans</div>
    </div>
    <div class="ui-section-card-body">
        <div class="upgrade-plans">
            <?php foreach ($plans as $planRow): ?>
                <?php $isCurrent = $plan && $plan['plan'] === $planRow['code']; ?>
                <div class="upgrade-plan <?= $isCurrent ? 'is-current' : '' ?>">
                    <?php if ($isCurrent): ?><div class="upgrade-badge">Current</div><?php endif; ?>
                    <h3><?= htmlspecialchars($planRow['name']) ?></h3>
                    <div class="upgrade-price">Rs.<?= number_format((int) $planRow['price_inr']) ?><span style="font-size:14px; font-weight:500; color:#64748b;">/year</span></div>
                    <ul class="upgrade-list">
                        <li><?= (int) $planRow['company_limit'] >= 999 ? 'Unlimited companies' : ((int) $planRow['company_limit'] . ' companies') ?></li>
                        <li><?= (int) $planRow['user_limit'] ?> users</li>
                        <li><?= (int) $planRow['ai_enabled'] === 1 ? 'AI drafting included' : 'AI drafting not included' ?></li>
                    </ul>
                    <p style="margin-top:12px; color:#64748b;"><?= htmlspecialchars($planRow['description_text']) ?></p>

                    <div class="plan-actions">
                        <?php if ($razorpayEnabled && $canPay): ?>
                            <form method="post" action="<?= BASE_URL ?>create_payment_link.php" class="plan-pay-form">
                                <?= csrfInput() ?>
                                <input type="hidden" name="plan_code" value="<?= htmlspecialchars($planRow['code']) ?>">
                                <?= uiButton($isCurrent ? 'Renew / Pay Again' : ('Pay Rs.' . number_format((int) $planRow['price_inr'])), '', 'primary') ?>
                            </form>
                        <?php else: ?>
                            <?= uiStatusBadge($razorpayEnabled ? 'Workspace admin payment only' : 'Payment setup pending', 'default') ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($recentPaymentLinks !== []): ?>
<div class="ui-section-card" style="margin-top:16px;">
    <div class="ui-section-card-header">
        <div class="ui-section-card-title">Recent Payment Links</div>
    </div>
    <div class="ui-section-card-body">
        <?= uiTableStart(['Reference', 'Plan', 'Amount', 'Status', 'Created', 'Open']) ?>
            <?php foreach ($recentPaymentLinks as $paymentLink): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $paymentLink['reference_id']) ?></td>
                    <td><?= htmlspecialchars((string) strtoupper((string) $paymentLink['plan_code'])) ?></td>
                    <td>Rs.<?= number_format((int) ($paymentLink['amount_inr'] ?? 0)) ?></td>
                    <td><?= uiStatusBadge(strtoupper((string) $paymentLink['status']), 'default') ?></td>
                    <td><?= htmlspecialchars((string) $paymentLink['created_at']) ?></td>
                    <td>
                        <?php if (!empty($paymentLink['razorpay_short_url'])): ?>
                            <?= uiButton('Open Link', htmlspecialchars((string) $paymentLink['razorpay_short_url']), 'outline', '', 'target="_blank" rel="noopener"') ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?= uiTableEnd() ?>
    </div>
</div>
<?php endif; ?>

<?php
unset($_SESSION['error']);
require_once __DIR__ . '/layouts/footer_v2.php';
?>
