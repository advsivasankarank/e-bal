<?php
require_once __DIR__ . '/runtime_helper.php';
require_once __DIR__ . '/plan_helper.php';

function ensureRazorpayTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!appAllowsRuntimeSchema()) {
            assertTableExists($pdo, 'razorpay_payment_links');
            $checked = true;
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS razorpay_payment_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_user_id INT NOT NULL,
                created_by_user_id INT NULL,
                plan_code VARCHAR(40) NOT NULL,
                amount_inr INT NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'INR',
                reference_id VARCHAR(40) NOT NULL UNIQUE,
                razorpay_link_id VARCHAR(80) NOT NULL UNIQUE,
                razorpay_short_url VARCHAR(255) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'created',
                expire_by INT NULL,
                callback_url VARCHAR(255) NOT NULL DEFAULT '',
                callback_payload TEXT NULL,
                amount_paid_inr INT NOT NULL DEFAULT 0,
                razorpay_payment_id VARCHAR(80) NULL,
                paid_at DATETIME NULL,
                activated_at DATETIME NULL,
                raw_payload LONGTEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rzp_owner (owner_user_id),
                INDEX idx_rzp_status (status)
            )
        ");

        $checked = true;
    } catch (Throwable $e) {
        appLog('ERROR', 'Razorpay schema validation failed', ['message' => $e->getMessage()]);
        failApplication('Payment configuration is incomplete.', 500, ['message' => $e->getMessage()]);
    }
}

function isRazorpayConfigured(): bool
{
    $keyId = getenv('RAZORPAY_KEY_ID');
    $keySecret = getenv('RAZORPAY_KEY_SECRET');

    return is_string($keyId) && $keyId !== '' && is_string($keySecret) && $keySecret !== '';
}

function razorpayCredentials(): array
{
    $keyId = getenv('RAZORPAY_KEY_ID');
    $keySecret = getenv('RAZORPAY_KEY_SECRET');
    $webhookSecret = getenv('RAZORPAY_WEBHOOK_SECRET');

    if (!isLocalEnv()) {
        requireProductionValue('RAZORPAY_KEY_ID', $keyId, 'Payment gateway configuration missing.');
        requireProductionValue('RAZORPAY_KEY_SECRET', $keySecret, 'Payment gateway configuration missing.');
        requireProductionValue('RAZORPAY_WEBHOOK_SECRET', $webhookSecret, 'Payment gateway configuration missing.');
    }

    return [
        'key_id' => is_string($keyId) ? $keyId : '',
        'key_secret' => is_string($keySecret) ? $keySecret : '',
        'webhook_secret' => is_string($webhookSecret) ? $webhookSecret : '',
        'base_url' => 'https://api.razorpay.com/v1',
    ];
}

function razorpayApiRequest(string $method, string $path, ?array $payload = null): array
{
    $creds = razorpayCredentials();
    if ($creds['key_id'] === '' || $creds['key_secret'] === '') {
        throw new RuntimeException('Razorpay is not configured.');
    }

    $url = rtrim($creds['base_url'], '/') . '/' . ltrim($path, '/');
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $creds['key_id'] . ':' . $creds['key_secret'],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Razorpay request failed: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    if ($status >= 400) {
        $message = is_array($decoded) ? (string) ($decoded['error']['description'] ?? $decoded['error']['reason'] ?? 'Razorpay API error') : 'Razorpay API error';
        throw new RuntimeException($message);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected Razorpay response.');
    }

    return $decoded;
}

function buildRazorpayReferenceId(int $ownerUserId): string
{
    return substr('EBAL' . $ownerUserId . time(), 0, 40);
}

function getPublicAppOrigin(): string
{
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        return ($isHttps ? 'https' : 'http') . '://' . $host;
    }

    if (!isLocalEnv()) {
        $baseUrl = getenv('APP_BASE_URL');
        if (is_string($baseUrl) && $baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }
    }

    return 'http://localhost';
}

function buildRazorpayCallbackUrl(): string
{
    return rtrim(getPublicAppOrigin(), '/') . rtrim(BASE_URL, '/') . '/payment_result.php';
}

function findPlanCatalogRow(PDO $pdo, string $planCode): ?array
{
    foreach (getPlanCatalog($pdo) as $plan) {
        if ((string) $plan['code'] === $planCode) {
            return $plan;
        }
    }

    return null;
}

function storeRazorpayPaymentLink(PDO $pdo, array $data): void
{
    ensureRazorpayTables($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO razorpay_payment_links
            (owner_user_id, created_by_user_id, plan_code, amount_inr, currency, reference_id, razorpay_link_id, razorpay_short_url, status, expire_by, callback_url, callback_payload, amount_paid_inr, razorpay_payment_id, paid_at, activated_at, raw_payload)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            plan_code = VALUES(plan_code),
            amount_inr = VALUES(amount_inr),
            currency = VALUES(currency),
            razorpay_short_url = VALUES(razorpay_short_url),
            status = VALUES(status),
            expire_by = VALUES(expire_by),
            callback_url = VALUES(callback_url),
            callback_payload = VALUES(callback_payload),
            amount_paid_inr = VALUES(amount_paid_inr),
            razorpay_payment_id = VALUES(razorpay_payment_id),
            paid_at = VALUES(paid_at),
            activated_at = COALESCE(razorpay_payment_links.activated_at, VALUES(activated_at)),
            raw_payload = VALUES(raw_payload)
    ");

    $stmt->execute([
        (int) $data['owner_user_id'],
        isset($data['created_by_user_id']) ? (int) $data['created_by_user_id'] : null,
        (string) $data['plan_code'],
        (int) $data['amount_inr'],
        (string) ($data['currency'] ?? 'INR'),
        (string) $data['reference_id'],
        (string) $data['razorpay_link_id'],
        (string) $data['razorpay_short_url'],
        (string) ($data['status'] ?? 'created'),
        isset($data['expire_by']) ? (int) $data['expire_by'] : null,
        (string) ($data['callback_url'] ?? ''),
        isset($data['callback_payload']) ? (string) $data['callback_payload'] : null,
        (int) ($data['amount_paid_inr'] ?? 0),
        $data['razorpay_payment_id'] ?? null,
        $data['paid_at'] ?? null,
        $data['activated_at'] ?? null,
        $data['raw_payload'] ?? null,
    ]);
}

function createRazorpayPaymentLink(PDO $pdo, int $requestUserId, string $planCode): array
{
    ensureRazorpayTables($pdo);

    $ownerUserId = getOwnerUserId($pdo, $requestUserId);
    $ownerUser = getUserById($pdo, $ownerUserId);
    if (!$ownerUser) {
        throw new RuntimeException('Workspace owner not found.');
    }

    $plan = findPlanCatalogRow($pdo, $planCode);
    if (!$plan) {
        throw new RuntimeException('Selected plan is invalid.');
    }

    $referenceId = buildRazorpayReferenceId($ownerUserId);
    $callbackUrl = buildRazorpayCallbackUrl();
    $expireBy = time() + (14 * 24 * 60 * 60);

    $payload = [
        'amount' => ((int) $plan['price_inr']) * 100,
        'currency' => 'INR',
        'accept_partial' => false,
        'reference_id' => $referenceId,
        'description' => 'e-BAL ' . $plan['name'] . ' annual subscription',
        'customer' => [
            'name' => (string) ($ownerUser['name'] ?? 'e-BAL Customer'),
            'email' => (string) ($ownerUser['email'] ?? ''),
        ],
        'notify' => [
            'sms' => false,
            'email' => false,
        ],
        'reminder_enable' => true,
        'expire_by' => $expireBy,
        'callback_url' => $callbackUrl,
        'callback_method' => 'get',
        'notes' => [
            'workspace_user_id' => (string) $ownerUserId,
            'plan_code' => (string) $plan['code'],
            'created_by_user_id' => (string) $requestUserId,
        ],
    ];

    $response = razorpayApiRequest('POST', '/payment_links', $payload);

    storeRazorpayPaymentLink($pdo, [
        'owner_user_id' => $ownerUserId,
        'created_by_user_id' => $requestUserId,
        'plan_code' => (string) $plan['code'],
        'amount_inr' => (int) $plan['price_inr'],
        'currency' => (string) ($response['currency'] ?? 'INR'),
        'reference_id' => (string) $response['reference_id'],
        'razorpay_link_id' => (string) $response['id'],
        'razorpay_short_url' => (string) $response['short_url'],
        'status' => (string) ($response['status'] ?? 'created'),
        'expire_by' => isset($response['expire_by']) ? (int) $response['expire_by'] : $expireBy,
        'callback_url' => (string) ($response['callback_url'] ?? $callbackUrl),
        'raw_payload' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    return $response;
}

function getRazorpayPaymentLinkByReference(PDO $pdo, string $referenceId): ?array
{
    ensureRazorpayTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM razorpay_payment_links WHERE reference_id = ? LIMIT 1');
    $stmt->execute([$referenceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function getRazorpayPaymentLinkByLinkId(PDO $pdo, string $linkId): ?array
{
    ensureRazorpayTables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM razorpay_payment_links WHERE razorpay_link_id = ? LIMIT 1');
    $stmt->execute([$linkId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fetchRazorpayPaymentLink(string $linkId): array
{
    return razorpayApiRequest('GET', '/payment_links/' . rawurlencode($linkId));
}

function paymentLinkAmountPaidInr(array $entity): int
{
    $amountPaid = (int) ($entity['amount_paid'] ?? 0);
    return (int) round($amountPaid / 100);
}

function paymentLinkPaidAt(array $entity): ?string
{
    $payments = $entity['payments'] ?? [];
    if (is_array($payments) && isset($payments[0]['created_at'])) {
        return date('Y-m-d H:i:s', (int) $payments[0]['created_at']);
    }

    return null;
}

function paymentLinkPaymentId(array $entity): ?string
{
    $payments = $entity['payments'] ?? [];
    if (is_array($payments) && isset($payments[0]['payment_id']) && is_string($payments[0]['payment_id'])) {
        return $payments[0]['payment_id'];
    }

    if (isset($payments[0]['id']) && is_string($payments[0]['id'])) {
        return $payments[0]['id'];
    }

    return null;
}

function syncRazorpayPaymentLinkEntity(PDO $pdo, array $entity, ?string $callbackPayload = null): ?array
{
    $linkId = (string) ($entity['id'] ?? '');
    $referenceId = (string) ($entity['reference_id'] ?? '');
    if ($linkId === '' || $referenceId === '') {
        return null;
    }

    $existing = getRazorpayPaymentLinkByLinkId($pdo, $linkId) ?: getRazorpayPaymentLinkByReference($pdo, $referenceId);
    if (!$existing) {
        return null;
    }

    $paidAt = paymentLinkPaidAt($entity);
    $paymentId = paymentLinkPaymentId($entity);

    storeRazorpayPaymentLink($pdo, [
        'owner_user_id' => (int) $existing['owner_user_id'],
        'created_by_user_id' => (int) ($existing['created_by_user_id'] ?? 0),
        'plan_code' => (string) $existing['plan_code'],
        'amount_inr' => (int) $existing['amount_inr'],
        'currency' => (string) ($entity['currency'] ?? $existing['currency'] ?? 'INR'),
        'reference_id' => $referenceId,
        'razorpay_link_id' => $linkId,
        'razorpay_short_url' => (string) ($entity['short_url'] ?? $existing['razorpay_short_url']),
        'status' => (string) ($entity['status'] ?? $existing['status']),
        'expire_by' => isset($entity['expire_by']) ? (int) $entity['expire_by'] : (int) ($existing['expire_by'] ?? 0),
        'callback_url' => (string) ($entity['callback_url'] ?? $existing['callback_url']),
        'callback_payload' => $callbackPayload,
        'amount_paid_inr' => paymentLinkAmountPaidInr($entity),
        'razorpay_payment_id' => $paymentId,
        'paid_at' => $paidAt,
        'activated_at' => $existing['activated_at'] ?? null,
        'raw_payload' => json_encode($entity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    return getRazorpayPaymentLinkByLinkId($pdo, $linkId);
}

function calculateRenewedPlanExpiry(PDO $pdo, int $ownerUserId): string
{
    $today = date('Y-m-d');
    $license = getActiveLicense($pdo, $ownerUserId);
    $base = $today;

    if ($license && ($license['status'] ?? '') === 'active') {
        $currentExpiry = (string) ($license['expires_at'] ?? '');
        if ($currentExpiry !== '' && strtotime($currentExpiry) > strtotime($today)) {
            $base = $currentExpiry;
        }
    }

    return date('Y-m-d', strtotime($base . ' +1 year'));
}

function determineLicenseTransactionType(PDO $pdo, int $ownerUserId, string $planCode): string
{
    $license = getActiveLicense($pdo, $ownerUserId);
    if (!$license) {
        return 'new_sale';
    }

    $existingPlan = (string) ($license['plan'] ?? '');
    if ($existingPlan === '') {
        return 'new_sale';
    }

    if ($existingPlan === $planCode) {
        return 'renewal';
    }

    $catalog = getPlanCatalog($pdo);
    $byCode = [];
    foreach ($catalog as $plan) {
        $byCode[(string) $plan['code']] = (int) $plan['price_inr'];
    }

    return (($byCode[$planCode] ?? 0) >= ($byCode[$existingPlan] ?? 0)) ? 'upgrade' : 'downgrade';
}

function activateWorkspaceLicenseFromPaymentLink(PDO $pdo, array $paymentLink): bool
{
    if ((string) ($paymentLink['status'] ?? '') !== 'paid') {
        return false;
    }

    if (!empty($paymentLink['activated_at'])) {
        return false;
    }

    $ownerUserId = (int) ($paymentLink['owner_user_id'] ?? 0);
    $planCode = (string) ($paymentLink['plan_code'] ?? '');
    if ($ownerUserId <= 0 || $planCode === '') {
        throw new RuntimeException('Payment link is missing workspace or plan details.');
    }

    $expiresAt = calculateRenewedPlanExpiry($pdo, $ownerUserId);
    $transactionType = determineLicenseTransactionType($pdo, $ownerUserId, $planCode);
    $billedAt = !empty($paymentLink['paid_at']) ? date('Y-m-d', strtotime((string) $paymentLink['paid_at'])) : date('Y-m-d');

    upsertWorkspaceLicense(
        $pdo,
        $ownerUserId,
        $planCode,
        $expiresAt,
        true,
        $transactionType,
        'paid',
        $billedAt,
        (int) ($paymentLink['amount_paid_inr'] ?? $paymentLink['amount_inr'] ?? 0),
        'Razorpay Payment Link ' . (string) ($paymentLink['razorpay_link_id'] ?? '')
    );

    $stmt = $pdo->prepare("
        UPDATE razorpay_payment_links
        SET activated_at = NOW()
        WHERE razorpay_link_id = ? AND activated_at IS NULL
    ");
    $stmt->execute([(string) $paymentLink['razorpay_link_id']]);

    return $stmt->rowCount() > 0;
}

function verifyRazorpayWebhookSignature(string $rawBody, string $signature): bool
{
    $creds = razorpayCredentials();
    $secret = (string) ($creds['webhook_secret'] ?? '');
    if ($secret === '' || $signature === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signature);
}

function listRecentRazorpayPaymentLinks(PDO $pdo, int $userId, int $limit = 5): array
{
    ensureRazorpayTables($pdo);
    $ownerUserId = getOwnerUserId($pdo, $userId);
    $limit = max(1, min($limit, 20));

    $stmt = $pdo->prepare("
        SELECT *
        FROM razorpay_payment_links
        WHERE owner_user_id = ?
        ORDER BY id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $ownerUserId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
