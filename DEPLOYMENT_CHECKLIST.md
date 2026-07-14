# Email & Invoice System - Deployment Checklist

> **Note:** `app/sql/migrations/*.sql` (the numbered migrations, separate from
> this feature's schema file below) can now be applied with
> `php app/sql/migrate.php` from the repo root instead of running each file
> by hand — it tracks what's already been applied in a `schema_migrations`
> table and stops at the first failure rather than silently skipping ahead.
> Use `--dry-run` to preview what's pending. The steps below (for this
> specific email/invoice schema file) are unaffected and still apply as
> written.

## Phase 1: Database Setup

- [ ] Execute SQL migration:
  ```bash
  mysql -u etaxadv_ebaluser -p etaxadv_ebal < app/sql/email_invoice_schema.sql
  ```
  Or run in MySQL client:
  ```sql
  SOURCE app/sql/email_invoice_schema.sql;
  ```

- [ ] Verify tables created:
  ```sql
  SHOW TABLES LIKE 'email%';
  SHOW TABLES LIKE 'invoices';
  DESCRIBE email_log;
  DESCRIBE invoices;
  ```

- [ ] Verify license_transactions has invoice_id column:
  ```sql
  DESCRIBE license_transactions;
  ```

## Phase 2: Configuration Setup

Update `config/env.php` with:

### Email Configuration
```php
putenv('MAIL_TRANSPORT=php');  // or 'smtp'
putenv('MAIL_FROM_ADDRESS=noreply@ebal.etaxadv.com');
putenv('MAIL_FROM_NAME=e-BAL');
putenv('COMPANY_SUPPORT_EMAIL=support@ebal.etaxadv.com');

// If using SMTP:
putenv('MAIL_SMTP_HOST=smtp.gmail.com');      // or your provider
putenv('MAIL_SMTP_PORT=587');
putenv('MAIL_SMTP_USERNAME=your-email@gmail.com');
putenv('MAIL_SMTP_PASSWORD=your-app-password');
putenv('MAIL_SMTP_ENCRYPTION=tls');
```

### Invoice Configuration
```php
putenv('INVOICE_COMPANY_NAME=e-BAL India Pvt. Ltd.');
putenv('INVOICE_COMPANY_GSTIN=27AABCU9603R1Z5');  // Add real GSTIN
putenv('INVOICE_COMPANY_PAN=AABCU9603R');         // Add real PAN
putenv('INVOICE_COMPANY_ADDRESS=Address Line, City, State 400001');
putenv('INVOICE_COMPANY_STATE=Maharashtra');
putenv('INVOICE_COMPANY_PHONE=+91 22 XXXX XXXX');
putenv('INVOICE_COMPANY_EMAIL=invoice@ebal.etaxadv.com');
putenv('INVOICE_COMPANY_WEBSITE=https://ebal.etaxadv.com');
```

## Phase 3: Directory Setup

- [ ] Verify `/invoices` directory exists and is writable:
  ```bash
  ls -la invoices/
  chmod 755 invoices/
  ```

- [ ] Verify `/templates/email` directory exists:
  ```bash
  ls -la templates/email/
  ```

- [ ] Verify all email templates exist:
  - welcome.php
  - payment_success.php
  - invoice_generated.php
  - subscription_renewal_reminder.php
  - subscription_expiry_warning.php

## Phase 4: Dependencies Verification

- [ ] Check composer.json has dompdf:
  ```bash
  grep "dompdf" composer.json
  ```

- [ ] Verify vendor/autoload.php exists:
  ```bash
  ls -la vendor/autoload.php
  ```

- [ ] Check dompdf is installed:
  ```bash
  ls -la vendor/dompdf/
  ```

## Phase 5: Code Verification

- [ ] PHP syntax check on all new files:
  ```bash
  php -l app/helpers/mail_helper.php
  php -l app/helpers/invoice_helper.php
  php -l public/invoice_download.php
  ```

- [ ] Verify new functions in plan_helper.php:
  ```bash
  grep "require.*mail_helper" app/helpers/plan_helper.php
  grep "sendWelcomeEmail" app/helpers/plan_helper.php
  ```

## Phase 6: Integration Testing

### Test 1: Email Template Rendering
```php
require_once 'config/database.php';
require_once 'app/helpers/mail_helper.php';

$html = renderEmailTemplate('welcome', [
    'user_name' => 'Test User',
    'company_name' => 'e-BAL',
    'support_email' => 'support@test.com',
    'base_url' => 'https://ebal.etaxadv.com/',
]);

echo strlen($html) > 100 ? "✓ Template renders" : "✗ Template failed";
```

### Test 2: GST Calculation
```php
require_once 'app/helpers/invoice_helper.php';

$gst = calculateGST(100000);  // ₹1000 in paise
echo "Base: ₹" . $gst['base_amount_rupees'];
echo "CGST: ₹" . $gst['cgst_amount_rupees'];
echo "SGST: ₹" . $gst['sgst_amount_rupees'];
echo "Total: ₹" . $gst['total_amount_rupees'];
```

### Test 3: Invoice Number Generation
```php
require_once 'config/database.php';
require_once 'app/helpers/invoice_helper.php';

$invoiceNum = generateInvoiceNumber($pdo);
echo "Invoice Number: " . $invoiceNum;  // Should be: INV-2024-00001
```

### Test 4: User Signup with Welcome Email
1. Go to login page
2. Create new user account
3. Check email_log table: should have welcome email entry
4. Optionally: check email inbox if SMTP configured

### Test 5: Payment Success with Invoice
1. Create Razorpay payment link for a plan
2. Complete payment
3. Check database:
   - License created/updated
   - Invoice generated in invoices table
   - License transaction recorded
   - Email log shows payment success + invoice emails
4. Test invoice download:
   - Visit: `/public/invoice_download.php?invoice_number=INV-2024-00001`
   - Verify PDF downloads correctly

## Phase 7: Email Service Configuration (SMTP)

### Using Gmail:
1. Enable 2-factor authentication
2. Generate App Password (not regular password)
3. Use in config:
   ```php
   putenv('MAIL_SMTP_HOST=smtp.gmail.com');
   putenv('MAIL_SMTP_PORT=587');
   putenv('MAIL_SMTP_USERNAME=your-email@gmail.com');
   putenv('MAIL_SMTP_PASSWORD=generated-app-password');
   putenv('MAIL_SMTP_ENCRYPTION=tls');
   ```

### Using SendGrid:
1. Get API credentials from SendGrid
2. Configure:
   ```php
   putenv('MAIL_SMTP_HOST=smtp.sendgrid.net');
   putenv('MAIL_SMTP_PORT=587');
   putenv('MAIL_SMTP_USERNAME=apikey');
   putenv('MAIL_SMTP_PASSWORD=your-sendgrid-api-key');
   putenv('MAIL_SMTP_ENCRYPTION=tls');
   ```

### Using AWS SES:
Similar to SendGrid with AWS credentials

## Phase 8: Monitoring

### Monitor Email Delivery
```sql
-- Check email log
SELECT * FROM email_log ORDER BY created_at DESC LIMIT 10;

-- Check failed emails
SELECT * FROM email_log WHERE status = 'failed';

-- Check success rate
SELECT status, COUNT(*) FROM email_log GROUP BY status;
```

### Monitor Invoice Generation
```sql
-- Check invoices created
SELECT * FROM invoices ORDER BY created_at DESC LIMIT 10;

-- Check invoice status
SELECT status, COUNT(*) FROM invoices GROUP BY status;

-- Verify link to transactions
SELECT i.invoice_number, lt.amount_inr, i.total_value
FROM invoices i
JOIN license_transactions lt ON i.license_transaction_id = lt.id;
```

### Monitor Integration
```sql
-- Check license transactions with invoices
SELECT lt.id, lt.amount_inr, i.invoice_number, i.status
FROM license_transactions lt
LEFT JOIN invoices i ON lt.invoice_id = i.id
WHERE lt.payment_status = 'paid'
ORDER BY lt.created_at DESC
LIMIT 10;
```

## Phase 9: Error Handling

### If emails don't send:
1. Check `MAIL_TRANSPORT` setting
2. If using SMTP:
   - Verify MAIL_SMTP_HOST, PORT, USERNAME, PASSWORD
   - Check firewall allows SMTP port (usually 587 or 465)
   - Test with telnet: `telnet smtp.gmail.com 587`
3. Check email_log table for error details
4. Check application error logs

### If invoices don't generate:
1. Verify dompdf is installed: `vendor/dompdf/dompdf`
2. Check `/invoices` directory permissions
3. Check invoice_helper logs
4. Verify invoice_id column exists in license_transactions
5. Check disk space availability

### If PDF download fails:
1. Verify user authentication
2. Check invoice_number parameter is valid
3. Verify PDF file exists in `/invoices` directory
4. Check invoice belongs to logged-in user

## Phase 10: Production Safety Checks

- [ ] Email configuration uses secure SMTP
- [ ] No hardcoded credentials in code (all in config/env.php)
- [ ] /invoices directory not accessible via web
- [ ] Database backups configured
- [ ] Email log table has retention policy (optional)
- [ ] Error logs monitored
- [ ] SSL certificate valid for SMTP
- [ ] Rate limiting considered for email sends

## Rollback Plan

If issues occur:

1. **Keep backup of original files:**
   ```bash
   cp app/helpers/plan_helper.php app/helpers/plan_helper.php.backup
   ```

2. **Revert email/invoice from recordLicenseTransaction if needed:**
   - Remove email sending code
   - Keep invoice generation (for compliance)

3. **Database rollback:**
   - Keep backup of email_log, invoices tables
   - Can drop tables if needed

4. **Test rollback:**
   - Verify payments still process
   - Verify licenses still activate
   - Verify transactions still record

## Support & Debugging

### Enable Debug Logging
Add to config/env.php:
```php
putenv('APP_DEBUG=1');
```

### Check Application Logs
```bash
tail -f /var/log/ebal/error.log
# or check mail logs
tail -f /var/log/mail.log
```

### Test Email Function Directly
```php
<?php
require_once 'config/database.php';
require_once 'app/helpers/mail_helper.php';

$result = sendEmail(
    'test@example.com',
    'Test Subject',
    '<h1>Test Email</h1>',
    1,  // user_id
    ['template_type' => 'test']
);

echo $result ? "✓ Email sent" : "✗ Email failed";
?>
```

## Completion Verification

- [ ] All database tables exist
- [ ] All configuration values set
- [ ] All email templates present
- [ ] /invoices directory writable
- [ ] PHP syntax valid
- [ ] User signup sends welcome email
- [ ] Payment creates invoice
- [ ] Invoice download works
- [ ] Email log records messages
- [ ] Invoice records in database
- [ ] GST calculations correct
- [ ] No errors in application logs

---

**Date Completed:** _______________
**Deployed By:** _______________
**Production URL:** https://ebal.etaxadv.com/
**Next Review:** _______________
