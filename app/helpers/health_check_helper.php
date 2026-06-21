<?php
/**
 * Health Check Helper - Platform Control Centre
 * System health checks for superadmin dashboard.
 */

function checkDatabaseHealth(PDO $pdo): array {
    $start = microtime(true);
    try {
        $pdo->query("SELECT 1");
        $elapsed = round((microtime(true) - $start) * 1000);
        if ($elapsed > 500) {
            return ['status' => 'warning', 'message' => "Slow ({$elapsed}ms)"];
        }
        return ['status' => 'healthy', 'message' => "OK ({$elapsed}ms)"];
    } catch (Throwable $e) {
        return ['status' => 'critical', 'message' => 'Connection failed'];
    }
}

function checkLicenseEngineHealth(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM licenses LIMIT 1");
        $stmt->fetchColumn();
        return ['status' => 'healthy', 'message' => 'Operational'];
    } catch (Throwable $e) {
        return ['status' => 'critical', 'message' => 'Query failed'];
    }
}

function checkExportEngineHealth(): array {
    $html = '<html><body><h1>Test</h1></body></html>';
    $start = microtime(true);
    try {
        $tempFile = tempnam(sys_get_temp_dir(), 'ebal_test_');
        file_put_contents($tempFile, $html);
        $success = file_exists($tempFile);
        @unlink($tempFile);
        $elapsed = round((microtime(true) - $start) * 1000);
        if (!$success) {
            return ['status' => 'critical', 'message' => 'Write failed'];
        }
        if ($elapsed > 5000) {
            return ['status' => 'warning', 'message' => "Slow ({$elapsed}ms)"];
        }
        return ['status' => 'healthy', 'message' => "OK ({$elapsed}ms)"];
    } catch (Throwable $e) {
        return ['status' => 'critical', 'message' => 'Export engine error'];
    }
}

function checkTallyBridgeHealth(): array {
    $bridgeHost = getenv('TALLY_ODBC_SERVER') ?: 'localhost';
    $bridgePort = (int)(getenv('TALLY_ODBC_PORT') ?: 9123);
    $start = microtime(true);
    $fp = @fsockopen($bridgeHost, $bridgePort, $errno, $errstr, 3);
    $elapsed = round((microtime(true) - $start) * 1000);
    if ($fp) {
        fclose($fp);
        return ['status' => 'healthy', 'message' => "Connected ({$elapsed}ms)"];
    }
    if ($elapsed > 3000) {
        return ['status' => 'warning', 'message' => "Timeout ({$elapsed}ms)"];
    }
    return ['status' => 'warning', 'message' => "Offline ({$errstr})"];
}

function checkStorageHealth(): array {
    $storageDir = __DIR__ . '/../../../storage';
    $downloadsDir = __DIR__ . '/../../../public/downloads';
    $totalSize = 0;
    foreach ([$storageDir, $downloadsDir] as $dir) {
        if (is_dir($dir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                $totalSize += $file->getSize();
            }
        }
    }
    $sizeMB = round($totalSize / 1048576, 1);
    if ($sizeMB > 5000) {
        return ['status' => 'critical', 'message' => "High usage ({$sizeMB} MB)"];
    }
    if ($sizeMB > 1000) {
        return ['status' => 'warning', 'message' => "Moderate ({$sizeMB} MB)"];
    }
    return ['status' => 'healthy', 'message' => "OK ({$sizeMB} MB)"];
}

function checkEmailServiceHealth(PDO $pdo): array {
    try {
        $stmt = $pdo->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM email_log
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int)($row['total'] ?? 0);
        $failed = (int)($row['failed'] ?? 0);
        if ($total === 0) {
            return ['status' => 'healthy', 'message' => 'No recent emails'];
        }
        $failRate = round(($failed / $total) * 100, 1);
        if ($failRate > 20) {
            return ['status' => 'critical', 'message' => "{$failRate}% failure rate"];
        }
        if ($failRate > 5) {
            return ['status' => 'warning', 'message' => "{$failRate}% failure rate"];
        }
        return ['status' => 'healthy', 'message' => "{$failRate}% failure rate"];
    } catch (Throwable $e) {
        return ['status' => 'healthy', 'message' => 'No email log data'];
    }
}

function checkBackupHealth(): array {
    $backupDir = __DIR__ . '/../../storage/backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0755, true);
        return ['status' => 'warning', 'message' => 'Backup directory created'];
    }
    $files = glob($backupDir . '/*');
    if (empty($files)) {
        return ['status' => 'warning', 'message' => 'No backups found'];
    }
    $latest = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            $latest = max($latest, filemtime($f));
        }
    }
    if ($latest === 0) {
        return ['status' => 'warning', 'message' => 'No backup files'];
    }
    $ageHours = round((time() - $latest) / 3600);
    if ($ageHours > 72) {
        return ['status' => 'critical', 'message' => "Last backup {$ageHours}h ago"];
    }
    if ($ageHours > 24) {
        return ['status' => 'warning', 'message' => "Last backup {$ageHours}h ago"];
    }
    return ['status' => 'healthy', 'message' => "Last backup {$ageHours}h ago"];
}

function checkAllSystemHealth(PDO $pdo): array {
    $checks = [
        'database'     => checkDatabaseHealth($pdo),
        'license'      => checkLicenseEngineHealth($pdo),
        'export'       => checkExportEngineHealth(),
        'tally_bridge' => checkTallyBridgeHealth(),
        'storage'      => checkStorageHealth(),
        'email'        => checkEmailServiceHealth($pdo),
        'backup'       => checkBackupHealth(),
    ];
    $total = count($checks);
    $healthy = 0;
    foreach ($checks as $c) {
        if ($c['status'] === 'healthy') $healthy++;
    }
    $score = $total > 0 ? round(($healthy / $total) * 100) : 0;
    return [
        'checks' => $checks,
        'score' => $score,
        'healthy' => $healthy,
        'total' => $total,
    ];
}
