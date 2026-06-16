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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}

requireCsrfToken();

if (!isWorkspaceAdmin($pdo, $userId)) {
    $_SESSION['error'] = 'Only workspace admin users can initiate plan payments.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}

$planCode = trim((string) ($_POST['plan_code'] ?? ''));
if ($planCode === '') {
    $_SESSION['error'] = 'Select a plan before proceeding to payment.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}

try {
    $response = createRazorpayPaymentLink($pdo, $userId, $planCode);
    $shortUrl = (string) ($response['short_url'] ?? '');
    if ($shortUrl === '') {
        throw new RuntimeException('Razorpay did not return a payment link.');
    }

    header('Location: ' . $shortUrl, true, 302);
    exit;
} catch (Throwable $e) {
    $_SESSION['error'] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to create payment link.';
    header('Location: ' . BASE_URL . 'upgrade.php');
    exit;
}
