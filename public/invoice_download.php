<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/invoice_helper.php';
require_once __DIR__ . '/../app/helpers/download_error_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    exitWithDownloadError('You must be signed in to download invoices.', 401, BASE_URL . 'login.php', 'Sign In');
}

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if (!$invoiceNumber) {
    exitWithDownloadError('Invoice number required.', 400, BASE_URL . 'invoice_history.php', 'Back to Invoices');
}

try {
    ensurePlanTables($pdo);
    ensureInvoiceTables($pdo);

    // Verify invoice belongs to current user
    $stmt = $pdo->prepare("
        SELECT i.* FROM invoices i
        WHERE i.invoice_number = ? AND i.user_id = ?
    ");
    $stmt->execute([$invoiceNumber, $userId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        exitWithDownloadError('Invoice not found.', 404, BASE_URL . 'invoice_history.php', 'Back to Invoices');
    }

    $pdfPath = $invoice['pdf_path'] ?? null;
    if (!$pdfPath || !file_exists($pdfPath)) {
        // Generate PDF on-the-fly if not stored
        $planStmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $planStmt->execute([$invoice['plan_id']]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            exitWithDownloadError('Plan information not found.', 500, BASE_URL . 'invoice_history.php', 'Back to Invoices');
        }

        $pdfPath = generateInvoicePDF($pdo, $invoice, $plan);
        if (!$pdfPath) {
            exitWithDownloadError('Failed to generate invoice PDF.', 500, BASE_URL . 'invoice_history.php', 'Back to Invoices');
        }

        // Update PDF path in database
        updateInvoiceStatus($pdo, $invoice['id'], 'issued', $pdfPath);
    }

    if (!file_exists($pdfPath)) {
        exitWithDownloadError('Invoice file not found.', 500, BASE_URL . 'invoice_history.php', 'Back to Invoices');
    }

    // Send PDF to browser
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $invoiceNumber . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private');
    
    readfile($pdfPath);
    exit;
} catch (Throwable $e) {
    appLog('ERROR', 'Invoice download failed', [
        'invoice_number' => $invoiceNumber,
        'user_id' => $userId,
        'error' => $e->getMessage(),
    ]);

    exitWithDownloadError('Error processing invoice download.', 500, BASE_URL . 'invoice_history.php', 'Back to Invoices');
}
