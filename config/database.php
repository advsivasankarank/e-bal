<?php

define('ENV', getenv('APP_ENV') ?: 'local');

if (ENV === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

$appTimezone = getenv('APP_TIMEZONE') ?: 'Asia/Kolkata';
date_default_timezone_set($appTimezone);

$host = getenv('DB_HOST') ?: 'localhost';
$port = (int) (getenv('DB_PORT') ?: 3306);
$db = getenv('DB_NAME') ?: 'ebal_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS');
$pass = $pass === false ? '' : $pass;
$persistent = filter_var(getenv('DB_PERSISTENT') ?: 'false', FILTER_VALIDATE_BOOLEAN);

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => $persistent,
        ]
    );

    $pdo->exec("SET sql_mode='STRICT_ALL_TABLES'");
} catch (PDOException $e) {
    if (ENV === 'local') {
        die('Database Error: ' . $e->getMessage());
    }

    http_response_code(500);
    die('Database connection failed.');
}
