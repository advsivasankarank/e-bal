<?php
define('TALLY_BRIDGE_MODE', true);
header('Content-Type: application/json');

$configPath = __DIR__ . '/../config/app.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

require_once __DIR__ . '/../xml_engine/tally_connector.php';
require_once __DIR__ . '/../app/helpers/xml_sanitizer.php';

$token = defined('TALLY_BRIDGE_TOKEN') ? (string) TALLY_BRIDGE_TOKEN : '';
$headerToken = $_SERVER['HTTP_X_BRIDGE_TOKEN'] ?? '';

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$payloadToken = is_array($payload) ? ($payload['token'] ?? '') : '';
$finalToken = $payloadToken !== '' ? $payloadToken : $headerToken;

if ($token !== '' && $finalToken !== $token) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = is_array($payload) ? ($payload['action'] ?? 'fetch') : 'fetch';

if ($action === 'health') {
    $context = fetchTallyLiveContext();
    echo json_encode([
        'ok' => $context !== null,
        'live_context' => $context,
    ]);
    exit;
}

$xml = is_array($payload) ? ($payload['xml'] ?? '') : '';
if ($xml === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing XML payload']);
    exit;
}

$response = fetchFromTally($xml);
if ($response === false || trim((string) $response) === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'No response from Tally']);
    exit;
}

echo json_encode([
    'ok' => true,
    'xml' => sanitizeTallyXML($response),
]);
