<?php
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/engines/fs_engine.php';
require_once __DIR__ . '/../../app/helpers/report_manual_helper.php';
require_once __DIR__ . '/../../app/helpers/figure_helper.php';
require_once __DIR__ . '/../../app/helpers/report_validation_helper.php';
require_once __DIR__ . '/../../app/helpers/company_reporting_helper.php';
require_once __DIR__ . '/../../app/workflow_engine.php';
require_once __DIR__ . '/../../app/helpers/workflow_navigation_helper.php';

$page_title = 'Review Centre';
requireAssignmentAccess();

/* requireAssignmentAccess() above only validates the SESSION company/fy —
   sourcing these from $_GET here would let a signed-in user read/write
   another firm's data by editing the query string, since the value used
   would never actually go through that ownership check. Session is the
   sole source of truth once the access check has passed. */
$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';
$issueParam = trim((string) ($_GET['issue'] ?? ''));

if ($company_id <= 0 || $fy_id <= 0) {
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

$companyMeta = getCompanyReportingMeta($pdo, $company_id);
$entityCategory = getEntityCategory($pdo, $company_id);
$entitySubcategory = $companyMeta['entity_subcategory'] ?? '';
$entityType = $companyMeta['entity_type'] ?? 'N/A';

$workflow = getWorkflow($company_id, $fy_id);

$manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyName);
$fs = generateFinancialStatements(
    $pdo, $company_id, $fy_id, $fyName,
    $manualBundle['current'] ?? [], $manualBundle['previous'] ?? []
);
$hasReportData = (bool) ($fs['has_data'] ?? false);
$companyMeta = $fs['company_meta'] ?? $companyMeta;

// --- Tab definitions (same as financials.php) ---
$isCorporate = $entityCategory === 'corporate';
$isNonCorporate = in_array($entitySubcategory, ['proprietorship', 'partnership'], true);
$isLLP = $entitySubcategory === 'llp';
$tabDefs = [];
if ($isCorporate) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet'],
        ['id' => 'profit-loss', 'label' => 'P&amp;L'],
        ['id' => 'cash-flow', 'label' => 'Cash Flow'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes'],
    ];
} elseif ($isNonCorporate || $isLLP) {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet'],
        ['id' => 'trading-account', 'label' => 'Trading A/c'],
        ['id' => 'profit-loss', 'label' => 'P&amp;L A/c'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes'],
    ];
} else {
    $tabDefs = [
        ['id' => 'balance-sheet', 'label' => 'Balance Sheet'],
        ['id' => 'income-expenditure', 'label' => 'Income &amp; Expenditure'],
        ['id' => 'notes-to-accounts', 'label' => 'Notes'],
    ];
}

// --- Handle POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['review_action'] ?? '';

    if ($action === 'save_remark') {
        $section = $_POST['remark_section'] ?? '';
        $text = trim($_POST['remark_text'] ?? '');
        $severity = $_POST['remark_severity'] ?? 'observation';
        $validSeverities = ['critical', 'important', 'observation', 'suggestion'];
        if (!in_array($severity, $validSeverities)) $severity = 'observation';

        $now = date('Y-m-d H:i:s');
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        saveManualInputs($pdo, $company_id, $fy_id, [
            'review_remark_text_' . $section => $text,
            'review_remark_severity_' . $section => $severity,
            'review_remark_by_' . $section => (string)$userId,
            'review_remark_at_' . $section => $now,
        ]);

        // Add timeline entry
        addTimelineEntry($pdo, $company_id, $fy_id, 'remark_added|' . $userId . '|' . $now . '|' . $section . '|' . $severity);

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }

    if ($action === 'remark_resolve' || $action === 'remark_reopen') {
        $section = $_POST['remark_section'] ?? '';
        $resolved = ($action === 'remark_resolve') ? '1' : '0';
        $now = date('Y-m-d H:i:s');
        $userId = $_SESSION['user_id'] ?? 0;

        saveManualInputs($pdo, $company_id, $fy_id, [
            'review_remark_resolved_' . $section => $resolved,
        ]);

        $tlType = $action === 'remark_resolve' ? 'remark_resolved' : 'remark_reopened';
        addTimelineEntry($pdo, $company_id, $fy_id, $tlType . '|' . $userId . '|' . $now . '|' . $section);

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }

    if ($action === 'signoff_sign') {
        $role = $_POST['signoff_role'] ?? '';
        $validRoles = ['staff', 'manager', 'partner'];
        if (in_array($role, $validRoles)) {
            $existingSignoffs = loadManualInputsByPrefix($pdo, $company_id, $fy_id, 'signoff_');
            if (!canCurrentUserSignRole($role, $existingSignoffs)) {
                http_response_code(403);
                exit('You are not authorised to perform this sign-off.');
            }
            $now = date('Y-m-d H:i:s');
            $userId = $_SESSION['user_id'] ?? 0;

            saveManualInputs($pdo, $company_id, $fy_id, [
                'signoff_' . $role . '_by' => (string)$userId,
                'signoff_' . $role . '_at' => $now,
            ]);

            addTimelineEntry($pdo, $company_id, $fy_id, 'signoff_' . $role . '|' . $userId . '|' . $now);

            /* HARDENED: Derive verified from signoff data + review policy. Never set manually. */
            require_once __DIR__ . '/../../app/helpers/approval_policy_helper.php';
            deriveAndPersistVerified($pdo, $company_id, $fy_id);
        }

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }

    if ($action === 'signoff_revoke') {
        $role = $_POST['signoff_role'] ?? '';
        $validRoles = ['staff', 'manager', 'partner'];
        if (in_array($role, $validRoles)) {
            $existingSignoffs = loadManualInputsByPrefix($pdo, $company_id, $fy_id, 'signoff_');
            if (!canCurrentUserSignRole($role, $existingSignoffs)) {
                http_response_code(403);
                exit('You are not authorised to revoke this sign-off.');
            }
            $now = date('Y-m-d H:i:s');
            $userId = $_SESSION['user_id'] ?? 0;

            saveManualInputs($pdo, $company_id, $fy_id, [
                'signoff_' . $role . '_by' => '',
                'signoff_' . $role . '_at' => '',
            ]);

            addTimelineEntry($pdo, $company_id, $fy_id, 'signoff_revoke|' . $userId . '|' . $now . '|' . $role);

            /* HARDENED: Derive verified from signoff data + review policy after revoke. */
            require_once __DIR__ . '/../../app/helpers/approval_policy_helper.php';
            deriveAndPersistVerified($pdo, $company_id, $fy_id);
        }

        header("Location: " . BASE_URL . "review/index.php");
        exit;
    }
}

// --- Helper to add timeline entry ---
function addTimelineEntry($pdo, $companyId, $fyId, $entry) {
    $count = (int) (getManualInputValue($pdo, $companyId, $fyId, 'review_timeline_count') ?: '0');
    $count++;
    saveManualInputs($pdo, $companyId, $fyId, [
        'review_timeline_count' => (string)$count,
        'review_timeline_' . $count => $entry,
    ]);
}

function canCurrentUserSignRole(string $role, array $signoffData): bool
{
    $currentRole = strtolower((string) ($_SESSION['user_role'] ?? ''));
    if (in_array($currentRole, ['admin', 'superadmin'], true)) {
        return true;
    }
    if ($currentRole !== $role) {
        return false;
    }
    if ($role === 'manager' && empty($signoffData['signoff_staff_by'])) {
        return false;
    }
    if ($role === 'partner' && (empty($signoffData['signoff_staff_by']) || empty($signoffData['signoff_manager_by']))) {
        return false;
    }
    return true;
}

// --- Compute readiness using centralized engine ---
require_once __DIR__ . '/../../app/helpers/readiness_helper.php';
$readiness = computeReadiness($pdo, $company_id, $fy_id, $fs);

// Pass readiness data to partials
$validationChecks = $readiness['validation']['error_messages'];
$remarkData = [];
$remarkData = loadManualInputsByPrefix($pdo, $company_id, $fy_id, 'review_remark_');
$signoffData = [];
$signoffData = loadManualInputsByPrefix($pdo, $company_id, $fy_id, 'signoff_');

$lastValidated = date('M d, Y, g:i A');

require_once __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Review', 'href' => BASE_URL . 'review/index.php'],
    ['label' => 'Workspace']
]) ?>

<meta name="csrf-token" content="<?= $_SESSION['_csrf_token'] ?? '' ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/review_workspace.css?v=<?= filemtime(__DIR__ . '/../asset/css/review_workspace.css') ?>">

<?= uiContextCard([
    'company' => $companyName,
    'fy' => $fyName,
    'entity_type' => $entityType,
    'profile' => 0,
    'status' => $hasReportData ? 'Reports Ready' : 'Setup Required',
    'edit_url' => '',
]) ?>

<?php
$navData = getWorkflowNavigation($pdo, $company_id, $fy_id);
echo renderWorkflowNavigation($navData);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
    <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>findings_recommendations.php">Findings &amp; Recommendations (Management Letter)</a>
    <a class="btn btn-sm" href="<?= BASE_URL ?>assignment_home.php">&larr; Back to Assignment</a>
</div>

<?php if (!$hasReportData): ?>
    <div class="rw-section">
        <div class="rw-section-body" style="text-align:center;padding:40px;color:var(--muted);">
            <div style="font-size:2rem;margin-bottom:8px;opacity:0.3;">&#128269;</div>
            <div>Complete data import and financial statement generation before reviewing.</div>
            <a class="btn btn-primary" href="<?= BASE_URL ?>statements/financials.php" style="margin-top:12px;">Go to Financials</a>
        </div>
    </div>
<?php else: ?>

<!-- Readiness Score -->
<?php include __DIR__ . '/_readiness_score.php'; ?>

<!-- Validation Centre -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Validation Centre</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_validation_centre.php'; ?>
    </div>
</div>

<!-- Difference Analysis (shown when issue=bs_difference) -->
<?php if ($issueParam === 'bs_difference'): ?>
<?php
$currentDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);
$previousDiff = (float) ($fs['validation']['previous_balance_difference'] ?? 0);
$conflicts = $fs['validation']['parent_group_conflicts'] ?? [];
$noteComplete = $fs['validation']['note_completeness'] ?? ['missing' => []];
$validationErrors = $validationResult['errors'] ?? [];
$validationWarnings = $validationResult['warnings'] ?? [];
$bsStatus = abs($currentDiff) > 0.01 ? 'critical' : (abs($previousDiff) > 0.01 ? 'warning' : 'ready');
$bsStatusLabel = $bsStatus === 'critical' ? 'Critical — Statement is not ready for final deliverables.' : ($bsStatus === 'warning' ? 'Warning — Previous year difference detected.' : 'Ready — Balance Sheet is balanced.');
?>

<div class="rw-section" style="border-left:4px solid #dc2626;">
    <div class="rw-section-header">
        <h3>&#128269; Balance Sheet Difference Analysis</h3>
    </div>
    <div class="rw-section-body">
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
            <div style="flex:1;min-width:200px;padding:14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;">
                <div style="font-size:0.82rem;color:#991b1b;font-weight:600;">Current Year Difference</div>
                <div style="font-size:1.4rem;font-weight:700;color:#dc2626;margin-top:4px;"><?= format_inr_number($currentDiff) ?></div>
            </div>
            <?php if (abs($previousDiff) > 0.01): ?>
            <div style="flex:1;min-width:200px;padding:14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
                <div style="font-size:0.82rem;color:#92400e;font-weight:600;">Previous Year Difference</div>
                <div style="font-size:1.4rem;font-weight:700;color:#d97706;margin-top:4px;"><?= format_inr_number($previousDiff) ?></div>
            </div>
            <?php endif; ?>
            <div style="flex:1;min-width:200px;padding:14px;background:<?= $bsStatus === 'critical' ? '#fef2f2' : ($bsStatus === 'warning' ? '#fffbeb' : '#dcfce7') ?>;border:1px solid <?= $bsStatus === 'critical' ? '#fca5a5' : ($bsStatus === 'warning' ? '#fcd34d' : '#86efac') ?>;border-radius:8px;">
                <div style="font-size:0.82rem;color:<?= $bsStatus === 'critical' ? '#991b1b' : ($bsStatus === 'warning' ? '#92400e' : '#166534') ?>;font-weight:600;">Status</div>
                <div style="font-size:0.95rem;font-weight:600;color:<?= $bsStatus === 'critical' ? '#dc2626' : ($bsStatus === 'warning' ? '#d97706' : '#16a34a') ?>;margin-top:4px;"><?= htmlspecialchars($bsStatusLabel) ?></div>
            </div>
        </div>

        <p style="font-size:0.85rem;color:#475569;margin-bottom:16px;">Detected possible causes are listed below. Review each ledger and fix the mapping/classification where required.</p>

        <!-- Parent-Group Conflicts -->
        <?php if (!empty($conflicts)): ?>
        <div style="margin-bottom:16px;">
            <h4 style="font-size:0.95rem;margin:0 0 10px;color:#991b1b;">&#128681; Parent-Group Conflicts (<?= count($conflicts) ?>)</h4>
            <div style="overflow-x:auto;">
            <table border="1" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                <tr style="background:#fef2f2;">
                    <th>Ledger Name</th>
                    <th>Tally Group</th>
                    <th>Current Mapping</th>
                    <th>Issue</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($conflicts as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['ledger_name'] ?? '?') ?></td>
                    <td><?= htmlspecialchars($c['parent_group'] ?? '') ?></td>
                    <td><?= htmlspecialchars($c['schedule_code'] ?? '') ?></td>
                    <td style="color:#991b1b;">Parent-group conflict</td>
                    <td><a href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>&search=<?= urlencode($c['ledger_name'] ?? '') ?>" style="color:#0f4c81;text-decoration:none;font-weight:600;">Fix Mapping</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Missing Note Headings -->
        <?php if (!empty($noteComplete['missing'])): ?>
        <div style="margin-bottom:16px;">
            <h4 style="font-size:0.95rem;margin:0 0 10px;color:#92400e;">&#128221; Missing Note Headings (<?= count($noteComplete['missing']) ?>)</h4>
            <ul style="font-size:0.85rem;color:#475569;margin:0 0 10px 18px;">
                <?php foreach (array_slice($noteComplete['missing'], 0, 10) as $m): ?>
                <li><?= htmlspecialchars(is_array($m) ? ($m['title'] ?? $m['label'] ?? '?') : (string)$m) ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="color:#0f4c81;text-decoration:none;font-weight:600;font-size:0.85rem;">Review Mapping Rules</a>
        </div>
        <?php endif; ?>

        <!-- Validation Errors -->
        <?php if (!empty($validationErrors)): ?>
        <div style="margin-bottom:16px;">
            <h4 style="font-size:0.95rem;margin:0 0 10px;color:#991b1b;">&#10060; Validation Errors (<?= count($validationErrors) ?>)</h4>
            <ul style="font-size:0.85rem;color:#475569;margin:0 0 10px 18px;">
                <?php foreach (array_slice($validationErrors, 0, 8) as $err): ?>
                <li><?= htmlspecialchars($err['message'] ?? $err['check'] ?? '?') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (empty($conflicts) && empty($noteComplete['missing']) && empty($validationErrors)): ?>
        <div style="padding:16px;text-align:center;color:#475569;font-size:0.85rem;background:#f8fafc;border-radius:8px;">
            No ledger-level cause was detected from available validation data. Please run Rebuild and review mapping rules.
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px;">
            <a class="btn" href="<?= BASE_URL ?>data_console/mapping_workbench.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.82rem;padding:6px 14px;">Open Mapping Workbench</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/trial_balance_preview.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.82rem;padding:6px 14px;">Open Trial Balance</a>
            <a class="btn" href="<?= BASE_URL ?>statements/financials.php?entity_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" style="font-size:0.82rem;padding:6px 14px;">Back to Financial Statements</a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Review Remarks -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Review Remarks</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_review_remarks.php'; ?>
    </div>
</div>

<!-- Sign-Offs -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Sign-Offs</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_signoffs.php'; ?>
    </div>
</div>

<!-- Review Timeline -->
<div class="rw-section">
    <div class="rw-section-header">
        <h3>Review Timeline</h3>
        <span class="rw-toggle">&#9660;</span>
    </div>
    <div class="rw-section-body">
        <?php include __DIR__ . '/_review_timeline.php'; ?>
    </div>
</div>

<!-- Actions Bar -->
<div class="rw-actions-bar">
    <a href="<?= BASE_URL . 'review/index.php' ?>" class="btn btn-sm">Revalidate</a>
    <?php if (($signoffData['signoff_staff_by'] ?? '') === ''): ?>
    <form method="post" style="display:inline;">
        <?= csrfInput() ?>
        <input type="hidden" name="review_action" value="signoff_sign">
        <input type="hidden" name="signoff_role" value="staff">
        <button type="submit" class="btn btn-sm btn-primary">Mark as Reviewed</button>
    </form>
    <?php endif; ?>
    <button type="button" class="btn btn-sm" onclick="window.print()">Export Validation Report</button>
</div>

<?php endif; ?>

<script src="<?= BASE_URL ?>asset/js/review_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/review_workspace.js') ?>"></script>

<?php
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
