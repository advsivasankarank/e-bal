<?php
/**
 * Review Timeline - Sprint 4C
 * Chronological activity log of review actions
 * Expects: $pdo, $company_id, $fy_id
 */
if (!isset($pdo) || !isset($company_id) || !isset($fy_id)) return;

function getTimelineEntries($pdo, $companyId, $fyId) {
    $stmt = $pdo->prepare("SELECT meta_key, meta_value FROM report_manual_inputs WHERE company_id = ? AND fy_id = ? AND meta_key LIKE 'review_timeline_%' ORDER BY meta_key ASC");
    $stmt->execute([$companyId, $fyId]);
    $entries = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['meta_key'] === 'review_timeline_count') continue;
        $entries[] = $row['meta_value'];
    }
    return $entries;
}

$entries = getTimelineEntries($pdo, $company_id, $fy_id);
$entries = array_reverse($entries); // newest first

function parseTimelineEntry($entry) {
    $parts = explode('|', $entry, 5);
    return [
        'type' => $parts[0] ?? 'unknown',
        'user_id' => $parts[1] ?? '',
        'timestamp' => $parts[2] ?? '',
        'detail1' => $parts[3] ?? '',
        'detail2' => $parts[4] ?? '',
    ];
}

function formatTimelineAction($parsed) {
    switch ($parsed['type']) {
        case 'validation_run':
            return 'Validation run: ' . $parsed['detail1'];
        case 'remark_added':
            return 'Added remark on ' . $parsed['detail1'];
        case 'remark_resolved':
            return 'Resolved remark on ' . $parsed['detail1'];
        case 'remark_reopened':
            return 'Reopened remark on ' . $parsed['detail1'];
        case 'signoff_staff':
            return 'Signed as Staff';
        case 'signoff_manager':
            return 'Signed as Manager';
        case 'signoff_partner':
            return 'Signed as Partner';
        case 'signoff_revoke':
            return 'Revoked sign-off (' . $parsed['detail1'] . ')';
        case 'review_marked':
            return 'Marked as Reviewed';
        case 'review_revoked':
            return 'Revoked Review';
        default:
            return $parsed['type'];
    }
}

function formatTimelineDotClass($type) {
    if (strpos($type, 'validation') !== false) return 'validation';
    if (strpos($type, 'remark') !== false) return 'remark';
    if (strpos($type, 'signoff') !== false || strpos($type, 'review') !== false) return 'signoff';
    return 'validation';
}
?>

<?php if (empty($entries)): ?>
<div style="text-align:center;padding:20px;color:var(--muted,#6b7280);font-size:0.88rem;">
    No review activity recorded yet.
</div>
<?php else: ?>
<?php
$displayLimit = 10;
$hiddenCount = max(0, count($entries) - $displayLimit);
?>
<?php foreach ($entries as $i => $entry):
    $parsed = parseTimelineEntry($entry);
    $isHidden = $i >= $displayLimit;
?>
<div class="rw-timeline-entry <?= $isHidden ? 'hidden' : '' ?>" <?= $isHidden ? 'style="display:none;"' : '' ?>>
    <div class="rw-timeline-dot <?= formatTimelineDotClass($parsed['type']) ?>"></div>
    <div class="rw-timeline-content">
        <div class="rw-tl-action">
            <strong>User #<?= htmlspecialchars($parsed['user_id']) ?></strong>
            <?= htmlspecialchars(formatTimelineAction($parsed)) ?>
        </div>
        <?php if ($parsed['detail2']): ?>
        <div class="rw-tl-context"><?= htmlspecialchars($parsed['detail2']) ?></div>
        <?php endif; ?>
    </div>
    <div class="rw-timeline-time"><?= htmlspecialchars($parsed['timestamp']) ?></div>
</div>
<?php endforeach; ?>

<?php if ($hiddenCount > 0): ?>
<div style="text-align:center;padding:8px;">
    <button type="button" id="rw-timeline-more" class="btn btn-sm">Show <?= $hiddenCount ?> older entries</button>
</div>
<?php endif; ?>
<?php endif; ?>
