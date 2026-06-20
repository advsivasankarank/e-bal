<?php
/**
 * AJAX: Fetch Tally company detail via Smart Bridge
 *
 * Architecture: PHP Server → Smart Bridge (port 9123) → Tally (port 9000)
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';

header('Content-Type: application/json');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

$companyName = trim((string) ($_GET['company_name'] ?? ''));
if ($companyName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'company_name is required']);
    exit;
}

/* Determine bridge URL */
$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') {
    $bridgeUrl = 'http://127.0.0.1:9123';
}
$bridgeUrl = rtrim($bridgeUrl, '/');
$bridgeToken = defined('TALLY_BRIDGE_TOKEN') ? trim((string) TALLY_BRIDGE_TOKEN) : '';

/* Build Tally XML request for company details */
$xmlRequest = '<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Collection</TYPE>
  <ID>Company Details</ID>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
   <TDL>
    <TDLMESSAGE>
     <COLLECTION NAME="Company Details">
      <TYPE>Company</TYPE>
      <FETCH>NAME,MAILINGNAME,ADDRESS,STATE,PINCODE,EMAIL,MOBILE,PHONENUMBER,INCOMETAXNO,GSTIN,CIN,COMPANYTYPE,BOOKSFROM,STARTINGFROM,ENDINGAT</FETCH>
     </COLLECTION>
    </TDLMESSAGE>
   </TDL>
  </DESC>
 </BODY>
</ENVELOPE>';

/* Forward via bridge /fetch */
$fetchPayload = json_encode([
    'xml' => $xmlRequest,
    'token' => $bridgeToken,
]);

$ch = curl_init($bridgeUrl . '/fetch');
curl_setopt_array($ch, [
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
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode >= 400) {
    echo json_encode(['ok' => false, 'message' => 'Smart Bridge is not reachable.']);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data['ok']) || !isset($data['xml'])) {
    echo json_encode(['ok' => false, 'message' => 'Smart Bridge returned an error.']);
    exit;
}

/* Parse company detail from Tally XML */
$tallyXml = sanitizeTallyXML($data['xml']);
libxml_use_internal_errors(true);
$xmlObj = simplexml_load_string($tallyXml);
if (!$xmlObj) {
    libxml_clear_errors();
    echo json_encode(['ok' => false, 'message' => 'Invalid response from Tally.']);
    exit;
}

$companyNodes = $xmlObj->xpath("//*[local-name()='DATA']/*[local-name()='COMPANY']");
if (empty($companyNodes[0])) {
    echo json_encode(['ok' => false, 'message' => 'Company data not found in Tally response.']);
    exit;
}

$c = $companyNodes[0];
$name = trim((string) ($c->NAME ?? ''));
$address = trim((string) ($c->ADDRESS ?? ''));
$state = trim((string) ($c->STATE ?? ''));
$pincode = trim((string) ($c->PINCODE ?? ''));
$email = trim((string) ($c->EMAIL ?? ''));
$mobile = trim((string) ($c->MOBILE ?? ''));
$phone = trim((string) ($c->PHONENUMBER ?? ''));
$pan = strtoupper(trim((string) ($c->INCOMETAXNO ?? '')));
$gstin = strtoupper(trim((string) ($c->GSTIN ?? '')));
$cin = strtoupper(trim((string) ($c->CIN ?? '')));
$companyType = trim((string) ($c->COMPANYTYPE ?? ''));

/* Build address */
$addressParts = array_filter(array_map('trim', explode("\n", $address)));
if ($pincode !== '') $addressParts[] = $pincode;
$registeredAddress = implode("\n", $addressParts);

/* Category auto-detection */
$category = 'non_corporate';
if ($cin !== '' && preg_match('/^[LU]\d{5}[A-Z]{2}\d{4}/', $cin)) {
    $category = 'corporate';
}

$mapped = [
    'name' => $name,
    'category' => $category,
    'cin' => $cin,
    'llp_code' => '',
    'pan' => $pan,
    'gstin' => $gstin,
    'registered_address' => $registeredAddress,
    'state_code' => '',
    'state_name' => $state,
    'email' => $email,
    'mobile' => $mobile,
    'phone' => $phone,
    'company_type' => $companyType,
];

/* Duplicate check */
$ownerId = getOwnerUserId($pdo, $userId);
$duplicates = [];
$checks = [
    ['field' => 'name', 'col' => 'name', 'value' => $name],
    ['field' => 'pan', 'col' => 'pan', 'value' => $pan],
    ['field' => 'gstin', 'col' => 'gstin', 'value' => $gstin],
    ['field' => 'cin', 'col' => 'cin', 'value' => $cin],
];
foreach ($checks as $chk) {
    if ($chk['value'] === '') continue;
    $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE {$chk['col']} = ? AND owner_user_id = ? LIMIT 1");
    $stmt->execute([$chk['value'], $ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $duplicates[] = [
            'field' => $chk['field'],
            'value' => $chk['value'],
            'existing_id' => (int) $row['id'],
            'existing_name' => $row['name'],
        ];
    }
}

echo json_encode([
    'ok' => true,
    'mapped' => $mapped,
    'duplicates' => $duplicates,
    'has_duplicates' => count($duplicates) > 0,
]);
