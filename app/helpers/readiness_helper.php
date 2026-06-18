<?php
/**
 * Readiness Score Engine - Hardening Fix
 * Single source of truth for readiness computation.
 * Used by: Review Workspace, Deliverables Workspace, Assignment Home.
 *
 * Scoring: Validation 60% + Remarks 30% + Sign-Offs 10%
 *
 * @param PDO $pdo Database connection
 * @param int $company_id Company ID
 * @param int $fy_id Financial Year ID
 * @param array $fs Financial statements array (from generateFinancialStatements)
 * @return array Standardised readiness data
 */
function computeReadiness(PDO $pdo, int $company_id, int $fy_id, array $fs = []): array {
    // --- Validation Score (60%) ---
    require_once __DIR__ . '/report_validation_helper.php';
    $validationResult = validateReportGeneration($pdo, $company_id, $fy_id, $fs);
    $totalErrors = count($validationResult['errors'] ?? []);
    $totalWarnings = count($validationResult['warnings'] ?? []);
    $totalChecks = 7; // Base check count
    $failedChecks = $totalErrors + $totalWarnings;
    $passedChecks = max(0, $totalChecks - $failedChecks);
    $validationScore = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 60) : 60;

    // --- Remarks Score (30%) ---
    $remarkStmt = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'review_remark_%'");
    $remarkStmt->execute([$company_id, $fy_id]);
    $remarkRows = $remarkStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $totalRemarks = 0;
    $resolvedRemarks = 0;
    foreach ($remarkRows as $k => $v) {
        if (strpos($k, 'review_remark_text_') === 0) {
            $totalRemarks++;
            $sectionKey = substr($k, strlen('review_remark_text_'));
            $resolvedKey = 'review_remark_resolved_' . $sectionKey;
            if (($remarkRows[$resolvedKey] ?? '0') === '1') {
                $resolvedRemarks++;
            }
        }
    }
    $remarksScore = $totalRemarks > 0 ? round(($resolvedRemarks / $totalRemarks) * 30) : 30;

    // --- Sign-Off Score (10%) ---
    $signoffStmt = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'signoff_%_by'");
    $signoffStmt->execute([$company_id, $fy_id]);
    $signoffRows = $signoffStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $signedRoles = 0;
    $roles = ['staff', 'manager', 'partner'];
    foreach ($roles as $role) {
        $key = 'signoff_' . $role . '_by';
        if (!empty($signoffRows[$key])) {
            $signedRoles++;
        }
    }
    $signoffScore = round(($signedRoles / 3) * 10);

    // --- Total ---
    $readinessScore = $validationScore + $remarksScore + $signoffScore;

    // --- Status ---
    if ($readinessScore >= 100) {
        $status = 'ready';
        $label = 'Ready for Delivery';
    } elseif ($readinessScore >= 90) {
        $status = 'nearly_ready';
        $label = 'Nearly Ready';
    } elseif ($readinessScore >= 70) {
        $status = 'in_review';
        $label = 'In Review';
    } elseif ($readinessScore >= 50) {
        $status = 'needs_attention';
        $label = 'Needs Attention';
    } else {
        $status = 'not_ready';
        $label = 'Not Ready';
    }

    // --- Sign-off details ---
    $signoffDetails = [];
    foreach ($roles as $role) {
        $byKey = 'signoff_' . $role . '_by';
        $atKey = 'signoff_' . $role . '_at';
        $signoffDetails[$role] = [
            'signed' => !empty($signoffRows[$byKey]),
            'user_id' => (int)($signoffRows[$byKey] ?? 0),
            'timestamp' => $signoffRows[$atKey] ?? '',
        ];
    }

    // --- Unresolved remarks count ---
    $unresolvedRemarks = $totalRemarks - $resolvedRemarks;

    // --- Blocking status ---
    $blockingErrors = $totalErrors > 0;

    return [
        'score' => $readinessScore,
        'status' => $status,
        'label' => $label,
        'validation' => [
            'score' => $validationScore,
            'max' => 60,
            'errors' => $totalErrors,
            'warnings' => $totalWarnings,
            'passed' => $passedChecks,
            'total' => $totalChecks,
            'blocking' => $blockingErrors,
            'error_messages' => $validationResult['errors'] ?? [],
            'warning_messages' => $validationResult['warnings'] ?? [],
        ],
        'remarks' => [
            'score' => $remarksScore,
            'max' => 30,
            'total' => $totalRemarks,
            'resolved' => $resolvedRemarks,
            'unresolved' => $unresolvedRemarks,
        ],
        'signoffs' => [
            'score' => $signoffScore,
            'max' => 10,
            'signed_count' => $signedRoles,
            'required' => 3,
            'details' => $signoffDetails,
        ],
    ];
}
