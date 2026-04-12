<?php

require_once '../../config/app.php';
require_once '../../app/helpers/mca_lookup_helper.php';

header('Content-Type: application/json');

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$identifier = trim((string) ($_GET['identifier'] ?? ''));
$category = strtolower(trim((string) ($_GET['category'] ?? '')));
$category = str_replace(['-', ' '], '_', $category);

if (!in_array($type, ['cin', 'llpin'], true)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid lookup type.',
    ]);
    exit;
}

$result = fetchMcaEntityData($type, $identifier, $category);
echo json_encode($result);
