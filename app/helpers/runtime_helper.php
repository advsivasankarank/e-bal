<?php

/* Centralized error-display policy: never show raw PHP errors to the browser,
   always log them. Applied here (rather than per-script) because this file is
   loaded by both config/app.php and config/database.php, which together are
   required by effectively every entry point in the app. Individual scripts
   (e.g. mapping_workbench.php) previously set this per-file; that duplicate
   config has been removed in favor of this single source of truth. */
if (PHP_SAPI !== 'cli') {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

function runtimeEnv(): string
{
    if (defined('ENV')) {
        $env = getenv('APP_ENV');
        if ($env !== false && $env !== '') {
            return (string) $env;
        }
        return (string) ENV;
    }

    $env = getenv('APP_ENV');
    return $env !== false && $env !== '' ? (string) $env : 'local';
}

function isLocalEnv(): bool
{
    $env = getenv('APP_ENV');
    if ($env !== false && $env !== '') {
        return $env === 'local';
    }
    if (defined('ENV')) {
        return (string) ENV === 'local';
    }
    return true;
}

function envFlag(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function appAllowsRuntimeSchema(): bool
{
    return isLocalEnv() || envFlag('APP_ALLOW_RUNTIME_SCHEMA', false);
}

function appLog(string $level, string $message, array $context = []): void
{
    $prefix = '[eBAL][' . strtoupper($level) . '] ' . $message;
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== '') {
            $prefix .= ' ' . $json;
        }
    }

    error_log($prefix);
}

function failApplication(string $publicMessage, int $statusCode = 500, array $logContext = []): void
{
    http_response_code($statusCode);
    appLog('ERROR', $publicMessage, $logContext);
    exit($publicMessage);
}

function requireProductionValue(string $envName, $value, string $publicMessage)
{
    if ($value === false || $value === null || $value === '') {
        appLog('CRITICAL', 'Required environment variable missing', ['env' => $envName]);
        http_response_code(500);
        die($publicMessage);
    }

    return $value;
}

function assertTableExists(PDO $pdo, string $tableName): void
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Missing required database table: ' . $tableName);
    }
}

function assertColumnExists(PDO $pdo, string $tableName, string $columnName): void
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '` LIKE ?');
    $stmt->execute([$columnName]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Missing required database column: ' . $tableName . '.' . $columnName);
    }
}
