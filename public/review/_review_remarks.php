<?php
/**
 * Review Remarks - Sprint 4C
 * Per-statement remark cards with severity and resolve workflow
 * Expects: $pdo, $company_id, $fy_id, $tabDefs, $manualBundle
 */
if (!isset($tabDefs)) return;

$remarkSections = [];
foreach ($tabDefs as $td) {
    $remarkSections[] = [
        'key' => $td['id'],
        'label' => strip_tags($td['label']),
    ];
}
// Add directors report for corporate
if (!empty($isCorporate)) {
    $remarkSections[] = ['key' => 'directors-report', 'label' => 'Directors Report'];
}

function getRemarkData($pdo, $companyId, $fyId, $sectionKey) {
    require_once __DIR__ . '/../../app/helpers/report_manual_helper.php';
    $all = loadManualInputsByPrefix($pdo, (int) $companyId, (int) $fyId, 'review_remark_');
    $data = [];
    foreach ($all as $key => $value) {
        $suffix = '_' . $sectionKey;
        if (substr((string) $key, -strlen($suffix)) === $suffix) {
            $data[$key] = $value;
        }
    }
    return $data;
}

$severityLabels = [
    'critical' => ['label' => 'Critical', 'color' => '#dc2626'],
    'important' => ['label' => 'Important', 'color' => '#d97706'],
    'observation' => ['label' => 'Observation', 'color' => '#2563eb'],
    'suggestion' => ['label' => 'Suggestion', 'color' => '#6b7280'],
];
?>

<?php foreach ($remarkSections as $section): ?>
<?php
    $remarkData = getRemarkData($pdo, $company_id, $fy_id, $section['key']);
    $remarkText = $remarkData['review_remark_text_' . $section['key']] ?? '';
    $remarkSeverity = $remarkData['review_remark_severity_' . $section['key']] ?? 'observation';
    $remarkResolved = ($remarkData['review_remark_resolved_' . $section['key']] ?? '0') === '1';
    $remarkBy = $remarkData['review_remark_by_' . $section['key']] ?? '';
    $remarkAt = $remarkData['review_remark_at_' . $section['key']] ?? '';
    $hasRemark = $remarkText !== '';
?>
<div class="rw-remark-card">
    <div class="rw-remark-header">
        <span><?= htmlspecialchars($section['label']) ?></span>
        <?php if (!$hasRemark): ?>
            <span class="rw-remark-status no-remark">No remark</span>
        <?php elseif ($remarkResolved): ?>
            <span class="rw-remark-status resolved">Resolved</span>
        <?php else: ?>
            <span class="rw-remark-status unresolved">Unresolved</span>
        <?php endif; ?>
    </div>
    <div class="rw-remark-body">
        <?php if ($hasRemark && $remarkResolved): ?>
            <div style="font-size:0.88rem;color:#475569;margin-bottom:6px;"><?= nl2br(htmlspecialchars($remarkText)) ?></div>
            <div class="rw-remark-meta">
                Resolved by user #<?= htmlspecialchars($remarkBy) ?>
                <?php if ($remarkAt): ?> on <?= htmlspecialchars($remarkAt) ?><?php endif; ?>
            </div>
            <div class="rw-remark-actions">
                <button type="button" class="btn btn-sm rw-resolve-btn" data-section="<?= htmlspecialchars($section['key']) ?>" data-action="remark_reopen">Reopen</button>
            </div>
        <?php else: ?>
            <form method="post" class="rw-remark-form">
                <?= csrfInput() ?>
                <input type="hidden" name="review_action" value="save_remark">
                <input type="hidden" name="remark_section" value="<?= htmlspecialchars($section['key']) ?>">
                <input type="hidden" name="remark_severity" value="<?= htmlspecialchars($remarkSeverity) ?>">
                <div class="rw-remark-severity">
                    <?php foreach ($severityLabels as $sevKey => $sevInfo): ?>
                    <button type="button" class="rw-sev-btn <?= $remarkSeverity === $sevKey ? 'active' : '' ?>" data-sev="<?= $sevKey ?>"><?= $sevInfo['label'] ?></button>
                    <?php endforeach; ?>
                </div>
                <textarea name="remark_text" placeholder="Add a remark for <?= htmlspecialchars($section['label']) ?>..."><?= htmlspecialchars($remarkText) ?></textarea>
                <div class="rw-remark-actions">
                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    <?php if ($hasRemark): ?>
                    <button type="button" class="btn btn-sm rw-resolve-btn" data-section="<?= htmlspecialchars($section['key']) ?>" data-action="remark_resolve">Mark Resolved</button>
                    <?php endif; ?>
                </div>
            </form>
            <?php if ($remarkBy || $remarkAt): ?>
            <div class="rw-remark-meta">
                Last edited by user #<?= htmlspecialchars($remarkBy) ?>
                <?php if ($remarkAt): ?> on <?= htmlspecialchars($remarkAt) ?><?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
