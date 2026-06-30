<?php
/**
 * e-BAL — AJAX Mapping Export Endpoint
 *
 * Exports ledger mapping data to XLSX using PhpSpreadsheet.
 * GET request. Validates session, company, FY.
 *
 * Query params:
 *   filter = all|unmapped|mapped|high_confidence|low_confidence
 */

require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/engines/ai_mapping_engine.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireFullContext();

/* Validate CSRF via header (GET endpoint cannot use requireCsrfToken) */
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isValidCsrfToken($csrfToken)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
    exit;
}

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);

if ($company_id <= 0 || $fy_id <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid context.']);
    exit;
}

ensureMappingAiSchema($pdo);

$companyStmt = $pdo->prepare("SELECT category, name FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
$companyCategory = strtolower((string) ($company['category'] ?? 'corporate'));
$companyName = $company['name'] ?? 'Company';

$mappingEngine = new AIMappingEngine($companyCategory, $pdo, $company_id);
$mappingOptions = $mappingEngine->getMappingOptions();

$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
if (!in_array($filter, ['all', 'unmapped', 'mapped', 'high_confidence', 'low_confidence'], true)) {
    $filter = 'all';
}

// Load trial balance data for amounts
$tbStmt = $pdo->prepare("
    SELECT ledger_name,
           SUM(CASE WHEN dr_cr = 'DR' THEN amount ELSE 0 END) AS closing_dr,
           SUM(CASE WHEN dr_cr = 'CR' THEN amount ELSE 0 END) AS closing_cr,
           SUM(opening_amount) AS opening_balance
    FROM tally_ledgers
    WHERE company_id = ? AND fy_id = ?
    GROUP BY ledger_name
");
$tbStmt->execute([$company_id, $fy_id]);
$tbData = [];
foreach ($tbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tbData[$row['ledger_name']] = $row;
}

// Load all ledgers with mapping
$hasHierarchyCols = false;
try {
    $chkStmt = $pdo->query("SHOW COLUMNS FROM tally_ledger_master LIKE 'tally_group_path'");
    $hasHierarchyCols = $chkStmt->rowCount() > 0;
} catch (Throwable $e) { /* ignore */ }

$ledgerStmt = $pdo->prepare("
    SELECT
        t.ledger_name,
        t.parent_group,
        " . ($hasHierarchyCols ? "
        COALESCE(tlm.primary_group, '') AS primary_group,
        " : "'' AS primary_group,") . "
        lm.schedule_code AS mapped_code,
        lm.confidence_score,
        lm.mapping_source,
        lm.mapping_reason
    FROM tally_ledger_master t
    LEFT JOIN tally_ledger_master tlm ON tlm.company_id = t.company_id AND tlm.ledger_name = t.parent_group
    LEFT JOIN ledger_mapping lm ON lm.company_id = t.company_id AND lm.ledger_name = t.ledger_name
    WHERE t.company_id = ?
    ORDER BY t.ledger_name
");
$ledgerStmt->execute([$company_id]);
$ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

// Apply filter
$filtered = [];
foreach ($ledgers as $row) {
    $isMapped = !empty($row['mapped_code']);
    $confidence = (int) ($row['confidence_score'] ?? 0);

    switch ($filter) {
        case 'unmapped':
            if ($isMapped) continue 2;
            break;
        case 'mapped':
            if (!$isMapped) continue 2;
            break;
        case 'high_confidence':
            if ($confidence < 90) continue 2;
            break;
        case 'low_confidence':
            if ($confidence >= 70 || $isMapped) continue 2;
            break;
    }
    $filtered[] = $row;
}

// Generate XLSX
$vendorPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($vendorPath)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'PhpSpreadsheet library not available.']);
    exit;
}

require_once $vendorPath;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Ledger Mapping');

// Headers
$headers = [
    'S.No.',
    'Ledger Name',
    'Ledger Alias',
    'Tally Group',
    'Primary Group',
    'Opening Balance',
    'Debit Total',
    'Credit Total',
    'Closing Balance',
    'Dr/Cr',
    'Current Mapping',
    'Schedule Label',
    'Suggested Group',
    'Confidence %',
    'Final Group',
    'Schedule',
    'Sub-Schedule',
    'Status',
    'Remarks',
];

$col = 1;
foreach ($headers as $header) {
    $cell = $sheet->getCellByColumnAndRow($col, 1);
    $cell->setValue($header);
    $cell->getStyle()->getFont()->setBold(true);
    $col++;
}

$sheet->getStyle('A1:S1')->getFill()
    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
    ->getStartColor()->setRGB('E8F0FE');

$rowNum = 2;
foreach ($filtered as $idx => $ledger) {
    $name = $ledger['ledger_name'];
    $tb = $tbData[$name] ?? null;

    $opening = $tb ? (float) $tb['opening_balance'] : 0;
    $closingDr = $tb ? (float) $tb['closing_dr'] : 0;
    $closingCr = $tb ? (float) $tb['closing_cr'] : 0;
    $closing = $closingDr - $closingCr;
    $drcr = $closing >= 0 ? 'DR' : 'CR';

    $mappedCode = $ledger['mapped_code'] ?? '';
    $scheduleLabel = $mappedCode !== '' ? ($mappingOptions[$mappedCode] ?? ucwords(str_replace('_', ' ', $mappedCode))) : '';
    $confidence = (int) ($ledger['confidence_score'] ?? 0);
    $status = $mappedCode !== '' ? 'Mapped' : 'Unmapped';

    $sheet->setCellValueByColumnAndRow(1, $rowNum, $idx + 1);
    $sheet->setCellValueByColumnAndRow(2, $rowNum, $name);
    $sheet->setCellValueByColumnAndRow(3, $rowNum, '');  // alias (not in DB)
    $sheet->setCellValueByColumnAndRow(4, $rowNum, $ledger['parent_group'] ?? '');
    $sheet->setCellValueByColumnAndRow(5, $rowNum, $ledger['primary_group'] ?? '');
    $sheet->setCellValueByColumnAndRow(6, $rowNum, $opening);
    $sheet->setCellValueByColumnAndRow(7, $rowNum, $closingDr);
    $sheet->setCellValueByColumnAndRow(8, $rowNum, $closingCr);
    $sheet->setCellValueByColumnAndRow(9, $rowNum, $closing);
    $sheet->setCellValueByColumnAndRow(10, $rowNum, $drcr);
    $sheet->setCellValueByColumnAndRow(11, $rowNum, $mappedCode);
    $sheet->setCellValueByColumnAndRow(12, $rowNum, $scheduleLabel);
    $sheet->setCellValueByColumnAndRow(13, $rowNum, '');  // suggested (computed client-side)
    $sheet->setCellValueByColumnAndRow(14, $rowNum, $confidence);
    $sheet->setCellValueByColumnAndRow(15, $rowNum, $mappedCode);  // final = current
    $sheet->setCellValueByColumnAndRow(16, $rowNum, '');
    $sheet->setCellValueByColumnAndRow(17, $rowNum, '');
    $sheet->setCellValueByColumnAndRow(18, $rowNum, $status);
    $sheet->setCellValueByColumnAndRow(19, $rowNum, $ledger['mapping_reason'] ?? '');

    $rowNum++;
}

// Auto-size columns
for ($c = 1; $c <= count($headers); $c++) {
    $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
}

// Output
$filename = 'ledger_mapping_' . preg_replace('/[^a-zA-Z0-9]/', '_', $companyName) . '_' . date('Y-m-d') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
