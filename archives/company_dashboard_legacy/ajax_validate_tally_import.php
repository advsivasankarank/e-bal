<?php
/**
 * AJAX: Validate Tally import data + check duplicates
 * Called AFTER browser fetches data directly from bridge.
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid data']);
    exit;
}

$ownerId = getOwnerUserId($pdo, $userId);
$checks = [];

/* Check duplicates for each provided field */
$fieldMap = [
    'name' => 'companies',
    'pan' => 'companies',
    'cin' => 'companies',
    'llp_code' => 'companies',
    'gstin' => 'companies',
];

foreach ($fieldMap as $field => $table) {
    $value = trim((string) ($data[$field] ?? ''));
    if ($value === '') continue;

    $col = $field;
    $stmt = $pdo->prepare("SELECT id, name FROM {$table} WHERE {$col} = ? AND owner_user_id = ? LIMIT 1");
    $stmt->execute([$value, $ownerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $checks[] = [
            'field' => $field,
            'value' => $value,
            'existing_id' => (int) $row['id'],
            'existing_name' => $row['name'],
        ];
    }
}

echo json_encode([
    'ok' => true,
    'duplicates' => $checks,
    'has_duplicates' => count($checks) > 0,
]);
