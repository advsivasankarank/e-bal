<?php
require_once __DIR__ . '/runtime_helper.php';

/**
 * Email Infrastructure Helper
 * Provides email sending capabilities with multiple transport options
 * Supports HTML templates for various customer communications
 */

function ensureMailTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!appAllowsRuntimeSchema()) {
            assertTableExists($pdo, 'email_log');
            $checked = true;
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS email_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                email_to VARCHAR(255) NOT NULL,
                email_from VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body_html LONGTEXT NOT NULL,
                template_type VARCHAR(50) NOT NULL,
                template_data JSON NULL,
                status ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
                error_message VARCHAR(500) NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_user (user_id),
                INDEX idx_email_status (status),
                INDEX idx_email_created (created_at)
            )
        ");

        $checked = true;
    } catch (Throwable $e) {
        appLog('ERROR', 'Email schema validation failed', ['message' => $e->getMessage()]);
        // Don't fail app on email schema issues
    }
}

function getMailConfig(): array
{
    return [
        'transport' => getenv('MAIL_TRANSPORT') ?: 'php',  // 'php' or 'smtp'
        'smtp_host' => getenv('MAIL_SMTP_HOST') ?: 'localhost',
        'smtp_port' => (int) (getenv('MAIL_SMTP_PORT') ?: 587),
        'smtp_username' => getenv('MAIL_SMTP_USERNAME') ?: '',
        'smtp_password' => getenv('MAIL_SMTP_PASSWORD') ?: '',
        'smtp_encryption' => getenv('MAIL_SMTP_ENCRYPTION') ?: 'tls',  // 'tls', 'ssl', or ''
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@ebal.etaxadv.com',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'e-BAL',
        'company_name' => getenv('COMPANY_NAME') ?: 'e-BAL',
        'company_support_email' => getenv('COMPANY_SUPPORT_EMAIL') ?: 'support@ebal.etaxadv.com',
    ];
}

function sendEmail(
    string $toEmail,
    string $subject,
    string $bodyHtml,
    ?int $userId = null,
    array $templateData = [],
    ?string $fromEmail = null,
    ?string $fromName = null,
    ?string $replyTo = null
): bool {
    try {
        $config = getMailConfig();
        
        // Log the email attempt
        if ($userId !== null && extension_loaded('pdo')) {
            try {
                $pdo = getPdoConnection();
                if ($pdo) {
                    ensureMailTables($pdo);
                    logEmailAttempt($pdo, $userId, $toEmail, $subject, $bodyHtml, 'queued', $templateData);
                }
            } catch (Throwable $e) {
                appLog('WARN', 'Failed to log email attempt', ['error' => $e->getMessage()]);
            }
        }

        $senderName = $fromName ?? $config['from_name'];
        $senderEmail = $fromEmail ?? $config['from_address'];
        $replyToEmail = $replyTo ?? $config['company_support_email'];

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . $senderName . " <" . $senderEmail . ">\r\n";
        $headers .= "Reply-To: " . $replyToEmail . "\r\n";

        $transport = strtolower($config['transport']);
        $sent = false;

        if ($transport === 'smtp' && $config['smtp_host']) {
            $sent = sendViaSmtp($toEmail, $subject, $bodyHtml, $headers);
        } else {
            $sent = mail($toEmail, $subject, $bodyHtml, $headers);
        }

        if ($sent && $userId !== null) {
            try {
                $pdo = getPdoConnection();
                if ($pdo) {
                    logEmailAttempt($pdo, $userId, $toEmail, $subject, $bodyHtml, 'sent', $templateData);
                }
            } catch (Throwable $e) {
                appLog('WARN', 'Failed to update email log', ['error' => $e->getMessage()]);
            }
        }

        return $sent;
    } catch (Throwable $e) {
        appLog('ERROR', 'Email sending failed', [
            'to' => $toEmail,
            'error' => $e->getMessage(),
        ]);

        if ($userId !== null) {
            try {
                $pdo = getPdoConnection();
                if ($pdo) {
                    logEmailAttempt(
                        $pdo,
                        $userId,
                        $toEmail,
                        $subject,
                        $bodyHtml,
                        'failed',
                        $templateData,
                        $e->getMessage()
                    );
                }
            } catch (Throwable $logE) {
                appLog('WARN', 'Failed to log email failure', ['error' => $logE->getMessage()]);
            }
        }

        return false;
    }
}

function sendViaSmtp(string $to, string $subject, string $body, string $headers): bool
{
    try {
        $config = getMailConfig();
        
        $from = $config['from_address'];
        $host = $config['smtp_host'];
        $port = $config['smtp_port'];
        $username = $config['smtp_username'];
        $password = $config['smtp_password'];
        $encryption = $config['smtp_encryption'];

        if (!$host || !$username || !$password) {
            appLog('WARN', 'SMTP not configured — missing host/username/password');
            return false;
        }

        $errno = 0;
        $errstr = '';

        /* SSL on port 465: connect via ssl:// wrapper */
        if ($encryption === 'ssl') {
            $context = stream_context_create([
                'ssl' => [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                    'allow_self_signed' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $socket = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        } else {
            $socket = @fsockopen($host, $port, $errno, $errstr, 30);
        }

        if (!$socket) {
            appLog('ERROR', 'SMTP connection failed', [
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption,
                'error' => $errstr,
            ]);
            return false;
        }

        stream_set_timeout($socket, 10);
        fgets($socket);

        fputs($socket, "EHLO " . gethostname() . "\r\n");
        fgets($socket);

        /* STARTTLS only for tls encryption (not ssl — already encrypted) */
        if ($encryption === 'tls') {
            fputs($socket, "STARTTLS\r\n");
            fgets($socket);
            stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($socket, 'ssl', 'verify_peer', false);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            fgets($socket);
        }

        if ($username && $password) {
            fputs($socket, "AUTH LOGIN\r\n");
            fgets($socket);
            fputs($socket, base64_encode($username) . "\r\n");
            fgets($socket);
            fputs($socket, base64_encode($password) . "\r\n");
            fgets($socket);
        }

        fputs($socket, "MAIL FROM: <" . $from . ">\r\n");
        fgets($socket);
        fputs($socket, "RCPT TO: <" . $to . ">\r\n");
        fgets($socket);
        fputs($socket, "DATA\r\n");
        fgets($socket);

        fputs($socket, "To: " . $to . "\r\n");
        fputs($socket, "Subject: " . $subject . "\r\n");
        fputs($socket, $headers . "\r\n");
        fputs($socket, $body . "\r\n.\r\n");
        $response = fgets($socket);

        fputs($socket, "QUIT\r\n");
        fclose($socket);

        return strpos($response, '250') !== false;
    } catch (Throwable $e) {
        appLog('ERROR', 'SMTP send failed', ['error' => $e->getMessage()]);
        return false;
    }
}

function getPdoConnection(): ?PDO
{
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }
    return null;
}

function logEmailAttempt(
    PDO $pdo,
    int $userId,
    string $toEmail,
    string $subject,
    string $bodyHtml,
    string $status,
    array $templateData = [],
    ?string $errorMessage = null
): void {
    try {
        ensureMailTables($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO email_log
                (user_id, email_to, email_from, subject, body_html, template_type, template_data, status, error_message, sent_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $config = getMailConfig();
        $templateType = $templateData['template_type'] ?? 'generic';
        $sentAt = ($status === 'sent') ? date('Y-m-d H:i:s') : null;

        $stmt->execute([
            $userId,
            $toEmail,
            $config['from_address'],
            $subject,
            $bodyHtml,
            $templateType,
            $templateData ? json_encode($templateData, JSON_UNESCAPED_SLASHES) : null,
            $status,
            $errorMessage,
            $sentAt,
        ]);
    } catch (Throwable $e) {
        appLog('WARN', 'Email logging failed', ['error' => $e->getMessage()]);
    }
}

function renderEmailTemplate(string $templateName, array $data = []): string
{
    $templatePath = __DIR__ . '/../../templates/email/' . $templateName . '.php';

    if (!file_exists($templatePath)) {
        appLog('WARN', 'Email template not found', ['template' => $templateName]);
        return '';
    }

    ob_start();
    try {
        extract($data, EXTR_SKIP);
        require $templatePath;
        $html = ob_get_clean();
        return $html ?: '';
    } catch (Throwable $e) {
        ob_end_clean();
        appLog('ERROR', 'Email template rendering failed', [
            'template' => $templateName,
            'error' => $e->getMessage(),
        ]);
        return '';
    }
}

// Template-specific email functions
function sendWelcomeEmail(PDO $pdo, array $user): bool
{
    $config = getMailConfig();
    $html = renderEmailTemplate('welcome', [
        'user_name' => $user['name'] ?? '',
        'company_name' => $config['company_name'],
        'support_email' => $config['company_support_email'],
        'base_url' => defined('BASE_URL') ? BASE_URL : '/',
    ]);

    if (!$html) {
        appLog('WARN', 'Failed to render welcome email template');
        return false;
    }

    $subject = 'Welcome to ' . $config['company_name'];
    $toEmail = $user['email'] ?? '';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        appLog('WARN', 'Invalid email address for welcome email', ['email' => $toEmail]);
        return false;
    }

    return sendEmail($toEmail, $subject, $html, $user['id'] ?? null, [
        'template_type' => 'welcome',
    ]);
}

function sendPaymentSuccessEmail(PDO $pdo, array $user, array $paymentDetails): bool
{
    $config = getMailConfig();
    $html = renderEmailTemplate('payment_success', [
        'user_name' => $user['name'] ?? '',
        'plan_name' => $paymentDetails['plan_name'] ?? '',
        'amount' => $paymentDetails['amount_inr'] ?? 0,
        'license_expires' => $paymentDetails['license_expires'] ?? '',
        'company_name' => $config['company_name'],
        'support_email' => $config['company_support_email'],
    ]);

    if (!$html) {
        appLog('WARN', 'Failed to render payment success email template');
        return false;
    }

    $subject = 'Payment Confirmed - ' . $config['company_name'] . ' Plan Activated';
    $toEmail = $user['email'] ?? '';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        appLog('WARN', 'Invalid email address for payment success email', ['email' => $toEmail]);
        return false;
    }

    return sendEmail($toEmail, $subject, $html, $user['id'] ?? null, [
        'template_type' => 'payment_success',
        'plan_name' => $paymentDetails['plan_name'] ?? '',
        'amount' => $paymentDetails['amount_inr'] ?? 0,
    ]);
}

function sendInvoiceGeneratedEmail(PDO $pdo, array $user, array $invoiceDetails): bool
{
    $config = getMailConfig();
    $html = renderEmailTemplate('invoice_generated', [
        'user_name' => $user['name'] ?? '',
        'invoice_number' => $invoiceDetails['invoice_number'] ?? '',
        'invoice_date' => $invoiceDetails['invoice_date'] ?? '',
        'amount' => $invoiceDetails['total_value'] ?? 0,
        'company_name' => $config['company_name'],
        'support_email' => $config['company_support_email'],
        'download_url' => $invoiceDetails['download_url'] ?? '',
    ]);

    if (!$html) {
        appLog('WARN', 'Failed to render invoice generated email template');
        return false;
    }

    $subject = 'Invoice Generated - ' . ($invoiceDetails['invoice_number'] ?? 'Invoice');
    $toEmail = $user['email'] ?? '';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        appLog('WARN', 'Invalid email address for invoice email', ['email' => $toEmail]);
        return false;
    }

    return sendEmail($toEmail, $subject, $html, $user['id'] ?? null, [
        'template_type' => 'invoice_generated',
        'invoice_number' => $invoiceDetails['invoice_number'] ?? '',
    ]);
}

function sendSubscriptionRenewalReminderEmail(PDO $pdo, array $user, array $subscriptionDetails): bool
{
    $config = getMailConfig();
    $html = renderEmailTemplate('subscription_renewal_reminder', [
        'user_name' => $user['name'] ?? '',
        'plan_name' => $subscriptionDetails['plan_name'] ?? '',
        'expiry_date' => $subscriptionDetails['expiry_date'] ?? '',
        'amount' => $subscriptionDetails['amount_inr'] ?? 0,
        'company_name' => $config['company_name'],
        'support_email' => $config['company_support_email'],
    ]);

    if (!$html) {
        appLog('WARN', 'Failed to render renewal reminder email template');
        return false;
    }

    $subject = 'Subscription Renewal Reminder - ' . $config['company_name'];
    $toEmail = $user['email'] ?? '';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        appLog('WARN', 'Invalid email address for renewal email', ['email' => $toEmail]);
        return false;
    }

    return sendEmail($toEmail, $subject, $html, $user['id'] ?? null, [
        'template_type' => 'subscription_renewal_reminder',
        'plan_name' => $subscriptionDetails['plan_name'] ?? '',
    ]);
}

function sendSubscriptionExpiryWarningEmail(PDO $pdo, array $user, array $subscriptionDetails): bool
{
    $config = getMailConfig();
    $html = renderEmailTemplate('subscription_expiry_warning', [
        'user_name' => $user['name'] ?? '',
        'plan_name' => $subscriptionDetails['plan_name'] ?? '',
        'company_name' => $config['company_name'],
        'support_email' => $config['company_support_email'],
    ]);

    if (!$html) {
        appLog('WARN', 'Failed to render expiry warning email template');
        return false;
    }

    $subject = 'Your ' . $config['company_name'] . ' Subscription Has Expired';
    $toEmail = $user['email'] ?? '';

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        appLog('WARN', 'Invalid email address for expiry email', ['email' => $toEmail]);
        return false;
    }

    return sendEmail($toEmail, $subject, $html, $user['id'] ?? null, [
        'template_type' => 'subscription_expiry_warning',
        'plan_name' => $subscriptionDetails['plan_name'] ?? '',
    ]);
}
