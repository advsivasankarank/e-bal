<?php
/**
 * Workflow Engine — HARDENED
 *
 * H3 FIX: ensureWorkflowColumns() removed from runtime.
 *         DDL is now in migration script only.
 * H4 FIX: Fallback return uses correct current schema keys.
 * H5 FIX: verified field is NEVER set by updateWorkflow() directly.
 *         verified is derived by approval_policy_helper.php.
 */
require_once __DIR__ . '/../config/database.php';

function getWorkflow($company_id, $fy_id) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT * FROM workflow_status 
        WHERE company_id=? AND fy_id=?
    ");
    $stmt->execute([$company_id, $fy_id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'ledger_fetched' => 0,
            'mapping_completed' => 0,
            'tally_fetched' => 0,
            'notes_prepared' => 0,
            'profit_loss_prepared' => 0,
            'balance_sheet_prepared' => 0,
            'directors_report_prepared' => 0,
            'verified' => 0,
        ];
    }
    return $row;
}

function updateWorkflow($company_id, $fy_id, $field) {
    global $pdo;

    $fieldMap = [
        'data_imported' => 'tally_fetched',
        'mapping_done' => 'mapping_completed',
        'mapping_completed' => 'mapping_completed',
        'tally_fetched' => 'tally_fetched',
        'ledger_fetched' => 'ledger_fetched',
        'notes_prepared' => 'notes_prepared',
        'profit_loss_prepared' => 'profit_loss_prepared',
        'balance_sheet_prepared' => 'balance_sheet_prepared',
        'directors_report_prepared' => 'directors_report_prepared',
    ];

    $column = $fieldMap[$field] ?? null;
    if ($column === null) {
        throw new InvalidArgumentException("Invalid workflow field: {$field}");
    }

    $pdo->prepare("
        INSERT INTO workflow_status (company_id, fy_id, {$column}, updated_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE {$column}=1, updated_at=NOW()
    ")->execute([$company_id, $fy_id]);
}

/**
 * Single source of truth for marking/clearing notes_prepared,
 * profit_loss_prepared and balance_sheet_prepared from the current
 * validation state of a generated financial statement.
 *
 * statements/financials.php and public/reports.php are two separate,
 * parallel implementations of the Financial Statement Console that both
 * need to run this exact same recompute -- previously financials.php
 * cleared the flags on validation failure and reports.php silently left
 * them set, so a statement broken via one page could still show as
 * "prepared" via the other. Call this identically from both.
 */
function syncWorkflowFromValidation(
    PDO $pdo,
    $company_id,
    $fy_id,
    bool $hasReportData,
    array $validationResult,
    float $currentDiff,
    array $noteCompleteness
): void {
    if (!$hasReportData) {
        return;
    }

    $hasBlockingErrors = !empty($validationResult['errors']);
    $bsBalanced = abs($currentDiff) <= 0.01;
    $notesComplete = $noteCompleteness['is_complete'] ?? true;

    if (!$hasBlockingErrors && $bsBalanced && $notesComplete) {
        updateWorkflow($company_id, $fy_id, 'notes_prepared');
        updateWorkflow($company_id, $fy_id, 'profit_loss_prepared');
        updateWorkflow($company_id, $fy_id, 'balance_sheet_prepared');
        return;
    }

    $pdo->prepare("UPDATE workflow_status SET notes_prepared=0, profit_loss_prepared=0, balance_sheet_prepared=0, updated_at=NOW() WHERE company_id=? AND fy_id=?")
        ->execute([$company_id, $fy_id]);
}

/**
 * Set verified status. Called only by approval_policy_helper.php.
 * NEVER call updateWorkflow() with 'verified' directly.
 */
function setWorkflowVerified($company_id, $fy_id, bool $verified): void {
    global $pdo;
    $pdo->prepare("
        INSERT INTO workflow_status (company_id, fy_id, verified, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE verified=?, updated_at=NOW()
    ")->execute([$company_id, $fy_id, $verified ? 1 : 0, $verified ? 1 : 0]);
}
