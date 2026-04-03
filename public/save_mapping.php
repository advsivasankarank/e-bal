<?php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/core/mapping_engine.php';

$company_id = 1;

if (!empty($_POST['mapping'])) {

    foreach ($_POST['mapping'] as $ledger => $code) {

        if ($code == '') continue;

        saveMapping($pdo, $company_id, $ledger, $code);
    }
}

/* 🔷 UPDATE WORKFLOW STATUS */
$pdo->query("UPDATE workflow_status SET mapping_completed = 1");

header("Location: index.php");
exit;