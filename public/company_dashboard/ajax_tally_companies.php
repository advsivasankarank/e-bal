<?php
/**
 * AJAX: List all companies from Tally via XML API (port 9000)
 * Uses existing fetchFromTally() function.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../xml_engine/tally_connector.php';
require_once __DIR__ . '/../../app/helpers/xml_sanitizer.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

$xmlRequest = <<<'XML'
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

$response = fetchFromTally($xmlRequest);
if ($response === false || trim($response) === '') {
    echo json_encode(['ok' => false, 'message' => 'Tally is not reachable. Ensure Tally is running on this machine.']);
    exit;
}

$response = sanitizeTallyXML($response);
libxml_use_internal_errors(true);
$xmlObj = simplexml_load_string($response);
if (!$xmlObj) {
    libxml_clear_errors();
    echo json_encode(['ok' => false, 'message' => 'Invalid response from Tally.']);
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

echo json_encode(['ok' => true, 'companies' => $companies]);
