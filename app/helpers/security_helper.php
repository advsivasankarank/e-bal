<?php

function secureSessionStart(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $cookieParams = [
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($cookieParams);
    } else {
        session_set_cookie_params(
            $cookieParams['lifetime'],
            $cookieParams['path'] . '; samesite=' . $cookieParams['samesite'],
            $cookieParams['domain'],
            $cookieParams['secure'],
            $cookieParams['httponly']
        );
    }

    session_start();
}

function ensureCsrfToken(): void
{
    if (!isset($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token']) || $_SESSION['_csrf_token'] === '') {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
}

function csrfToken(): string
{
    ensureCsrfToken();
    return (string) $_SESSION['_csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrfRequestToken(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    $postToken = $_POST['_csrf_token'] ?? '';
    if (is_string($postToken) && $postToken !== '') {
        return $postToken;
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['_csrf_token']) && is_string($data['_csrf_token'])) {
            return $data['_csrf_token'];
        }
    }

    return '';
}

function isValidCsrfToken(?string $token): bool
{
    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($sessionToken) && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function requireCsrfToken(bool $jsonResponse = false): void
{
    if (isValidCsrfToken(csrfRequestToken())) {
        return;
    }

    if ($jsonResponse) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
        exit;
    }

    http_response_code(419);
    die('Invalid security token. Refresh the page and try again.');
}

function buildBridgeBrowserToken(string $purpose, int $ttlSeconds = 600): string
{
    $secret = defined('TALLY_BRIDGE_TOKEN') ? trim((string) TALLY_BRIDGE_TOKEN) : '';
    if ($secret === '' || $purpose === '') {
        return '';
    }

    $expiresAt = time() + max(60, $ttlSeconds);
    $nonce = bin2hex(random_bytes(8));
    $payload = implode('|', [$purpose, (string) $expiresAt, $nonce]);
    $signature = hash_hmac('sha256', $payload, $secret);

    return implode('.', ['v1', $purpose, (string) $expiresAt, $nonce, $signature]);
}
