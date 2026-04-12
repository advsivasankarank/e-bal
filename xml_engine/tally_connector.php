<?php
function fetchFromTally($xmlRequest) {
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
        $error = curl_error($ch);
        curl_close($ch);
        return false;
    }

    curl_close($ch);
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
