<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/report_document_helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
$allowedFormats = ['pdf', 'word', 'excel'];

if (!in_array($format, $allowedFormats, true)) {
    http_response_code(400);
    exit('Unsupported export format.');
}

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);
$fs = generateFinancialStatements(
    $pdo,
    $company_id,
    $fy_id,
    $fyName,
    $manualBundle['current'] ?? [],
    $manualBundle['previous'] ?? []
);

if (!($fs['has_data'] ?? false)) {
    http_response_code(404);
    exit('No report data available for export.');
}

$title = ($fs['title'] ?? 'Financial Statements') . ' - ' . $companyName . ' - ' . $fyName;
$htmlBody = renderFinancialReportDocument($fs, $companyName, $fyName);
$htmlDocument = wrapReportHtmlDocument($title, $htmlBody);

if ($format === 'pdf') {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($htmlDocument, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream(buildReportExportFilename($companyName, $fyName, 'pdf'), ['Attachment' => true]);
    exit;
}

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . buildReportExportFilename($companyName, $fyName, 'doc') . '"');
    header('Cache-Control: max-age=0');
    echo $htmlDocument;
    exit;
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . buildReportExportFilename($companyName, $fyName, 'xls') . '"');
header('Cache-Control: max-age=0');
echo $htmlDocument;
exit;
