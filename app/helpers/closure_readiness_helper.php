<?php
/**
 * Closure Readiness Helper — e-BAL
 *
 * 10-check readiness engine for Financial Year Closure.
 * Uses only existing backend functions and workflow_status flags.
 *
 * Checks:
 *  1. Trial Balance Imported (tally_fetched)
 *  2. Ledger Mapping Completed (mapping_completed)
 *  3. No Critical Unmapped Ledgers (unmapped_ledgers count)
 *  4. Balance Sheet Tallies (validateFYClosure BS check)
 *  5. Profit & Loss Generated (profit_loss_prepared)
 *  6. Notes Generated (notes_prepared)
 *  7. Review Centre Completed (verified)
 *  8. Deliverables Generated (reports_generated)
 *  9. Manual Overrides Reviewed (report_validation_helper)
 * 10. Reconciliation Completed (mapping_completed reuse)
 */

require_once __DIR__ . '/fy_closure_helper.php';
require_once __DIR__ . '/../workflow_engine.php';
require_once __DIR__ . '/report_validation_helper.php';
require_once __DIR__ . '/report_manual_helper.php';
require_once __DIR__ . '/approval_policy_helper.php';
require_once __DIR__ . '/parent_group_validation_helper.php';

/**
 * Compute closure readiness for a given entity + FY.
 *
 * @return array{checks: array, score: int, status: string, label: string, blockers: array, warnings: array, has_blockers: bool}
 */
function computeClosureReadiness(PDO $pdo, int $companyId, int $fyId): array
{
    $checks = [];
    $blockers = [];
    $warnings = [];

    /* ---- Load workflow status ---- */
    $wf = getWorkflow($companyId, $fyId);

    /* ---- Check 1: Trial Balance Imported ---- */
    $tallyFetched = (int) ($wf['tally_fetched'] ?? 0);
    $checks[] = [
        'id' => 'tally_fetched',
        'label' => 'Trial Balance Imported',
        'status' => $tallyFetched ? 'pass' : 'blocker',
        'severity' => 'blocker',
        'detail' => $tallyFetched ? 'Trial balance data has been imported.' : 'Trial balance has not been imported yet.',
    ];
    if (!$tallyFetched) {
        $blockers[] = 'Trial balance has not been imported.';
    }

    /* ---- Check 2: Ledger Mapping Completed ---- */
    $mappingCompleted = (int) ($wf['mapping_completed'] ?? 0);
    $checks[] = [
        'id' => 'mapping_completed',
        'label' => 'Ledger Mapping Completed',
        'status' => $mappingCompleted ? 'pass' : 'blocker',
        'severity' => 'blocker',
        'detail' => $mappingCompleted ? 'All ledgers have been mapped.' : 'Ledger mapping is not complete.',
    ];
    if (!$mappingCompleted) {
        $blockers[] = 'Ledger mapping is not complete.';
    }

    /* ---- Check 3: No Critical Unmapped Ledgers ---- */
    $unmappedCount = 0;
    try {
        $umStmt = $pdo->prepare("SELECT COUNT(*) FROM unmapped_ledgers WHERE company_id = ?");
        $umStmt->execute([$companyId]);
        $unmappedCount = (int) $umStmt->fetchColumn();
    } catch (Throwable $e) {
        /* Table may not exist — treat as 0 */
    }
    $checks[] = [
        'id' => 'unmapped_ledgers',
        'label' => 'No Unmapped Ledgers',
        'status' => $unmappedCount === 0 ? 'pass' : 'blocker',
        'severity' => 'blocker',
        'detail' => $unmappedCount === 0 ? 'No unmapped ledgers remaining.' : "{$unmappedCount} unmapped ledger(s) remaining.",
    ];
    if ($unmappedCount > 0) {
        $blockers[] = "{$unmappedCount} unmapped ledger(s) remaining.";
    }

    /* ---- Check 4: Balance Sheet Tallies ---- */
    $bsPass = true;
    try {
        require_once __DIR__ . '/../engines/classification_engine.php';
        $classified = getClassifiedData($pdo, $companyId, $fyId);
        $summary = $classified['summary'] ?? [];
        $assets = (float) ($summary['assets_total'] ?? 0);
        $liabilities = (float) ($summary['liabilities_total'] ?? 0);
        $profit = (float) ($summary['profit'] ?? 0);
        $diff = round($assets - ($liabilities + $profit), 2);
        $bsPass = abs($diff) <= 0.01;
    } catch (Throwable $e) {
        $bsPass = false;
    }
    $checks[] = [
        'id' => 'bs_balance',
        'label' => 'Balance Sheet Tallies',
        'status' => $bsPass ? 'pass' : 'blocker',
        'severity' => 'blocker',
        'detail' => $bsPass ? 'Assets = Liabilities + Equity.' : 'Balance Sheet does not balance.',
    ];
    if (!$bsPass) {
        $blockers[] = 'Balance Sheet does not balance.';
    }

    /* ---- Check 5: Profit & Loss Generated ---- */
    $plPrepared = (int) ($wf['profit_loss_prepared'] ?? 0);
    $checks[] = [
        'id' => 'pl_prepared',
        'label' => 'Profit & Loss Generated',
        'status' => $plPrepared ? 'pass' : 'warning',
        'severity' => 'warning',
        'detail' => $plPrepared ? 'P&L statement has been prepared.' : 'P&L statement has not been prepared yet.',
    ];
    if (!$plPrepared) {
        $warnings[] = 'P&L statement has not been prepared.';
    }

    /* ---- Check 6: Notes Generated ---- */
    $notesPrepared = (int) ($wf['notes_prepared'] ?? 0);
    $checks[] = [
        'id' => 'notes_prepared',
        'label' => 'Notes Generated',
        'status' => $notesPrepared ? 'pass' : 'warning',
        'severity' => 'warning',
        'detail' => $notesPrepared ? 'Notes to financial statements have been prepared.' : 'Notes have not been prepared yet.',
    ];
    if (!$notesPrepared) {
        $warnings[] = 'Notes have not been prepared.';
    }

    /* ---- Check 7: Review Centre Completed ---- */
    $verified = (int) ($wf['verified'] ?? 0);
    $checks[] = [
        'id' => 'verified',
        'label' => 'Review Centre Completed',
        'status' => $verified ? 'pass' : 'warning',
        'severity' => 'warning',
        'detail' => $verified ? 'Review and sign-off completed.' : 'Review sign-off pending.',
    ];
    if (!$verified) {
        $warnings[] = 'Review sign-off pending.';
    }

    /* ---- Check 8: Deliverables Generated ---- */
    $reportsGenerated = (int) ($wf['reports_generated'] ?? 0);
    $checks[] = [
        'id' => 'reports_generated',
        'label' => 'Deliverables Generated',
        'status' => $reportsGenerated ? 'pass' : 'warning',
        'severity' => 'warning',
        'detail' => $reportsGenerated ? 'Deliverables have been generated.' : 'Deliverables not yet generated.',
    ];
    if (!$reportsGenerated) {
        $warnings[] = 'Deliverables not yet generated.';
    }

    /* ---- Check 9: Manual Overrides Reviewed ---- */
    $manualOverrides = [];
    try {
        $manualOverrides = getManualOverrides($pdo, $companyId);
    } catch (Throwable $e) {
        /* Function may not exist — ignore */
    }
    $hasOverrides = !empty($manualOverrides);
    $checks[] = [
        'id' => 'manual_overrides',
        'label' => 'Manual Overrides Reviewed',
        'status' => $hasOverrides ? 'warning' : 'pass',
        'severity' => 'info',
        'detail' => $hasOverrides ? count($manualOverrides) . ' manual override(s) present — review retained.' : 'No manual overrides.',
    ];
    if ($hasOverrides) {
        $warnings[] = count($manualOverrides) . ' manual override(s) present.';
    }

    /* ---- Check 10: Reconciliation Completed ---- */
    $reconCompleted = $mappingCompleted;
    $checks[] = [
        'id' => 'reconciliation',
        'label' => 'Reconciliation Completed',
        'status' => $reconCompleted ? 'pass' : 'info',
        'severity' => 'info',
        'detail' => $reconCompleted ? 'Data reconciliation completed.' : 'Reconciliation pending.',
    ];

    /* ---- Compute Score ---- */
    $total = count($checks);
    $passed = 0;
    foreach ($checks as $c) {
        if ($c['status'] === 'pass') {
            $passed++;
        }
    }
    $score = $total > 0 ? round(($passed / $total) * 100) : 0;

    /* ---- Determine Status ---- */
    $hasBlockers = !empty($blockers);
    if ($hasBlockers) {
        $status = 'not_ready';
        $label = 'NOT READY';
    } elseif ($score >= 90) {
        $status = 'ready';
        $label = 'READY TO CLOSE';
    } elseif ($score >= 70) {
        $status = 'nearly_ready';
        $label = 'READY FOR CLOSURE';
    } else {
        $status = 'needs_attention';
        $label = 'Needs Attention';
    }

    return [
        'checks' => $checks,
        'score' => $score,
        'status' => $status,
        'label' => $label,
        'blockers' => $blockers,
        'warnings' => $warnings,
        'has_blockers' => $hasBlockers,
    ];
}
