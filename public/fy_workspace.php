<?php
/**
 * e-BAL V2 — FY Workspace
 *
 * After entity + FY selection, shows four main modules:
 * Data Centre, Financial Statements, Review Centre, Deliverables.
 */
$page_title = 'Financial Year Console';

/* ---- Bootstrap before any output ---- */
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';
require_once __DIR__ . '/../app/helpers/workflow_navigation_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$entityId = (int) ($_GET['entity_id'] ?? 0);
$fyId = (int) ($_GET['fy_id'] ?? 0);

/* ---- Validate entity access (may redirect) ---- */
validateEntityAccessOrRedirect($pdo, $entityId, 'view');

$stmt = $pdo->prepare("SELECT id, name, category, pan, cin, llp_code FROM companies WHERE id = ?");
$stmt->execute([$entityId]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entity) {
    $_SESSION['error'] = 'Entity not found.';
    header("Location: " . BASE_URL . "dashboard_company.php");
    exit;
}

/* ---- Validate FY (may redirect) ---- */
if ($fyId <= 0) {
    header("Location: " . BASE_URL . "fy_manager.php?entity_id=" . $entityId);
    exit;
}

$fyStmt = $pdo->prepare("SELECT id, fy_label, fy_start, fy_end, status FROM financial_years WHERE id = ? AND company_id = ?");
$fyStmt->execute([$fyId, $entityId]);
$fy = $fyStmt->fetch(PDO::FETCH_ASSOC);

if (!$fy) {
    $_SESSION['error'] = 'Financial Year not found for this entity.';
    header("Location: " . BASE_URL . "fy_manager.php?entity_id=" . $entityId);
    exit;
}

/* ---- Set session context ---- */
$_SESSION['company_id'] = $entityId;
$_SESSION['company_name'] = $entity['name'];
$_SESSION['fy_id'] = $fyId;
$_SESSION['fy_name'] = $fy['fy_label'];

/* Ensure session is written for subsequent requests */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/* ---- Load workflow status ---- */
$wfStmt = $pdo->prepare("SELECT * FROM workflow_status WHERE company_id = ? AND fy_id = ?");
$wfStmt->execute([$entityId, $fyId]);
$wf = $wfStmt->fetch(PDO::FETCH_ASSOC);

$wfStatus = [
    'tally_fetched' => (int) ($wf['tally_fetched'] ?? 0),
    'ledger_fetched' => (int) ($wf['ledger_fetched'] ?? 0),
    'mapping_completed' => (int) ($wf['mapping_completed'] ?? 0),
    'notes_prepared' => (int) ($wf['notes_prepared'] ?? 0),
    'profit_loss_prepared' => (int) ($wf['profit_loss_prepared'] ?? 0),
    'balance_sheet_prepared' => (int) ($wf['balance_sheet_prepared'] ?? 0),
    'verified' => (int) ($wf['verified'] ?? 0),
    'reports_generated' => (int) ($wf['reports_generated'] ?? 0),
];

/* ---- Module status helpers ---- */
function moduleStatus(array $wf, string $key): array {
    $val = $wf[$key] ?? 0;
    return [
        'done' => $val >= 1,
        'label' => $val >= 1 ? 'Completed' : 'Pending',
        'color' => $val >= 1 ? 'var(--success)' : 'var(--muted)',
    ];
}

$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
];
$catKey = strtolower(str_replace(['-', ' '], '_', $entity['category'] ?? ''));
$entLabel = $entityLabelMap[$catKey] ?? ucfirst($entity['category'] ?? '');

/* ---- All redirects complete, safe to output HTML ---- */
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => htmlspecialchars($entity['name'])],
    ['label' => 'FY ' . htmlspecialchars($fy['fy_label'])],
]) ?>

<?= uiPageHero('Financial Year Console', htmlspecialchars($entity['name']) . ' — Financial Year ' . htmlspecialchars($fy['fy_label'])) ?>

<?php
$navData = getWorkflowNavigation($pdo, $entityId, $fyId);
echo renderWorkflowNavigation($navData);
?>

<?= uiWorkspaceStart() ?>

<!-- Workspace Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:24px;">

    <!-- Data Centre -->
    <a href="<?= BASE_URL ?>data/index.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" style="text-decoration:none;">
        <?php
        $dcStatus = moduleStatus($wfStatus, 'mapping_completed');
        if (!$wfStatus['tally_fetched']) $dcNext = 'Fetch Ledgers from Tally';
        elseif (!$wfStatus['ledger_fetched']) $dcNext = 'Import Trial Balance';
        elseif (!$wfStatus['mapping_completed']) $dcNext = 'Map Ledgers';
        else $dcNext = 'Data Centre Ready';
        ?>
        <div style="background:var(--panel);border:2px solid <?= $dcStatus['done'] ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;transition:all .2s;min-height:160px;"
             onmouseover="this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#3b82f6);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">📊</div>
                <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $dcStatus['color'] ?>15;color:<?= $dcStatus['color'] ?>;"><?= $dcStatus['label'] ?></span>
            </div>
            <div>
                <div style="font-size:1rem;font-weight:700;color:var(--text);">Data Centre</div>
                <div style="font-size:.8rem;color:var(--muted);margin-top:4px;"><?= $dcNext ?></div>
            </div>
            <div style="font-size:.8rem;color:var(--brand);font-weight:600;margin-top:auto;">Open &rarr;</div>
        </div>
    </a>

    <!-- Financial Statements -->
    <a href="<?= BASE_URL ?>statements/financials.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" style="text-decoration:none;">
        <?php
        $fsDone = $wfStatus['balance_sheet_prepared'] || $wfStatus['profit_loss_prepared'];
        $fsStatus = $fsDone ? ['done' => true, 'label' => 'Completed', 'color' => 'var(--success)'] : ['done' => false, 'label' => 'Pending', 'color' => 'var(--muted)'];
        $fsNext = $fsDone ? 'View Statements' : 'Generate Balance Sheet & P&L';
        ?>
        <div style="background:var(--panel);border:2px solid <?= $fsStatus['done'] ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;transition:all .2s;min-height:160px;"
             onmouseover="this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#a78bfa);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">📑</div>
                <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $fsStatus['color'] ?>15;color:<?= $fsStatus['color'] ?>;"><?= $fsStatus['label'] ?></span>
            </div>
            <div>
                <div style="font-size:1rem;font-weight:700;color:var(--text);">Financial Statements</div>
                <div style="font-size:.8rem;color:var(--muted);margin-top:4px;"><?= $fsNext ?></div>
            </div>
            <div style="font-size:.8rem;color:var(--brand);font-weight:600;margin-top:auto;">Open &rarr;</div>
        </div>
    </a>

    <!-- Review Centre -->
    <a href="<?= BASE_URL ?>review/index.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" style="text-decoration:none;">
        <?php
        $rvDone = $wfStatus['verified'] >= 1;
        $rvStatus = $rvDone ? ['done' => true, 'label' => 'Completed', 'color' => 'var(--success)'] : ['done' => false, 'label' => 'Pending', 'color' => 'var(--muted)'];
        $rvNext = $rvDone ? 'View Review' : 'Run Validation Checks & Sign-offs';
        ?>
        <div style="background:var(--panel);border:2px solid <?= $rvStatus['done'] ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;transition:all .2s;min-height:160px;"
             onmouseover="this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#059669,#10b981);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">✅</div>
                <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $rvStatus['color'] ?>15;color:<?= $rvStatus['color'] ?>;"><?= $rvStatus['label'] ?></span>
            </div>
            <div>
                <div style="font-size:1rem;font-weight:700;color:var(--text);">Review Centre</div>
                <div style="font-size:.8rem;color:var(--muted);margin-top:4px;"><?= $rvNext ?></div>
            </div>
            <div style="font-size:.8rem;color:var(--brand);font-weight:600;margin-top:auto;">Open &rarr;</div>
        </div>
    </a>

    <!-- Deliverables -->
    <a href="<?= BASE_URL ?>deliverables/index.php?entity_id=<?= $entityId ?>&fy_id=<?= $fyId ?>" style="text-decoration:none;">
        <?php
        $dlDone = $wfStatus['reports_generated'] >= 1;
        $dlStatus = $dlDone ? ['done' => true, 'label' => 'Completed', 'color' => 'var(--success)'] : ['done' => false, 'label' => 'Pending', 'color' => 'var(--muted)'];
        $dlNext = $dlDone ? 'Download Reports' : 'Generate PDF, DOCX, XLSX Deliverables';
        ?>
        <div style="background:var(--panel);border:2px solid <?= $dlStatus['done'] ? 'var(--success)' : 'var(--border)' ?>;border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px;transition:all .2s;min-height:160px;"
             onmouseover="this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#dc2626,#ef4444);display:flex;align-items:center;justify-content:center;font-size:1.2rem;">📦</div>
                <span style="font-size:.72rem;font-weight:600;padding:3px 10px;border-radius:999px;background:<?= $dlStatus['color'] ?>15;color:<?= $dlStatus['color'] ?>;"><?= $dlStatus['label'] ?></span>
            </div>
            <div>
                <div style="font-size:1rem;font-weight:700;color:var(--text);">Deliverables</div>
                <div style="font-size:.8rem;color:var(--muted);margin-top:4px;"><?= $dlNext ?></div>
            </div>
            <div style="font-size:.8rem;color:var(--brand);font-weight:600;margin-top:auto;">Open &rarr;</div>
        </div>
    </a>

</div>

<!-- Quick Actions -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
    <a href="<?= BASE_URL ?>fy_manager.php?entity_id=<?= $entityId ?>" class="v2-btn v2-btn--outline">Switch Financial Year</a>
    <a href="<?= BASE_URL ?>entity_select.php" class="v2-btn v2-btn--outline">Switch Entity</a>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
