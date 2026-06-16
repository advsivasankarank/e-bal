<?php
require_once '../../app/session_bootstrap.php';
require_once '../../config/database.php';
require_once '../../app/helpers/plan_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: company_list.php");
    exit;
}

requireCsrfToken();

$id = (int) ($_POST['id'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = $userId > 0 ? getOwnerUserId($pdo, $userId) : 0;

if ($id <= 0) {
    header("Location: company_list.php?error=invalid_company");
    exit;
}

if ($ownerId > 0) {
    $scopeStmt = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND owner_user_id = ?");
    $scopeStmt->execute([$id, $ownerId]);
    if (!$scopeStmt->fetchColumn()) {
        header("Location: company_list.php?error=invalid_company");
        exit;
    }
}

$pdo->beginTransaction();

try {
    $cleanupTables = [
        'workflow_status',
        'tally_ledgers',
        'tally_ledger_master',
        'ledger_mapping',
        'report_manual_inputs',
    ];

    foreach ($cleanupTables as $table) {
        try {
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE company_id = ?");
            $stmt->execute([$id]);
        } catch (Throwable $e) {
            // Ignore missing legacy tables so company deletion still succeeds.
        }
    }

    if ($ownerId > 0) {
        $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ? AND owner_user_id = ?");
        $stmt->execute([$id, $ownerId]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
        $stmt->execute([$id]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header("Location: company_list.php?error=delete_failed");
    exit;
}

if ((int) ($_SESSION['company_id'] ?? 0) === $id) {
    unset($_SESSION['company_id'], $_SESSION['company_name'], $_SESSION['fy_id'], $_SESSION['fy_name']);
}

header("Location: company_list.php?deleted=1");
exit;
