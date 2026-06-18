<?php
/**
 * Export Validation Report - Sprint 4C
 * Printable summary of all review data
 * Expects: $validationChecks, $remarkData, $signoffData, $readinessScore, $timelineEntries, $companyName, $fyName, $entityType
 */
if (!isset($validationChecks)) return;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Report - <?= htmlspecialchars($companyName ?? '') ?> - <?= htmlspecialchars($fyName ?? '') ?></title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 20px; color: #1a1d21; font-size: 13px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 20px 0 8px; color: #0f4c81; border-bottom: 2px solid #0f4c81; padding-bottom: 4px; }
        h3 { font-size: 13px; margin: 12px 0 6px; }
        .meta { color: #6b7280; font-size: 12px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin: 8px 0; }
        th, td { border: 1px solid #e0e4e8; padding: 6px 10px; text-align: left; font-size: 12px; }
        th { background: #f4f6f8; font-weight: 600; }
        .error { color: #dc2626; }
        .warning { color: #d97706; }
        .info { color: #2563eb; }
        .passed { color: #16a34a; }
        .score-box { display: inline-block; padding: 8px 16px; border: 2px solid; border-radius: 8px; font-size: 24px; font-weight: 700; margin: 8px 0; }
        .score-green { border-color: #16a34a; color: #16a34a; }
        .score-blue { border-color: #2563eb; color: #2563eb; }
        .score-amber { border-color: #d97706; color: #d97706; }
        .score-orange { border-color: #ea580c; color: #ea580c; }
        .score-red { border-color: #dc2626; color: #dc2626; }
        .signoff-row td:first-child { font-weight: 600; width: 120px; }
        @media print { body { margin: 10mm; } }
    </style>
</head>
<body>
    <h1>Review Report</h1>
    <div class="meta">
        Company: <strong><?= htmlspecialchars($companyName ?? '') ?></strong> &middot;
        FY: <strong><?= htmlspecialchars($fyName ?? '') ?></strong> &middot;
        Entity: <strong><?= htmlspecialchars($entityType ?? '') ?></strong> &middot;
        Generated: <strong><?= date('d M Y, h:i A') ?></strong>
    </div>

    <!-- Readiness Score -->
    <h2>Readiness Score</h2>
    <?php
    $totalChecks = count($validationChecks);
    $passedChecks = count(array_filter($validationChecks, fn($c) => $c['passed']));
    $vScore = $totalChecks > 0 ? round(($passedChecks / $totalChecks) * 60) : 60;
    $totalRemarks = count($remarkData ?? []);
    $resolvedRemarks = count(array_filter($remarkData ?? [], fn($r) => ($r['resolved'] ?? '0') === '1'));
    $rScore = $totalRemarks > 0 ? round(($resolvedRemarks / $totalRemarks) * 30) : 30;
    $signedRoles = count(array_filter($signoffData ?? [], fn($s) => $s !== ''));
    $sScore = round(($signedRoles / 3) * 10);
    $total = $vScore + $rScore + $sScore;
    $scoreClass = $total >= 100 ? 'score-green' : ($total >= 90 ? 'score-blue' : ($total >= 70 ? 'score-amber' : ($total >= 50 ? 'score-orange' : 'score-red')));
    ?>
    <div class="score-box <?= $scoreClass ?>"><?= $total ?>%</div>
    <table>
        <tr><td>Validation</td><td><?= $vScore ?>/60</td></tr>
        <tr><td>Remarks</td><td><?= $rScore ?>/30</td></tr>
        <tr><td>Sign-Offs</td><td><?= $sScore ?>/10</td></tr>
    </table>

    <!-- Validation Results -->
    <h2>Validation Results</h2>
    <?php foreach ($validationChecks as $check): ?>
    <table>
        <tr>
            <td class="<?= $check['passed'] ? 'passed' : $check['severity'] ?>">
                <?= $check['passed'] ? '&#10003;' : ($check['severity'] === 'error' ? '&#10007;' : '&#9888;') ?>
            </td>
            <td>
                <strong><?= htmlspecialchars($check['message']) ?></strong>
                <?php if (!$check['passed'] && $check['detail']): ?><br><small><?= htmlspecialchars($check['detail']) ?></small><?php endif; ?>
                <?php if (!$check['passed'] && $check['impact'] === 'financial' && $check['impact_value'] > 0): ?>
                <br><small>Financial Impact: Rs. <?= number_format($check['impact_value'], 2) ?></small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($check['category']) ?></td>
            <td><?= $check['passed'] ? 'Passed' : ucfirst($check['severity']) ?></td>
        </tr>
    </table>
    <?php endforeach; ?>

    <!-- Review Remarks -->
    <h2>Review Remarks</h2>
    <?php if (empty($remarkData)): ?>
    <p>No remarks recorded.</p>
    <?php else: ?>
    <table>
        <tr><th>Section</th><th>Severity</th><th>Remark</th><th>Status</th></tr>
        <?php foreach ($remarkData as $section => $remark): ?>
        <?php if (strpos($section, 'text_') !== false): ?>
        <?php
            $sectionKey = str_replace('review_remark_text_', '', $section);
            $severity = $remarkData['review_remark_severity_' . $sectionKey] ?? 'observation';
            $resolved = ($remarkData['review_remark_resolved_' . $sectionKey] ?? '0') === '1';
        ?>
        <tr>
            <td><?= htmlspecialchars($sectionKey) ?></td>
            <td><?= ucfirst(htmlspecialchars($severity)) ?></td>
            <td><?= htmlspecialchars($remark) ?></td>
            <td><?= $resolved ? 'Resolved' : 'Unresolved' ?></td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <!-- Sign-Off Status -->
    <h2>Sign-Off Status</h2>
    <table class="signoff-row">
        <tr><td>Staff</td><td><?= ($signoffData['signoff_staff_by'] ?? '') !== '' ? 'Signed by user #' . htmlspecialchars($signoffData['signoff_staff_by']) . ' on ' . htmlspecialchars($signoffData['signoff_staff_at'] ?? '') : 'Not signed' ?></td></tr>
        <tr><td>Manager</td><td><?= ($signoffData['signoff_manager_by'] ?? '') !== '' ? 'Signed by user #' . htmlspecialchars($signoffData['signoff_manager_by']) . ' on ' . htmlspecialchars($signoffData['signoff_manager_at'] ?? '') : 'Not signed' ?></td></tr>
        <tr><td>Partner</td><td><?= ($signoffData['signoff_partner_by'] ?? '') !== '' ? 'Signed by user #' . htmlspecialchars($signoffData['signoff_partner_by']) . ' on ' . htmlspecialchars($signoffData['signoff_partner_at'] ?? '') : 'Not signed' ?></td></tr>
    </table>

    <div style="margin-top:24px;text-align:center;color:#6b7280;font-size:11px;">
        Generated by e-BAL Financial Statement Software &middot; <?= date('d M Y') ?>
    </div>
</body>
</html>
