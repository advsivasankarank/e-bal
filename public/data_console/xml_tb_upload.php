<?php
require_once '../../config/database.php';
require_once '../../app/context_check.php';
require_once '../../app/helpers/xml_sanitizer.php';

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

if (!isset($_FILES['tb_xml']) || $_FILES['tb_xml']['error'] !== UPLOAD_ERR_OK) {
    die("TB XML upload failed");
}

$rawXml = file_get_contents($_FILES['tb_xml']['tmp_name']);
$rawXml = sanitizeTallyXML($rawXml);

libxml_use_internal_errors(true);
$xml = simplexml_load_string($rawXml);

if (!$xml) {
    $errors = array_map(static function ($error) {
        return trim($error->message);
    }, libxml_get_errors());
    libxml_clear_errors();
    die("Invalid TB XML" . (!empty($errors) ? ': ' . implode('; ', $errors) : ''));
}

/* CLEAR OLD */
$pdo->prepare("DELETE FROM tally_ledgers WHERE company_id=? AND fy_id=?")
    ->execute([$company_id, $fy_id]);

$stmt = $pdo->prepare("
INSERT INTO tally_ledgers 
(company_id, fy_id, ledger_name, parent_group, amount, dr_cr)
VALUES (?, ?, ?, ?, ?, ?)
");

$parentStmt = $pdo->prepare("
SELECT parent_group FROM tally_ledger_master
WHERE company_id=? AND ledger_name=?
");

$drTotal = 0;
$crTotal = 0;
$count = 0;

foreach ($xml->xpath("//*[local-name()='DSPACCLINE']") as $node) {

    $name = trim((string)$node->dspaccname->dspdispname);
    if ($name === '') {
        continue;
    }

    $dr = (float)($node->dspaccinfo->dspcldramt->dspcldramta ?? 0);
    $cr = (float)($node->dspaccinfo->dspclcramt->dspclcramta ?? 0);

    if ($dr != 0) {
        $amount = abs($dr);
        $dr_cr = "DR";
    } elseif ($cr != 0) {
        $amount = abs($cr);
        $dr_cr = "CR";
    } else continue;

    $parentStmt->execute([$company_id, $name]);
    $parent = $parentStmt->fetchColumn() ?: "UNMAPPED";

    $stmt->execute([$company_id, $fy_id, $name, $parent, $amount, $dr_cr]);
    $count++;

    if ($dr_cr === 'DR') {
        $drTotal += $amount;
    } else {
        $crTotal += $amount;
    }
}

/* WORKFLOW UPDATE */
$pdo->prepare("
    INSERT INTO workflow_status (company_id, fy_id, tally_fetched, updated_at)
    VALUES (?, ?, 1, NOW())
    ON DUPLICATE KEY UPDATE tally_fetched=1, updated_at=NOW()
")->execute([$company_id, $fy_id]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['process_stats'] = [
    'total' => $count,
    'dr_total' => $drTotal,
    'cr_total' => $crTotal,
    'type' => 'xml'
];

if ($count === 0) {
    $_SESSION['error'] = "No valid trial balance rows found in the uploaded XML.";
}

header("Location: process_result.php");
exit;
