<?php
require_once __DIR__ . '/runtime_helper.php';

/**
 * GST Invoice System Helper
 * Generates legally compliant tax invoices for India
 * Supports PDF generation, invoice tracking, and GST calculations
 */

const GST_RATE = 0.18;  // 18% total
const CGST_RATE = 0.09; // 9% Central GST
const SGST_RATE = 0.09; // 9% State GST
const INVOICE_SEQUENCE_PREFIX = 'INV-';

function ensureInvoiceTables(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        if (!appAllowsRuntimeSchema()) {
            assertTableExists($pdo, 'invoices');
            assertColumnExists($pdo, 'license_transactions', 'invoice_id');
            $checked = true;
            return;
        }

        // Main invoices table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                plan_id INT NOT NULL,
                license_transaction_id INT NULL,
                invoice_number VARCHAR(30) NOT NULL UNIQUE,
                invoice_date DATE NOT NULL,
                customer_name VARCHAR(255) NOT NULL,
                customer_email VARCHAR(255) NOT NULL,
                gstin VARCHAR(15) NULL,
                pan VARCHAR(10) NULL,
                taxable_value INT NOT NULL,
                cgst_amount INT NOT NULL,
                sgst_amount INT NOT NULL,
                igst_amount INT NOT NULL DEFAULT 0,
                total_value INT NOT NULL,
                pdf_path VARCHAR(500) NULL,
                status ENUM('draft','issued','paid','cancelled') NOT NULL DEFAULT 'draft',
                notes VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_invoice_user (user_id),
                INDEX idx_invoice_number (invoice_number),
                INDEX idx_invoice_date (invoice_date),
                INDEX idx_invoice_status (status)
            )
        ");

        // Add invoice_id column to license_transactions if it doesn't exist
        $txColumns = $pdo->query("SHOW COLUMNS FROM license_transactions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('invoice_id', $txColumns, true)) {
            $pdo->exec("ALTER TABLE license_transactions ADD COLUMN invoice_id INT NULL AFTER id");
        }

        $checked = true;
    } catch (Throwable $e) {
        appLog('ERROR', 'Invoice schema validation failed', ['message' => $e->getMessage()]);
        // Don't fail app on invoice schema issues
    }
}

function getCompanyInvoiceDetails(PDO $pdo): array
{
    $details = [
        'company_name' => getenv('INVOICE_COMPANY_NAME') ?: 'e-BAL India Pvt. Ltd.',
        'company_gstin' => getenv('INVOICE_COMPANY_GSTIN') ?: '',
        'company_pan' => getenv('INVOICE_COMPANY_PAN') ?: '',
        'company_address' => getenv('INVOICE_COMPANY_ADDRESS') ?: '',
        'company_state' => getenv('INVOICE_COMPANY_STATE') ?: 'Maharashtra',
        'company_phone' => getenv('INVOICE_COMPANY_PHONE') ?: '',
        'company_email' => getenv('INVOICE_COMPANY_EMAIL') ?: 'invoice@ebal.etaxadv.com',
        'company_website' => getenv('INVOICE_COMPANY_WEBSITE') ?: 'https://ebal.etaxadv.com',
    ];

    return $details;
}

function generateInvoiceNumber(PDO $pdo): string
{
    ensureInvoiceTables($pdo);

    $currentYear = date('Y');
    $prefix = INVOICE_SEQUENCE_PREFIX . $currentYear . '-';

    // Get the next sequence number for this year
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM invoices
        WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$prefix . '%']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextNumber = ((int) ($result['count'] ?? 0)) + 1;

    return $prefix . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
}

function calculateGST(int $baseAmountPaise): array
{
    // Convert from paise to rupees for calculation
    $baseAmount = $baseAmountPaise / 100;

    // For within-state supply: CGST + SGST
    $cgstAmount = $baseAmount * CGST_RATE;
    $sgstAmount = $baseAmount * SGST_RATE;
    $igstAmount = 0; // For inter-state, would be GST_RATE
    $totalGst = $cgstAmount + $sgstAmount + $igstAmount;
    $totalAmount = $baseAmount + $totalGst;

    return [
        'base_amount' => $baseAmountPaise,
        'base_amount_rupees' => $baseAmount,
        'cgst_amount_rupees' => $cgstAmount,
        'sgst_amount_rupees' => $sgstAmount,
        'igst_amount_rupees' => $igstAmount,
        'total_gst_rupees' => $totalGst,
        'total_amount_rupees' => $totalAmount,
        'cgst_paise' => (int) round($cgstAmount * 100),
        'sgst_paise' => (int) round($sgstAmount * 100),
        'igst_paise' => 0,
        'total_gst_paise' => (int) round($totalGst * 100),
        'total_amount_paise' => (int) round($totalAmount * 100),
    ];
}

function storeInvoice(
    PDO $pdo,
    int $userId,
    int $planId,
    ?int $licenseTransactionId,
    string $invoiceNumber,
    string $customerName,
    string $customerEmail,
    int $taxableValuePaise,
    int $cgstPaise,
    int $sgstPaise,
    int $totalValuePaise,
    ?string $gstin = null,
    ?string $pan = null,
    ?string $notes = null
): int {
    ensureInvoiceTables($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO invoices
            (user_id, plan_id, license_transaction_id, invoice_number, invoice_date, customer_name, customer_email, gstin, pan, taxable_value, cgst_amount, sgst_amount, igst_amount, total_value, status, notes)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $today = date('Y-m-d');
    $stmt->execute([
        $userId,
        $planId,
        $licenseTransactionId,
        $invoiceNumber,
        $today,
        $customerName,
        $customerEmail,
        $gstin,
        $pan,
        $taxableValuePaise,
        $cgstPaise,
        $sgstPaise,
        0,  // IGST for now
        $totalValuePaise,
        'draft',
        $notes,
    ]);

    return (int) $pdo->lastInsertId();
}

function updateInvoiceStatus(PDO $pdo, int $invoiceId, string $status, ?string $pdfPath = null): bool
{
    ensureInvoiceTables($pdo);

    $stmt = $pdo->prepare("
        UPDATE invoices
        SET status = ?, pdf_path = COALESCE(?, pdf_path)
        WHERE id = ?
    ");

    return $stmt->execute([$status, $pdfPath, $invoiceId]);
}

function getInvoiceById(PDO $pdo, int $invoiceId): ?array
{
    ensureInvoiceTables($pdo);

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $stmt->execute([$invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function getInvoiceByNumber(PDO $pdo, string $invoiceNumber): ?array
{
    ensureInvoiceTables($pdo);

    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_number = ?");
    $stmt->execute([$invoiceNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function getInvoicesForUser(PDO $pdo, int $userId, ?string $status = null): array
{
    ensureInvoiceTables($pdo);

    $sql = "SELECT * FROM invoices WHERE user_id = ?";
    $params = [$userId];

    if ($status !== null) {
        $sql .= " AND status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY invoice_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateInvoiceHTML(PDO $pdo, array $invoice, array $plan): string
{
    $company = getCompanyInvoiceDetails($pdo);

    // Format amounts
    $taxableValue = number_format($invoice['taxable_value'] / 100, 2);
    $cgstAmount = number_format($invoice['cgst_amount'] / 100, 2);
    $sgstAmount = number_format($invoice['sgst_amount'] / 100, 2);
    $totalValue = number_format($invoice['total_value'] / 100, 2);

    $gstin = htmlspecialchars($invoice['gstin'] ?? 'N/A', ENT_QUOTES);
    $pan = htmlspecialchars($invoice['pan'] ?? 'N/A', ENT_QUOTES);
    $invoiceNumber = htmlspecialchars($invoice['invoice_number'], ENT_QUOTES);
    $invoiceDate = date('d/m/Y', strtotime($invoice['invoice_date']));
    $customerName = htmlspecialchars($invoice['customer_name'], ENT_QUOTES);
    $planName = htmlspecialchars($plan['name'] ?? '', ENT_QUOTES);
    $companyName = htmlspecialchars($company['company_name'], ENT_QUOTES);
    $companyGstin = htmlspecialchars($company['company_gstin'], ENT_QUOTES);
    $companyPan = htmlspecialchars($company['company_pan'], ENT_QUOTES);
    $companyAddress = htmlspecialchars($company['company_address'], ENT_QUOTES);

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Invoice - {$invoiceNumber}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #333;
            margin: 20px;
            line-height: 1.4;
        }
        .invoice-container {
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #000;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .header h1 {
            margin: 0 0 5px 0;
            font-size: 16px;
        }
        .header p {
            margin: 0;
        }
        .invoice-title {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin: 15px 0;
        }
        .invoice-details {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        .detail-col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding: 5px;
        }
        .detail-box {
            border: 1px solid #999;
            padding: 8px;
            margin-bottom: 5px;
        }
        .detail-title {
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 5px;
        }
        .detail-item {
            margin: 2px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        td {
            border: 1px solid #000;
            padding: 8px;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .total-section {
            margin-top: 20px;
        }
        .gst-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .gst-table th,
        .gst-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: right;
        }
        .gst-table .label {
            text-align: left;
        }
        .footer {
            border-top: 2px solid #000;
            margin-top: 20px;
            padding-top: 15px;
            text-align: center;
            font-size: 10px;
        }
        .signature-section {
            display: table;
            width: 100%;
            margin-top: 30px;
        }
        .signature-col {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Header -->
        <div class="header">
            <h1>{$companyName}</h1>
            <p>Tax Invoice</p>
            <p>{$companyAddress}</p>
            <p>GSTIN: {$companyGstin} | PAN: {$companyPan}</p>
        </div>

        <div class="invoice-title">
            TAX INVOICE
        </div>

        <!-- Invoice Details -->
        <div class="invoice-details">
            <div class="detail-col">
                <div class="detail-box">
                    <div class="detail-title">Invoice Details</div>
                    <div class="detail-item"><strong>Invoice No:</strong> {$invoiceNumber}</div>
                    <div class="detail-item"><strong>Invoice Date:</strong> {$invoiceDate}</div>
                </div>
            </div>
            <div class="detail-col">
                <div class="detail-box">
                    <div class="detail-title">Bill To</div>
                    <div class="detail-item"><strong>{$customerName}</strong></div>
                    <div class="detail-item">GSTIN: {$gstin}</div>
                    <div class="detail-item">PAN: {$pan}</div>
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">HSN/SAC</th>
                    <th class="text-right">Quantity</th>
                    <th class="text-right">Unit Price (₹)</th>
                    <th class="text-right">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{$planName} Annual Subscription</td>
                    <td class="text-right">998399</td>
                    <td class="text-center">1</td>
                    <td class="text-right">{$taxableValue}</td>
                    <td class="text-right">{$taxableValue}</td>
                </tr>
            </tbody>
        </table>

        <!-- GST Calculation -->
        <div class="total-section">
            <table class="gst-table">
                <tr>
                    <td class="label"><strong>Subtotal (Taxable Value)</strong></td>
                    <td>₹ {$taxableValue}</td>
                </tr>
                <tr>
                    <td class="label"><strong>CGST (9%)</strong></td>
                    <td>₹ {$cgstAmount}</td>
                </tr>
                <tr>
                    <td class="label"><strong>SGST (9%)</strong></td>
                    <td>₹ {$sgstAmount}</td>
                </tr>
                <tr style="background-color: #e8e8e8;">
                    <td class="label"><strong style="font-size: 12px;">TOTAL AMOUNT PAYABLE</strong></td>
                    <td><strong style="font-size: 12px;">₹ {$totalValue}</strong></td>
                </tr>
            </table>
        </div>

        <!-- Terms and Conditions -->
        <div style="margin-top: 20px; font-size: 9px; border: 1px solid #999; padding: 10px;">
            <strong>Terms & Conditions:</strong>
            <ol style="margin: 5px 0; padding-left: 20px;">
                <li>This is a computer-generated invoice and does not require a physical signature.</li>
                <li>Payment must be made within 30 days of invoice date.</li>
                <li>For disputes or clarifications, contact support@ebal.etaxadv.com</li>
                <li>All taxes are as per GST regulations applicable in India.</li>
            </ol>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>For support: {$company['company_email']} | Website: {$company['company_website']}</p>
            <p>Generated on: {$invoiceDate} | This is an electronically generated document and valid without signature.</p>
        </div>

        <!-- Signature Section -->
        <div class="signature-section">
            <div class="signature-col">
                <p>Authorized Signatory</p>
                <p style="border-top: 1px solid #000; height: 40px; margin-top: 10px;"></p>
            </div>
            <div class="signature-col">
                <p>Buyer's Seal & Signature</p>
                <p style="border-top: 1px solid #000; height: 40px; margin-top: 10px;"></p>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

    return $html;
}

function generateInvoicePDF(PDO $pdo, array $invoice, array $plan): ?string
{
    try {
        // Check if dompdf is available
        if (!class_exists('Dompdf\Dompdf')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        }

        if (!class_exists('Dompdf\Dompdf')) {
            appLog('ERROR', 'Dompdf library not found');
            return null;
        }

        $html = generateInvoiceHTML($pdo, $invoice, $plan);
        if (!$html) {
            appLog('ERROR', 'Failed to generate invoice HTML');
            return null;
        }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Create invoices directory if not exists
        $invoiceDir = __DIR__ . '/../../invoices';
        if (!is_dir($invoiceDir)) {
            @mkdir($invoiceDir, 0755, true);
        }

        // Generate filename
        $filename = 'invoice_' . $invoice['invoice_number'] . '_' . date('Ymd_His') . '.pdf';
        $filepath = $invoiceDir . '/' . $filename;

        // Save PDF
        file_put_contents($filepath, $dompdf->output());

        if (file_exists($filepath)) {
            appLog('INFO', 'Invoice PDF generated', [
                'invoice_id' => $invoice['id'],
                'filepath' => $filepath,
            ]);
            return $filepath;
        }

        return null;
    } catch (Throwable $e) {
        appLog('ERROR', 'Invoice PDF generation failed', [
            'invoice_id' => $invoice['id'],
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}

function createAndIssueInvoice(
    PDO $pdo,
    int $userId,
    int $planId,
    ?int $licenseTransactionId,
    string $customerName,
    string $customerEmail,
    int $amountInPaise,
    ?string $gstin = null,
    ?string $pan = null
): ?array {
    try {
        ensureInvoiceTables($pdo);

        // Get plan details
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            appLog('WARN', 'Plan not found for invoice generation', ['plan_id' => $planId]);
            return null;
        }

        // Calculate GST
        $gstCalc = calculateGST($amountInPaise);

        // Generate invoice number
        $invoiceNumber = generateInvoiceNumber($pdo);

        // Store invoice
        $invoiceId = storeInvoice(
            $pdo,
            $userId,
            $planId,
            $licenseTransactionId,
            $invoiceNumber,
            $customerName,
            $customerEmail,
            (int) $gstCalc['base_amount_paise'],
            (int) $gstCalc['cgst_paise'],
            (int) $gstCalc['sgst_paise'],
            (int) $gstCalc['total_amount_paise'],
            $gstin,
            $pan
        );

        if ($invoiceId <= 0) {
            appLog('ERROR', 'Failed to store invoice in database');
            return null;
        }

        // Retrieve stored invoice
        $invoice = getInvoiceById($pdo, $invoiceId);
        if (!$invoice) {
            appLog('ERROR', 'Failed to retrieve stored invoice');
            return null;
        }

        // Generate PDF
        $pdfPath = generateInvoicePDF($pdo, $invoice, $plan);
        if ($pdfPath) {
            updateInvoiceStatus($pdo, $invoiceId, 'issued', $pdfPath);
            $invoice['pdf_path'] = $pdfPath;
        }

        // Update license transaction with invoice_id if provided
        if ($licenseTransactionId !== null) {
            $updateStmt = $pdo->prepare("UPDATE license_transactions SET invoice_id = ? WHERE id = ?");
            $updateStmt->execute([$invoiceId, $licenseTransactionId]);
        }

        $invoice['gst_details'] = $gstCalc;
        $invoice['plan'] = $plan;

        return $invoice;
    } catch (Throwable $e) {
        appLog('ERROR', 'Invoice creation and issuance failed', [
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}
