<?php
/**
 * e-BAL V2 — Tally Console
 *
 * Central hub for data import mode selection.
 * Shows workflow progress, context, bridge status, and recent imports.
 */
$page_title = "Tally Console";
require_once '../../app/context_check.php';
require_once '../../app/helpers/financial_year_helper.php';
require_once '../../config/database.php';

/* Handle FY selection POST before any output */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['context_action']) && $_POST['context_action'] === 'select_fy') {
    requireCsrfToken();
    $selFyId = (int) ($_POST['fy_id'] ?? 0);
    if ($selFyId > 0 && hasCompanyContext()) {
        $fy = findFinancialYearById($pdo, $selFyId);
        if ($fy) {
            $_SESSION['fy_id'] = $fy['id'];
            $_SESSION['fy_name'] = $fy['fy_label'];
        }
    }
    header("Location: " . BASE_URL . "data_console/tally_console.php");
    exit;
}

requireFullContext();
require_once '../../app/workflow_engine.php';
require_once __DIR__ . '/../layouts/header_v2.php';

$companyId = (int) ($_SESSION['company_id'] ?? 0);
$fyId = (int) ($_SESSION['fy_id'] ?? 0);
$companyName = $_SESSION['company_name'] ?? 'Not Selected';
$fyName = $_SESSION['fy_name'] ?? 'Not Selected';

/* ---- Workflow Status ---- */
$wf = ['ledger_fetched' => 0, 'mapping_completed' => 0, 'tally_fetched' => 0,
       'notes_prepared' => 0, 'profit_loss_prepared' => 0, 'balance_sheet_prepared' => 0,
       'directors_report_prepared' => 0, 'verified' => 0];
if ($companyId > 0 && $fyId > 0) {
    $wfStmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id=? AND fy_id=?");
    $wfStmt->execute([$companyId, $fyId]);
    $wfRow = $wfStmt->fetch(PDO::FETCH_ASSOC);
    if ($wfRow) {
        foreach ($wf as $k => $v) {
            $wf[$k] = (int) ($wfRow[$k] ?? $v);
        }
    }
}

/* ---- Profile Completion ---- */
$profilePct = 0;
if ($companyId > 0) {
    $pStmt = $pdo->prepare("SELECT profile_completeness FROM companies WHERE id=?");
    $pStmt->execute([$companyId]);
    $profilePct = (int) ($pStmt->fetchColumn() ?: 0);
}

/* ---- Company Category ---- */
$companyCategory = '';
$entityLabel = '';
if ($companyId > 0) {
    $catStmt = $pdo->prepare("SELECT category FROM companies WHERE id=?");
    $catStmt->execute([$companyId]);
    $companyCategory = (string) ($catStmt->fetchColumn() ?: '');
    $labelMap = ['corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate'];
    $entityLabel = $labelMap[strtolower(str_replace(['-', ' '], '_', $companyCategory))] ?? ucfirst($companyCategory);
}

/* ---- Recent Imports (last 5 from tally_ledgers) ---- */
$recentImports = [];
try {
    $riStmt = $pdo->prepare("
        SELECT ledger_name, parent_group, amount, dr_cr, created_at
        FROM tally_ledgers
        WHERE company_id = ? AND fy_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");
    $riStmt->execute([$companyId, $fyId]);
    $recentImports = $riStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    /* tally_ledgers may not have created_at column */
}

/* ---- Import Count ---- */
$importCount = 0;
try {
    $icStmt = $pdo->prepare("SELECT COUNT(*) FROM tally_ledgers WHERE company_id=? AND fy_id=?");
    $icStmt->execute([$companyId, $fyId]);
    $importCount = (int) $icStmt->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

/* ---- Determine Primary Action ---- */
$primaryAction = '';
$primaryHref = '';
if ($wf['tally_fetched'] === 1) {
    $primaryAction = 'Continue to Mapping';
    $primaryHref = BASE_URL . 'data_console/mapping_workbench.php';
} elseif ($importCount > 0) {
    $primaryAction = 'Upload XML';
    $primaryHref = BASE_URL . 'data_console/tally_offline.php';
} else {
    $primaryAction = 'Start Live Import';
    $primaryHref = BASE_URL . 'data_console/tally_online.php';
}

/* ---- Bridge Status (check if Smart Bridge is configured) ---- */
$bridgeConfigured = defined('TALLY_BRIDGE_URL') && TALLY_BRIDGE_URL !== '';
$bridgeStatusText = $bridgeConfigured ? 'Configured' : 'Not Configured';
$bridgeStatusVariant = $bridgeConfigured ? 'success' : 'default';

/* ---- Tally Status (check if tally_ledger_master has data) ---- */
$tallyStatusText = 'No Data';
$tallyStatusVariant = 'default';
$tallyMasterCount = 0;
try {
    $tmStmt = $pdo->prepare("SELECT COUNT(*) FROM tally_ledger_master WHERE company_id=?");
    $tmStmt->execute([$companyId]);
    $tallyMasterCount = (int) $tmStmt->fetchColumn();
    if ($tallyMasterCount > 0) {
        $tallyStatusText = number_format($tallyMasterCount) . ' ledgers loaded';
        $tallyStatusVariant = 'success';
    }
} catch (Throwable $e) { /* ignore */ }

/* ---- Last Sync Time ---- */
$lastSync = '';
try {
    $lsStmt = $pdo->prepare("SELECT MAX(updated_at) FROM workflow_status WHERE company_id=? AND fy_id=?");
    $lsStmt->execute([$companyId, $fyId]);
    $lastSync = (string) ($lsStmt->fetchColumn() ?: '');
} catch (Throwable $e) { /* ignore */ }

/* ---- Workflow Steps ---- */
$workflowSteps = [
    ['label' => 'Company', 'done' => $companyId > 0, 'href' => BASE_URL . 'company_dashboard/company_list.php'],
    ['label' => 'Tally', 'done' => $wf['ledger_fetched'] === 1, 'href' => BASE_URL . 'data_console/tally_console.php', 'active' => true],
    ['label' => 'Trial Balance', 'done' => $wf['tally_fetched'] === 1, 'href' => BASE_URL . 'data_console/trial_balance_preview.php'],
    ['label' => 'Mapping', 'done' => $wf['mapping_completed'] === 1, 'href' => BASE_URL . 'data_console/mapping_workbench.php'],
    ['label' => 'Reconciliation', 'done' => $wf['verified'] === 1, 'href' => BASE_URL . 'reconciliation_console.php'],
];

/* ---- Format helpers ---- */
function formatAmount($val): string {
    $num = (float) $val;
    if ($num == 0) return '—';
    return ($num < 0 ? '-' : '') . '₹' . number_format(abs($num), 2);
}

?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Data', 'href' => BASE_URL . 'data/index.php'],
    ['label' => 'Tally Console'],
]) ?>

<?= uiPageHero('Tally Console', 'Import ledger and trial balance data from Tally into e-BAL.') ?>

<!-- Expanded Context Card -->
<div class="ui-context-card" style="margin-bottom:20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
        <div style="display:flex;align-items:center;gap:16px;min-width:0;">
            <div class="ui-context-card-avatar"><?= strtoupper(substr($companyName, 0, 1)) ?></div>
            <div style="min-width:0;">
                <div class="ui-context-card-name"><?= htmlspecialchars($companyName) ?></div>
                <div class="ui-context-card-meta">
                    <span><?= htmlspecialchars($entityLabel) ?></span>
                    <span class="ui-sep">•</span>
                    <span><?= htmlspecialchars($fyName) ?></span>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div style="text-align:center;">
                <div style="font-size:.68rem;color:var(--muted);margin-bottom:4px;">Bridge</div>
                <?= uiStatusBadge($bridgeStatusText, $bridgeStatusVariant) ?>
            </div>
            <div style="text-align:center;">
                <div style="font-size:.68rem;color:var(--muted);margin-bottom:4px;">Tally</div>
                <?= uiStatusBadge($tallyStatusText, $tallyStatusVariant) ?>
            </div>
            <?php if ($lastSync !== ''): ?>
            <div style="text-align:center;">
                <div style="font-size:.68rem;color:var(--muted);margin-bottom:4px;">Last Sync</div>
                <div style="font-size:.78rem;font-weight:600;color:var(--text);"><?= date('d M Y', strtotime($lastSync)) ?></div>
            </div>
            <?php endif; ?>
            <div style="text-align:center;">
                <div style="width:48px;height:48px;border-radius:50%;border:3px solid <?= $profilePct >= 80 ? 'var(--success)' : ($profilePct >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;display:flex;align-items:center;justify-content:center;font-size:.82rem;font-weight:700;color:<?= $profilePct >= 80 ? 'var(--success)' : ($profilePct >= 40 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $profilePct ?>%</div>
                <div style="font-size:.65rem;color:var(--muted);margin-top:2px;">Profile</div>
            </div>
        </div>
    </div>
</div>

<!-- Workflow Progress -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;">
    <div style="font-size:.82rem;font-weight:700;color:var(--text);margin-bottom:12px;">Workflow Progress</div>
    <div style="display:flex;align-items:center;gap:0;overflow-x:auto;">
        <?php foreach ($workflowSteps as $i => $step):
            $isActive = !empty($step['active']);
            $isDone = $step['done'];
            $stepColor = $isDone ? 'var(--success)' : ($isActive ? 'var(--brand)' : 'var(--muted-light)');
            $stepBg = $isDone ? 'var(--success-light)' : ($isActive ? 'var(--brand-light)' : '#f1f5f9');
        ?>
            <?php if ($i > 0): ?>
                <div style="flex:0 0 24px;height:2px;background:<?= $isDone ? 'var(--success)' : '#e2e8f0' ?>;"></div>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($step['href']) ?>" style="text-decoration:none;display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:<?= $stepBg ?>;color:<?= $stepColor ?>;font-size:.75rem;font-weight:600;white-space:nowrap;transition:all .15s;<?= $isActive ? 'box-shadow:0 0 0 2px var(--brand);' : '' ?>">
                <?php if ($isDone): ?>
                    <span style="width:16px;height:16px;border-radius:50%;background:var(--success);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;">✓</span>
                <?php elseif ($isActive): ?>
                    <span style="width:16px;height:16px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;">●</span>
                <?php else: ?>
                    <span style="width:16px;height:16px;border-radius:50%;background:#e2e8f0;color:var(--muted-light);display:flex;align-items:center;justify-content:center;font-size:.6rem;"><?= $i + 1 ?></span>
                <?php endif; ?>
                <?= htmlspecialchars($step['label']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?= uiWorkspaceStart() ?>

<!-- Primary Action -->
<?php if ($primaryAction !== ''): ?>
<div style="margin-bottom:20px;">
    <?= uiButton($primaryAction, $primaryHref, 'primary', '⬇️') ?>
</div>
<?php endif; ?>

<!-- Import Mode Cards -->
<div style="margin-bottom:24px;">
    <div style="font-size:.88rem;font-weight:700;color:var(--text);margin-bottom:12px;">Import Mode</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <a href="<?= BASE_URL ?>data_console/tally_online.php" style="background:var(--panel);border:2px solid <?= $wf['ledger_fetched'] === 1 ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:24px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:12px;transition:all .15s;<?= $wf['ledger_fetched'] === 1 ? 'background:#f0fdf4;' : '' ?>" onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'" onmouseout="this.style.borderColor='<?= $wf['ledger_fetched'] === 1 ? 'var(--success)' : 'var(--border)' ?>';this.style.boxShadow='none'">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#12355b,#1e5aa8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;flex-shrink:0;">🔄</div>
                <div>
                    <div style="font-size:.95rem;font-weight:700;color:var(--text);">Online Mode</div>
                    <div style="font-size:.72rem;color:var(--muted);">Live Tally Connection</div>
                </div>
            </div>
            <p style="font-size:.82rem;color:var(--muted);line-height:1.5;margin:0;">Connect to live Tally via Smart Bridge, sync ledger master, and fetch trial balance against the active financial year.</p>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:auto;">
                <?= uiStatusBadge('Use Live Tally', 'brand') ?>
                <span style="font-size:.78rem;color:var(--brand);font-weight:600;">Open →</span>
            </div>
        </a>

        <a href="<?= BASE_URL ?>data_console/tally_offline.php" style="background:var(--panel);border:2px solid <?= $importCount > 0 ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:24px;text-decoration:none;color:inherit;display:flex;flex-direction:column;gap:12px;transition:all .15s;<?= $importCount > 0 ? 'background:#f0fdf4;' : '' ?>" onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'" onmouseout="this.style.borderColor='<?= $importCount > 0 ? 'var(--success)' : 'var(--border)' ?>';this.style.boxShadow='none'">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#047857,#10b981);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;flex-shrink:0;">📄</div>
                <div>
                    <div style="font-size:.95rem;font-weight:700;color:var(--text);">Offline Mode</div>
                    <div style="font-size:.72rem;color:var(--muted);">XML File Upload</div>
                </div>
            </div>
            <p style="font-size:.82rem;color:var(--muted);line-height:1.5;margin:0;">Upload exported XML files for ledger master and trial balance when direct Tally access is not available.</p>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-top:auto;">
                <?= uiStatusBadge('Use XML Upload', 'brand') ?>
                <span style="font-size:.78rem;color:var(--brand);font-weight:600;">Open →</span>
            </div>
        </a>
    </div>
</div>

<!-- Recent Imports -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
    <div style="padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
        <div style="font-size:.88rem;font-weight:700;color:var(--text);">Recent Imports</div>
        <?php if ($importCount > 0): ?>
            <span style="font-size:.72rem;color:var(--muted);"><?= number_format($importCount) ?> total rows</span>
        <?php endif; ?>
    </div>
    <?php if (empty($recentImports)): ?>
        <div style="padding:32px;text-align:center;color:var(--muted);font-size:.82rem;">
            <div style="font-size:1.5rem;margin-bottom:8px;opacity:.3;">📥</div>
            No imports yet. Choose Online or Offline mode to get started.
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:10px 16px;background:#f8fafc;border-bottom:2px solid var(--border);font-weight:600;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Ledger</th>
                    <th style="text-align:left;padding:10px 16px;background:#f8fafc;border-bottom:2px solid var(--border);font-weight:600;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Group</th>
                    <th style="text-align:right;padding:10px 16px;background:#f8fafc;border-bottom:2px solid var(--border);font-weight:600;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Amount</th>
                    <th style="text-align:center;padding:10px 16px;background:#f8fafc;border-bottom:2px solid var(--border);font-weight:600;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;">Type</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentImports as $ri): ?>
                <tr>
                    <td style="padding:10px 16px;border-bottom:1px solid #f1f5f9;font-weight:500;color:var(--text);"><?= htmlspecialchars($ri['ledger_name'] ?? '') ?></td>
                    <td style="padding:10px 16px;border-bottom:1px solid #f1f5f9;color:var(--muted);"><?= htmlspecialchars($ri['parent_group'] ?? '') ?></td>
                    <td style="padding:10px 16px;border-bottom:1px solid #f1f5f9;text-align:right;font-weight:500;color:var(--text);font-family:var(--font-mono);"><?= formatAmount($ri['amount'] ?? 0) ?></td>
                    <td style="padding:10px 16px;border-bottom:1px solid #f1f5f9;text-align:center;"><?= uiStatusBadge(($ri['dr_cr'] ?? '') === 'DR' ? 'Debit' : 'Credit', ($ri['dr_cr'] ?? '') === 'DR' ? 'info' : 'success') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
