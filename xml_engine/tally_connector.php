<?php
if (!defined('TALLY_BRIDGE_URL')) {
    $appConfig = __DIR__ . '/../config/app.php';
    if (file_exists($appConfig)) {
        require_once $appConfig;
    }
}

function fetchFromTally($xmlRequest) {
    $bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
    if ($bridgeUrl !== '' && !defined('TALLY_BRIDGE_MODE')) {
        return fetchFromTallyBridge($bridgeUrl, $xmlRequest);
    }

    $url = "http://localhost:9000";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => $xmlRequest,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/xml"],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $response;
}

function fetchFromTallyBridge(string $bridgeUrl, string $xmlRequest) {
    $token = defined('TALLY_BRIDGE_TOKEN') ? (string) TALLY_BRIDGE_TOKEN : '';
    $payload = json_encode([
        'action' => 'fetch',
        'xml' => $xmlRequest,
        'token' => $token,
    ]);

    $ch = curl_init($bridgeUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Accept: application/json",
        ],
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($token !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-Bridge-Token: " . $token,
        ]);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return false;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        return false;
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        if (!empty($decoded['ok']) && isset($decoded['xml'])) {
            return (string) $decoded['xml'];
        }

        return false;
    }

    return $response;
}

function formatTallyContextDate($value) {
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{8}$/', $value)) {
        $date = DateTime::createFromFormat('Ymd', $value);
        if ($date instanceof DateTime) {
            return $date->format('d M Y');
        }
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y', $timestamp) : $value;
}

function fetchTallyLiveContext() {
    if (!function_exists('sanitizeTallyXML')) {
        require_once __DIR__ . '/../app/helpers/xml_sanitizer.php';
    }

    $xml = <<<'XML'
<ENVELOPE>
    <HEADER>
        <VERSION>1</VERSION>
        <TALLYREQUEST>EXPORT</TALLYREQUEST>
        <TYPE>COLLECTION</TYPE>
        <ID>Company Collection</ID>
    </HEADER>
    <BODY>
        <DESC>
            <STATICVARIABLES>
                <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
            </STATICVARIABLES>
            <TDL>
                <TDLMESSAGE>
                    <COLLECTION NAME="Company Collection">
                        <TYPE>Company</TYPE>
                        <FETCH>NAME,STARTINGFROM,BOOKSFROM,ENDINGAT</FETCH>
                    </COLLECTION>
                </TDLMESSAGE>
            </TDL>
        </DESC>
    </BODY>
</ENVELOPE>
XML;

    $response = fetchFromTally($xml);
    if (!$response) {
        return null;
    }

    $response = sanitizeTallyXML($response);
    libxml_use_internal_errors(true);
    $xmlObj = simplexml_load_string($response);

    if (!$xmlObj) {
        libxml_clear_errors();
        return null;
    }

    $companyNodes = $xmlObj->xpath("//*[local-name()='DATA']/*[local-name()='COLLECTION']/*[local-name()='COMPANY']");
    if (!$companyNodes || empty($companyNodes[0])) {
        return null;
    }

    $companyNode = $companyNodes[0];
    $name = trim((string) ($companyNode->NAME ?? $companyNode['NAME'] ?? ''));
    $booksFrom = trim((string) ($companyNode->BOOKSFROM ?? ''));
    $startingFrom = trim((string) ($companyNode->STARTINGFROM ?? ''));
    $endingAt = trim((string) ($companyNode->ENDINGAT ?? ''));

    return [
        'company_name' => $name,
        'period_from' => formatTallyContextDate($booksFrom !== '' ? $booksFrom : $startingFrom),
        'period_to' => formatTallyContextDate($endingAt),
    ];
}
?>
