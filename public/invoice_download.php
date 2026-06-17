<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/invoice_helper.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    die('Unauthorized');
}

$invoiceNumber = trim((string) ($_GET['invoice_number'] ?? ''));
if (!$invoiceNumber) {
    http_response_code(400);
    die('Invoice number required');
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
        http_response_code(404);
        die('Invoice not found');
    }

    $pdfPath = $invoice['pdf_path'] ?? null;
    if (!$pdfPath || !file_exists($pdfPath)) {
        // Generate PDF on-the-fly if not stored
        $planStmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $planStmt->execute([$invoice['plan_id']]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            http_response_code(500);
            die('Plan information not found');
        }

        $pdfPath = generateInvoicePDF($pdo, $invoice, $plan);
        if (!$pdfPath) {
            http_response_code(500);
            die('Failed to generate invoice PDF');
        }

        // Update PDF path in database
        updateInvoiceStatus($pdo, $invoice['id'], 'issued', $pdfPath);
    }

    if (!file_exists($pdfPath)) {
        http_response_code(500);
        die('Invoice file not found');
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

    http_response_code(500);
    die('Error processing invoice download');
}
