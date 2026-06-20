<?php
/**
 * AJAX: Prepare Tally company import — map fields and check duplicates.
 *
 * Architecture (v2): Browser → Smart Bridge → Tally (direct, no PHP cURL)
 * Browser sends company details retrieved from bridge. This endpoint
 * maps fields and checks for duplicates in the database.
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

/* Accept company details as JSON POST body */
$input = json_decode(file_get_contents('php://input'), true);
$company = $input['company'] ?? null;
if (!is_array($company) || empty($company['name'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing company data from bridge.']);
    exit;
}

$name = trim((string) ($company['name'] ?? ''));
$address = trim((string) ($company['address'] ?? ''));
$state = trim((string) ($company['state'] ?? ''));
$pincode = trim((string) ($company['pincode'] ?? ''));
$email = trim((string) ($company['email'] ?? ''));
$mobile = trim((string) ($company['mobile'] ?? ''));
$phone = trim((string) ($company['phone'] ?? ''));
$pan = strtoupper(trim((string) ($company['pan'] ?? '')));
$gstin = strtoupper(trim((string) ($company['gstin'] ?? '')));
$cin = strtoupper(trim((string) ($company['cin'] ?? '')));
$companyType = trim((string) ($company['company_type'] ?? ''));

/* Build registered address */
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
