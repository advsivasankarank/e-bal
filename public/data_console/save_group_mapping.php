<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id = $_SESSION['fy_id'];

$pdo->beginTransaction();

try {

    // 1) Save group mapping
    foreach ($_POST['mapping'] as $group => $code) {

        if (!$code) continue;

        $stmt = $pdo->prepare("
            INSERT INTO group_mapping (company_id, fy_id, group_name, mapped_code, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE mapped_code=VALUES(mapped_code), updated_at=NOW()
        ");

        $stmt->execute([$company_id, $fy_id, $group, $code]);
    }

    // 2) Auto-apply to ledgers (skip overrides)
    $stmt = $pdo->prepare("
        INSERT INTO ledger_mapping (company_id, fy_id, ledger_name, mapped_code, is_confirmed, updated_at)
        SELECT 
            t.company_id, t.fy_id, t.ledger_name, gm.mapped_code, 1, NOW()
        FROM tally_ledgers t
        JOIN group_mapping gm
            ON gm.group_name = t.parent_group
            AND gm.company_id = t.company_id
            AND gm.fy_id = t.fy_id
        LEFT JOIN ledger_mapping lm
            ON lm.ledger_name = t.ledger_name
            AND lm.company_id = t.company_id
            AND lm.fy_id = t.fy_id
        WHERE t.company_id=? AND t.fy_id=?
          AND (lm.is_override IS NULL OR lm.is_override = 0)
        ON DUPLICATE KEY UPDATE
            mapped_code = VALUES(mapped_code),
            is_confirmed = 1,
            updated_at = NOW()
    ");

    $stmt->execute([$company_id, $fy_id]);

    // 3) Check completeness
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tally_ledgers t
        LEFT JOIN ledger_mapping lm
            ON lm.ledger_name = t.ledger_name
            AND lm.company_id = t.company_id
            AND lm.fy_id = t.fy_id
        WHERE t.company_id=? AND t.fy_id=?
          AND (lm.mapped_code IS NULL OR lm.mapped_code = '')
    ");
    $stmt->execute([$company_id, $fy_id]);

    if ($stmt->fetchColumn() == 0) {
        updateWorkflow($company_id, $fy_id, 'mapping_done');
    }

    $pdo->commit();

    header("Location: mapping_console.php?saved=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Mapping save failed: " . $e->getMessage());
}