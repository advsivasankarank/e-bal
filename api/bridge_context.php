<?php

require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/security_helper.php';
require_once __DIR__ . '/../app/helpers/runtime_helper.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

$token = trim((string) ($payload['token'] ?? ''));
$clientId = trim((string) ($payload['client_id'] ?? ''));
$companyId = (int) ($payload['company_id'] ?? 0);
$fyId = (int) ($payload['fy_id'] ?? 0);

$expected = defined('TALLY_BRIDGE_TOKEN') ? trim((string) TALLY_BRIDGE_TOKEN) : '';
$isAuthenticatedUser = (int) ($_SESSION['user_id'] ?? 0) > 0 && isValidCsrfToken((string) ($payload['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
$isBridgeTokenValid = $expected !== '' && $token !== '' && hash_equals($expected, $token);

if (!$isAuthenticatedUser && !$isBridgeTokenValid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($clientId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'client_id is required']);
    exit;
}

try {
    if (appAllowsRuntimeSchema()) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bridge_clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id VARCHAR(50) NOT NULL UNIQUE,
                company_id INT NOT NULL,
                fy_id INT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    } else {
        assertTableExists($pdo, 'bridge_clients');
    }
} catch (Throwable $e) {
    appLog('ERROR', 'Bridge context schema unavailable', ['message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Bridge configuration unavailable']);
    exit;
}

if ($companyId > 0 && $fyId > 0) {
    $stmt = $pdo->prepare("
        INSERT INTO bridge_clients (client_id, company_id, fy_id, active)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            company_id = VALUES(company_id),
            fy_id = VALUES(fy_id),
            active = 1,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$clientId, $companyId, $fyId]);

    echo json_encode([
        'ok' => true,
        'client_id' => $clientId,
        'company_id' => $companyId,
        'fy_id' => $fyId,
        'mode' => 'updated',
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT company_id, fy_id
    FROM bridge_clients
    WHERE client_id = ? AND active = 1
    LIMIT 1
");
$stmt->execute([$clientId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Client mapping not found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'company_id' => (int) $row['company_id'],
    'fy_id' => (int) $row['fy_id'],
    'client_id' => $clientId,
    'mode' => 'fetched',
]);
