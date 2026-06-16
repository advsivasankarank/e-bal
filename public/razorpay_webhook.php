<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/razorpay_helper.php';

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || trim($rawBody) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Empty webhook payload']);
    exit;
}

$signature = trim((string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''));
if (!verifyRazorpayWebhookSignature($rawBody, $signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Invalid webhook signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$event = (string) ($payload['event'] ?? '');
$entity = $payload['payload']['payment_link']['entity'] ?? null;
if (!is_array($entity) || empty($entity['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Payment link entity missing']);
    exit;
}

try {
    $paymentLink = syncRazorpayPaymentLinkEntity($pdo, $entity, $rawBody);
    if (!$paymentLink) {
        throw new RuntimeException('Payment link is not mapped to any workspace.');
    }

    if ($event === 'payment_link.paid' || (string) ($paymentLink['status'] ?? '') === 'paid') {
        activateWorkspaceLicenseFromPaymentLink($pdo, $paymentLink);
    }

    echo json_encode([
        'ok' => true,
        'event' => $event,
        'status' => $paymentLink['status'] ?? null,
    ]);
} catch (Throwable $e) {
    appLog('ERROR', 'Razorpay webhook failed', ['message' => $e->getMessage(), 'event' => $event]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Webhook processing failed']);
}
