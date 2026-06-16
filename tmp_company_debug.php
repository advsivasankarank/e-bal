<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/config/database.php';
echo "DB OK\n";
foreach ($pdo->query('SHOW COLUMNS FROM companies') as $col) {
    echo $col['Field'] . ' | ' . $col['Type'] . ' | ' . $col['Null'] . ' | ' . ($col['Default'] ?? 'NULL') . PHP_EOL;
}
