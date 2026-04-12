<?php

function ensurePlanTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','staff') NOT NULL DEFAULT 'admin',
            company_owner_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            price_inr INT NOT NULL DEFAULT 0,
            company_limit INT NOT NULL DEFAULT 0,
            user_limit INT NOT NULL DEFAULT 0,
            ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS licenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan VARCHAR(40) NOT NULL,
            company_limit INT NOT NULL DEFAULT 0,
            user_limit INT NOT NULL DEFAULT 0,
            ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
            expires_at DATE NOT NULL,
            status ENUM('active','expired') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_license_user (user_id),
            INDEX idx_license_status (status)
        )
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('owner_user_id', $columns, true)) {
        $pdo->exec("ALTER TABLE companies ADD COLUMN owner_user_id INT NULL AFTER name");
    }

    seedPlans($pdo);
}

function seedPlans(PDO $pdo): void
{
    $plans = [
        ['starter', 'Starter', 2999, 5, 1, 0],
        ['professional', 'Professional', 4999, 10, 3, 1],
        ['pro_plus', 'Pro Plus', 9999, 999, 5, 1],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO plans (code, name, price_inr, company_limit, user_limit, ai_enabled)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            price_inr = VALUES(price_inr),
            company_limit = VALUES(company_limit),
            user_limit = VALUES(user_limit),
            ai_enabled = VALUES(ai_enabled)
    ");

    foreach ($plans as $plan) {
        $stmt->execute($plan);
    }
}

function getUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function getOwnerUserId(PDO $pdo, int $userId): int
{
    $user = getUserById($pdo, $userId);
    if (!$user) {
        return $userId;
    }

    $owner = (int) ($user['company_owner_id'] ?? 0);
    return $owner > 0 ? $owner : $userId;
}

function getActiveLicense(PDO $pdo, int $userId): ?array
{
    ensurePlanTables($pdo);

    $stmt = $pdo->prepare("
        SELECT * FROM licenses
        WHERE user_id = ?
        ORDER BY expires_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        return null;
    }

    $expiresAt = (string) ($license['expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) < strtotime(date('Y-m-d'))) {
        $pdo->prepare("UPDATE licenses SET status='expired' WHERE id=?")->execute([(int) $license['id']]);
        $license['status'] = 'expired';
    }

    return $license;
}

function getPlanDefinition(PDO $pdo, string $planCode): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM plans WHERE code = ?");
    $stmt->execute([$planCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function getUserPlan(int $userId, ?PDO $pdo = null): ?array
{
    if ($pdo === null) {
        return null;
    }

    $license = getActiveLicense($pdo, $userId);
    if (!$license || ($license['status'] ?? 'expired') !== 'active') {
        return null;
    }

    $plan = getPlanDefinition($pdo, (string) $license['plan']);
    if (!$plan) {
        $plan = [
            'code' => $license['plan'],
            'name' => ucfirst(str_replace('_', ' ', $license['plan'])),
            'price_inr' => 0,
            'company_limit' => (int) $license['company_limit'],
            'user_limit' => (int) $license['user_limit'],
            'ai_enabled' => (int) $license['ai_enabled'],
        ];
    }

    return [
        'plan' => $plan['code'],
        'plan_name' => $plan['name'],
        'company_limit' => (int) ($license['company_limit'] ?? $plan['company_limit']),
        'user_limit' => (int) ($license['user_limit'] ?? $plan['user_limit']),
        'ai_enabled' => (int) ($license['ai_enabled'] ?? $plan['ai_enabled']),
        'expires_at' => (string) ($license['expires_at'] ?? ''),
        'status' => (string) ($license['status'] ?? 'expired'),
    ];
}

function countCompaniesForUser(PDO $pdo, int $userId): int
{
    $columns = $pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('owner_user_id', $columns, true)) {
        $ownerId = getOwnerUserId($pdo, $userId);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE owner_user_id = ?");
        $stmt->execute([$ownerId]);
        return (int) $stmt->fetchColumn();
    }

    return (int) $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
}

function countUsersForOwner(PDO $pdo, int $userId): int
{
    $ownerId = getOwnerUserId($pdo, $userId);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? OR company_owner_id = ?");
    $stmt->execute([$ownerId, $ownerId]);
    return (int) $stmt->fetchColumn();
}

function canAddCompany(int $userId, ?PDO $pdo = null): bool
{
    if ($pdo === null) {
        return false;
    }

    $plan = getUserPlan($userId, $pdo);
    if (!$plan) {
        return false;
    }

    $limit = (int) ($plan['company_limit'] ?? 0);
    if ($limit >= 999) {
        return true;
    }

    return countCompaniesForUser($pdo, $userId) < $limit;
}

function canAddUser(int $userId, ?PDO $pdo = null): bool
{
    if ($pdo === null) {
        return false;
    }

    $plan = getUserPlan($userId, $pdo);
    if (!$plan) {
        return false;
    }

    $limit = (int) ($plan['user_limit'] ?? 0);
    if ($limit >= 999) {
        return true;
    }

    return countUsersForOwner($pdo, $userId) < $limit;
}

function hasFeature(int $userId, string $feature, ?PDO $pdo = null): bool
{
    if ($pdo === null) {
        return false;
    }

    $plan = getUserPlan($userId, $pdo);
    if (!$plan) {
        return false;
    }

    $aiEnabled = (int) ($plan['ai_enabled'] ?? 0) === 1;

    if (in_array($feature, ['ai_notes', 'directors_report'], true)) {
        return $aiEnabled;
    }

    return true;
}

function getPlanUsage(PDO $pdo, int $userId): array
{
    $plan = getUserPlan($userId, $pdo);
    if (!$plan) {
        return [
            'plan_name' => 'No Active Plan',
            'company_limit' => 0,
            'user_limit' => 0,
            'companies_used' => 0,
            'users_used' => 0,
            'expires_at' => '',
            'ai_enabled' => 0,
            'status' => 'expired',
        ];
    }

    return [
        'plan_name' => $plan['plan_name'],
        'company_limit' => (int) $plan['company_limit'],
        'user_limit' => (int) $plan['user_limit'],
        'companies_used' => countCompaniesForUser($pdo, $userId),
        'users_used' => countUsersForOwner($pdo, $userId),
        'expires_at' => $plan['expires_at'],
        'ai_enabled' => (int) $plan['ai_enabled'],
        'status' => $plan['status'],
    ];
}
