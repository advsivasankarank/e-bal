<?php
/**
 * e-BAL V2 — Data Centre
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
$page_title = 'Data Centre';
require_once __DIR__ . '/../../app/context_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/workflow_engine.php';

/* ---- Require active context ---- */
$v2CompanyId = (int) ($_SESSION['company_id'] ?? 0);
$v2FyId      = (int) ($_SESSION['fy_id']      ?? 0);
$v2CompanyName = (string) ($_SESSION['company_name'] ?? '');
$v2FyName = (string) ($_SESSION['fy_name'] ?? '');

if ($v2CompanyId <= 0 || $v2FyId <= 0) {
    /* Context missing — redirect to Entity Dashboard */
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
        'label' => 'Ledger Mapping',
        'desc'  => 'Map ledgers to Schedule III codes with AI assistance',
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
    ['label' => 'Data Centre'],
]) ?>

<?= uiPageHero('Data Centre', htmlspecialchars($v2CompanyName) . ' · FY ' . htmlspecialchars($v2FyName)) ?>

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
                    <span class="v2-dw-badge v2-dw-badge-<?= $ss['status'] ?>">
                        <?= $ss['status'] === 'complete' ? '✓ Complete' : ($ss['status'] === 'partial' ? '◐ In Progress' : ($ss['status'] === 'available' ? '→ Available' : '○ Not Started')) ?>
                    </span>
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

<script>
(function() {
    /* Tiles are direct links — no tab switching needed */
    var tiles = document.querySelectorAll('.v2-dw-tile');
    for (var i = 0; i < tiles.length; i++) {
        tiles[i].addEventListener('click', function(e) {
            /* Allow default link navigation */
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
