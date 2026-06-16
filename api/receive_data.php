<?php
require_once '../config/database.php';
require_once '../app/session_bootstrap.php';
require_once '../app/helpers/xml_sanitizer.php';
require_once '../config/app.php';
require_once '../app/helpers/runtime_helper.php';

if (defined('ENV') && ENV !== 'local') {
    http_response_code(404);
    exit;
}

$raw = file_get_contents("php://input");
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    exit("No input received");
}

/* SANITIZE */
$raw = sanitizeTallyXML($raw);

/* PARSE */
libxml_use_internal_errors(true);
$xml = simplexml_load_string($raw);

if ($xml === false) {
    echo "XML Parse Error\n";
    foreach (libxml_get_errors() as $e) {
        echo $e->message . "\n";
    }
    exit;
}

/* ========= EXTRACT LEDGERS ========= */
$ledgers = $xml->xpath("//*[local-name()='LEDGER']");
if (!$ledgers || count($ledgers) === 0) {
    http_response_code(400);
    exit("No ledger data found");
}

/* ========= CONTEXT ========= */
if (!isset($_SESSION['company_id'], $_SESSION['fy_id'])) {
    http_response_code(400);
    exit("Session context missing");
}
$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];

/* ========= DB WRITE ========= */
$pdo->beginTransaction();

try {
    $pdo->prepare("DELETE FROM tally_ledger_master WHERE company_id=?")
        ->execute([$company_id]);

    $stmt = $pdo->prepare("
        INSERT INTO tally_ledger_master
        (company_id, ledger_name, parent_group)
        VALUES (?, ?, ?)
    ");

    $count = 0;
    foreach ($ledgers as $l) {
        $name   = trim((string)$l['NAME']);
        $parent = trim((string)$l->PARENT);

        if ($name === '') continue;

        $stmt->execute([$company_id, $name, $parent]);
        $count++;
    }

    $pdo->prepare("
        INSERT INTO workflow_status
        (company_id, fy_id, ledger_fetched, tally_fetched, updated_at)
        VALUES (?, ?, 1, 1, NOW())
        ON DUPLICATE KEY UPDATE
            ledger_fetched = 1,
            tally_fetched  = 1,
            updated_at     = NOW()
    ")->execute([$company_id, $fy_id]);

    $pdo->commit();
    echo "SUCCESS: {$count} ledgers inserted";

} catch (Exception $e) {
    $pdo->rollBack();
    appLog('ERROR', 'Legacy receive_data import failed', ['message' => $e->getMessage()]);
    echo "DB ERROR: Please check logs";
}
