<?php

use Dompdf\Dompdf;

function generatePDF($html)
{
    require_once __DIR__ . '/../../vendor/autoload.php';

    $dompdf = new Dompdf();

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');

    $dompdf->render();

    $dompdf->stream("financial_statements.pdf", ["Attachment" => true]);
}