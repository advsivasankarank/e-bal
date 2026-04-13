<?php
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$token = $payload['token'] ?? '';
$headerToken = $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? '';
$expected = defined('TALLY_BRIDGE_WEBHOOK_TOKEN') ? TALLY_BRIDGE_WEBHOOK_TOKEN : '';

if ($expected !== '' && $token !== $expected && $headerToken !== $expected) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$publicUrl = trim((string) ($payload['public_url'] ?? ''));
$fetchUrl = trim((string) ($payload['fetch_url'] ?? ''));

if ($publicUrl === '' && $fetchUrl === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing public_url or fetch_url']);
    exit;
}

if ($fetchUrl === '' && $publicUrl !== '') {
    $fetchUrl = rtrim($publicUrl, '/') . '/fetch';
}

if (!filter_var($fetchUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid fetch_url']);
    exit;
}

$bridgeSettingsPath = __DIR__ . '/../config/bridge_settings.php';
$content = "<?php\n"
    . "if (!defined('TALLY_BRIDGE_URL')) { define('TALLY_BRIDGE_URL', " . var_export($fetchUrl, true) . "); }\n"
    . "if (!defined('TALLY_BRIDGE_PUBLIC_URL')) { define('TALLY_BRIDGE_PUBLIC_URL', " . var_export($publicUrl, true) . "); }\n"
    . "if (!defined('TALLY_BRIDGE_UPDATED_AT')) { define('TALLY_BRIDGE_UPDATED_AT', " . time() . "); }\n";

if (file_put_contents($bridgeSettingsPath, $content, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to write settings']);
    exit;
}

echo json_encode(['ok' => true, 'fetch_url' => $fetchUrl]);
