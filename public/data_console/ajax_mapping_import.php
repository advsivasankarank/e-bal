<?php
/**
 * e-BAL — AJAX Mapping Import Endpoint
 *
 * Accepts XLSX upload, validates rows, returns validation results.
 * On confirm action, saves valid rows via transaction.
 *
 * POST (multipart/form-data):
 *   file = XLSX file
 *   action = validate | save
 *   csrf_token = ...
 *
 * Returns JSON only.
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/engines/ai_mapping_engine.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Early session check to return JSON instead of redirect on expiry */
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id']) || empty($_SESSION['fy_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.', 'redirect' => true]);
    exit;
}

requireFullContext();
requireCsrfToken(true);

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);
$userId     = (int) ($_SESSION['user_id'] ?? 0);

if ($company_id <= 0 || $fy_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid context.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$action = strtolower(trim((string) ($_POST['action'] ?? 'validate')));
if (!in_array($action, ['validate', 'save'], true)) {
    $action = 'validate';
}

// Check file upload
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error.']);
    exit;
}

// File size limit (10 MB)
$maxSize = 10 * 1024 * 1024;
if ($_FILES['file']['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 10 MB.']);
    exit;
}

$uploadedFile = $_FILES['file']['tmp_name'];

// Validate file type by extension + zip signature (not fileinfo -- that
// extension isn't guaranteed to be compiled into every PHP build; confirmed
// missing in production, causing a fatal "Class finfo not found").
$filename = $_FILES['file']['name'] ?? '';
$extension = strtolower(substr($filename, strrpos($filename, '.') + 1));
$fileHandle = fopen($uploadedFile, 'rb');
$signature = $fileHandle !== false ? fread($fileHandle, 4) : '';
if ($fileHandle !== false) {
    fclose($fileHandle);
}
if ($extension !== 'xlsx' || $signature !== "PK\x03\x04") {
    echo json_encode(['success' => false, 'error' => 'Only XLSX files are allowed.']);
    exit;
}

$vendorPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!file_exists($vendorPath)) {
    echo json_encode(['success' => false, 'error' => 'PhpSpreadsheet library not available.']);
    exit;
}

require_once $vendorPath;

use PhpOffice\PhpSpreadsheet\IOFactory;

try {
    $spreadsheet = IOFactory::load($uploadedFile);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
} catch (Throwable $e) {
    appLog('ERROR', 'XLSX import parse failed', ['message' => $e->getMessage()]);
    echo json_encode(['success' => false, 'error' => 'Failed to read XLSX file. Please check the file format.']);
    exit;
}

if (count($rows) < 2) {
    echo json_encode(['success' => false, 'error' => 'File is empty or has no data rows.']);
    exit;
}

// Parse headers from first row
$headerRow = $rows[1];
$headers = [];
$colMap = [];

$expectedHeaders = [
    'ledger_name' => ['ledger name', 'ledger_name', 'name'],
    'final_group' => ['final group', 'final_group', 'schedule_code', 'mapping', 'mapped_group'],
    'remarks' => ['remarks', 'remark', 'notes', 'comment'],
];

foreach ($headerRow as $col => $value) {
    $normalized = strtolower(trim((string) $value));
    foreach ($expectedHeaders as $field => $aliases) {
        if (in_array($normalized, $aliases, true)) {
            $colMap[$field] = $col;
            break;
        }
    }
}

if (empty($colMap['ledger_name']) || empty($colMap['final_group'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Required columns not found. Must have "Ledger Name" and "Final Group" columns.',
        'found_headers' => array_values($headerRow),
    ]);
    exit;
}

// Validate all ledgers belong to this company
$companyLedgers = [];
$ledgerStmt = $pdo->prepare("SELECT ledger_name FROM tally_ledger_master WHERE company_id = ?");
$ledgerStmt->execute([$company_id]);
while ($row = $ledgerStmt->fetch(PDO::FETCH_ASSOC)) {
    $companyLedgers[$row['ledger_name']] = true;
}

// Valid schedule codes
$validCodes = [];
$codeStmt = $pdo->query("SELECT code FROM schedule_heads");
while ($row = $codeStmt->fetch(PDO::FETCH_ASSOC)) {
    $validCodes[$row['code']] = true;
}
// Also include non-corporate codes
$validCodes = array_merge($validCodes, array_fill_keys([
    'non_current_assets', 'current_assets', 'equity', 'borrowings',
    'current_liabilities', 'expenses',
], true));

// Process rows
$validRows = [];
$invalidRows = [];
$dataRows = array_slice($rows, 1); // skip header

foreach ($dataRows as $rowIdx => $row) {
    $excelRowNum = $rowIdx + 2; // 1-indexed, after header
    $ledgerName = trim((string) ($row[$colMap['ledger_name']] ?? ''));
    $finalGroup = trim((string) ($row[$colMap['final_group']] ?? ''));
    $remarks = trim((string) ($row[$colMap['remarks']] ?? ''));

    if ($ledgerName === '' && $finalGroup === '') {
        continue; // skip empty rows
    }

    $rowErrors = [];

    if ($ledgerName === '') {
        $rowErrors[] = 'Ledger name is empty.';
    } elseif (!isset($companyLedgers[$ledgerName])) {
        $rowErrors[] = 'Ledger "' . $ledgerName . '" not found in this company.';
    }

    if ($finalGroup === '') {
        $rowErrors[] = 'Final group is empty.';
    } elseif (!isset($validCodes[$finalGroup])) {
        $rowErrors[] = 'Invalid schedule code: "' . $finalGroup . '". Valid codes: ' . implode(', ', array_keys($validCodes));
    }

    if (!empty($rowErrors)) {
        $invalidRows[] = [
            'row' => $excelRowNum,
            'ledger_name' => $ledgerName,
            'final_group' => $finalGroup,
            'errors' => $rowErrors,
        ];
    } else {
        $validRows[] = [
            'ledger_name' => $ledgerName,
            'final_group' => $finalGroup,
            'remarks' => $remarks,
        ];
    }
}

// If validation mode, return results
if ($action === 'validate') {
    echo json_encode([
        'success' => true,
        'valid_count' => count($validRows),
        'invalid_count' => count($invalidRows),
        'valid_rows' => $validRows,
        'invalid_rows' => array_slice($invalidRows, 0, 50), // limit for response size
        'total_rows' => count($validRows) + count($invalidRows),
    ]);
    exit;
}

// Save mode
if (count($invalidRows) > 0) {
    echo json_encode([
        'success' => false,
        'error' => count($invalidRows) . ' rows have validation errors. Fix them before saving.',
        'invalid_rows' => array_slice($invalidRows, 0, 20),
    ]);
    exit;
}

if (empty($validRows)) {
    echo json_encode(['success' => false, 'error' => 'No valid rows to save.']);
    exit;
}

ensureMappingAiSchema($pdo);
ensureLedgerMappingOverrideColumn($pdo);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory, $pdo, $company_id);

$saveStmt = $pdo->prepare("
    INSERT INTO ledger_mapping
    (company_id, ledger_name, schedule_code, override_parent_group, mapping_source, confidence_score, mapping_reason, remember_scope, approved_by_user_id, approved_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        schedule_code = VALUES(schedule_code),
        override_parent_group = VALUES(override_parent_group),
        mapping_source = VALUES(mapping_source),
        confidence_score = VALUES(confidence_score),
        mapping_reason = VALUES(mapping_reason),
        remember_scope = VALUES(remember_scope),
        approved_by_user_id = VALUES(approved_by_user_id),
        approved_at = VALUES(approved_at)
");

$parentStmt = $pdo->prepare("SELECT parent_group FROM tally_ledger_master WHERE company_id = ? AND ledger_name = ? LIMIT 1");

$pdo->beginTransaction();
$saved = 0;

try {
    foreach ($validRows as $row) {
        $parentStmt->execute([$company_id, $row['ledger_name']]);
        $parentGroup = (string) ($parentStmt->fetchColumn() ?: '');

        $saveStmt->execute([
            $company_id,
            $row['ledger_name'],
            $row['final_group'],
            0, // no override from import
            'excel_import',
            100.0,
            'Imported from Excel file.' . ($row['remarks'] !== '' ? ' ' . $row['remarks'] : ''),
            null,
            $userId > 0 ? $userId : null,
        ]);
        $saved++;
    }

    // Check workflow
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tally_ledger_master t
        LEFT JOIN ledger_mapping lm ON lm.company_id = t.company_id AND lm.ledger_name = t.ledger_name
        WHERE t.company_id = ? AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
    ");
    $checkStmt->execute([$company_id]);
    $pendingCount = (int) $checkStmt->fetchColumn();

    if ($pendingCount === 0) {
        updateWorkflow($company_id, $fy_id, 'mapping_completed');
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'saved' => $saved,
        'pending' => $pendingCount,
        'mapping_complete' => ($pendingCount === 0),
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLog('ERROR', 'Excel import save failed', ['message' => $e->getMessage()]);
    echo json_encode(['success' => false, 'error' => 'Server error during save.']);
}
