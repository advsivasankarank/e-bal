<?php
require_once __DIR__ . '/runtime_helper.php';

function ensurePlanTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!appAllowsRuntimeSchema()) {
            assertTableExists($pdo, 'users');
            assertTableExists($pdo, 'plans');
            assertTableExists($pdo, 'licenses');
            assertTableExists($pdo, 'license_transactions');
            assertColumnExists($pdo, 'companies', 'owner_user_id');
            assertColumnExists($pdo, 'plans', 'description_text');
            $checked = true;
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                reset_token VARCHAR(255) NULL,
                reset_token_expires_at TIMESTAMP NULL,
                role ENUM('superadmin','admin','staff') NOT NULL DEFAULT 'admin',
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
                description_text VARCHAR(255) NOT NULL DEFAULT '',
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

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS license_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                license_id INT NULL,
                plan_code VARCHAR(40) NOT NULL,
                amount_inr INT NOT NULL DEFAULT 0,
                transaction_type ENUM('new_sale','renewal','upgrade','downgrade','manual_adjustment') NOT NULL DEFAULT 'renewal',
                payment_status ENUM('paid','pending','waived','refunded') NOT NULL DEFAULT 'paid',
                billed_at DATE NOT NULL,
                notes VARCHAR(255) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_license_tx_user (user_id),
                INDEX idx_license_tx_status (payment_status),
                INDEX idx_license_tx_billed (billed_at)
            )
        ");

        $columns = $pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('owner_user_id', $columns, true)) {
            $pdo->exec("ALTER TABLE companies ADD COLUMN owner_user_id INT NULL AFTER name");
        }

        $planColumns = $pdo->query("SHOW COLUMNS FROM plans")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('description_text', $planColumns, true)) {
            $pdo->exec("ALTER TABLE plans ADD COLUMN description_text VARCHAR(255) NOT NULL DEFAULT '' AFTER ai_enabled");
        }

        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin','admin','staff') NOT NULL DEFAULT 'admin'");

        seedPlans($pdo);
        ensureDefaultSuperAdmin($pdo);
        $checked = true;
    } catch (Throwable $e) {
        appLog('ERROR', 'License schema validation failed', ['message' => $e->getMessage()]);
        failApplication('Application database schema is incomplete.', 500, ['message' => $e->getMessage()]);
    }
}

function seedPlans(PDO $pdo): void
{
    $plans = [
        ['base', 'e-BAL Base', 7499, 10, 1, 0, '1 user, 10 entities, core workflow access'],
        ['pro', 'e-BAL Pro', 14999, 25, 10, 1, '10 users, 25 entities, AI assisted drafting'],
        ['elite', 'e-BAL Elite', 29999, 999, 999, 1, 'Unlimited users, unlimited entities, full feature access'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO plans (code, name, price_inr, company_limit, user_limit, ai_enabled, description_text)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            price_inr = VALUES(price_inr),
            company_limit = VALUES(company_limit),
            user_limit = VALUES(user_limit),
            ai_enabled = VALUES(ai_enabled),
            description_text = VALUES(description_text)
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

function getUserByEmail(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ensureDefaultSuperAdmin(PDO $pdo): void
{
    $existing = $pdo->query("SELECT id FROM users WHERE role = 'superadmin' ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($existing) {
        return;
    }

    $env = defined('ENV') ? ENV : (getenv('APP_ENV') ?: 'local');
    $nameEnv = getenv('EBAL_SUPERADMIN_NAME');
    $emailEnv = getenv('EBAL_SUPERADMIN_EMAIL');
    $passwordEnv = getenv('EBAL_SUPERADMIN_PASSWORD');

    if ($env !== 'local' && ($nameEnv === false || $nameEnv === '' || $emailEnv === false || $emailEnv === '' || $passwordEnv === false || $passwordEnv === '')) {
        return;
    }

    $name = ($nameEnv !== false && $nameEnv !== '') ? $nameEnv : 'e-BAL Super Admin';
    $email = ($emailEnv !== false && $emailEnv !== '') ? $emailEnv : 'superadmin@ebal.local';
    $password = ($passwordEnv !== false && $passwordEnv !== '') ? $passwordEnv : 'SuperAdmin@123';
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role, company_owner_id)
        VALUES (?, ?, ?, 'superadmin', NULL)
    ");
    $stmt->execute([$name, $email, $hash]);
}

function getOwnerUserId(PDO $pdo, int $userId): int
{
    $user = getUserById($pdo, $userId);
    if (!$user) {
        return $userId;
    }

    if (($user['role'] ?? '') === 'superadmin') {
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
        ORDER BY id DESC
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

function getPlanCatalog(PDO $pdo): array
{
    ensurePlanTables($pdo);
    $rows = $pdo->query("SELECT * FROM plans ORDER BY price_inr ASC")->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $row): array {
        return [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'price_inr' => (int) $row['price_inr'],
            'company_limit' => (int) $row['company_limit'],
            'user_limit' => (int) $row['user_limit'],
            'ai_enabled' => (int) $row['ai_enabled'],
            'description_text' => (string) ($row['description_text'] ?? ''),
        ];
    }, $rows);
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
        'price_inr' => (int) ($plan['price_inr'] ?? 0),
        'company_limit' => (int) ($license['company_limit'] ?? $plan['company_limit']),
        'user_limit' => (int) ($license['user_limit'] ?? $plan['user_limit']),
        'ai_enabled' => (int) ($license['ai_enabled'] ?? $plan['ai_enabled']),
        'description_text' => (string) ($plan['description_text'] ?? ''),
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

    if (in_array($feature, ['ai_notes', 'directors_report_ai'], true)) {
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
            'price_inr' => 0,
            'company_limit' => 0,
            'user_limit' => 0,
            'companies_used' => 0,
            'users_used' => 0,
            'expires_at' => '',
            'ai_enabled' => 0,
            'description_text' => '',
            'status' => 'expired',
        ];
    }

    return [
        'plan_name' => $plan['plan_name'],
        'price_inr' => (int) ($plan['price_inr'] ?? 0),
        'company_limit' => (int) $plan['company_limit'],
        'user_limit' => (int) $plan['user_limit'],
        'companies_used' => countCompaniesForUser($pdo, $userId),
        'users_used' => countUsersForOwner($pdo, $userId),
        'expires_at' => $plan['expires_at'],
        'ai_enabled' => (int) $plan['ai_enabled'],
        'description_text' => (string) ($plan['description_text'] ?? ''),
        'status' => $plan['status'],
    ];
}

function listWorkspaceUsers(PDO $pdo, int $userId): array
{
    ensurePlanTables($pdo);
    $ownerId = getOwnerUserId($pdo, $userId);
    $stmt = $pdo->prepare("
        SELECT id, name, email, role, company_owner_id, created_at
        FROM users
        WHERE id = ? OR company_owner_id = ?
        ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, created_at ASC
    ");
    $stmt->execute([$ownerId, $ownerId, $ownerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function listWorkspaceCompanies(PDO $pdo, int $userId): array
{
    $ownerId = getOwnerUserId($pdo, $userId);
    $columns = $pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('owner_user_id', $columns, true)) {
        return $pdo->query("SELECT id, name, category, created_at FROM companies ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmt = $pdo->prepare("
        SELECT id, name, category, created_at
        FROM companies
        WHERE owner_user_id = ?
        ORDER BY created_at DESC, id DESC
    ");
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCurrentCommercialLicense(PDO $pdo, int $userId): ?array
{
    return getActiveLicense($pdo, $userId);
}

function recordLicenseTransaction(
    PDO $pdo,
    int $userId,
    string $planCode,
    int $amountInr,
    string $transactionType,
    string $paymentStatus,
    string $billedAt,
    string $notes = '',
    ?int $licenseId = null
): void {
    ensurePlanTables($pdo);

    $ownerId = getOwnerUserId($pdo, $userId);
    $validTypes = ['new_sale', 'renewal', 'upgrade', 'downgrade', 'manual_adjustment'];
    $validStatuses = ['paid', 'pending', 'waived', 'refunded'];

    if (!in_array($transactionType, $validTypes, true)) {
        throw new InvalidArgumentException('Invalid transaction type selected.');
    }

    if (!in_array($paymentStatus, $validStatuses, true)) {
        throw new InvalidArgumentException('Invalid payment status selected.');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $billedAt)) {
        throw new InvalidArgumentException('Billing date must be in YYYY-MM-DD format.');
    }

    $stmt = $pdo->prepare("
        INSERT INTO license_transactions
            (user_id, license_id, plan_code, amount_inr, transaction_type, payment_status, billed_at, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $ownerId,
        $licenseId,
        $planCode,
        max(0, $amountInr),
        $transactionType,
        $paymentStatus,
        $billedAt,
        trim($notes),
    ]);

    // Get transaction ID for invoice linking
    $transactionId = (int) $pdo->lastInsertId();

    // Generate invoice and send payment success email if payment is successful
    if ($paymentStatus === 'paid' && $transactionId > 0) {
        try {
            require_once __DIR__ . '/mail_helper.php';
            require_once __DIR__ . '/invoice_helper.php';

            $user = getUserById($pdo, $ownerId);
            $plan = getPlanDefinition($pdo, $planCode);

            if ($user && $plan) {
                // Create and issue invoice
                $invoiceAmount = max(0, $amountInr) * 100; // Convert to paise
                $planId = (int) ($plan['id'] ?? 0);
                
                $invoice = createAndIssueInvoice(
                    $pdo,
                    $ownerId,
                    $planId,
                    $transactionId,
                    $user['name'] ?? '',
                    $user['email'] ?? '',
                    $invoiceAmount,
                    null,  // gstin
                    null   // pan
                );

                if ($invoice) {
                    // Send payment success email with invoice details
                    $paymentDetails = [
                        'plan_name' => $plan['name'] ?? '',
                        'amount_inr' => $amountInr,
                        'license_expires' => date('d M Y', strtotime('+1 year')),
                    ];
                    
                    sendPaymentSuccessEmail($pdo, $user, $paymentDetails);

                    // Send invoice email
                    $downloadUrl = defined('BASE_URL') 
                        ? BASE_URL . 'invoice_download.php?invoice_number=' . urlencode($invoice['invoice_number'])
                        : '';
                    
                    $invoiceDetails = [
                        'invoice_number' => $invoice['invoice_number'] ?? '',
                        'invoice_date' => $invoice['invoice_date'] ?? '',
                        'total_value' => $invoice['total_value'] ?? 0,
                        'download_url' => $downloadUrl,
                    ];
                    
                    sendInvoiceGeneratedEmail($pdo, $user, $invoiceDetails);
                }
            }
        } catch (Throwable $e) {
            appLog('WARN', 'Failed to generate invoice or send emails after payment', [
                'error' => $e->getMessage(),
                'transaction_id' => $transactionId,
            ]);
            // Don't fail the transaction recording if email/invoice fails
        }
    }
}

function upsertWorkspaceLicense(
    PDO $pdo,
    int $userId,
    string $planCode,
    string $expiresAt,
    bool $recordBilling = false,
    string $transactionType = 'renewal',
    string $paymentStatus = 'paid',
    ?string $billedAt = null,
    ?int $amountInr = null,
    string $notes = ''
): void
{
    ensurePlanTables($pdo);
    $ownerId = getOwnerUserId($pdo, $userId);
    $plan = getPlanDefinition($pdo, $planCode);

    if (!$plan) {
        throw new InvalidArgumentException('Invalid plan selected.');
    }

    $status = strtotime($expiresAt) >= strtotime(date('Y-m-d')) ? 'active' : 'expired';
    $existing = getCurrentCommercialLicense($pdo, $ownerId);
    $licenseId = null;

    if ($existing) {
        $licenseId = (int) $existing['id'];
        $stmt = $pdo->prepare("
            UPDATE licenses
            SET plan = ?, company_limit = ?, user_limit = ?, ai_enabled = ?, expires_at = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $plan['code'],
            (int) $plan['company_limit'],
            (int) $plan['user_limit'],
            (int) $plan['ai_enabled'],
            $expiresAt,
            $status,
            $licenseId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO licenses (user_id, plan, company_limit, user_limit, ai_enabled, expires_at, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ownerId,
            $plan['code'],
            (int) $plan['company_limit'],
            (int) $plan['user_limit'],
            (int) $plan['ai_enabled'],
            $expiresAt,
            $status,
        ]);
        $licenseId = (int) $pdo->lastInsertId();
    }

    if ($recordBilling) {
        $effectiveBilledAt = $billedAt ?: date('Y-m-d');
        $effectiveAmount = $amountInr ?? (int) ($plan['price_inr'] ?? 0);
        recordLicenseTransaction(
            $pdo,
            $ownerId,
            $plan['code'],
            $effectiveAmount,
            $transactionType,
            $paymentStatus,
            $effectiveBilledAt,
            $notes,
            $licenseId
        );
    }
}

function isWorkspaceAdmin(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $user = getUserById($pdo, $userId);
    if (!$user) {
        return true;
    }

    return in_array(($user['role'] ?? 'admin'), ['superadmin', 'admin'], true) || (int) ($user['company_owner_id'] ?? 0) === 0;
}

function isSuperAdmin(PDO $pdo, int $userId): bool
{
    $user = getUserById($pdo, $userId);
    return (string) ($user['role'] ?? '') === 'superadmin';
}

function listClientAdmins(PDO $pdo): array
{
    ensurePlanTables($pdo);
    $stmt = $pdo->query("
        SELECT id, name, email, role, created_at
        FROM users
        WHERE role = 'admin' AND (company_owner_id IS NULL OR company_owner_id = 0)
        ORDER BY created_at DESC, id DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function createClientAdmin(PDO $pdo, string $name, string $email, string $password): int
{
    ensurePlanTables($pdo);

    if (getUserByEmail($pdo, $email)) {
        throw new RuntimeException('Email already exists.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role, company_owner_id)
        VALUES (?, ?, ?, 'admin', NULL)
    ");
    $stmt->execute([$name, $email, $hash]);

    $userId = (int) $pdo->lastInsertId();

    // Send welcome email
    try {
        require_once __DIR__ . '/mail_helper.php';
        
        $user = getUserById($pdo, $userId);
        if ($user) {
            sendWelcomeEmail($pdo, $user);
        }
    } catch (Throwable $e) {
        appLog('WARN', 'Failed to send welcome email', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
        // Don't fail user creation if email fails
    }

    return $userId;
}

function getSuperAdminSummary(PDO $pdo): array
{
    ensurePlanTables($pdo);
    $pdo->exec("UPDATE licenses SET status = 'expired' WHERE expires_at < CURDATE() AND status <> 'expired'");

    $clientAdmins = (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'admin' AND (company_owner_id IS NULL OR company_owner_id = 0)
    ")->fetchColumn();

    $workspaceUsers = (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role IN ('admin', 'staff')
    ")->fetchColumn();

    $totalCompanies = (int) $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
    $activeLicenses = (int) $pdo->query("
        SELECT COUNT(*)
        FROM licenses l
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id
            FROM licenses
            GROUP BY user_id
        ) latest ON latest.max_id = l.id
        WHERE l.status = 'active'
    ")->fetchColumn();
    $expiredLicenses = (int) $pdo->query("
        SELECT COUNT(*)
        FROM licenses l
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id
            FROM licenses
            GROUP BY user_id
        ) latest ON latest.max_id = l.id
        WHERE l.status = 'expired'
    ")->fetchColumn();

    $expiringStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM licenses l
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id
            FROM licenses
            GROUP BY user_id
        ) latest ON latest.max_id = l.id
        WHERE l.status = 'active'
          AND l.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ");
    $expiringStmt->execute();
    $expiringSoon = (int) $expiringStmt->fetchColumn();

    $planMixStmt = $pdo->query("
        SELECT l.plan, COUNT(*) AS total
        FROM licenses l
        INNER JOIN (
            SELECT user_id, MAX(id) AS max_id
            FROM licenses
            GROUP BY user_id
        ) latest ON latest.max_id = l.id
        WHERE l.status = 'active'
        GROUP BY l.plan
        ORDER BY total DESC, plan ASC
    ");
    $planMix = $planMixStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'client_admins' => $clientAdmins,
        'workspace_users' => $workspaceUsers,
        'companies' => $totalCompanies,
        'active_licenses' => $activeLicenses,
        'expired_licenses' => $expiredLicenses,
        'expiring_soon' => $expiringSoon,
        'plan_mix' => $planMix,
    ];
}

function getRevenueSummary(PDO $pdo): array
{
    ensurePlanTables($pdo);

    $stmt = $pdo->query("
        SELECT
            COALESCE(SUM(CASE WHEN t.payment_status = 'paid' THEN t.amount_inr ELSE 0 END), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN t.payment_status = 'paid' AND YEAR(t.billed_at) = YEAR(CURDATE()) THEN t.amount_inr ELSE 0 END), 0) AS current_year_revenue,
            COALESCE(SUM(CASE WHEN t.payment_status = 'paid' AND YEAR(t.billed_at) = YEAR(CURDATE()) AND MONTH(t.billed_at) = MONTH(CURDATE()) THEN t.amount_inr ELSE 0 END), 0) AS current_month_revenue,
            COALESCE(SUM(CASE WHEN t.payment_status = 'paid' AND t.billed_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN t.amount_inr ELSE 0 END), 0) AS last_90_days_revenue
        FROM license_transactions t
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_revenue' => (int) ($row['total_revenue'] ?? 0),
        'current_year_revenue' => (int) ($row['current_year_revenue'] ?? 0),
        'current_month_revenue' => (int) ($row['current_month_revenue'] ?? 0),
        'last_90_days_revenue' => (int) ($row['last_90_days_revenue'] ?? 0),
    ];
}

function getRevenueByMonth(PDO $pdo, int $months = 12): array
{
    ensurePlanTables($pdo);
    $months = max(1, min($months, 24));

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(t.billed_at, '%Y-%m') AS month_key,
            DATE_FORMAT(t.billed_at, '%b %Y') AS month_label,
            COALESCE(SUM(t.amount_inr), 0) AS revenue
        FROM license_transactions t
        WHERE t.payment_status = 'paid'
          AND t.billed_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        GROUP BY DATE_FORMAT(t.billed_at, '%Y-%m'), DATE_FORMAT(t.billed_at, '%b %Y')
        ORDER BY month_key DESC
    ");
    $stmt->execute([$months - 1]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRecentLicenseActivity(PDO $pdo, int $limit = 10): array
{
    ensurePlanTables($pdo);
    $limit = max(1, min($limit, 50));

    $stmt = $pdo->prepare("
        SELECT
            t.plan_code AS plan,
            t.payment_status AS status,
            t.transaction_type,
            t.billed_at,
            t.created_at,
            t.amount_inr AS price_inr,
            t.notes,
            u.name AS user_name,
            u.email AS user_email
        FROM license_transactions t
        INNER JOIN users u ON u.id = t.user_id
        ORDER BY t.billed_at DESC, t.id DESC
        LIMIT ?
    ");
     $stmt->bindValue(1, $limit, PDO::PARAM_INT);
     $stmt->execute();
 
     return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ensure grace period columns exist in licenses table
 */
function ensureGracePeriodSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!appAllowsRuntimeSchema()) {
            assertColumnExists($pdo, 'licenses', 'grace_period_active');
            assertColumnExists($pdo, 'licenses', 'grace_period_expires_at');
            $checked = true;
            return;
        }

        $columns = $pdo->query("SHOW COLUMNS FROM licenses")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('grace_period_active', $columns, true)) {
            $pdo->exec("ALTER TABLE licenses ADD COLUMN grace_period_active TINYINT(1) DEFAULT 0 AFTER status");
        }
        
        if (!in_array('grace_period_expires_at', $columns, true)) {
            $pdo->exec("ALTER TABLE licenses ADD COLUMN grace_period_expires_at DATE NULL AFTER grace_period_active");
        }

        $checked = true;
    } catch (Throwable $e) {
        appLog('ERROR', 'Grace period schema validation failed', ['message' => $e->getMessage()]);
        // Don't fail app on schema issues
    }
}

/**
 * Get license status: returns 'active', 'grace_period', or 'expired'
 * @param PDO $pdo Database connection
 * @param int $licenseId License ID
 * @return string License status
 */
function getLicenseStatus(PDO $pdo, int $licenseId): string
{
    ensurePlanTables($pdo);
    ensureGracePeriodSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT expires_at, status, grace_period_active, grace_period_expires_at 
        FROM licenses 
        WHERE id = ?
    ");
    $stmt->execute([$licenseId]);
    $license = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$license) {
        return 'expired';
    }

    $expiresAt = (string) ($license['expires_at'] ?? '');
    $today = date('Y-m-d');

    // Check if license is still active
    if ($expiresAt !== '' && $expiresAt >= $today) {
        return 'active';
    }

    // Check if in grace period
    $graceActive = (int) ($license['grace_period_active'] ?? 0);
    $graceExpiresAt = (string) ($license['grace_period_expires_at'] ?? '');

    if ($graceActive === 1 && $graceExpiresAt !== '' && $graceExpiresAt >= $today) {
        return 'grace_period';
    }

    return 'expired';
}

/**
 * Apply 15-day grace period to expired license (only if not already applied)
 * @param PDO $pdo Database connection
 * @param int $licenseId License ID
 * @return bool Success status
 */
function applyGracePeriod(PDO $pdo, int $licenseId): bool
{
    ensurePlanTables($pdo);
    ensureGracePeriodSchema($pdo);

    try {
        // Check if grace period already applied
        $stmt = $pdo->prepare("SELECT grace_period_active FROM licenses WHERE id = ?");
        $stmt->execute([$licenseId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result || (int) ($result['grace_period_active'] ?? 0) === 1) {
            // Already applied
            return false;
        }

        $graceExpiresAt = date('Y-m-d', time() + (15 * 24 * 3600)); // 15 days from now

        $stmt = $pdo->prepare("
            UPDATE licenses 
            SET grace_period_active = 1, grace_period_expires_at = ? 
            WHERE id = ?
        ");

        $success = $stmt->execute([$graceExpiresAt, $licenseId]);

        if ($success) {
            appLog('INFO', 'Grace period applied', ['license_id' => $licenseId, 'expires' => $graceExpiresAt]);
        }

        return $success;
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to apply grace period', ['license_id' => $licenseId, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Check if license is in grace period
 * @param PDO $pdo Database connection
 * @param int $licenseId License ID
 * @return bool True if in grace period, false otherwise
 */
function isInGracePeriod(PDO $pdo, int $licenseId): bool
{
    return getLicenseStatus($pdo, $licenseId) === 'grace_period';
}

/**
 * Get days remaining in grace period
 * @param PDO $pdo Database connection
 * @param int $licenseId License ID
 * @return int Days remaining (0-15), or -1 if not in grace period
 */
function getGraceDaysRemaining(PDO $pdo, int $licenseId): int
{
    ensurePlanTables($pdo);
    ensureGracePeriodSchema($pdo);

    if (!isInGracePeriod($pdo, $licenseId)) {
        return -1;
    }

    $stmt = $pdo->prepare("SELECT grace_period_expires_at FROM licenses WHERE id = ?");
    $stmt->execute([$licenseId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        return -1;
    }

    $expiresAt = (string) ($result['grace_period_expires_at'] ?? '');
    if ($expiresAt === '') {
        return -1;
    }

    $today = new DateTime(date('Y-m-d'));
    $expireDate = new DateTime($expiresAt);
    $interval = $today->diff($expireDate);

    // If negative, grace period has expired
    if ($interval->invert === 1) {
        return 0;
    }

    return (int) $interval->days;
}

/**
 * Clear grace period from a license (when renewed)
 * @param PDO $pdo Database connection
 * @param int $licenseId License ID
 * @return bool Success status
 */
function clearGracePeriod(PDO $pdo, int $licenseId): bool
{
    try {
        $stmt = $pdo->prepare("
            UPDATE licenses 
            SET grace_period_active = 0, grace_period_expires_at = NULL 
            WHERE id = ?
        ");
        return $stmt->execute([$licenseId]);
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to clear grace period', ['license_id' => $licenseId, 'error' => $e->getMessage()]);
        return false;
    }
}
