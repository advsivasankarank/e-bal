<?php
/**
 * e-BAL — Findings & Recommendations (Management Observations Report)
 *
 * A client-facing deliverable, distinct from Review Centre (internal CA
 * working notes): accounting inconsistencies/gaps found while preparing
 * the statements, disclosed to the client with the CA's recommendation.
 * Deliberately independent of Balance Sheet / Directors' Report readiness
 * gates -- it exists specifically to disclose issues like an unbalanced
 * Balance Sheet, so it must stay usable even when those are blocked.
 *
 * Auto-detected findings (from buildBsDiagnostics()/
 * computeOpeningBalanceDiagnostics(), the same engines that already power
 * the BS Diagnostics panel and Opening Balance Diagnostics) land as
 * 'pending_review' and only reach the exported report once a CA
 * explicitly includes them with a recommendation -- see
 * app/helpers/management_findings_helper.php for the full rationale.
 */
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/helpers/management_findings_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$userId = (int) ($_SESSION['user_id'] ?? 0);

$infoMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = (string) ($_POST['finding_action'] ?? '');

    if ($action === 'decide') {
        $findingId = (int) ($_POST['finding_id'] ?? 0);
        $decision = (string) ($_POST['decision'] ?? '');
        $recommendation = trim((string) ($_POST['ca_recommendation'] ?? ''));
        $exclusionReason = trim((string) ($_POST['exclusion_reason'] ?? ''));
        if ($decision === 'included' && $recommendation === '') {
            $infoMessage = 'A recommendation is required before including a finding in the report.';
        } else {
            decideFinding($pdo, $company_id, $fy_id, $findingId, $decision, $recommendation, $exclusionReason, $userId);
            $infoMessage = 'Finding updated.';
        }
    } elseif ($action === 'add_manual') {
        $title = trim((string) ($_POST['manual_title'] ?? ''));
        $message = trim((string) ($_POST['manual_message'] ?? ''));
        $severity = (string) ($_POST['manual_severity'] ?? 'observation');
        $recommendation = trim((string) ($_POST['manual_recommendation'] ?? ''));
        if ($title === '' || $message === '') {
            $infoMessage = 'Title and description are required for a manual observation.';
        } else {
            addManualFinding($pdo, $company_id, $fy_id, $title, $message, $severity, $recommendation, $userId);
            $infoMessage = 'Manual observation added.';
        }
    } elseif ($action === 'delete') {
        deleteFinding($pdo, $company_id, $fy_id, (int) ($_POST['finding_id'] ?? 0));
        $infoMessage = 'Finding removed.';
    }
}

$syncResult = syncAutoDetectedFindings($pdo, $company_id, $fy_id);
if ($infoMessage === '' && ($syncResult['new_findings'] > 0 || $syncResult['auto_resolved'] > 0)) {
    $parts = [];
    if ($syncResult['new_findings'] > 0) $parts[] = $syncResult['new_findings'] . ' new finding(s) detected';
    if ($syncResult['auto_resolved'] > 0) $parts[] = $syncResult['auto_resolved'] . ' prior finding(s) appear resolved';
    $infoMessage = implode('; ', $parts) . '.';
}

$pending = getManagementFindings($pdo, $company_id, $fy_id, 'pending_review');
$included = getManagementFindings($pdo, $company_id, $fy_id, 'included');
$excluded = getManagementFindings($pdo, $company_id, $fy_id, 'excluded');
$recurring = getRecurringUnresolvedFindings($pdo, $company_id, $fy_id);
$recurringByKey = [];
foreach ($recurring as $r) {
    $recurringByKey[$r['finding_key']][] = $r;
}

$severityLabel = ['critical' => 'Critical', 'significant' => 'Significant', 'observation' => 'Observation'];
$severityColor = ['critical' => '#b91c1c', 'significant' => '#b45309', 'observation' => '#475569'];

require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Review Centre', 'href' => BASE_URL . 'review/index.php'],
    ['label' => 'Findings & Recommendations'],
]) ?>

<?= uiPageHero('Findings & Recommendations', 'Management Observations Report -- disclose accounting inconsistencies and recommendations to the client, independent of Balance Sheet or Directors\' Report readiness') ?>

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => '',
    'profile' => 0,
    'status' => count($pending) . ' pending review, ' . count($included) . ' included in report',
    'edit_url' => '',
]) ?>

<?php if ($infoMessage !== ''): ?>
    <div class="card section-card"><?= htmlspecialchars($infoMessage) ?></div>
<?php endif; ?>

<div class="card section-card">
    <strong>Export Management Observations Report</strong><br>
    <span style="font-size:0.85rem;color:var(--muted);">Includes only findings marked "Included" below. Available regardless of Balance Sheet or Directors' Report status.</span><br><br>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?doc=management_observations&format=pdf" target="_blank">Download PDF</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?doc=management_observations&format=docx" style="margin-left:8px;">Download Word</a>
    <a class="btn" href="<?= BASE_URL ?>report_download.php?doc=management_observations&format=html" target="_blank" style="margin-left:8px;">Preview HTML</a>
</div>

<?php if ($pending !== []): ?>
<div class="card section-card">
    <h3 style="margin-top:0;">Pending Review (<?= count($pending) ?>)</h3>
    <p style="font-size:0.85rem;color:var(--muted);">Auto-detected during statement preparation. Review each and decide whether to include it (with a recommendation) in the client-facing report, or exclude it as not relevant this year.</p>
    <?php foreach ($pending as $f): ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;">
            <strong><?= htmlspecialchars((string) $f['title']) ?></strong>
            <span style="font-size:0.75rem;font-weight:700;text-transform:uppercase;color:<?= $severityColor[$f['severity']] ?? '#475569' ?>;"><?= $severityLabel[$f['severity']] ?? ucfirst((string) $f['severity']) ?></span>
        </div>
        <p style="font-size:0.85rem;margin:6px 0;"><?= htmlspecialchars((string) $f['detected_message']) ?></p>
        <?php if (!empty($recurringByKey[$f['finding_key']])): ?>
        <p style="font-size:0.8rem;color:#b45309;">&#9888; Also raised (and still unresolved) in: <?= htmlspecialchars(implode(', ', array_map(static fn ($r) => 'FY ' . $r['fy_label'], $recurringByKey[$f['finding_key']]))) ?></p>
        <?php endif; ?>
        <form method="post" style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-top:8px;">
            <?= csrfInput() ?>
            <input type="hidden" name="finding_action" value="decide">
            <input type="hidden" name="finding_id" value="<?= (int) $f['id'] ?>">
            <textarea name="ca_recommendation" placeholder="Recommendation to the client (required to include)" style="flex:1;min-width:260px;" rows="2"></textarea>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <button type="submit" name="decision" value="included" class="btn-primary btn-sm">Include in Report</button>
                <button type="submit" name="decision" value="excluded" class="btn-outline btn-sm" onclick="this.form.exclusion_reason.value='Not relevant this year';">Exclude</button>
            </div>
            <input type="hidden" name="exclusion_reason" value="">
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card section-card">
    <h3 style="margin-top:0;">Included in Report (<?= count($included) ?>)</h3>
    <?php if ($included === []): ?>
        <p style="font-size:0.85rem;color:var(--muted);">Nothing included yet.</p>
    <?php else: ?>
    <?php foreach ($included as $f): ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:12px;background:#f0fdf4;">
        <div style="display:flex;justify-content:space-between;">
            <strong><?= htmlspecialchars((string) $f['title']) ?></strong>
            <span style="font-size:0.75rem;font-weight:700;text-transform:uppercase;color:<?= $severityColor[$f['severity']] ?? '#475569' ?>;"><?= $severityLabel[$f['severity']] ?? ucfirst((string) $f['severity']) ?> <?= $f['source'] === 'manual' ? '&middot; Manual' : '' ?></span>
        </div>
        <p style="font-size:0.85rem;margin:6px 0;"><?= htmlspecialchars((string) $f['detected_message']) ?></p>
        <p style="font-size:0.85rem;margin:6px 0;"><strong>Recommendation:</strong> <?= htmlspecialchars((string) $f['ca_recommendation']) ?></p>
        <form method="post" style="display:inline;">
            <?= csrfInput() ?>
            <input type="hidden" name="finding_action" value="decide">
            <input type="hidden" name="finding_id" value="<?= (int) $f['id'] ?>">
            <input type="hidden" name="decision" value="excluded">
            <input type="hidden" name="exclusion_reason" value="Removed from report">
            <button type="submit" class="btn-outline btn-sm">Remove from Report</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card section-card">
    <h3 style="margin-top:0;">Add a Manual Observation</h3>
    <p style="font-size:0.85rem;color:var(--muted);">For matters no automated check can detect -- e.g. weak internal controls observed, absence of a formal fixed asset register historically, etc.</p>
    <form method="post">
        <?= csrfInput() ?>
        <input type="hidden" name="finding_action" value="add_manual">
        <div class="form-group">
            <label>Title</label>
            <input type="text" name="manual_title" required>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="manual_message" rows="2" required></textarea>
        </div>
        <div class="form-group">
            <label>Severity</label>
            <select name="manual_severity">
                <option value="observation">Observation</option>
                <option value="significant">Significant</option>
                <option value="critical">Critical</option>
            </select>
        </div>
        <div class="form-group">
            <label>Recommendation</label>
            <textarea name="manual_recommendation" rows="2"></textarea>
        </div>
        <button class="btn-primary" type="submit">Add &amp; Include in Report</button>
    </form>
</div>

<?php if ($excluded !== []): ?>
<div class="card section-card">
    <h3 style="margin-top:0;">Excluded (<?= count($excluded) ?>)</h3>
    <table class="note-table" border="1" width="100%" cellpadding="5" style="font-size:0.82rem;">
        <thead><tr><th>Title</th><th>Reason</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($excluded as $f): ?>
        <tr>
            <td><?= htmlspecialchars((string) $f['title']) ?></td>
            <td><?= htmlspecialchars((string) $f['exclusion_reason']) ?></td>
            <td>
                <form method="post" style="display:inline;">
                    <?= csrfInput() ?>
                    <input type="hidden" name="finding_action" value="decide">
                    <input type="hidden" name="finding_id" value="<?= (int) $f['id'] ?>">
                    <input type="hidden" name="decision" value="pending_review">
                    <input type="hidden" name="ca_recommendation" value="">
                    <input type="hidden" name="exclusion_reason" value="">
                    <button type="submit" class="btn-outline btn-sm">Re-open</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
