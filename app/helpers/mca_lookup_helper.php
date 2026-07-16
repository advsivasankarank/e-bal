<?php

require_once __DIR__ . '/company_reporting_helper.php';

function normalizeMcaIdentifier(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)));
}

function pickFirstValue(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && trim((string) $data[$key]) !== '') {
            return trim((string) $data[$key]);
        }
    }

    return '';
}

function sandboxStateCodeFromName(string $stateName): string
{
    $needle = strtolower(trim($stateName));
    if ($needle === '') {
        return '';
    }

    foreach (getIndianStateOptions() as $code => $label) {
        if (strtolower($label) === $needle) {
            return $code;
        }
    }

    return '';
}

function sandboxAuthenticate(): array
{
    if (SANDBOX_API_KEY === '' || SANDBOX_API_SECRET === '') {
        return [
            'ok' => false,
            'message' => 'MCA lookup is not configured. Set SANDBOX_API_KEY and SANDBOX_API_SECRET.',
        ];
    }

    $ch = curl_init(rtrim(SANDBOX_API_BASE_URL, '/') . '/authenticate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'x-api-key: ' . SANDBOX_API_KEY,
            'x-api-secret: ' . SANDBOX_API_SECRET,
            'x-api-version: 1.0.0',
            'Content-Type: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => false,
            'message' => 'MCA authentication failed: ' . $message,
        ];
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $payload = json_decode($response, true);
    if ($statusCode >= 400 || !is_array($payload)) {
        return [
            'ok' => false,
            'message' => 'MCA authentication returned HTTP ' . $statusCode . '.',
        ];
    }

    $accessToken = pickFirstValue($payload, ['access_token']);
    if ($accessToken === '') {
        return [
            'ok' => false,
            'message' => 'MCA authentication did not return an access token.',
        ];
    }

    return [
        'ok' => true,
        'access_token' => $accessToken,
    ];
}

function mapMcaResponse(array $company): array
{
    return [
        'name' => pickFirstValue($company, ['company_name']),
        'registered_address' => pickFirstValue($company, ['registered_office_address']),
        'state_code' => sandboxStateCodeFromName(pickFirstValue($company, ['company_state_code'])),
    ];
}

function fetchMcaEntityData(string $identifier): array
{
    $normalizedIdentifier = normalizeMcaIdentifier($identifier);
    if ($normalizedIdentifier === '') {
        return [
            'ok' => false,
            'message' => 'Identifier is required.',
        ];
    }

    $auth = sandboxAuthenticate();
    if (!$auth['ok']) {
        return $auth;
    }

    $ch = curl_init(rtrim(SANDBOX_API_BASE_URL, '/') . '/kyc/mca/company/master-data');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $auth['access_token'],
            'x-api-key: ' . SANDBOX_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['cin' => $normalizedIdentifier]),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = curl_exec($ch);

    if ($response === false) {
        $message = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => false,
            'message' => 'MCA lookup failed: ' . $message,
        ];
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($statusCode === 521) {
        return [
            'ok' => false,
            'message' => 'No MCA record found for this identifier.',
        ];
    }

    if ($statusCode >= 400) {
        return [
            'ok' => false,
            'message' => 'MCA lookup returned HTTP ' . $statusCode . '.',
        ];
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        return [
            'ok' => false,
            'message' => 'MCA lookup did not return valid JSON.',
        ];
    }

    $company = $payload['data'][0] ?? null;
    if (!is_array($company)) {
        return [
            'ok' => false,
            'message' => 'No MCA record found for this identifier.',
        ];
    }

    return [
        'ok' => true,
        'identifier' => $normalizedIdentifier,
        'fields' => mapMcaResponse($company),
        'raw' => $payload,
    ];
}
