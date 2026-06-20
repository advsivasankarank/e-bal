<?php
/**
 * AJAX: Check Smart Bridge status + list Tally companies
 *
 * Architecture: PHP Server → Smart Bridge (port 9123) → Tally (port 9000)
 * Uses existing bridge /fetch endpoint to forward XML requests.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

/* Determine bridge URL */
$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') {
    /* Fallback: try common local bridge ports */
    $bridgeUrl = 'http://127.0.0.1:9123';
}
$bridgeUrl = rtrim($bridgeUrl, '/');
$bridgeToken = defined('TALLY_BRIDGE_TOKEN') ? trim((string) TALLY_BRIDGE_TOKEN) : '';

/* Step 1: Health check via bridge /health endpoint */
$healthOk = false;
$healthData = null;
$healthCh = curl_init($bridgeUrl . '/health');
curl_setopt_array($healthCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER => array_filter([
        'Accept: application/json',
        $bridgeToken !== '' ? 'X-Bridge-Token: ' . $bridgeToken : null,
    ]),
]);
$healthResponse = curl_exec($healthCh);
$healthCode = curl_getinfo($healthCh, CURLINFO_HTTP_CODE);
curl_close($healthCh);

if ($healthResponse !== false) {
    $healthData = json_decode($healthResponse, true);
    $healthOk = is_array($healthData) && !empty($healthData['ok']);
}

if (!$healthOk) {
    echo json_encode([
        'ok' => false,
        'bridge_status' => 'offline',
        'tally_status' => 'unknown',
        'message' => 'e-BAL Smart Bridge is not connected. Start the Smart Bridge application on your computer.',
    ]);
    exit;
}

/* Step 2: List companies via bridge /fetch endpoint (forward XML to Tally) */
$companyXml = <<<'XML'
<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>List of Companies</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="List of Companies">
      <TYPE>Company</TYPE>
      <FETCH>NAME</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>
XML;

$fetchPayload = json_encode([
    'xml' => $companyXml,
    'token' => $bridgeToken,
]);

$fetchCh = curl_init($bridgeUrl . '/fetch');
curl_setopt_array($fetchCh, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => $fetchPayload,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => array_filter([
        'Content-Type: application/json',
        'Accept: application/json',
        $bridgeToken !== '' ? 'X-Bridge-Token: ' . $bridgeToken : null,
    ]),
]);
$fetchResponse = curl_exec($fetchCh);
$fetchCode = curl_getinfo($fetchCh, CURLINFO_HTTP_CODE);
curl_close($fetchCh);

if ($fetchResponse === false || $fetchCode >= 400) {
    echo json_encode([
        'ok' => false,
        'bridge_status' => 'online',
        'tally_status' => 'disconnected',
        'message' => 'Smart Bridge is running but Tally is not connected. Open Tally and try again.',
    ]);
    exit;
}

$fetchData = json_decode($fetchResponse, true);
if (!is_array($fetchData) || empty($fetchData['ok']) || !isset($fetchData['xml'])) {
    echo json_encode([
        'ok' => false,
        'bridge_status' => 'online',
        'tally_status' => 'error',
        'message' => 'Smart Bridge returned an error: ' . ($fetchData['error'] ?? 'Unknown error'),
    ]);
    exit;
}

/* Parse company list from Tally XML response */
$tallyXml = sanitizeTallyXML($fetchData['xml']);
libxml_use_internal_errors(true);
$xmlObj = simplexml_load_string($tallyXml);
if (!$xmlObj) {
    libxml_clear_errors();
    echo json_encode([
        'ok' => false,
        'bridge_status' => 'online',
        'tally_status' => 'connected',
        'message' => 'Invalid response from Tally.',
    ]);
    exit;
}

$companyNodes = $xmlObj->xpath("//*[local-name()='DATA']/*[local-name()='COLLECTION']/*[local-name()='COMPANY']");
$companies = [];
if ($companyNodes) {
    foreach ($companyNodes as $node) {
        $name = trim((string) ($node->NAME ?? $node['NAME'] ?? ''));
        if ($name !== '') {
            $companies[] = $name;
        }
    }
}

echo json_encode([
    'ok' => true,
    'bridge_status' => 'online',
    'tally_status' => 'connected',
    'companies' => $companies,
]);
