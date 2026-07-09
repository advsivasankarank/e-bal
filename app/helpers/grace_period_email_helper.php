<?php
/**
 * Grace Period Email Helper
 * Sends automated emails for license expiry and grace period reminders
 */

require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/plan_helper.php';

/**
 * Send expiry warning email (sent on or near expiry date)
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $licenseId License ID
 * @return bool Success status
 */
function sendExpiryWarningEmail(PDO $pdo, int $userId, int $licenseId): bool
{
    try {
        $user = getUserById($pdo, $userId);
        if (!$user) {
            return false;
        }

        $license = getActiveLicense($pdo, $userId);
        if (!$license) {
            return false;
        }

        $plan = getPlanDefinition($pdo, (string) $license['plan']);
        $planName = $plan ? (string) $plan['name'] : ucfirst(str_replace('_', ' ', (string) $license['plan']));

        $expiresAt = (string) ($license['expires_at'] ?? '');
        $expiryDate = $expiresAt ? date('F j, Y', strtotime($expiresAt)) : 'soon';

        $emailBody = renderExpiryWarningEmailTemplate($user['name'], $planName, $expiryDate);

        return sendEmail(
            $user['email'],
            'Your e-BAL subscription is about to expire',
            $emailBody,
            $userId,
            ['template' => 'expiry_warning', 'license_id' => $licenseId]
        );
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to send expiry warning email', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Send grace period urgent renewal reminder (sent at 10-day mark)
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param int $licenseId License ID
 * @param int $daysRemaining Days left in grace period
 * @return bool Success status
 */
function sendGracePeriodReminderEmail(PDO $pdo, int $userId, int $licenseId, int $daysRemaining): bool
{
    try {
        $user = getUserById($pdo, $userId);
        if (!$user) {
            return false;
        }

        $license = getActiveLicense($pdo, $userId);
        if (!$license) {
            return false;
        }

        $plan = getPlanDefinition($pdo, (string) $license['plan']);
        $planName = $plan ? (string) $plan['name'] : ucfirst(str_replace('_', ' ', (string) $license['plan']));

        $emailBody = renderGracePeriodReminderEmailTemplate($user['name'], $planName, $daysRemaining);

        return sendEmail(
            $user['email'],
            'Urgent: Your e-BAL subscription grace period is ending soon',
            $emailBody,
            $userId,
            ['template' => 'grace_period_reminder', 'license_id' => $licenseId, 'days_remaining' => $daysRemaining]
        );
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to send grace period reminder email', ['user_id' => $userId, 'error' => $e->getMessage()]);
        return false;
    }
}

/**
 * Render expiry warning email template
 * @param string $userName User name
 * @param string $planName Plan name
 * @param string $expiryDate Formatted expiry date
 * @return string HTML email body
 */
function renderExpiryWarningEmailTemplate(string $userName, string $planName, string $expiryDate): string
{
    $companyName = getenv('COMPANY_NAME') ?: 'e-BAL';
    $supportEmail = getenv('COMPANY_SUPPORT_EMAIL') ?: 'support@ebal.etaxadv.com';
    $renewalLink = rtrim(defined('BASE_URL') ? BASE_URL : 'https://ebal.etaxadv.com/', '/') . '/upgrade.php';

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
        .button { display: inline-block; background: #f59e0b; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: 600; }
        .footer { background: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e5e7eb; }
        .highlight { background: #fef3c7; padding: 12px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #f59e0b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Subscription Expiry Notice</h2>
        </div>
        <div class="content">
            <p>Hello {$userName},</p>
            
            <p>This is a friendly reminder that your <strong>{$planName}</strong> subscription with {$companyName} will expire on <strong>{$expiryDate}</strong>.</p>
            
            <div class="highlight">
                <strong>Action Required:</strong> Renew your subscription before it expires to avoid any interruption to your service.
            </div>
            
            <p>When your subscription expires, you will:</p>
            <ul style="color: #666;">
                <li>Lose access to all {$companyName} features</li>
                <li>Be unable to create or modify documents</li>
                <li>Have 15 days to renew during our grace period (after expiry)</li>
            </ul>
            
            <center>
                <a href="{$renewalLink}" class="button">Renew Your Subscription</a>
            </center>
            
            <p style="color: #666; font-size: 13px; margin-top: 20px;">
                <strong>Already renewed?</strong> Your updated subscription will be activated immediately upon payment confirmation.
            </p>
            
            <p>If you have any questions or need assistance, please contact our support team at <a href="mailto:{$supportEmail}">{$supportEmail}</a></p>
            
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

/**
 * Render grace period reminder email template
 * @param string $userName User name
 * @param string $planName Plan name
 * @param int $daysRemaining Days left in grace period
 * @return string HTML email body
 */
function renderGracePeriodReminderEmailTemplate(string $userName, string $planName, int $daysRemaining): string
{
    $companyName = getenv('COMPANY_NAME') ?: 'e-BAL';
    $supportEmail = getenv('COMPANY_SUPPORT_EMAIL') ?: 'support@ebal.etaxadv.com';
    $renewalLink = rtrim(defined('BASE_URL') ? BASE_URL : 'https://ebal.etaxadv.com/', '/') . '/upgrade.php';
    $dayText = $daysRemaining === 1 ? 'day' : 'days';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .button { display: inline-block; background: #dc2626; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: 600; }
        .footer { background: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #666; border-top: 1px solid #e5e7eb; }
        .urgent-box { background: #fee2e2; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #dc2626; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>⚠️ URGENT: Grace Period Ending Soon</h2>
        </div>
        <div class="content">
            <p>Hello {$userName},</p>
            
            <div class="urgent-box">
                <strong>Your subscription grace period expires in {$daysRemaining} {$dayText}.</strong>
                <p style="margin: 10px 0 0;">After this period, you will lose all access to {$companyName} and your data may become inaccessible.</p>
            </div>
            
            <p>Your <strong>{$planName}</strong> subscription with {$companyName} has expired. However, we're giving you a <strong>15-day grace period</strong> to renew without losing access.</p>
            
            <h3 style="color: #dc2626; margin-top: 20px;">What happens if you don't renew?</h3>
            <ul style="color: #666;">
                <li>Immediate loss of access to all {$companyName} features</li>
                <li>Inability to create, edit, or export documents</li>
                <li>Data retention policy applies after grace period expires</li>
            </ul>
            
            <h3 style="color: #059669; margin-top: 20px;">How to renew:</h3>
            <p>Click the button below to renew your subscription immediately and regain full access:</p>
            
            <center>
                <a href="{$renewalLink}" class="button">Renew Now - Only {$daysRemaining} {$dayText} Left!</a>
            </center>
            
            <p style="color: #666; font-size: 13px; margin-top: 20px;">
                <strong>Need help?</strong> Our support team is available at <a href="mailto:{$supportEmail}">{$supportEmail}</a> to assist you with renewal or payment issues.
            </p>
            
            <p>Don't wait—renew today to avoid losing access!</p>
            
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

/**
 * Process licenses for grace period email reminders (call via cron)
 * Sends email to licenses with 10 days or less remaining in grace period
 * @param PDO $pdo Database connection
 * @return array ['processed' => int, 'sent' => int, 'errors' => int]
 */
function processGracePeriodEmailReminders(PDO $pdo): array
{
    ensurePlanTables($pdo);
    ensureGracePeriodSchema($pdo);

    $processed = 0;
    $sent = 0;
    $errors = 0;

    try {
        // Find licenses in grace period with 10 days or less remaining
        // That haven't had reminder email sent in last 24 hours
        $stmt = $pdo->prepare("
            SELECT l.id, l.user_id, 
                   DATEDIFF(l.grace_period_expires_at, CURDATE()) as days_remaining
            FROM licenses l
            WHERE l.grace_period_active = 1
            AND l.grace_period_expires_at IS NOT NULL
            AND DATEDIFF(l.grace_period_expires_at, CURDATE()) BETWEEN 0 AND 10
            ORDER BY l.user_id, l.id
        ");
        $stmt->execute();
        $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($licenses as $license) {
            $processed++;
            $licenseId = (int) $license['id'];
            $userId = (int) $license['user_id'];
            $daysRemaining = max(0, (int) $license['days_remaining']);

            // Check if we already sent email in last 24 hours
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM email_log 
                WHERE user_id = ? 
                AND template_type = 'grace_period_reminder'
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $checkStmt->execute([$userId]);
            $alreadySent = (int) $checkStmt->fetchColumn();

            if ($alreadySent > 0) {
                continue; // Already sent in last 24 hours
            }

            if (sendGracePeriodReminderEmail($pdo, $userId, $licenseId, $daysRemaining)) {
                $sent++;
            } else {
                $errors++;
            }
        }

        appLog('INFO', 'Grace period email reminders processed', ['processed' => $processed, 'sent' => $sent, 'errors' => $errors]);

        return ['processed' => $processed, 'sent' => $sent, 'errors' => $errors];
    } catch (Throwable $e) {
        appLog('ERROR', 'Failed to process grace period email reminders', ['error' => $e->getMessage()]);
        return ['processed' => $processed, 'sent' => $sent, 'errors' => $errors + 1];
    }
}
