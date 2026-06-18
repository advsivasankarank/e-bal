<?php
/**
 * Review Snapshot - Hardening Fix
 * Displays review status using centralized computeReadiness() data.
 * Expects: $readiness array from computeReadiness()
 */
if (!isset($readiness)) return;

$readinessScore = $readiness['score'];
$totalRemarks = $readiness['remarks']['total'];
$resolvedRemarks = $readiness['remarks']['resolved'];
$unresolvedRemarks = $readiness['remarks']['unresolved'];
$signedCount = $readiness['signoffs']['signed_count'];
$signoffDetails = $readiness['signoffs']['details'];
$errorCount = $readiness['validation']['errors'];
$warningCount = $readiness['validation']['warnings'];

$staffSigned = $signoffDetails['staff']['signed'] ?? false;
$managerSigned = $signoffDetails['manager']['signed'] ?? false;
$partnerSigned = $signoffDetails['partner']['signed'] ?? false;

if ($readinessScore >= 100) $scoreColor = '#16a34a';
elseif ($readinessScore >= 90) $scoreColor = '#2563eb';
elseif ($readinessScore >= 70) $scoreColor = '#d97706';
elseif ($readinessScore >= 50) $scoreColor = '#ea580c';
else $scoreColor = '#dc2626';
?>

<div class="dw-review-grid">
    <div class="dw-review-card">
        <div class="dw-rc-label">Readiness Score</div>
        <div class="dw-rc-value" style="color:<?= $scoreColor ?>"><?= $readinessScore ?>%</div>
        <div class="dw-rc-sub">
            <?= $readinessScore >= 100 ? 'Ready' : ($readinessScore >= 70 ? 'In Review' : 'Needs Attention') ?>
        </div>
    </div>
    <div class="dw-review-card">
        <div class="dw-rc-label">Validation</div>
        <div class="dw-rc-value">
            <?php if ($errorCount > 0): ?>
            <span style="color:#dc2626;"><?= $errorCount ?> error<?= $errorCount !== 1 ? 's' : '' ?></span>
            <?php else: ?>
            <span style="color:#16a34a;">Passed</span>
            <?php endif; ?>
        </div>
        <div class="dw-rc-sub"><?= $warningCount ?> warning<?= $warningCount !== 1 ? 's' : '' ?></div>
    </div>
    <div class="dw-review-card">
        <div class="dw-rc-label">Sign-Offs</div>
        <div class="dw-rc-value"><?= $signedCount ?>/3</div>
        <div class="dw-rc-sub">
            Staff<?= $staffSigned ? ': Signed' : ': Pending' ?> &middot;
            Manager<?= $managerSigned ? ': Signed' : ': Pending' ?>
        </div>
    </div>
    <div class="dw-review-card">
        <div class="dw-rc-label">Remarks</div>
        <div class="dw-rc-value">
            <?php if ($totalRemarks === 0): ?>
            None
            <?php elseif ($unresolvedRemarks > 0): ?>
            <span style="color:#d97706;"><?= $unresolvedRemarks ?> open</span>
            <?php else: ?>
            <span style="color:#16a34a;">All resolved</span>
            <?php endif; ?>
        </div>
        <div class="dw-rc-sub"><?= $resolvedRemarks ?>/<?= $totalRemarks ?> resolved</div>
    </div>
</div>
