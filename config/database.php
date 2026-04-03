<?php
$pghost = getenv('PGHOST') ?: 'helium';
$pgport = getenv('PGPORT') ?: '5432';
$pguser = getenv('PGUSER') ?: 'postgres';
$pgpassword = getenv('PGPASSWORD') ?: '';
$pgdatabase = getenv('PGDATABASE') ?: 'heliumdb';

$dsn = "pgsql:host=$pghost;port=$pgport;dbname=$pgdatabase";

$pdo = new PDO($dsn, $pguser, $pgpassword);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
?>
