<?php
require_once __DIR__ . '/../config/app.php';

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = BASE_URL . 'data_console/xml_import.php' . ($query !== '' ? '?' . $query : '');

header('Location: ' . $target, true, 302);
exit;
