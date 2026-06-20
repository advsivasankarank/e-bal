<?php
/**
 * AJAX: Fetch Tally company detail via XML API (port 9000)
 * Returns mapped entity fields + duplicate check.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/session_bootstrap.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/plan_helper.php';
require_once __DIR__ . '/../../xml_engine/tally_connector.php';
require_once __DIR__ . '/../../app/helpers/xml_sanitizer.php';

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

$xmlRequest = '<ENVELOPE>
 <HEADER>
  <VERSION>1</VERSION>
  <TALLYREQUEST>Export</TALLYREQUEST>
  <TYPE>Company</TYPE>
 </HEADER>
 <BODY>
  <DESC>
   <STATICVARIABLES>
    <SVEXPORTFORMAT>$$SysName:XML</SVEXPORTFORMAT>
   </STATICVARIABLES>
  </DESC>
 </BODY>
</ENVELOPE>';

$response = fetchFromTally($xmlRequest);
if ($response === false || trim($response) === '') {
    echo json_encode(['ok' => false, 'message' => 'Tally is not reachable.']);
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

/* Extract company data from Tally XML */
$companyData = $xmlObj->xpath("//*[local-name()='DATA']/*[local-name()='COMPANY']");
if (empty($companyData[0])) {
    echo json_encode(['ok' => false, 'message' => 'Company data not found in Tally response.']);
    exit;
}

$c = $companyData[0];
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
$booksFrom = trim((string) ($c->BOOKSFROM ?? ''));

/* Build address */
$addressParts = array_filter(array_map('trim', explode("\n", $address)));
if ($pincode !== '') $addressParts[] = $pincode;
$registeredAddress = implode("\n", $addressParts);

/* Category auto-detection */
$category = 'non_corporate';
if ($cin !== '' && preg_match('/^[LU]\d{5}[A-Z]{2}\d{4}/', $cin)) {
    $category = 'corporate';
} elseif (preg_match('/^[A-Z]{2}\d{5}$/', $pan) && $companyType !== '') {
    /* If company type suggests corporate */
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
