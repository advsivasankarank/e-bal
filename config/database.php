<?php
$envFile = __DIR__ . '/env.php';
if (file_exists($envFile)) {
    require_once $envFile;
}
require_once __DIR__ . '/../app/helpers/runtime_helper.php';

$host = getenv('DB_HOST') ?: 'localhost';
$port = (int) (getenv('DB_PORT') ?: 3306);
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

$env = defined('ENV') ? ENV : (getenv('APP_ENV') ?: 'local');

if ($env !== 'local') {
    if ($db === false || $db === '' || $db === null) {
        appLog('CRITICAL', 'DB_NAME environment variable is not set');
        http_response_code(500);
        die('Database configuration missing.');
    }
    if ($user === false || $user === '' || $user === null) {
        appLog('CRITICAL', 'DB_USER environment variable is not set');
        http_response_code(500);
        die('Database configuration missing.');
    }
    if ($pass === false || $pass === null) {
        appLog('CRITICAL', 'DB_PASS environment variable is not set');
        http_response_code(500);
        die('Database configuration missing.');
    }
} else {
    $db   = $db ?: 'ebal_db';
    $user = $user ?: 'root';
    $pass = $pass !== false ? $pass : '';
}

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
    appLog('ERROR', 'Database connection failed', [
        'code' => $e->getCode(),
        'host' => $host,
        'port' => $port,
        'database' => $db,
        'env' => $env,
        'message' => $e->getMessage(),
    ]);

    http_response_code(500);
    die('Database connection failed.');
}
