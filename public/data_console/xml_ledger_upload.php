<?php
require_once '../../config/database.php';
require_once '../../app/context_check.php';
require_once '../../app/helpers/xml_sanitizer.php';

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

if (!isset($_FILES['ledger_xml']) || $_FILES['ledger_xml']['error'] !== UPLOAD_ERR_OK) {
    die("Ledger XML upload failed");
}

$rawXml = file_get_contents($_FILES['ledger_xml']['tmp_name']);
$rawXml = sanitizeTallyXML($rawXml);

libxml_use_internal_errors(true);
$xml = simplexml_load_string($rawXml);

if (!$xml) {
    $errors = array_map(static function ($error) {
        return trim($error->message);
    }, libxml_get_errors());
    libxml_clear_errors();
    die("Invalid Ledger XML" . (!empty($errors) ? ': ' . implode('; ', $errors) : ''));
}

/* CLEAR OLD */
$pdo->prepare("DELETE FROM tally_ledger_master WHERE company_id=?")
    ->execute([$company_id]);

$stmt = $pdo->prepare("
INSERT INTO tally_ledger_master (company_id, ledger_name, parent_group)
VALUES (?, ?, ?)
");

/* PARSE LEDGER XML */
$ledgers = $xml->xpath("//*[local-name()='LEDGER']");
$count = 0;

foreach ($ledgers as $l) {

    $name = trim((string) ($l['NAME'] ?? $l->NAME));
    $parent = trim((string)$l->PARENT);

    if (!$name) continue;

    $stmt->execute([$company_id, $name, $parent]);
    $count++;
}

/* WORKFLOW UPDATE */
$pdo->prepare("
INSERT INTO workflow_status (company_id, fy_id, ledger_fetched, updated_at)
VALUES (?, ?, 1, NOW())
ON DUPLICATE KEY UPDATE ledger_fetched=1, updated_at=NOW()
")->execute([$company_id, $fy_id]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['success'] = "Ledger master uploaded successfully ({$count} ledgers).";

header("Location: tally_offline.php");
exit;
