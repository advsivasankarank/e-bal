<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

if (empty($_POST['override']) || !is_array($_POST['override'])) {
    $_SESSION['error'] = "No override data submitted.";
    header("Location: mapping_console.php");
    exit;
}

foreach ($_POST['override'] as $ledger => $code) {

    if (!$code) continue;

    $pdo->prepare("
        INSERT INTO ledger_mapping (company_id, ledger_name, schedule_code)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            schedule_code=VALUES(schedule_code)
    ")->execute([$company_id, $ledger, $code]);
}

$checkStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM tally_ledgers t
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = t.company_id
        AND lm.ledger_name = t.ledger_name
    WHERE t.company_id = ? AND t.fy_id = ?
      AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
");
$checkStmt->execute([$company_id, $fy_id]);

if ((int) $checkStmt->fetchColumn() === 0) {
    updateWorkflow($company_id, $fy_id, 'mapping_completed');
}

$_SESSION['success'] = "Overrides saved successfully.";
header("Location: mapping_console.php?override=1");
exit;
