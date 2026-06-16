<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/razorpay_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$message = '';
$error = '';
$status = 'unknown';
$paymentLink = null;

$referenceId = trim((string) ($_GET['razorpay_payment_link_reference_id'] ?? ''));
$linkId = trim((string) ($_GET['razorpay_payment_link_id'] ?? ''));

try {
    if ($linkId !== '') {
        $remote = fetchRazorpayPaymentLink($linkId);
        $paymentLink = syncRazorpayPaymentLinkEntity($pdo, $remote, json_encode($_GET, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($paymentLink) {
            activateWorkspaceLicenseFromPaymentLink($pdo, $paymentLink);
            $paymentLink = getRazorpayPaymentLinkByLinkId($pdo, $linkId);
        }
    } elseif ($referenceId !== '') {
        $paymentLink = getRazorpayPaymentLinkByReference($pdo, $referenceId);
    }

    if (!$paymentLink) {
        throw new RuntimeException('Payment record not found. If money was debited, wait a minute and refresh.');
    }

    $status = strtolower((string) ($paymentLink['status'] ?? 'unknown'));
    if ($status === 'paid') {
        $message = 'Payment received successfully. Your plan has been updated.';
    } elseif (in_array($status, ['created', 'issued', 'partially_paid'], true)) {
        $message = 'Payment is still pending. Once Razorpay confirms it, your plan will be activated automatically.';
    } elseif ($status === 'cancelled') {
        $error = 'The payment link was cancelled.';
    } elseif ($status === 'expired') {
        $error = 'The payment link has expired. Please create a fresh payment link.';
    } else {
        $message = 'Current payment status: ' . strtoupper($status);
    }
} catch (Throwable $e) {
    $error = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to confirm payment status.';
}

$usage = getPlanUsage($pdo, $userId);
$page_title = 'Payment Result';
require_once __DIR__ . '/layouts/header.php';
?>

<div class="page-title">Payment Result</div>

<?php if ($error !== ''): ?>
    <div class="error-box"><p><?= htmlspecialchars($error) ?></p></div>
<?php else: ?>
    <div class="success-box"><p><?= htmlspecialchars($message) ?></p></div>
<?php endif; ?>

<div class="card section-card">
    <strong>Current Plan</strong><br>
    <?php if (!empty($usage['plan_name'])): ?>
        <?= htmlspecialchars((string) $usage['plan_name']) ?><br>
        <?php if (!empty($usage['expires_at'])): ?>
            Expires on <?= htmlspecialchars((string) $usage['expires_at']) ?><br>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($paymentLink): ?>
        <div style="margin-top:12px;">
            Payment Link ID: <strong><?= htmlspecialchars((string) $paymentLink['razorpay_link_id']) ?></strong><br>
            Status: <strong><?= htmlspecialchars(strtoupper((string) $paymentLink['status'])) ?></strong><br>
            Amount: <strong>Rs.<?= number_format((int) ($paymentLink['amount_inr'] ?? 0)) ?></strong>
        </div>
    <?php endif; ?>
</div>

<div style="margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;">
    <a class="btn" href="<?= BASE_URL ?>upgrade.php">Back to Upgrade Plan</a>
    <a class="btn" href="<?= BASE_URL ?>index.php">Go to Dashboard</a>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
