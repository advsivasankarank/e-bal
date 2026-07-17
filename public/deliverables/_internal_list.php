<?php
/**
 * Internal Package List - Sprint 4D
 * Working Papers, Validation Report, Review Report, Sign-Off Report
 * Expects: $hasReportData, $workflow, $validationResult, $signoffData, $remarkData, $readinessScore
 */
if (!isset($hasReportData)) return;

$docs = [
    [
        'name' => 'Working Papers Package',
        'ready' => $hasReportData,
        'formats' => ['xlsx'],
        'description' => 'Per-statement computation sheets',
        'fix_url' => BASE_URL . 'data_console/tally_console.php',
        'fix_label' => 'Go to Data Import',
    ],
    [
        'name' => 'Validation Report',
        'ready' => true,
        'formats' => ['pdf', 'html'],
        'description' => 'All validation checks with status and impact',
    ],
    [
        'name' => 'Review Report',
        'ready' => !empty($remarkData),
        'formats' => ['pdf', 'html'],
        'description' => 'Review remarks with severity and resolution status',
        'fix_url' => BASE_URL . 'review/index.php',
        'fix_label' => 'Go to Review Centre',
    ],
    [
        'name' => 'Sign-Off Report',
        'ready' => !empty($signoffData),
        'formats' => ['pdf', 'html'],
        'description' => 'Staff/Manager/Partner sign-off status',
        'fix_url' => BASE_URL . 'review/index.php',
        'fix_label' => 'Go to Review Centre',
    ],
];

$readyCount = count(array_filter($docs, fn($d) => $d['ready']));
$totalCount = count($docs);
?>

<div class="dw-pkg-title">Internal Working Documents</div>

<?php foreach ($docs as $doc): ?>
<div class="dw-doc-item">
    <span class="dw-doc-icon <?= $doc['ready'] ? 'ready' : 'not-ready' ?>"><?= $doc['ready'] ? '&#10003;' : '&#10007;' ?></span>
    <div style="flex:1;">
        <span class="dw-doc-name"><?= htmlspecialchars($doc['name']) ?></span>
        <div style="font-size:0.75rem;color:var(--muted,#6b7280);"><?= htmlspecialchars($doc['description']) ?></div>
        <?php if (!$doc['ready'] && !empty($doc['fix_url'])): ?>
        <a href="<?= htmlspecialchars($doc['fix_url']) ?>" style="font-size:0.75rem;">&rarr; <?= htmlspecialchars($doc['fix_label'] ?? 'Fix this') ?></a>
        <?php endif; ?>
    </div>
    <div class="dw-doc-formats">
        <?php foreach ($doc['formats'] as $fmt): ?>
        <?php if ($doc['ready']): ?>
        <a href="<?= BASE_URL ?>report_download.php?format=<?= urlencode($fmt) ?>" class="dw-format-btn" title="Download <?= strtoupper($fmt) ?>"><?= strtoupper($fmt) ?></a>
        <?php else: ?>
        <span class="dw-format-btn" title="Not ready" style="opacity:0.4;cursor:not-allowed;"><?= strtoupper($fmt) ?></span>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div style="margin-top:12px;font-size:0.8rem;color:var(--muted,#6b7280);">
    <?= $readyCount ?>/<?= $totalCount ?> internal documents available
</div>
