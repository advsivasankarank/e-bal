<?php
session_start();

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/core/mapping_engine.php';

$company_id = $_SESSION['company_id'] ?? 1;
$fy_id      = $_SESSION['fy_id'] ?? null;

if (!empty($_POST['mapping'])) {

    foreach ($_POST['mapping'] as $ledger => $code) {

        if ($code == '') continue;

        saveMapping($pdo, $company_id, $ledger, $code);
    }
}

/* 🔷 UPDATE WORKFLOW STATUS */
if ($fy_id) {
    $stmt = $pdo->prepare("SELECT id FROM workflow_status WHERE company_id = ? AND fy_id = ?");
    $stmt->execute([$company_id, $fy_id]);
    $wsId = $stmt->fetchColumn();

    if ($wsId) {
        $pdo->prepare("UPDATE workflow_status SET mapping_completed = 1 WHERE company_id = ? AND fy_id = ?")
            ->execute([$company_id, $fy_id]);
    } else {
        $pdo->prepare("INSERT INTO workflow_status (company_id, fy_id, mapping_completed) VALUES (?, ?, 1)")
            ->execute([$company_id, $fy_id]);
    }
}

header("Location: index.php");
exit;