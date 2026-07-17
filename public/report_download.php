<?php
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/fs_engine.php';
require_once __DIR__ . '/../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../app/helpers/report_document_helper.php';
require_once __DIR__ . '/../app/helpers/report_fallback_export_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/helpers/share_capital_helper.php';
require_once __DIR__ . '/../app/helpers/directors_report_ai_helper.php';
require_once __DIR__ . '/../app/helpers/readiness_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/download_error_helper.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
    require_once __DIR__ . '/../app/exporters/financial_statement_xlsx.php';
}
if (class_exists('PhpOffice\\PhpWord\\PhpWord')) {
    require_once __DIR__ . '/../app/exporters/financial_statement_docx.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;

requireFullContext();

require_once __DIR__ . '/../app/helpers/demo_helper.php';
$_isDemoExport = isDemoUser($pdo);

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
    exitWithDownloadError('Unsupported export format.', 400, BASE_URL . 'deliverables/index.php', 'Back to Deliverables');
}

$docType = strtolower(trim((string) ($_GET['doc'] ?? 'financial_statements')));
$allowedDocTypes = ['financial_statements', 'directors_report', 'management_observations'];

if (!in_array($docType, $allowedDocTypes, true)) {
    exitWithDownloadError('Unsupported document type.', 400, BASE_URL . 'deliverables/index.php', 'Back to Deliverables');
}

/* Directors' Report and the Management Observations Report have no
   spreadsheet form -- both are narrative text, not figures. */
if (in_array($docType, ['directors_report', 'management_observations'], true) && in_array($format, ['excel', 'xlsx'], true)) {
    exitWithDownloadError('This document is not available in Excel format.', 400, BASE_URL . 'deliverables/index.php', 'Back to Deliverables');
}

/* DEMO: Server-side enforcement — only allow PDF */
if ($_isDemoExport && in_array($format, ['word', 'docx', 'xlsx', 'excel', 'html'], true)) {
    exitWithDownloadError('Demo access allows PDF demo copy only. Please upgrade to generate final deliverables.', 403, BASE_URL . 'deliverables/index.php', 'Back to Deliverables');
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
    exitWithDownloadError('No report data available for export.', 404, BASE_URL . 'reports.php', 'Back to Financial Statements');
}

/* HARDENED: deliverables/index.php shows a "Delivery Blocked" banner using
   this exact same readiness/workflow logic, but that was UI-only -- nothing
   stopped a direct request to this endpoint from bypassing it. Re-check the
   same conditions here so the block is real, not advisory.
   The Management Observations Report is deliberately EXEMPT from both
   gates below -- it exists specifically to disclose issues like an
   unbalanced Balance Sheet to the client, so it must remain downloadable
   even when the statements themselves are blocked. */
if ($docType !== 'management_observations') {
    $readiness = computeReadiness($pdo, $company_id, $fy_id, $fs);
    if ($readiness['validation']['blocking'] ?? false) {
        $errorLines = array_map(
            static fn (array $e): string => '- ' . ($e['message'] ?? ''),
            $readiness['validation']['error_messages'] ?? []
        );
        exitWithDownloadError(
            "This report cannot be downloaded yet -- unresolved validation errors:\n" . implode("\n", $errorLines),
            403,
            BASE_URL . 'review/index.php',
            'Go to Review Centre'
        );
    }

    $workflow = getWorkflow($company_id, $fy_id);
    $blockingDR = ($fs['entity_category'] ?? '') === 'corporate' && ($workflow['directors_report_prepared'] ?? 0) != 1;
    if ($blockingDR) {
        exitWithDownloadError(
            "This report cannot be downloaded yet -- the Directors' Report has not been finalised. Complete and save it on the Directors Report page first.",
            403,
            BASE_URL . 'directors_report.php',
            'Go to Directors Report'
        );
    }
}

$subcategory = $fs['entity_subcategory'] ?? '';
if ($subcategory === 'trust') {
    $fs['format_template'] = __DIR__ . '/reports_dashboard/formats/trust_format.php';
    $fs['notes_template'] = __DIR__ . '/reports_dashboard/formats/notes_trust.php';
} elseif ($subcategory === 'society') {
    $fs['format_template'] = __DIR__ . '/reports_dashboard/formats/society_format.php';
    $fs['notes_template'] = __DIR__ . '/reports_dashboard/formats/notes_society.php';
}

$directorsReportPlace = trim((string) ($manualBundle['saved_current']['directors_report_place'] ?? ''));
$directorsReportDate = trim((string) ($manualBundle['saved_current']['directors_report_date'] ?? ''));
if ($directorsReportDate === '') {
    $directorsReportDate = date('d.m.Y');
}

if ($docType === 'management_observations') {
    require_once __DIR__ . '/../app/helpers/management_findings_helper.php';
    syncAutoDetectedFindings($pdo, $company_id, $fy_id);
    $includedFindings = getManagementFindings($pdo, $company_id, $fy_id, 'included');
    $recurringFindings = getRecurringUnresolvedFindings($pdo, $company_id, $fy_id);

    $documentLabel = 'management-observations';
    $title = 'Management Observations Report - ' . $companyName . ' - ' . $fyName;
    $htmlBody = renderManagementObservationsDocument($includedFindings, $recurringFindings, $companyName, $fyName, $fs['company_meta'] ?? []);
} elseif ($docType === 'directors_report') {
    if (($fs['entity_category'] ?? '') !== 'corporate') {
        exitWithDownloadError('Directors Report is only available for corporate entities.', 404, BASE_URL . 'reports.php', 'Back to Financial Statements');
    }

    $shareholders = getShareholders($pdo, $company_id, $fy_id);
    $loadedDirectorsReport = loadDirectorsReportSections($manualBundle, $fs, $companyName, $fyName, $shareholders);

    $documentLabel = 'directors-report';
    $title = "Directors' Report - " . $companyName . ' - ' . $fyName;
    $htmlBody = renderDirectorsReportDocument($loadedDirectorsReport['sections'], $companyName, $fyName, $fs['company_meta'] ?? [], $fs['data'] ?? [], $directorsReportPlace, $directorsReportDate);
} else {
    $directorsReportSections = [];
    if (($fs['entity_category'] ?? '') === 'corporate') {
        $shareholders = getShareholders($pdo, $company_id, $fy_id);
        $loadedDirectorsReport = loadDirectorsReportSections($manualBundle, $fs, $companyName, $fyName, $shareholders);
        $directorsReportSections = $loadedDirectorsReport['sections'];
    }

    $documentLabel = 'financial-statements';
    $title = ($fs['title'] ?? 'Financial Statements') . ' - ' . $companyName . ' - ' . $fyName;
    $htmlBody = renderFinancialReportDocument($fs, $companyName, $fyName, $directorsReportSections, $directorsReportPlace, $directorsReportDate);
}
$htmlDocument = wrapReportHtmlDocument($title, $htmlBody);

if ($format === 'html') {
    http_response_code(200);
    echo $htmlDocument;
    exit;
}

if ($format === 'pdf') {
    if (class_exists('Dompdf\\Dompdf')) {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);

        /* DEMO: Inject watermark into HTML before rendering */
        if ($_isDemoExport) {
            $demoWatermarkCss = getDemoWatermarkCss();
            $demoWatermarkHtml = getDemoWatermarkHtml();
            $htmlDocument = str_replace('</head>', '<style>' . $demoWatermarkCss . '</style></head>', $htmlDocument);
            $htmlDocument = str_replace('<body>', '<body>' . $demoWatermarkHtml, $htmlDocument);
        }

        $dompdf->loadHtml($htmlDocument, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        /* DEMO: Use DEMO-COPY filename */
        $exportFilename = $_isDemoExport
            ? buildDemoExportFilename($companyName, $fyName, 'pdf', $documentLabel)
            : buildReportExportFilename($companyName, $fyName, 'pdf', $documentLabel);

        $dompdf->stream($exportFilename, ['Attachment' => true]);
    } else {
        appLog('WARN', 'Dompdf not available, using fallback PDF export', ['company_id' => $company_id, 'fy_id' => $fy_id]);
        $pdfPath = createFallbackPdf($htmlDocument, $title);
        $exportFilename = $_isDemoExport
            ? buildDemoExportFilename($companyName, $fyName, 'pdf', $documentLabel)
            : buildReportExportFilename($companyName, $fyName, 'pdf', $documentLabel);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        header('X-eBAL-Export-Notice: Advanced export library unavailable. Basic export format generated.');
        readfile($pdfPath);
        unlink($pdfPath);
    }
    exit;
}

if (in_array($format, ['word', 'docx'], true)) {
    /* The advanced PhpWord exporter only knows the Financial Statements
       structure ($fs) -- Directors Report is narrative text, so it always
       goes through the generic HTML-to-DOCX fallback. */
    $useAdvancedDocx = $docType === 'financial_statements' && function_exists('exportFinancialStatementsToDocx');
    $docxPath = $useAdvancedDocx
        ? exportFinancialStatementsToDocx($fs, $companyName, $fyName)
        : createFallbackDocx($htmlDocument);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . buildReportExportFilename($companyName, $fyName, 'docx', $documentLabel) . '"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($docxPath));
    if (!$useAdvancedDocx) {
        appLog('WARN', 'PhpWord not available, using fallback DOCX export', ['company_id' => $company_id, 'fy_id' => $fy_id]);
        header('X-eBAL-Export-Notice: Advanced export library unavailable. Basic export format generated.');
    }
    readfile($docxPath);
    unlink($docxPath);
    exit;
}

if (in_array($format, ['excel', 'xlsx'], true)) {
    $useAdvancedXlsx = function_exists('exportFinancialStatementsToXlsx');
    $xlsxPath = $useAdvancedXlsx
        ? exportFinancialStatementsToXlsx($fs, $companyName, $fyName)
        : createFallbackXlsx($htmlDocument);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . buildReportExportFilename($companyName, $fyName, 'xlsx', $documentLabel) . '"');
    header('Cache-Control: max-age=0');
    header('Content-Length: ' . filesize($xlsxPath));
    if (!$useAdvancedXlsx) {
        appLog('WARN', 'PhpSpreadsheet not available, using fallback XLSX export', ['company_id' => $company_id, 'fy_id' => $fy_id]);
        header('X-eBAL-Export-Notice: Advanced export library unavailable. Basic export format generated.');
    }
    readfile($xlsxPath);
    unlink($xlsxPath);
    exit;
}
