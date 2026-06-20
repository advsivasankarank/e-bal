<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/report_document_helper.php';
require_once __DIR__ . '/../app/exporters/financial_statement_xlsx.php';
require_once __DIR__ . '/../app/exporters/financial_statement_docx.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireFullContext();

/* HARDENED: Timeout and memory protection for large report generation */
set_time_limit(120);
ini_set('memory_limit', '256M');

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

$format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
$allowedFormats = ['pdf', 'word', 'excel', 'docx', 'xlsx', 'html'];

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

$subcategory = $fs['entity_subcategory'] ?? '';
if ($subcategory === 'trust') {
    $fs['format_template'] = __DIR__ . '/reports_dashboard/formats/trust_format.php';
    $fs['notes_template'] = __DIR__ . '/reports_dashboard/formats/notes_trust.php';
} elseif ($subcategory === 'society') {
    $fs['format_template'] = __DIR__ . '/reports_dashboard/formats/society_format.php';
    $fs['notes_template'] = __DIR__ . '/reports_dashboard/formats/notes_society.php';
}

$title = ($fs['title'] ?? 'Financial Statements') . ' - ' . $companyName . ' - ' . $fyName;
$htmlBody = renderFinancialReportDocument($fs, $companyName, $fyName);
$htmlDocument = wrapReportHtmlDocument($title, $htmlBody);

if ($format === 'html') {
    echo $htmlDocument;
    exit;
}

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

if (in_array($format, ['word', 'docx'], true)) {
    $docxPath = exportFinancialStatementsToDocx($fs, $companyName, $fyName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . buildReportExportFilename($companyName, $fyName, 'docx') . '"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($docxPath));
    readfile($docxPath);
    unlink($docxPath);
    exit;
}

if (in_array($format, ['excel', 'xlsx'], true)) {
    $xlsxPath = exportFinancialStatementsToXlsx($fs, $companyName, $fyName);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . buildReportExportFilename($companyName, $fyName, 'xlsx') . '"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($xlsxPath));
    readfile($xlsxPath);
    unlink($xlsxPath);
    exit;
}
