<?php
/**
 * e-BAL — SaaS Demo Mode Helper
 *
 * Core functions for demo user management, consent, expiry, purge, and upgrade.
 * All demo logic is isolated here for clean separation from production code.
 */

/* ============================================================
   DEMO IDENTIFICATION
   ============================================================ */

/**
 * Check if the currently logged-in user is a demo user.
 * Uses session value when available, falls back to DB lookup.
 */
function isDemoUser(?PDO $pdo = null): bool
{
    if (isset($_SESSION['is_demo'])) {
        return (bool) $_SESSION['is_demo'];
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || $pdo === null) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT is_demo FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $isDemo = (int) ($stmt->fetchColumn() ?: 0);
        $_SESSION['is_demo'] = $isDemo === 1;
        return $isDemo === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if demo user has accepted consent.
 */
function isDemoConsentAccepted(?PDO $pdo = null): bool
{
    if (isset($_SESSION['demo_consent_accepted']) && $_SESSION['demo_consent_accepted']) {
        return true;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || $pdo === null) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("SELECT demo_consent_accepted_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        $accepted = !empty($val);
        $_SESSION['demo_consent_accepted'] = $accepted;
        return $accepted;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if demo has expired (based on demo_expires_at).
 */
function isDemoExpired(?PDO $pdo = null): bool
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return true;
    }

    if ($pdo === null) {
        return true;
    }

    try {
        $stmt = $pdo->prepare("SELECT demo_expires_at, demo_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return true;
        }

        $status = (string) ($row['demo_status'] ?? '');
        if ($status === 'upgrade_pending' || $status === 'paid_active') {
            return false;
        }

        $expiresAt = (string) ($row['demo_expires_at'] ?? '');
        if (empty($expiresAt)) {
            return false; // demo not started yet
        }

        return strtotime($expiresAt) < time();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get remaining demo time in human-readable format.
 */
function getDemoTimeRemaining(?PDO $pdo = null): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || $pdo === null) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("SELECT demo_expires_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $expiresAt = (string) ($stmt->fetchColumn() ?: '');
        if (empty($expiresAt)) {
            return '';
        }

        $remaining = strtotime($expiresAt) - time();
        if ($remaining <= 0) {
            return 'Expired';
        }

        $hours = (int) floor($remaining / 3600);
        $minutes = (int) floor(($remaining % 3600) / 60);

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm remaining';
        }
        return $minutes . 'm remaining';
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Get demo user's current status string.
 */
function getDemoStatus(?PDO $pdo = null): string
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || $pdo === null) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("SELECT demo_status FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/* ============================================================
   DEMO LEAD MANAGEMENT (Part C)
   ============================================================ */

/**
 * Ensure demo_leads table exists (runtime schema for local dev).
 */
function ensureDemoTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!function_exists('appAllowsRuntimeSchema') || !appAllowsRuntimeSchema()) {
            $checked = true;
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demo_leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                name VARCHAR(120) NOT NULL,
                mobile VARCHAR(20) NOT NULL,
                email VARCHAR(190) NOT NULL,
                address_city VARCHAR(120) NULL,
                profession_type VARCHAR(60) NULL,
                firm_company_name VARCHAR(180) NULL,
                source VARCHAR(60) NOT NULL DEFAULT 'e-BAL Demo',
                status VARCHAR(40) NOT NULL DEFAULT 'Demo Access Created',
                interest_level VARCHAR(20) NULL DEFAULT 'Warm',
                consent_accepted_at DATETIME NULL,
                demo_requested_at DATETIME NULL,
                demo_started_at DATETIME NULL,
                demo_expires_at DATETIME NULL,
                last_demo_activity_at DATETIME NULL,
                upgrade_requested_at DATETIME NULL,
                retain_demo_data TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_demo_leads_email (email),
                INDEX idx_demo_leads_mobile (mobile),
                INDEX idx_demo_leads_user (user_id),
                INDEX idx_demo_leads_status (status)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demo_upgrade_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                lead_id INT NULL,
                preferred_plan VARCHAR(40) NULL,
                message TEXT NULL,
                retain_demo_data TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                requested_at DATETIME NOT NULL,
                converted_at DATETIME NULL,
                converted_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_demo_upgrade_user (user_id),
                INDEX idx_demo_upgrade_lead (lead_id),
                INDEX idx_demo_upgrade_status (status)
            )
        ");

        $checked = true;
    } catch (Throwable $e) {
        if (function_exists('appLog')) {
            appLog('ERROR', 'Demo schema validation failed', ['message' => $e->getMessage()]);
        }
    }
}

/**
 * Find existing demo lead by email or mobile.
 */
function findDemoLeadByEmailOrMobile(PDO $pdo, string $email, string $mobile): ?array
{
    ensureDemoTables($pdo);

    $stmt = $pdo->prepare(
        "SELECT * FROM demo_leads WHERE email = ? OR mobile = ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email, $mobile]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * Create or update demo lead record.
 */
function upsertDemoLead(
    PDO $pdo,
    string $name,
    string $mobile,
    string $email,
    string $addressCity = '',
    string $professionType = '',
    string $firmCompanyName = '',
    ?int $userId = null
): array {
    ensureDemoTables($pdo);

    $existing = findDemoLeadByEmailOrMobile($pdo, $email, $mobile);

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE demo_leads SET
                name = ?,
                mobile = ?,
                address_city = COALESCE(NULLIF(?, ''), address_city),
                profession_type = COALESCE(NULLIF(?, ''), profession_type),
                firm_company_name = COALESCE(NULLIF(?, ''), firm_company_name),
                user_id = COALESCE(?, user_id),
                status = 'Demo Access Created',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $mobile, $addressCity, $professionType, $firmCompanyName, $userId, $existing['id']]);
        return ['id' => (int) $existing['id'], 'action' => 'updated'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO demo_leads
            (user_id, name, mobile, email, address_city, profession_type, firm_company_name,
             source, status, interest_level, demo_requested_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'e-BAL Demo', 'Demo Access Created', 'Warm', NOW(), NOW(), NOW())
    ");
    $stmt->execute([$userId, $name, $mobile, $email, $addressCity, $professionType, $firmCompanyName]);

    return ['id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
}

/* ============================================================
   DEMO USER MANAGEMENT (Part D)
   ============================================================ */

/**
 * Generate a strong random password.
 */
function generateDemoPassword(int $length = 14): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/**
 * Create a demo user account.
 * Returns ['user_id' => int, 'password' => string, 'email' => string]
 */
function createDemoUser(
    PDO $pdo,
    string $name,
    string $email,
    string $password
): array {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password, role, is_demo, demo_status, created_at, updated_at)
        VALUES (?, ?, ?, 'staff', 1, 'credentials_sent', NOW(), NOW())
    ");
    $stmt->execute([$name, $email, $hash]);

    $userId = (int) $pdo->lastInsertId();

    // Link lead to user
    try {
        $pdo->prepare("UPDATE demo_leads SET user_id = ? WHERE email = ? AND user_id IS NULL")
            ->execute([$userId, $email]);
    } catch (Throwable $e) {
        // Non-critical
    }

    return [
        'user_id' => $userId,
        'password' => $password,
        'email' => $email,
    ];
}

/**
 * Handle demo request: find or create lead + user, return credentials.
 * If email/mobile already exists, regenerate and resend credentials.
 */
function handleDemoRequest(
    PDO $pdo,
    string $name,
    string $mobile,
    string $email,
    string $addressCity = '',
    string $professionType = '',
    string $firmCompanyName = ''
): array {
    ensureDemoTables($pdo);

    $existing = findDemoLeadByEmailOrMobile($pdo, $email, $mobile);

    if ($existing && !empty($existing['user_id'])) {
        // User already exists — regenerate password
        $newPassword = generateDemoPassword();
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $pdo->prepare("UPDATE users SET password = ?, demo_status = 'credentials_sent', updated_at = NOW() WHERE id = ?")
            ->execute([$hash, (int) $existing['user_id']]);

        // Update lead
        upsertDemoLead($pdo, $name, $mobile, $email, $addressCity, $professionType, $firmCompanyName, (int) $existing['user_id']);

        return [
            'user_id' => (int) $existing['user_id'],
            'password' => $newPassword,
            'email' => $email,
            'action' => 'credentials_regenerated',
        ];
    }

    // Create new user
    $newPassword = generateDemoPassword();
    $userResult = createDemoUser($pdo, $name, $email, $newPassword);

    // Create/update lead
    upsertDemoLead($pdo, $name, $mobile, $email, $addressCity, $professionType, $firmCompanyName, $userResult['user_id']);

    return [
        'user_id' => $userResult['user_id'],
        'password' => $newPassword,
        'email' => $email,
        'action' => 'created',
    ];
}

/* ============================================================
   EMAIL CREDENTIALS (Part E)
   ============================================================ */

/**
 * Send demo credentials email.
 */
function sendDemoCredentialsEmail(
    PDO $pdo,
    string $email,
    string $password,
    string $userName
): bool {
    $subject = 'Your e-BAL Demo Access';

    $bodyHtml = '
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#1e293b;">
        <div style="background:#12355B;padding:20px 24px;border-radius:8px 8px 0 0;">
            <h1 style="color:#fff;font-size:20px;margin:0;">e-BAL Demo Access</h1>
        </div>
        <div style="background:#fff;padding:24px;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;">
            <p style="font-size:14px;line-height:1.6;">Hello ' . htmlspecialchars($userName) . ',</p>
            <p style="font-size:14px;line-height:1.6;">Your e-BAL demo access has been created.</p>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:16px 0;">
                <p style="margin:4px 0;font-size:13px;"><strong>Login Email:</strong> ' . htmlspecialchars($email) . '</p>
                <p style="margin:4px 0;font-size:13px;"><strong>Temporary Password:</strong> <code style="background:#e2e8f0;padding:2px 6px;border-radius:4px;">' . htmlspecialchars($password) . '</code></p>
            </div>
            <p style="font-size:13px;color:#64748b;line-height:1.5;">Your demo access will be valid for 24 hours from your first login.</p>
            <p style="font-size:13px;color:#64748b;line-height:1.5;">All financial/demo working data entered during the demo will be deleted on logout or expiry for data security, unless you request upgrade during the demo period.</p>
            <div style="margin-top:20px;text-align:center;">
                <a href="' . BASE_URL . 'login.php" style="display:inline-block;background:#047857;color:#fff;padding:12px 28px;border-radius:7px;text-decoration:none;font-weight:600;font-size:14px;">Login to e-BAL</a>
            </div>
        </div>
        <div style="text-align:center;padding:12px;font-size:11px;color:#94a3b8;">
            &copy; ' . date('Y') . ' e-BAL — E Tax Advisors Private Limited
        </div>
    </div>';

    try {
        require_once __DIR__ . '/mail_helper.php';
        return sendEmail($email, $subject, $bodyHtml, (int) ($_SESSION['user_id'] ?? 0), [
            'template_type' => 'demo_credentials',
            'user_email' => $email,
        ], 'support@etaxadv.com', 'e-BAL Support', 'support@etaxadv.com');
    } catch (Throwable $e) {
        if (function_exists('appLog')) {
            appLog('WARN', 'Demo credentials email failed', ['email' => $email, 'error' => $e->getMessage()]);
        }
        return false;
    }
}

/* ============================================================
   CONSENT & DEMO START (Part G)
   ============================================================ */

/**
 * Accept demo consent and start the 24-hour timer.
 */
function acceptDemoConsent(PDO $pdo, int $userId): bool
{
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

    try {
        $stmt = $pdo->prepare("
            UPDATE users SET
                demo_consent_accepted_at = COALESCE(demo_consent_accepted_at, ?),
                demo_started_at = COALESCE(demo_started_at, ?),
                demo_expires_at = ?,
                demo_status = 'active',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$now, $now, $expiresAt, $userId]);

        // Update lead
        $pdo->prepare("
            UPDATE demo_leads SET
                consent_accepted_at = COALESCE(consent_accepted_at, ?),
                demo_started_at = COALESCE(demo_started_at, ?),
                demo_expires_at = ?,
                status = 'Demo Active',
                updated_at = NOW()
            WHERE user_id = ?
        ")->execute([$now, $now, $expiresAt, $userId]);

        // Set session values
        $_SESSION['demo_consent_accepted'] = true;
        $_SESSION['demo_expires_at'] = $expiresAt;

        return true;
    } catch (Throwable $e) {
        if (function_exists('appLog')) {
            appLog('ERROR', 'Failed to accept demo consent', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
        return false;
    }
}

/* ============================================================
   DATA PURGE (Part N-O)
   ============================================================ */

/**
 * Purge all demo-created working data for a user.
 * Safe: only deletes data created by the demo user.
 * Does NOT delete: user account, lead data, consent record, normal user data.
 */
function purgeDemoUserData(PDO $pdo, int $userId, ?int $companyId = null): array
{
    $purged = [];
    $companyFilter = '';
    $params = [$userId];

    if ($companyId !== null && $companyId > 0) {
        $companyFilter = ' AND company_id = ?';
        $params[] = $companyId;
    }

    // 1. Delete generated demo reports/PDFs
    try {
        $stmt = $pdo->prepare("DELETE FROM manual_inputs WHERE created_by = ? AND company_id IS NOT NULL" . $companyFilter);
        $stmt->execute($params);
        $purged['manual_inputs'] = $stmt->rowCount();
    } catch (Throwable $e) {
        $purged['manual_inputs'] = 'skipped';
    }

    // 2. Delete workflow records
    try {
        $stmt = $pdo->prepare("DELETE FROM workflow_status WHERE company_id IN (SELECT id FROM companies WHERE owner_user_id = ?)" . $companyFilter);
        $stmt->execute($params);
        $purged['workflow_status'] = $stmt->rowCount();
    } catch (Throwable $e) {
        $purged['workflow_status'] = 'skipped';
    }

    // 3. Delete review remarks and sign-offs
    try {
        $tables_to_check = ['review_remarks', 'sign_offs', 'review_data'];
        foreach ($tables_to_check as $table) {
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $existsStmt->execute([$table]);
            $exists = (bool) $existsStmt->fetchColumn();
            if ($exists) {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?");
                $stmt->execute([$userId]);
                $purged[$table] = $stmt->rowCount();
            }
        }
    } catch (Throwable $e) {
        $purged['review_data'] = 'skipped';
    }

    // 4. Delete imported trial balances and ledgers
    try {
        $tables_to_check = ['trial_balance_entries', 'trial_balance_imports', 'ledger_mappings'];
        foreach ($tables_to_check as $table) {
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $existsStmt->execute([$table]);
            $exists = (bool) $existsStmt->fetchColumn();
            if ($exists) {
                $companyIdFilter = '';
                $deleteParams = [$userId];
                if ($companyId !== null && $companyId > 0) {
                    $companyIdFilter = ' AND company_id = ?';
                    $deleteParams[] = $companyId;
                }
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE created_by = ?" . $companyIdFilter);
                $stmt->execute($deleteParams);
                $purged[$table] = $stmt->rowCount();
            }
        }
    } catch (Throwable $e) {
        $purged['import_data'] = 'skipped';
    }

    // 5. Delete financial years created by demo user
    try {
        $stmt = $pdo->prepare("
            DELETE FROM financial_years
            WHERE company_id IN (SELECT id FROM companies WHERE owner_user_id = ?)
        ");
        $stmt->execute([$userId]);
        $purged['financial_years'] = $stmt->rowCount();
    } catch (Throwable $e) {
        $purged['financial_years'] = 'skipped';
    }

    // 6. Delete companies/entities created by demo user
    try {
        $stmt = $pdo->prepare("DELETE FROM companies WHERE owner_user_id = ?");
        $stmt->execute([$userId]);
        $purged['companies'] = $stmt->rowCount();
    } catch (Throwable $e) {
        $purged['companies'] = 'skipped';
    }

    // 7. Delete uploaded files for demo companies
    try {
        if ($companyId !== null && $companyId > 0) {
            $uploadDir = __DIR__ . '/../../storage/uploads/' . $companyId;
            if (is_dir($uploadDir)) {
                deleteDirectory($uploadDir);
                $purged['upload_files'] = 'deleted';
            }
        }
    } catch (Throwable $e) {
        $purged['upload_files'] = 'skipped';
    }

    // 8. Delete generated PDF files
    try {
        $downloadsDir = __DIR__ . '/../../public/downloads';
        if (is_dir($downloadsDir)) {
            $files = glob($downloadsDir . '/demo_*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            $purged['demo_pdfs'] = count($files);
        }
    } catch (Throwable $e) {
        $purged['demo_pdfs'] = 'skipped';
    }

    // 9. Update lead status
    try {
        $pdo->prepare("UPDATE demo_leads SET status = 'Demo Data Purged', updated_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
    } catch (Throwable $e) {
        // Non-critical
    }

    return $purged;
}

/**
 * Recursively delete a directory.
 */
function deleteDirectory(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }

    return rmdir($dir);
}

/* ============================================================
   UPGRADE REQUEST (Part Q-R)
   ============================================================ */

/**
 * Submit an upgrade request for a demo user.
 */
function submitUpgradeRequest(
    PDO $pdo,
    int $userId,
    string $preferredPlan = '',
    string $message = '',
    bool $retainDemoData = true
): bool {
    ensureDemoTables($pdo);

    $now = date('Y-m-d H:i:s');

    // Find lead
    $leadId = null;
    try {
        $stmt = $pdo->prepare("SELECT id FROM demo_leads WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        $leadId = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        // Non-critical
    }

    // Insert upgrade request
    $stmt = $pdo->prepare("
        INSERT INTO demo_upgrade_requests
            (user_id, lead_id, preferred_plan, message, retain_demo_data, status, requested_at, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
    ");
    $stmt->execute([$userId, $leadId, $preferredPlan, $message, $retainDemoData ? 1 : 0, $now]);

    // Update user
    $pdo->prepare("
        UPDATE users SET
            demo_status = 'upgrade_pending',
            upgrade_requested_at = ?,
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$now, $userId]);

    // Update lead
    if ($leadId) {
        $pdo->prepare("
            UPDATE demo_leads SET
                status = 'Upgrade Requested',
                upgrade_requested_at = ?,
                retain_demo_data = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$now, $retainDemoData ? 1 : 0, $leadId]);
    }

    // Update session
    $_SESSION['demo_status'] = 'upgrade_pending';

    return true;
}

/* ============================================================
   ADMIN CONVERSION (Part S)
   ============================================================ */

/**
 * Convert a demo user to a paid user.
 * Phase 1: Manual backend helper. Admin UI is a follow-up item.
 */
function convertDemoUserToPaid(
    PDO $pdo,
    int $userId,
    array $options = []
): bool {
    $retainData = $options['retain_demo_data'] ?? true;

    $now = date('Y-m-d H:i:s');

    try {
        // Update user: remove demo flag, update status
        $stmt = $pdo->prepare("
            UPDATE users SET
                is_demo = 0,
                demo_status = 'paid_active',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId]);

        // Update lead
        $pdo->prepare("
            UPDATE demo_leads SET
                status = 'Converted - Paid Customer',
                updated_at = NOW()
            WHERE user_id = ?
        ")->execute([$userId]);

        // Mark upgrade request as converted
        $pdo->prepare("
            UPDATE demo_upgrade_requests SET
                status = 'converted',
                converted_at = ?,
                updated_at = NOW()
            WHERE user_id = ? AND status = 'pending'
        ")->execute([$now, $userId]);

        // If not retaining data, purge demo working data
        if (!$retainData) {
            purgeDemoUserData($pdo, $userId);
        }

        // Clear session demo flags
        $_SESSION['is_demo'] = false;
        $_SESSION['demo_consent_accepted'] = false;
        $_SESSION['demo_expires_at'] = '';
        $_SESSION['demo_status'] = 'paid_active';

        return true;
    } catch (Throwable $e) {
        if (function_exists('appLog')) {
            appLog('ERROR', 'Failed to convert demo user to paid', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
        return false;
    }
}

/* ============================================================
   PDF WATERMARK HELPER (Part L)
   ============================================================ */

/**
 * Get demo watermark HTML for PDF output.
 */
function getDemoWatermarkHtml(): string
{
    return '<div class="demo-watermark">e-BAL DEMO COPY</div>';
}

/**
 * Get demo watermark CSS for PDF output.
 */
function getDemoWatermarkCss(): string
{
    return '
    .demo-watermark {
        position: fixed;
        top: 45%;
        left: 5%;
        width: 90%;
        text-align: center;
        font-size: 42px;
        font-weight: 800;
        color: rgba(120, 120, 120, 0.18);
        transform: rotate(-30deg);
        z-index: 9999;
        letter-spacing: 4px;
        pointer-events: none;
    }
    @media print {
        .demo-watermark { display: block !important; }
    }';
}

/**
 * Build demo-specific PDF filename.
 * Appends -DEMO-COPY to the standard filename.
 */
function buildDemoExportFilename(string $companyName, string $fyName, string $extension, string $documentLabel = 'financial-statements'): string
{
    $base = trim($companyName) !== '' ? $companyName : 'financial-statements';
    $fy = trim($fyName) !== '' ? $fyName : 'financial-year';
    $filename = $base . '-' . $fy . '-' . $documentLabel . '-DEMO-COPY';
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: $documentLabel . '-DEMO-COPY';
    $filename = trim($filename, '-');

    return $filename . '.' . ltrim($extension, '.');
}
