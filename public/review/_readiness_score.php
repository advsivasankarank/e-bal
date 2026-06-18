<?php
/**
 * Readiness Score Display - Hardening Fix
 * Uses centralized computeReadiness() data.
 * Expects: $readiness array from computeReadiness()
 */
if (!isset($readiness)) return;

$readinessScore = $readiness['score'];
$validationScore = $readiness['validation']['score'];
$remarksScore = $readiness['remarks']['score'];
$signoffScore = $readiness['signoffs']['score'];
$statusText = $readiness['label'];

if ($readinessScore >= 100) $scoreClass = 'score-green';
elseif ($readinessScore >= 90) $scoreClass = 'score-blue';
elseif ($readinessScore >= 70) $scoreClass = 'score-amber';
elseif ($readinessScore >= 50) $scoreClass = 'score-orange';
else $scoreClass = 'score-red';
?>

<div class="rw-readiness-banner">
    <div class="rw-score-circle <?= $scoreClass ?>">
        <span class="rw-score-num"><?= $readinessScore ?></span>
        <span class="rw-score-pct">%</span>
    </div>
    <div class="rw-score-detail">
        <h3>Readiness Score</h3>
        <div class="rw-score-status">
            Status: <strong><?= $statusText ?></strong>
            <?php if (isset($lastValidated)): ?> &middot; Last validated: <?= htmlspecialchars($lastValidated) ?><?php endif; ?>
        </div>
        <div class="rw-score-bars">
            <div class="rw-score-bar-item">Validation: <strong><?= $validationScore ?>/60</strong></div>
            <div class="rw-score-bar-item">Remarks: <strong><?= $remarksScore ?>/30</strong></div>
            <div class="rw-score-bar-item">Sign-Offs: <strong><?= $signoffScore ?>/10</strong></div>
        </div>
    </div>
</div>
