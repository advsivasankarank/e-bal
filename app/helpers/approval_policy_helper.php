<?php
/**
 * Approval Policy Helper — HARDENED
 *
 * H5 FIX: verified is NEVER set manually.
 *         verified is DERIVED from signoff data + review_policy.
 *
 * Policy modes:
 *   single     = staff signed → verified
 *   two_level  = staff signed AND manager signed → verified
 *   three_level = staff signed AND manager signed AND partner signed → verified
 *
 * Default: single (backward compatible with existing firms)
 */

require_once __DIR__ . '/../workflow_engine.php';
require_once __DIR__ . '/report_manual_helper.php';

/**
 * Get the review policy for a company.
 * Falls back to 'single' if not set (backward compatible).
 */
function getReviewPolicy(PDO $pdo, int $companyId): string
{
    $stmt = $pdo->prepare("SELECT review_policy FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $policy = (string) ($stmt->fetchColumn() ?: 'single');

    return in_array($policy, ['single', 'two_level', 'three_level'], true) ? $policy : 'single';
}

/**
 * Get signoff data for a company+FY.
 */
function getApprovalSignoffData(PDO $pdo, int $companyId, int $fyId): array
{
    return loadManualInputsByPrefix($pdo, $companyId, $fyId, 'signoff_');
}

/**
 * Compute verified status from signoff data + review policy.
 * This is the SINGLE SOURCE OF TRUTH for verified status.
 */
function computeVerifiedStatus(PDO $pdo, int $companyId, int $fyId): bool
{
    $policy = getReviewPolicy($pdo, $companyId);
    $signoffs = getApprovalSignoffData($pdo, $companyId, $fyId);

    $staffSigned = !empty($signoffs['signoff_staff_by']);
    $managerSigned = !empty($signoffs['signoff_manager_by']);
    $partnerSigned = !empty($signoffs['signoff_partner_by']);

    return match($policy) {
        'single' => $staffSigned,
        'two_level' => $staffSigned && $managerSigned,
        'three_level' => $staffSigned && $managerSigned && $partnerSigned,
        default => false,
    };
}

/**
 * Derive and persist verified status.
 * Call this after any signoff sign/revoke action.
 */
function deriveAndPersistVerified(PDO $pdo, int $companyId, int $fyId): void
{
    $verified = computeVerifiedStatus($pdo, $companyId, $fyId);
    setWorkflowVerified($companyId, $fyId, $verified);
}
