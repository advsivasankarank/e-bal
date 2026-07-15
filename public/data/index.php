<?php
/**
 * e-BAL V2 — Data Console
 *
 * Unified Data workspace with 4 sub-sections:
 *   1. Ledger Import
 *   2. Mapping
 *   3. Trial Balance
 *   4. Reconciliation
 *
 * Requires active Entity + FY context.
 * No inline context selection — context must be established before reaching this page.
 */
$page_title = 'Data Console';
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/workflow_engine.php';
require_once __DIR__ . '/../../app/helpers/workflow_navigation_helper.php';

/* ---- Require active context ---- */
$v2CompanyId = (int) ($_SESSION['company_id'] ?? 0);
$v2FyId      = (int) ($_SESSION['fy_id']      ?? 0);
$v2CompanyName = (string) ($_SESSION['company_name'] ?? '');
$v2FyName = (string) ($_SESSION['fy_name'] ?? '');

if ($v2CompanyId <= 0 || $v2FyId <= 0) {
    /* Context missing — redirect to e-BAL Gateway */
    header('Location: ' . BASE_URL . 'dashboard_company.php');
    exit;
}

requireAssignmentAccess();

/* Now safe to output HTML */
require_once __DIR__ . '/../layouts/header_v2.php';

/* ---- Query workflow status ---- */
$v2Stmt = $pdo->prepare("
    SELECT
        COALESCE(ledger_fetched, 0) AS ledger_fetched,
        COALESCE(mapping_completed, 0) AS mapping_completed,
        COALESCE(tally_fetched, 0) AS tally_fetched,
        COALESCE(notes_prepared, 0) AS notes_prepared,
        COALESCE(profit_loss_prepared, 0) AS profit_loss_prepared,
        COALESCE(balance_sheet_prepared, 0) AS balance_sheet_prepared,
        COALESCE(verified, 0) AS verified
    FROM workflow_status
    WHERE company_id = ? AND fy_id = ?
");
$v2Stmt->execute([$v2CompanyId, $v2FyId]);
$v2Ws = $v2Stmt->fetch(PDO::FETCH_ASSOC) ?: [];

/* ---- Count unmapped ledgers ---- */
$v2Unmapped = 0;
if (!empty($v2Ws['ledger_fetched'])) {
    try {
        $umStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM ledgers l
            LEFT JOIN ledger_mapping lm ON lm.ledger_id = l.id
            WHERE l.company_id = ? AND lm.ledger_id IS NULL
        ");
        $umStmt->execute([$v2CompanyId]);
        $v2Unmapped = (int) $umStmt->fetchColumn();
    } catch (Throwable $e) {
        $v2Unmapped = 0;
    }
}

/* ---- Count mapped ledgers ---- */
$v2Mapped = 0;
if (!empty($v2Ws['mapping_completed'])) {
    try {
        $mStmt = $pdo->prepare("SELECT COUNT(*) FROM ledger_mapping WHERE company_id = ?");
        $mStmt->execute([$v2CompanyId]);
        $v2Mapped = (int) $mStmt->fetchColumn();
    } catch (Throwable $e) {
        $v2Mapped = 0;
    }
}

/* ---- Count total ledgers ---- */
$v2TotalLedgers = 0;
try {
    $lStmt = $pdo->prepare("SELECT COUNT(*) FROM ledgers WHERE company_id = ?");
    $lStmt->execute([$v2CompanyId]);
    $v2TotalLedgers = (int) $lStmt->fetchColumn();
} catch (Throwable $e) {
    $v2TotalLedgers = 0;
}

/* ---- Define sub-sections as workflow tiles ---- */
$v2SubSections = [
    [
        'key'   => 'import',
        'icon'  => '📥',
        'label' => 'Ledger Import',
        'desc'  => 'Import ledger master from Tally or XML upload',
        'href'  => BASE_URL . 'data_console/tally_console.php',
        'status' => !empty($v2Ws['ledger_fetched']) ? 'complete' : ($v2TotalLedgers > 0 ? 'partial' : 'pending'),
        'detail' => !empty($v2Ws['ledger_fetched'])
            ? $v2TotalLedgers . ' ledgers imported'
            : 'No ledgers imported yet',
        'metric' => $v2TotalLedgers,
        'metricLabel' => 'Ledgers',
    ],
    [
        'key'   => 'mapping',
        'icon'  => '🗂️',
        'label' => 'ReconHub',
        'desc'  => 'Map ledgers to Schedule III codes, tag groups, review risks',
        'href'  => BASE_URL . 'data_console/mapping_workbench.php',
        'status' => !empty($v2Ws['mapping_completed']) ? 'complete' : ($v2Mapped > 0 ? 'partial' : 'pending'),
        'detail' => !empty($v2Ws['mapping_completed'])
            ? 'Mapping complete'
            : ($v2Mapped > 0 ? $v2Mapped . ' mapped, ' . $v2Unmapped . ' pending' : 'Not started'),
        'metric' => $v2Mapped,
        'metricLabel' => 'Mapped',
    ],
    [
        'key'   => 'trial_balance',
        'icon'  => '📋',
        'label' => 'Trial Balance',
        'desc'  => 'View and validate trial balance data',
        'href'  => BASE_URL . 'data_console/trial_balance_preview.php',
        'status' => !empty($v2Ws['tally_fetched']) ? 'complete' : 'pending',
        'detail' => !empty($v2Ws['tally_fetched'])
            ? 'Trial balance loaded'
            : 'Not loaded yet',
        'metric' => $v2TotalLedgers,
        'metricLabel' => 'Entries',
    ],
    [
        'key'   => 'reconciliation',
        'icon'  => '⚖️',
        'label' => 'Reconciliation',
        'desc'  => 'Validate data integrity and reconcile balances',
        'href'  => BASE_URL . 'reconciliation_console.php',
        'status' => !empty($v2Ws['tally_fetched']) && !empty($v2Ws['mapping_completed'])
            ? (!empty($v2Ws['verified']) ? 'complete' : 'available')
            : 'pending',
        'detail' => !empty($v2Ws['tally_fetched']) && !empty($v2Ws['mapping_completed'])
            ? (!empty($v2Ws['verified']) ? 'Complete' : 'Ready to reconcile')
            : 'Complete data steps first',
        'metric' => '',
        'metricLabel' => '',
    ],
];

/* ---- Overall data status ---- */
$v2DataComplete = !empty($v2Ws['ledger_fetched']) && !empty($v2Ws['mapping_completed']) && !empty($v2Ws['tally_fetched']);
$v2CompletedCount = 0;
foreach ($v2SubSections as $ss) {
    if ($ss['status'] === 'complete') $v2CompletedCount++;
}
?>

<?= uiBreadcrumb([
    ['label' => 'My Assignments', 'href' => BASE_URL . 'my_assignments.php'],
    ['label' => 'Data Console'],
]) ?>

<?= uiPageHero('Data Console', htmlspecialchars($v2CompanyName) . ' · FY ' . htmlspecialchars($v2FyName)) ?>

<?php
$navData = getWorkflowNavigation($pdo, $v2CompanyId, $v2FyId);
echo renderWorkflowNavigation($navData);
?>

<!-- Progress -->
<div class="v2-dw-progress">
    <div class="v2-dw-progress-bar">
        <div class="v2-dw-progress-fill" style="width: <?= $v2CompletedCount > 0 ? round($v2CompletedCount / 4 * 100) : 0 ?>%"></div>
    </div>
    <div class="v2-dw-progress-labels">
        <span><?= $v2CompletedCount ?>/4 sections complete</span>
        <?php if ($v2DataComplete): ?>
            <span class="v2-dw-progress-done">✓ Data preparation complete</span>
        <?php else: ?>
            <span><?= $v2TotalLedgers ?> ledgers · <?= $v2Mapped ?> mapped</span>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Stats -->
<div class="v2-dw-stats">
    <div class="v2-dw-stat">
        <span class="v2-dw-stat-num"><?= $v2TotalLedgers ?></span>
        <span class="v2-dw-stat-lbl">Ledgers</span>
    </div>
    <div class="v2-dw-stat">
        <span class="v2-dw-stat-num"><?= $v2Mapped ?></span>
        <span class="v2-dw-stat-lbl">Mapped</span>
    </div>
    <div class="v2-dw-stat">
        <span class="v2-dw-stat-num"><?= $v2Unmapped ?></span>
        <span class="v2-dw-stat-lbl">Pending</span>
    </div>
    <div class="v2-dw-stat <?= !empty($v2Ws['tally_fetched']) ? 'ok' : '' ?>">
        <span class="v2-dw-stat-num"><?= !empty($v2Ws['tally_fetched']) ? '✓' : '○' ?></span>
        <span class="v2-dw-stat-lbl">TB Status</span>
    </div>
</div>

<!-- Workflow Tiles (Horizontal) -->
<div class="v2-dw-tiles">
    <?php foreach ($v2SubSections as $idx => $ss): ?>
        <a href="<?= $ss['href'] ?>" class="v2-dw-tile" data-tile="<?= $ss['key'] ?>">
            <div class="v2-dw-tile-header">
                <div class="v2-dw-tile-icon"><?= $ss['icon'] ?></div>
                <div class="v2-dw-tile-info">
                    <h3><?= htmlspecialchars($ss['label']) ?></h3>
                    <?php
                    $ssBadgeVariant = ['complete' => 'success', 'partial' => 'warning', 'available' => 'info'][$ss['status']] ?? 'default';
                    $ssBadgeLabel = $ss['status'] === 'complete' ? '✓ Complete' : ($ss['status'] === 'partial' ? '◐ In Progress' : ($ss['status'] === 'available' ? '→ Available' : '○ Not Started'));
                    ?>
                    <?= uiStatusBadge($ssBadgeLabel, $ssBadgeVariant) ?>
                </div>
            </div>
            <p class="v2-dw-tile-desc"><?= htmlspecialchars($ss['desc']) ?></p>
            <?php if (!empty($ss['metric'])): ?>
                <div class="v2-dw-tile-metric">
                    <span class="v2-dw-tile-metric-num"><?= number_format($ss['metric']) ?></span>
                    <span class="v2-dw-tile-metric-lbl"><?= htmlspecialchars($ss['metricLabel']) ?></span>
                </div>
            <?php endif; ?>
            <div class="v2-dw-tile-detail"><?= htmlspecialchars($ss['detail']) ?></div>
            <div class="v2-dw-tile-action">
                <?= $ss['status'] === 'complete' ? 'Review' : ($ss['status'] === 'partial' ? 'Continue' : ($ss['status'] === 'available' ? 'Open' : 'Start')) ?> →
            </div>
        </a>
    <?php endforeach; ?>
</div>

<div style="margin-top:20px;">
    <?= uiButton('← Back to My Assignments', BASE_URL . 'my_assignments.php', 'outline') ?>
</div>

<!-- Import Method Modal -->
<div id="importModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#fff;border-radius:12px;max-width:600px;width:95%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <h3 style="margin:0;font-size:1.1rem;">&#128229; Choose Import Method</h3>
    <button onclick="document.getElementById('importModal').style.display='none'" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#666;">&times;</button>
</div>
<p style="font-size:0.88rem;color:#475569;margin:0 0 16px;">Select how you would like to import ledger and trial balance data.</p>

<a href="<?= BASE_URL ?>data_console/tally_online.php?company_id=<?= (int)$v2CompanyId ?>&fy_id=<?= (int)$v2FyId ?>" style="display:flex;align-items:center;gap:14px;padding:16px;border:2px solid var(--border);border-radius:10px;text-decoration:none;color:inherit;margin-bottom:10px;transition:all .15s;" onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'" onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
    <span style="font-size:1.6rem;">&#128279;</span>
    <div style="flex:1;">
        <div style="font-weight:700;font-size:0.95rem;">Tally Online Sync <span style="background:#dcfce7;color:#166534;font-size:0.68rem;padding:2px 8px;border-radius:99px;margin-left:6px;">Recommended</span></div>
        <div style="font-size:0.82rem;color:#666;margin-top:3px;">Connect directly to Tally via Smart Bridge / ODBC. Real-time ledger and trial balance sync.</div>
    </div>
    <span style="color:var(--brand);font-size:1.2rem;">&#8250;</span>
</a>

<a href="<?= BASE_URL ?>data_console/xml_import.php?company_id=<?= (int)$v2CompanyId ?>&fy_id=<?= (int)$v2FyId ?>" style="display:flex;align-items:center;gap:14px;padding:16px;border:2px solid var(--border);border-radius:10px;text-decoration:none;color:inherit;margin-bottom:10px;transition:all .15s;" onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'" onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
    <span style="font-size:1.6rem;">&#128196;</span>
    <div style="flex:1;">
        <div style="font-weight:700;font-size:0.95rem;">XML Upload <span style="background:#dbeafe;color:#1e40af;font-size:0.68rem;padding:2px 8px;border-radius:99px;margin-left:6px;">Popular</span></div>
        <div style="font-size:0.82rem;color:#666;margin-top:3px;">Upload Tally-exported XML files for ledgers and trial balance.</div>
    </div>
    <span style="color:var(--brand);font-size:1.2rem;">&#8250;</span>
</a>

<a href="<?= BASE_URL ?>data_console/tally_offline.php?company_id=<?= (int)$v2CompanyId ?>&fy_id=<?= (int)$v2FyId ?>" style="display:flex;align-items:center;gap:14px;padding:16px;border:2px solid var(--border);border-radius:10px;text-decoration:none;color:inherit;margin-bottom:16px;transition:all .15s;" onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'" onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
    <span style="font-size:1.6rem;">&#128203;</span>
    <div style="flex:1;">
        <div style="font-weight:700;font-size:0.95rem;">CSV / Excel / Manual <span style="background:#fef3c7;color:#92400e;font-size:0.68rem;padding:2px 8px;border-radius:99px;margin-left:6px;">Standard</span></div>
        <div style="font-size:0.82rem;color:#666;margin-top:3px;">Import from CSV/Excel or enter data manually.</div>
    </div>
    <span style="color:var(--brand);font-size:1.2rem;">&#8250;</span>
</a>

<div style="text-align:right;">
    <button onclick="document.getElementById('importModal').style.display='none'" style="padding:8px 20px;border:1px solid var(--border);border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;">Cancel</button>
</div>
</div>
</div>

<script>
(function() {
    /* Intercept Ledger Import tile click to show modal */
    var importTile = document.querySelector('.v2-dw-tile[data-tile="import"]');
    if (importTile) {
        importTile.addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('importModal').style.display = 'flex';
        });
    }
    /* Close modal on Escape */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('importModal');
            if (modal && modal.style.display === 'flex') {
                modal.style.display = 'none';
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
