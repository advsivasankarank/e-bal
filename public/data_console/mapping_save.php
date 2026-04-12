<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/parent_group_validation_helper.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];
$allowOverride = isset($_POST['allow_override']) && (string) $_POST['allow_override'] === '1';
ensureLedgerMappingOverrideColumn($pdo);

if (!isset($_POST['mapping'])) {
    $_SESSION['error'] = "No mapping data";
    header("Location: mapping_console.php");
    exit;
}

$pdo->beginTransaction();

try {

    $stmt = $pdo->prepare("
        INSERT INTO ledger_mapping 
        (company_id, ledger_name, schedule_code, override_parent_group)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            schedule_code=VALUES(schedule_code),
            override_parent_group=VALUES(override_parent_group),
            schedule_code=VALUES(schedule_code)
    ");

    $parentStmt = $pdo->prepare("
        SELECT parent_group
        FROM tally_ledger_master
        WHERE company_id = ? AND ledger_name = ?
        LIMIT 1
    ");

    $conflicts = [];

    foreach ($_POST['mapping'] as $ledger => $head) {

        if (!$head) continue;

        $parentStmt->execute([$company_id, $ledger]);
        $parentGroup = (string) ($parentStmt->fetchColumn() ?: '');

        if (!isScheduleCodeAllowedForParentGroup($parentGroup, (string) $head)) {
            $conflicts[] = buildParentGroupConflict((string) $ledger, $parentGroup, (string) $head);
            if (!$allowOverride) {
                continue;
            }
        }

        $stmt->execute([
            $company_id,
            $ledger,
            $head,
            $allowOverride ? 1 : 0
        ]);
    }

    if ($conflicts !== [] && !$allowOverride) {
        $pdo->rollBack();
        $conflictMessages = array_map(static function (array $conflict): string {
            return $conflict['ledger_name'] . ' [' . ($conflict['parent_group'] !== '' ? $conflict['parent_group'] : 'No Parent Group') . '] cannot be mapped to ' . $conflict['schedule_code'];
        }, array_slice($conflicts, 0, 5));

        $_SESSION['error'] = 'Parent group conflict found. ' . implode('; ', $conflictMessages);
        $_SESSION['mapping_parent_group_conflicts'] = $conflicts;
        header("Location: mapping_console.php");
        exit;
    }

    $checkStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tally_ledger_master t
        LEFT JOIN ledger_mapping lm
            ON lm.company_id = t.company_id
            AND lm.ledger_name = t.ledger_name
        WHERE t.company_id = ?
          AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
    ");
    $checkStmt->execute([$company_id]);
    $pendingCount = (int) $checkStmt->fetchColumn();

    if ($pendingCount === 0) {
        updateWorkflow($company_id, $fy_id, 'mapping_completed');
    }

    $pdo->commit();

    $overrideNotice = '';
    if ($allowOverride && $conflicts !== []) {
        $overrideNotice = ' Parent group overrides were applied for ' . count($conflicts) . ' ledger(s).';
        $_SESSION['mapping_parent_group_conflicts'] = $conflicts;
    }

    $_SESSION['success'] = ($pendingCount === 0
        ? "Mapping saved successfully. Trial balance step is now unlocked."
        : "Mapping saved successfully. Complete the remaining ledger mappings to unlock the trial balance step.") . $overrideNotice;

} catch (Exception $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['error'] = $e->getMessage();
}

header("Location: mapping_console.php");
exit;
