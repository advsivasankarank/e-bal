<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/engines/ai_mapping_engine.php';

requireFullContext();
requireCsrfToken();

$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];
$userId = (int) ($_SESSION['user_id'] ?? 0);
$allowOverride = isset($_POST['allow_override']) && (string) $_POST['allow_override'] === '1';
ensureLedgerMappingOverrideColumn($pdo);
ensureMappingAiSchema($pdo);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory, $pdo, (int) $company_id);

if (isset($_POST['mapping_data'])) {
    $decoded = json_decode((string) $_POST['mapping_data'], true);
    if (is_array($decoded)) {
        $_POST['mapping'] = $decoded;
    }
}

if (!isset($_POST['mapping'])) {
    $_SESSION['error'] = "No mapping data";
    header("Location: mapping_console.php");
    exit;
}

$pdo->beginTransaction();

try {

    $stmt = $pdo->prepare("
        INSERT INTO ledger_mapping 
        (company_id, ledger_name, schedule_code, override_parent_group, mapping_source, confidence_score, mapping_reason, remember_scope, approved_by_user_id, approved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            schedule_code=VALUES(schedule_code),
            override_parent_group=VALUES(override_parent_group),
            mapping_source=VALUES(mapping_source),
            confidence_score=VALUES(confidence_score),
            mapping_reason=VALUES(mapping_reason),
            remember_scope=VALUES(remember_scope),
            approved_by_user_id=VALUES(approved_by_user_id),
            approved_at=VALUES(approved_at)
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
        $ledger = trim((string) $ledger);
        $head = trim((string) $head);
        if ($ledger === '' || $head === '') {
            continue;
        }

        $parentStmt->execute([$company_id, $ledger]);
        $parentGroup = (string) ($parentStmt->fetchColumn() ?: '');
        $rememberScope = strtolower(trim((string) ($_POST['remember_scope'][$ledger] ?? '')));
        if (!in_array($rememberScope, ['', 'company', 'global'], true)) {
            $rememberScope = '';
        }

        $suggestion = $mappingEngine->mapLedger($ledger, $parentGroup);
        $suggestedHead = trim((string) ($suggestion['head'] ?? ''));
        $suggestionMatched = $suggestedHead !== '' && $suggestedHead === $head && $suggestedHead !== 'unmapped';
        $mappingSource = $suggestionMatched
            ? ('ai_' . (string) ($suggestion['source'] ?? ($suggestion['method'] ?? 'rule')))
            : 'manual';
        $confidenceScore = $suggestionMatched ? (float) ($suggestion['confidence'] ?? 0) : 100.0;
        $mappingReason = $suggestionMatched
            ? (string) ($suggestion['reason'] ?? 'Accepted AI/rule suggestion.')
            : 'Manual override selected by user.';

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
            $allowOverride ? 1 : 0,
            $mappingSource,
            $confidenceScore,
            $mappingReason,
            $rememberScope !== '' ? $rememberScope : null,
            $userId > 0 ? $userId : null,
        ]);

        if ($rememberScope !== '') {
            saveMappingLearning($pdo, (int) $company_id, $ledger, $parentGroup, (string) $head, $rememberScope, $userId > 0 ? $userId : null);
        }
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

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
if ($returnTo === 'mapping_workbench') {
    header("Location: mapping_workbench.php");
} else {
    header("Location: mapping_console.php");
}
exit;
