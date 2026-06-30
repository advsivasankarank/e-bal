<?php
/**
 * e-BAL — AJAX Mapping Save Endpoint
 *
 * Transaction-based bulk save for ledger mappings.
 * Returns JSON only. Validates CSRF, session, company, FY, and ledger ownership.
 *
 * POST body (JSON):
 *   { "mappings": { "ledger_name": "schedule_code", ... }, "overrides": { "ledger_name": 1, ... }, "remember": { "ledger_name": "company"|"global", ... } }
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/engines/ai_mapping_engine.php';

/* ---- Session & Auth ---- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

/* Early session check to return JSON instead of redirect on expiry */
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id']) || empty($_SESSION['fy_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.', 'redirect' => true]);
    exit;
}

requireFullContext();
requireCsrfToken(true);

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id      = (int) ($_SESSION['fy_id'] ?? 0);
$userId     = (int) ($_SESSION['user_id'] ?? 0);

if ($company_id <= 0 || $fy_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid company or financial year context.']);
    exit;
}

/* ---- Read JSON body ---- */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || empty($data['mappings'])) {
    echo json_encode(['success' => false, 'error' => 'No mapping data provided.']);
    exit;
}

$mappings  = $data['mappings'];
$overrides = is_array($data['overrides'] ?? null) ? $data['overrides'] : [];
$remember  = is_array($data['remember'] ?? null) ? $data['remember'] : [];

/* ---- Schema check ---- */
ensureMappingAiSchema($pdo);
ensureLedgerMappingOverrideColumn($pdo);

/* ---- Load AI engine for metadata ---- */
$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory, $pdo, $company_id);

/* ---- Validate all ledgers belong to this company ---- */
$ledgerNames = array_keys($mappings);
if (empty($ledgerNames)) {
    echo json_encode(['success' => false, 'error' => 'Empty mappings array.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ledgerNames), '?'));
$validStmt = $pdo->prepare("
    SELECT ledger_name FROM tally_ledger_master
    WHERE company_id = ? AND ledger_name IN ($placeholders)
");
$validParams = array_merge([$company_id], $ledgerNames);
$validStmt->execute($validParams);
$validLedgers = $validStmt->fetchAll(PDO::FETCH_COLUMN);

$invalidLedgers = array_diff($ledgerNames, $validLedgers);
if (!empty($invalidLedgers)) {
    echo json_encode([
        'success' => false,
        'error' => 'Some ledgers do not belong to this company.',
        'invalid_ledgers' => array_values($invalidLedgers),
    ]);
    exit;
}

/* ---- Parent group lookup ---- */
$parentStmt = $pdo->prepare("
    SELECT ledger_name, parent_group FROM tally_ledger_master
    WHERE company_id = ? AND ledger_name = ?
    LIMIT 1
");

/* ---- Save statement ---- */
$saveStmt = $pdo->prepare("
    INSERT INTO ledger_mapping
    (company_id, ledger_name, schedule_code, override_parent_group, mapping_source, confidence_score, mapping_reason, remember_scope, approved_by_user_id, approved_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        schedule_code = VALUES(schedule_code),
        override_parent_group = VALUES(override_parent_group),
        mapping_source = VALUES(mapping_source),
        confidence_score = VALUES(confidence_score),
        mapping_reason = VALUES(mapping_reason),
        remember_scope = VALUES(remember_scope),
        approved_by_user_id = VALUES(approved_by_user_id),
        approved_at = VALUES(approved_at)
");

/* ---- Begin transaction ---- */
$pdo->beginTransaction();
$saved = 0;
$errors = [];
$conflicts = [];

try {
    foreach ($mappings as $ledger => $head) {
        $ledger = trim((string) $ledger);
        $head = trim((string) $head);

        if ($ledger === '' || $head === '') {
            continue;
        }

        $parentStmt->execute([$company_id, $ledger]);
        $parentGroup = (string) ($parentStmt->fetchColumn() ?: '');

        $isOverride = !empty($overrides[$ledger]);
        $rememberScope = isset($remember[$ledger]) ? strtolower(trim($remember[$ledger])) : '';
        if (!in_array($rememberScope, ['', 'company', 'global'], true)) {
            $rememberScope = '';
        }

        // Determine mapping source
        $suggestion = null;
        try {
            $suggestion = $mappingEngine->mapLedger($ledger, $parentGroup);
        } catch (Throwable $e) {
            /* ignore */
        }

        $suggestedHead = $suggestion ? trim((string) ($suggestion['head'] ?? '')) : '';
        $suggestionMatched = $suggestedHead !== '' && $suggestedHead === $head && $suggestedHead !== 'unmapped';
        $mappingSource = $suggestionMatched
            ? ('ai_' . (string) ($suggestion['source'] ?? $suggestion['method'] ?? 'rule'))
            : 'bulk_manual';
        $confidenceScore = $suggestionMatched ? (float) ($suggestion['confidence'] ?? 0) : 100.0;
        $mappingReason = $suggestionMatched
            ? (string) ($suggestion['reason'] ?? 'Accepted AI/rule suggestion via bulk workbench.')
            : 'Manually mapped via bulk workbench.';

        // Parent group validation
        if (!isScheduleCodeAllowedForParentGroup($parentGroup, $head)) {
            $conflicts[] = [
                'ledger_name' => $ledger,
                'parent_group' => $parentGroup,
                'schedule_code' => $head,
            ];
            if (!$isOverride) {
                $errors[] = $ledger . ': parent group conflict (use override to force).';
                continue;
            }
        }

        $saveStmt->execute([
            $company_id,
            $ledger,
            $head,
            $isOverride ? 1 : 0,
            $mappingSource,
            $confidenceScore,
            $mappingReason,
            $rememberScope !== '' ? $rememberScope : null,
            $userId > 0 ? $userId : null,
        ]);

        // Save learning
        if ($rememberScope !== '') {
            saveMappingLearning($pdo, $company_id, $ledger, $parentGroup, $head, $rememberScope, $userId > 0 ? $userId : null);
        }

        $saved++;
    }

    // Check workflow completion
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tally_ledger_master t
        LEFT JOIN ledger_mapping lm ON lm.company_id = t.company_id AND lm.ledger_name = t.ledger_name
        WHERE t.company_id = ? AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
    ");
    $checkStmt->execute([$company_id]);
    $pendingCount = (int) $checkStmt->fetchColumn();

    if ($pendingCount === 0) {
        updateWorkflow($company_id, $fy_id, 'mapping_completed');
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'saved' => $saved,
        'pending' => $pendingCount,
        'conflicts' => count($conflicts),
        'conflict_details' => array_slice($conflicts, 0, 10),
        'errors' => array_slice($errors, 0, 10),
        'mapping_complete' => ($pendingCount === 0),
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Bulk mapping save failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error during save. Please try again.']);
}
