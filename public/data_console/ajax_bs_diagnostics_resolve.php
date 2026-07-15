<?php
/**
 * e-BAL — Balance Sheet Diagnostics Resolve Endpoint
 *
 * POST /api/financials/bs-diagnostics/resolve
 * Body: { entity_id, fy_id, issue_id, action: 'apply_suggested'|'manual', note_id? }
 *
 * Only parent_group_conflict issues are resolvable here (the only type with
 * a safe programmatic fix — missing_note_heading / validation_error /
 * validation_warning issues route to "Review manually" in the UI instead).
 * Every applied fix is logged to bs_diagnostics_audit_log with before/after
 * state, and the BS build is re-run so the response always reflects the
 * real, current residual — never a claim that the sheet is now "balanced".
 */

header('Content-Type: application/json; charset=utf-8');

require_once '../../app/context_check.php';
require_once '../../config/database.php';
require_once '../../app/helpers/bs_diagnostics_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

$requestedEntityId = isset($data['entity_id']) ? (int) $data['entity_id'] : $company_id;
$requestedFyId = isset($data['fy_id']) ? (int) $data['fy_id'] : $fy_id;
if ($requestedEntityId !== $company_id || $requestedFyId !== $fy_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'entity_id/fy_id does not match the active session context.']);
    exit;
}

$issueId = trim((string) ($data['issue_id'] ?? ''));
$action = trim((string) ($data['action'] ?? ''));
$noteId = trim((string) ($data['note_id'] ?? ''));

if ($issueId === '' || !in_array($action, ['apply_suggested', 'manual'], true)) {
    echo json_encode(['success' => false, 'error' => 'issue_id and a valid action are required.']);
    exit;
}

ensureBsDiagnosticsAuditSchema($pdo);
ensureLedgerMappingOverrideColumn($pdo);

try {
    /* Re-derive the issue from a fresh build — never trust a stale
       client-supplied ledger_name/schedule_code pair beyond looking it
       up by issue_id. */
    $diagnostics = buildBsDiagnostics($pdo, $company_id, $fy_id);
    $issue = null;
    foreach ($diagnostics['issues'] as $candidate) {
        if (($candidate['issue_id'] ?? '') === $issueId) {
            $issue = $candidate;
            break;
        }
    }

    if ($issue === null) {
        echo json_encode(['success' => false, 'error' => 'Issue not found — it may already be resolved. Refresh and try again.']);
        exit;
    }

    if (($issue['type'] ?? '') !== 'parent_group_conflict') {
        echo json_encode(['success' => false, 'error' => 'This issue type has no automatic fix. Use Review Manually instead.']);
        exit;
    }

    $ledgerName = (string) ($issue['ledger_name'] ?? '');
    $beforeNote = (string) ($issue['current_note'] ?? '');

    if ($action === 'apply_suggested') {
        if (!($issue['auto_fixable'] ?? false) || empty($issue['suggested_note'])) {
            echo json_encode(['success' => false, 'error' => 'This conflict has no single unambiguous suggested note. Choose one manually.']);
            exit;
        }
        $afterNote = (string) $issue['suggested_note'];
    } else {
        if ($noteId === '') {
            echo json_encode(['success' => false, 'error' => 'note_id is required for a manual fix.']);
            exit;
        }
        $candidateIds = array_column($issue['suggested_note_candidates'] ?? [], 'note_id');
        if (!empty($issue['suggested_note']) && $candidateIds === []) {
            $candidateIds[] = $issue['suggested_note'];
        }
        if (!in_array($noteId, $candidateIds, true)) {
            echo json_encode(['success' => false, 'error' => 'Selected note does not match this ledger\'s Tally-group nature. Choose one of the offered candidates.']);
            exit;
        }
        $afterNote = $noteId;
    }

    /* Independent server-side re-verification: re-derive the ledger's parent
       group directly and confirm the chosen note's nature actually matches —
       never trust the candidate list alone (defense in depth). */
    $parentStmt = $pdo->prepare("SELECT parent_group FROM tally_ledger_master WHERE company_id = ? AND ledger_name = ? LIMIT 1");
    $parentStmt->execute([$company_id, $ledgerName]);
    $parentGroup = (string) ($parentStmt->fetchColumn() ?: '');
    $ledgerNature = normalizeParentGroupNature($parentGroup);
    $afterNoteNature = normalizeScheduleCodeNature($afterNote);
    if ($ledgerNature !== null && $afterNoteNature !== null && $ledgerNature !== $afterNoteNature) {
        echo json_encode(['success' => false, 'error' => 'Selected note nature does not match this ledger\'s Tally-group nature.']);
        exit;
    }

    $pdo->beginTransaction();

    $saveStmt = $pdo->prepare("
        INSERT INTO ledger_mapping (company_id, ledger_name, schedule_code, override_parent_group, mapping_source, mapping_reason, approved_by_user_id, approved_at)
        VALUES (?, ?, ?, 0, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            schedule_code = VALUES(schedule_code),
            override_parent_group = 0,
            mapping_source = VALUES(mapping_source),
            mapping_reason = VALUES(mapping_reason),
            approved_by_user_id = VALUES(approved_by_user_id),
            approved_at = VALUES(approved_at)
    ");
    $mappingSource = $action === 'apply_suggested' ? 'bs_diagnostics_auto' : 'bs_diagnostics_manual';
    $mappingReason = 'Applied from Balance Sheet diagnostics drill-down (issue ' . $issueId . ').';
    $saveStmt->execute([$company_id, $ledgerName, $afterNote, $mappingSource, $mappingReason, $userId > 0 ? $userId : null]);

    $auditStmt = $pdo->prepare("
        INSERT INTO bs_diagnostics_audit_log (company_id, fy_id, issue_id, action, ledger_name, before_note, after_note, user_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $auditStmt->execute([$company_id, $fy_id, $issueId, $action, $ledgerName, $beforeNote, $afterNote, $userId > 0 ? $userId : null]);

    $pdo->commit();

    /* Re-run the full build so the response always shows the real, current
       residual — fixing one issue can change the computed amounts of others. */
    $freshDiagnostics = buildBsDiagnostics($pdo, $company_id, $fy_id);

    echo json_encode([
        'success' => true,
        'resolved_issue_id' => $issueId,
        'ledger_name' => $ledgerName,
        'before_note' => $beforeNote,
        'after_note' => $afterNote,
        'diagnostics' => $freshDiagnostics,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('BS diagnostics resolve failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error while applying the fix. Please try again.']);
}
