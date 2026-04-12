<?php

function normalizeMcaIdentifier(string $value): string
{
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)));
}

function mcaLookupUrl(string $identifierType, string $identifier): string
{
    $baseUrl = rtrim((string) MCA_LOOKUP_URL, '/');
    $query = http_build_query([
        'type' => $identifierType,
        'identifier' => $identifier,
    ]);

    return $baseUrl . '?' . $query;
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

function mapMcaResponse(array $payload, string $category): array
{
    $company = $payload['company'] ?? $payload;
    $directors = $payload['directors'] ?? $payload['partners'] ?? $payload['signatories'] ?? [];
    $auditor = $payload['auditor'] ?? $payload['statutory_auditor'] ?? [];

    $signatoryOne = $directors[0] ?? [];
    $signatoryTwo = $directors[1] ?? [];

    $defaultDesignation = match ($category) {
        'corporate' => 'Director',
        'llp' => 'Designated Partner',
        default => 'Authorised Signatory',
    };

    return [
        'name' => pickFirstValue($company, ['name', 'company_name', 'llp_name']),
        'registered_address' => pickFirstValue($company, ['registered_address', 'address', 'registered_office_address']),
        'branch_address' => pickFirstValue($company, ['branch_address']),
        'state_code' => pickFirstValue($company, ['state_code']),
        'official_email' => pickFirstValue($company, ['official_email', 'email']),
        'mobile_no' => pickFirstValue($company, ['phone', 'contact_no', 'mobile']),
        'statutory_auditor_name' => pickFirstValue($auditor, ['name', 'auditor_name', 'partner_name']),
        'statutory_auditor_firm' => pickFirstValue($auditor, ['firm', 'firm_name', 'auditor_firm']),
        'statutory_auditor_frn' => pickFirstValue($auditor, ['frn', 'firm_registration_number']),
        'statutory_auditor_membership_no' => pickFirstValue($auditor, ['membership_no', 'membership_number']),
        'signatory_1_name' => pickFirstValue($signatoryOne, ['name', 'director_name', 'partner_name']),
        'signatory_1_designation' => pickFirstValue($signatoryOne, ['designation', 'role']) ?: $defaultDesignation,
        'signatory_1_id_no' => pickFirstValue($signatoryOne, ['din', 'dpin', 'id_no']),
        'signatory_1_is_signing' => '1',
        'signatory_2_name' => pickFirstValue($signatoryTwo, ['name', 'director_name', 'partner_name']),
        'signatory_2_designation' => pickFirstValue($signatoryTwo, ['designation', 'role']) ?: $defaultDesignation,
        'signatory_2_id_no' => pickFirstValue($signatoryTwo, ['din', 'dpin', 'id_no']),
        'signatory_2_is_signing' => $signatoryTwo !== [] ? '1' : '',
    ];
}

function fetchMcaEntityData(string $identifierType, string $identifier, string $category): array
{
    $normalizedIdentifier = normalizeMcaIdentifier($identifier);
    if ($normalizedIdentifier === '') {
        return [
            'ok' => false,
            'message' => 'Identifier is required.',
        ];
    }

    if (MCA_LOOKUP_URL === '') {
        return [
            'ok' => false,
            'message' => 'MCA lookup is not configured. Set MCA_LOOKUP_URL for your MCA or master-data connector.',
        ];
    }

    $url = mcaLookupUrl($identifierType, $normalizedIdentifier);
    $headers = ['Accept: application/json'];
    if (MCA_LOOKUP_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . MCA_LOOKUP_TOKEN;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
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

    if (($payload['ok'] ?? true) === false) {
        return [
            'ok' => false,
            'message' => (string) ($payload['message'] ?? 'MCA lookup failed.'),
        ];
    }

    return [
        'ok' => true,
        'identifier' => $normalizedIdentifier,
        'fields' => mapMcaResponse($payload, $category),
        'raw' => $payload,
    ];
}
