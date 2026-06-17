<?php
/**
 * Password Reset Helper
 * Manages secure password recovery flow with token-based verification
 * Tokens are cryptographically random, single-use, and expire after 2 hours
 */

require_once __DIR__ . '/runtime_helper.php';
require_once __DIR__ . '/mail_helper.php';

function ensurePasswordResetTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!appAllowsRuntimeSchema()) {
            assertColumnExists($pdo, 'users', 'reset_token');
            assertColumnExists($pdo, 'users', 'reset_token_expires_at');
            $checked = true;
            return;
        }

        $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('reset_token', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL AFTER password");
        }
        
        if (!in_array('reset_token_expires_at', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN reset_token_expires_at TIMESTAMP NULL AFTER reset_token");
        }

        $checked = true;
    } catch (Throwable $e) {
        appLog('ERROR', 'Password reset schema validation failed', ['message' => $e->getMessage()]);
        // Don't fail app on schema issues
    }
}

/**
 * Generate a cryptographically secure random token (32 characters)
 * @return string Random hex token
 */
function generateResetToken(): string
{
    return bin2hex(random_bytes(16)); // 32 character hex string
}

/**
 * Save reset token to database with 2-hour expiry
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $token Reset token
 * @param int $expiryHours Hours until expiry (default 2)
 * @return bool Success status
 */
function saveResetToken(PDO $pdo, int $userId, string $token, int $expiryHours = 2): bool
{
    try {
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryHours * 3600));
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET reset_token = ?, reset_token_expires_at = ? 
            WHERE id = ?
        ");
        
        return $stmt->execute([$token, $expiresAt, $userId]);
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to save reset token', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Validate reset token: check existence, validity, and expiry
 * @param PDO $pdo Database connection
 * @param string $token Reset token to validate
 * @return ?array User data if valid, null otherwise
 */
function validateResetToken(PDO $pdo, string $token): ?array
{
    try {
        if ($token === '' || strlen($token) !== 32) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT id, email, name, reset_token, reset_token_expires_at 
            FROM users 
            WHERE reset_token = ?
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return null;
        }

        $expiresAt = (string) ($user['reset_token_expires_at'] ?? '');
        if ($expiresAt === '' || strtotime($expiresAt) < time()) {
            // Token expired
            clearResetToken($pdo, (int) $user['id']);
            return null;
        }

        return $user;
    } catch (Throwable $e) {
        appLog('ERROR', 'Token validation failed', ['error' => $e->getMessage()]);
        return null;
    }
}

/**
 * Check if password meets requirements:
 * - Minimum 8 characters
 * - Contains at least one letter
 * - Contains at least one number
 * - Contains at least one special character (!@#$%^&*)
 * @param string $password Password to validate
 * @return array ['valid' => bool, 'message' => string]
 */
function isValidPassword(string $password): array
{
    if (strlen($password) < 8) {
        return ['valid' => false, 'message' => 'Password must be at least 8 characters long.'];
    }

    if (!preg_match('/[a-zA-Z]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one letter.'];
    }

    if (!preg_match('/[0-9]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one number.'];
    }

    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};:\'",.<>?\/\\|`~]/', $password)) {
        return ['valid' => false, 'message' => 'Password must contain at least one special character (!@#$%^&* etc).'];
    }

    return ['valid' => true, 'message' => 'Password is valid.'];
}

/**
 * Reset password: validate token, update password, clear token
 * @param PDO $pdo Database connection
 * @param string $token Reset token
 * @param string $newPassword New password
 * @return array ['success' => bool, 'message' => string]
 */
function resetPassword(PDO $pdo, string $token, string $newPassword): array
{
    try {
        // Validate password requirements
        $passwordCheck = isValidPassword($newPassword);
        if (!$passwordCheck['valid']) {
            return ['success' => false, 'message' => $passwordCheck['message']];
        }

        // Validate token
        $user = validateResetToken($pdo, $token);
        if (!$user) {
            return ['success' => false, 'message' => 'Invalid or expired reset token. Please request a new password reset.'];
        }

        // Hash and update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $userId = (int) $user['id'];

        $stmt = $pdo->prepare("
            UPDATE users 
            SET password = ?, reset_token = NULL, reset_token_expires_at = NULL 
            WHERE id = ?
        ");

        if ($stmt->execute([$hashedPassword, $userId])) {
            appLog('INFO', 'Password reset successful', ['user_id' => $userId, 'email' => $user['email']]);
            return ['success' => true, 'message' => 'Password has been reset successfully. Please login with your new password.'];
        }

        return ['success' => false, 'message' => 'Failed to update password. Please try again.'];
    } catch (Throwable $e) {
        appLog('ERROR', 'Password reset failed', ['error' => $e->getMessage()]);
        return ['success' => false, 'message' => 'An error occurred during password reset. Please try again later.'];
    }
}

/**
 * Clear reset token (called on expiry or after use)
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @return bool Success status
 */
function clearResetToken(PDO $pdo, int $userId): bool
{
    try {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET reset_token = NULL, reset_token_expires_at = NULL 
            WHERE id = ?
        ");
        return $stmt->execute([$userId]);
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to clear reset token', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Request password reset: generate token and send email
 * @param PDO $pdo Database connection
 * @param string $email User email
 * @param string $baseUrl Application base URL
 * @return array ['success' => bool, 'message' => string]
 */
function requestPasswordReset(PDO $pdo, string $email, string $baseUrl): array
{
    try {
        $email = trim(strtolower($email));

        // Find user
        $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE LOWER(email) = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Don't reveal if email exists for security
            return ['success' => true, 'message' => 'If that email address is in our system, we\'ve sent a password reset link.'];
        }

        // Generate token
        $token = generateResetToken();
        $userId = (int) $user['id'];

        // Save token
        if (!saveResetToken($pdo, $userId, $token)) {
            return ['success' => false, 'message' => 'Failed to generate reset token. Please try again.'];
        }

        // Build reset link
        $resetLink = rtrim($baseUrl, '/') . '/reset_password.php?token=' . urlencode($token);

        // Send email
        $emailBody = renderPasswordResetEmail($user['name'], $resetLink);
        $emailSent = sendEmail(
            $user['email'],
            'Password Reset Request for e-BAL',
            $emailBody,
            $userId,
            ['template' => 'password_reset', 'token' => $token]
        );

        if (!$emailSent) {
            appLog('WARN', 'Password reset email failed to send', ['user_id' => $userId, 'email' => $user['email']]);
        }

        appLog('INFO', 'Password reset requested', ['user_id' => $userId, 'email' => $user['email']]);

        return ['success' => true, 'message' => 'If that email address is in our system, we\'ve sent a password reset link.'];
    } catch (Throwable $e) {
        appLog('ERROR', 'Password reset request failed', ['email' => $email, 'error' => $e->getMessage()]);
        return ['success' => false, 'message' => 'An error occurred. Please try again later.'];
    }
}

/**
 * Render password reset email HTML
 * @param string $userName User name
 * @param string $resetLink Password reset link with token
 * @return string HTML email body
 */
function renderPasswordResetEmail(string $userName, string $resetLink): string
{
    $companyName = getenv('COMPANY_NAME') ?: 'e-BAL';
    $supportEmail = getenv('COMPANY_SUPPORT_EMAIL') ?: 'support@ebal.etaxadv.com';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #0f766e 0%, #1a7a73 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: #0f766e; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { background: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e5e7eb; }
        .warning { background: #fef3c7; border: 1px solid #fcd34d; padding: 12px; border-radius: 6px; margin: 20px 0; color: #92400e; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Password Reset Request</h2>
        </div>
        <div class="content">
            <p>Hello {$userName},</p>
            
            <p>We received a request to reset the password for your {$companyName} account. If you did not make this request, you can safely ignore this email.</p>
            
            <p><strong>This reset link will expire in 2 hours.</strong></p>
            
            <center>
                <a href="{$resetLink}" class="button">Reset Your Password</a>
            </center>
            
            <p>Or copy and paste this link into your browser:</p>
            <p style="word-break: break-all; background: #f0f0f0; padding: 10px; border-radius: 4px; font-size: 12px;">
                {$resetLink}
            </p>
            
            <div class="warning">
                <strong>Security Notice:</strong> This link is for password reset only. Never share this link with anyone. {$companyName} staff will never ask for your password reset link.
            </div>
            
            <p>If you continue to have access issues, please contact our support team at <a href="mailto:{$supportEmail}">{$supportEmail}</a></p>
            
            <p>Best regards,<br><strong>{$companyName} Team</strong></p>
        </div>
        <div class="footer">
            <p>&copy; {$companyName}. All rights reserved.</p>
            <p>This is an automated email. Please do not reply to this address.</p>
        </div>
    </div>
</body>
</html>
HTML;
}
