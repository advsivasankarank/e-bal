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

/* ---- Define sub-sections ---- */
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
    ],
    [
        'key'   => 'mapping',
        'icon'  => '🗂️',
        'label' => 'Mapping',
        'desc'  => 'Map ledgers to Schedule III codes with AI assistance',
        'href'  => BASE_URL . 'data_console/mapping_workbench.php',
        'status' => !empty($v2Ws['mapping_completed']) ? 'complete' : ($v2Mapped > 0 ? 'partial' : 'pending'),
        'detail' => !empty($v2Ws['mapping_completed'])
            ? 'Mapping complete'
            : ($v2Mapped > 0 ? $v2Mapped . ' ledgers mapped, ' . $v2Unmapped . ' pending' : 'Mapping not started'),
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
            : 'Trial balance not loaded',
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
            ? (!empty($v2Ws['verified']) ? 'Reconciliation complete' : 'Ready to reconcile')
            : 'Complete data steps first',
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

<!-- Sub-section Tabs -->
<div class="v2-dw-tabs" id="dw-tabs">
    <?php foreach ($v2SubSections as $idx => $ss): ?>
        <button class="v2-dw-tab <?= $idx === 0 ? 'active' : '' ?>" data-tab="<?= $ss['key'] ?>">
            <span class="v2-dw-tab-icon"><?= $ss['icon'] ?></span>
            <span class="v2-dw-tab-label"><?= htmlspecialchars($ss['label']) ?></span>
            <span class="v2-dw-tab-status v2-dw-tab-<?= $ss['status'] ?>">
                <?= $ss['status'] === 'complete' ? '✓' : ($ss['status'] === 'partial' ? '◐' : ($ss['status'] === 'available' ? '→' : '○')) ?>
            </span>
        </button>
    <?php endforeach; ?>
</div>

<!-- Sub-section Content -->
<div class="v2-dw-panels">
    <?php foreach ($v2SubSections as $idx => $ss): ?>
        <div class="v2-dw-panel <?= $idx === 0 ? 'active' : '' ?>" data-panel="<?= $ss['key'] ?>">
            <div class="v2-dw-panel-header">
                <div class="v2-dw-panel-icon"><?= $ss['icon'] ?></div>
                <div class="v2-dw-panel-info">
                    <h2><?= htmlspecialchars($ss['label']) ?></h2>
                    <p><?= htmlspecialchars($ss['desc']) ?></p>
                </div>
                <div class="v2-dw-panel-status">
                    <span class="v2-dw-badge v2-dw-badge-<?= $ss['status'] ?>">
                        <?= $ss['status'] === 'complete' ? 'Complete' : ($ss['status'] === 'partial' ? 'In Progress' : ($ss['status'] === 'available' ? 'Available' : 'Not Started')) ?>
                    </span>
                </div>
            </div>
            <div class="v2-dw-panel-detail">
                <span class="v2-dw-panel-detail-text"><?= htmlspecialchars($ss['detail']) ?></span>
            </div>
            <div class="v2-dw-panel-action">
                <a href="<?= $ss['href'] ?>" class="v2-btn v2-btn-primary">
                    <?= $ss['status'] === 'complete' ? 'Review' : ($ss['status'] === 'partial' ? 'Continue' : ($ss['status'] === 'available' ? 'Open' : 'Start')) ?> →
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div style="margin-top:20px;">
    <?= uiButton('← Back to My Assignments', BASE_URL . 'my_assignments.php', 'outline') ?>
</div>

<script>
(function() {
    var tabs = document.querySelectorAll('.v2-dw-tab');
    var panels = document.querySelectorAll('.v2-dw-panel');

    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function() {
            var target = this.getAttribute('data-tab');

            for (var j = 0; j < tabs.length; j++) tabs[j].classList.remove('active');
            for (var k = 0; k < panels.length; k++) panels[k].classList.remove('active');

            this.classList.add('active');
            var panel = document.querySelector('[data-panel="' + target + '"]');
            if (panel) panel.classList.add('active');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
