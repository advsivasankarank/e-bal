<?php
/**
 * Minimal migration runner.
 *
 * Usage (from repo root): php app/sql/migrate.php [--dry-run]
 *
 * Applies any file in app/sql/migrations/*.sql not yet recorded in the
 * schema_migrations table, in filename order (001_, 002_, ...), and stops
 * at the first failure rather than skipping ahead.
 *
 * Note: MySQL DDL (ALTER/CREATE TABLE) auto-commits, so a transaction
 * around a migration file does NOT give true atomic rollback if a later
 * statement in the same file fails — earlier statements in that file stay
 * applied. Real safety here comes from the migration files themselves
 * already being idempotent (see e.g. 005_add_archived_at_to_companies.sql's
 * INFORMATION_SCHEMA guard) — simply re-running this script after fixing
 * whatever caused the failure is safe, not a workaround.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script is CLI-only.');
}

require_once __DIR__ . '/../../config/database.php';

$dryRun = in_array('--dry-run', $argv, true);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration_file VARCHAR(255) NOT NULL UNIQUE,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$appliedStmt = $pdo->query("SELECT migration_file FROM schema_migrations");
$applied = $appliedStmt->fetchAll(PDO::FETCH_COLUMN);

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

if ($files === false || $files === []) {
    fwrite(STDOUT, "No migration files found in {$migrationsDir}\n");
    exit(0);
}

$pending = array_filter($files, fn($f) => !in_array(basename($f), $applied, true));

if ($pending === []) {
    fwrite(STDOUT, "Nothing to do — all " . count($files) . " migration(s) already applied.\n");
    exit(0);
}

fwrite(STDOUT, count($pending) . " pending migration(s):\n");
foreach ($pending as $f) {
    fwrite(STDOUT, "  - " . basename($f) . "\n");
}

if ($dryRun) {
    fwrite(STDOUT, "\n--dry-run: no changes made.\n");
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "FAILED to read {$name}\n");
        exit(1);
    }

    fwrite(STDOUT, "Applying {$name} ... ");

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $pdo->prepare("INSERT INTO schema_migrations (migration_file) VALUES (?)")->execute([$name]);
        $pdo->commit();
        fwrite(STDOUT, "OK\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "FAILED\n" . $e->getMessage() . "\n");
        fwrite(STDERR, "Stopped at {$name} — earlier migrations in this run remain applied and recorded.\n");
        exit(1);
    }
}

fwrite(STDOUT, "\nAll pending migrations applied.\n");
