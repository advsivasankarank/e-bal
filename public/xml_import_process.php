<?php
session_start();

require_once __DIR__ . '/../app/bootstrap.php';

/* =====================================================
   STEP 1: VALIDATE POST INPUTS
===================================================== */

$company_id = intval($_POST['company_id'] ?? 0);
$fy_start   = trim($_POST['fy_start'] ?? '');
$fy_end     = trim($_POST['fy_end'] ?? '');

if (!$company_id || !$fy_start || !$fy_end) {
    die("Missing required fields. <a href='xml_import.php'>Go back</a>.");
}

// Verify company exists
$stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ?");
$stmt->execute([$company_id]);
if (!$stmt->fetchColumn()) {
    die("Invalid company. <a href='xml_import.php'>Go back</a>.");
}

/* =====================================================
   STEP 2: VALIDATE UPLOADED FILE
===================================================== */

if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['xml_file']['error'] ?? 'No file uploaded';
    die("File upload failed (error: $uploadError). <a href='xml_import.php'>Go back</a>.");
}

$tmpPath = $_FILES['xml_file']['tmp_name'];

/* =====================================================
   STEP 3: PARSE XML
===================================================== */

libxml_use_internal_errors(true);
$xmlObj = simplexml_load_file($tmpPath);

if ($xmlObj === false) {
    $errors = libxml_get_errors();
    $msg = !empty($errors) ? $errors[0]->message : 'Unknown XML error';
    die("Invalid XML file: " . htmlspecialchars($msg) . " <a href='xml_import.php'>Go back</a>.");
}

/* =====================================================
   STEP 4: AUTO-DETECT FY DATES FROM XML (if present)
   Falls back to form values if not found.
===================================================== */

// Tally sometimes embeds SVFROMDATE / SVTODATE in the export envelope
$xmlFromDate = (string)($xmlObj->HEADER->STATICVARIABLES->SVFROMDATE ?? '');
$xmlToDate   = (string)($xmlObj->HEADER->STATICVARIABLES->SVTODATE ?? '');

if ($xmlFromDate && strlen($xmlFromDate) === 8) {
    // Tally format: YYYYMMDD → PHP date: Y-m-d
    $fy_start = substr($xmlFromDate, 0, 4) . '-'
              . substr($xmlFromDate, 4, 2) . '-'
              . substr($xmlFromDate, 6, 2);
}

if ($xmlToDate && strlen($xmlToDate) === 8) {
    $fy_end = substr($xmlToDate, 0, 4) . '-'
            . substr($xmlToDate, 4, 2) . '-'
            . substr($xmlToDate, 6, 2);
}

/* =====================================================
   STEP 5: PARSE TRIAL BALANCE ENTRIES
   Uses identical structure to tally_fetch.php output.
===================================================== */

$data = [];

$names = $xmlObj->DSPACCNAME;
$infos = $xmlObj->DSPACCINFO;

$total = min(count($names), count($infos));

for ($i = 0; $i < $total; $i++) {

    $name = trim((string)$names[$i]->DSPDISPNAME);

    if ($name === '') continue;

    $dr = (float)$infos[$i]->DSPCLDRAMT->DSPCLDRAMTA;
    $cr = (float)$infos[$i]->DSPCLCRAMT->DSPCLCRAMTA;

    $amount = $cr != 0 ? $cr : $dr;

    if ($amount == 0) continue;

    $type = classifyLedgerType($name);

    $data[] = [
        'name'   => $name,
        'amount' => abs($amount),
        'type'   => $type,
    ];
}

if (empty($data)) {
    die("No trial balance entries found in the XML. Check the file format. <a href='xml_import.php'>Go back</a>.");
}

/* =====================================================
   STEP 6: FIND OR CREATE FINANCIAL YEAR ROW
===================================================== */

$stmt = $pdo->prepare(
    "SELECT id FROM financial_years WHERE company_id = ? AND fy_start = ? AND fy_end = ?"
);
$stmt->execute([$company_id, $fy_start, $fy_end]);
$fy_id = $stmt->fetchColumn();

if (!$fy_id) {
    $stmt = $pdo->prepare(
        "INSERT INTO financial_years (company_id, fy_start, fy_end) VALUES (?, ?, ?) RETURNING id"
    );
    $stmt->execute([$company_id, $fy_start, $fy_end]);
    $fy_id = $stmt->fetchColumn();
}

/* =====================================================
   STEP 7: UPSERT WORKFLOW STATUS
===================================================== */

$stmt = $pdo->prepare("SELECT id FROM workflow_status WHERE company_id = ? AND fy_id = ?");
$stmt->execute([$company_id, $fy_id]);
$wsId = $stmt->fetchColumn();

if ($wsId) {
    $pdo->prepare("UPDATE workflow_status SET tally_fetched = 1 WHERE company_id = ? AND fy_id = ?")
        ->execute([$company_id, $fy_id]);
} else {
    $pdo->prepare("INSERT INTO workflow_status (company_id, fy_id, tally_fetched) VALUES (?, ?, 1)")
        ->execute([$company_id, $fy_id]);
}

/* =====================================================
   STEP 8: STORE IN SESSION
===================================================== */

$_SESSION['classified_data'] = $data;
$_SESSION['company_id']      = $company_id;
$_SESSION['fy_id']           = $fy_id;

/* =====================================================
   REDIRECT TO MAPPING CONSOLE
===================================================== */

header("Location: mapping_console.php");
exit;

/* =====================================================
   HELPER: CLASSIFY LEDGER AS BALANCE SHEET OR P&L
   Uses keyword matching consistent with suggestMapping().
===================================================== */

function classifyLedgerType($ledger_name)
{
    $name = strtolower($ledger_name);

    $pl_keywords = [
        'sales', 'revenue', 'income', 'purchase', 'wages', 'salary',
        'depreciation', 'amortisation', 'amortization', 'expense', 'expenses',
        'cost of', 'charges', 'commission', 'discount allowed', 'discount received',
        'interest paid', 'interest on loan', 'bank interest', 'interest exp',
        'rent paid', 'rent exp', 'advertisement', 'postage', 'printing',
        'repairs', 'conveyance', 'travelling', 'profit & loss', 'profit and loss',
        'p & l', 'p&l', 'sundry exp', 'office exp', 'administrative',
        'selling exp', 'distribution', 'freight', 'carriage', 'audit fee',
        'legal fee', 'professional fee', 'bad debts', 'insurance exp',
    ];

    foreach ($pl_keywords as $keyword) {
        if (strpos($name, $keyword) !== false) {
            return 'P&L';
        }
    }

    return 'Balance Sheet';
}
