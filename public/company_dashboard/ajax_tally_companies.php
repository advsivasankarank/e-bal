<?php
/**
 * AJAX: Return Smart Bridge configuration for browser-side communication.
 *
 * Architecture (v2): Browser → Smart Bridge (localhost:9123) → Tally (port 9000)
 * The browser calls the bridge directly. This endpoint only provides configuration.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') {
    $bridgeUrl = 'http://127.0.0.1:9123';
}
$bridgeUrl = rtrim($bridgeUrl, '/');
$bridgeToken = buildBridgeBrowserToken('company');

echo json_encode([
    'ok' => true,
    'bridge_url' => $bridgeUrl,
    'bridge_token' => $bridgeToken,
]);
