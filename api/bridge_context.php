<?php

require_once __DIR__ . '/../config/database.php';

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

$expected = getenv('EBAL_BRIDGE_TOKEN') ?: '';
if ($expected !== '' && $token !== $expected) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($clientId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'client_id is required']);
    exit;
}

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
]);
