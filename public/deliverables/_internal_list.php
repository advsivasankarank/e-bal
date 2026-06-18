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
    ],
    [
        'name' => 'Sign-Off Report',
        'ready' => !empty($signoffData),
        'formats' => ['pdf', 'html'],
        'description' => 'Staff/Manager/Partner sign-off status',
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
    </div>
    <div class="dw-doc-formats">
        <?php foreach ($doc['formats'] as $fmt): ?>
        <span class="dw-format-btn" title="<?= strtoupper($fmt) ?>"><?= strtoupper($fmt) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div style="margin-top:12px;font-size:0.8rem;color:var(--muted,#6b7280);">
    <?= $readyCount ?>/<?= $totalCount ?> internal documents available
</div>
