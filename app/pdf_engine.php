<?php

use Dompdf\Dompdf;

function generatePDF($html)
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('Dompdf\\Dompdf')) {
        require_once __DIR__ . '/helpers/report_fallback_export_helper.php';
        $pdfPath = createFallbackPdf((string) $html, 'Financial Statements');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="financial_statements.pdf"');
        header('Content-Length: ' . filesize($pdfPath));
        readfile($pdfPath);
        unlink($pdfPath);
        return;
    }

    $dompdf = new Dompdf();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');

    $dompdf->render();

    $dompdf->stream("financial_statements.pdf", ["Attachment" => true]);
}
