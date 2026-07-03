<?php
/**
 * e-BAL V2 — Entity Select Page
 *
 * Shows active entities for selecting/opening/editing/archiving.
 * Supports modes: open (default), edit, archive.
 */
$page_title = 'Select Entity';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'open')));
if (!in_array($mode, ['open', 'edit', 'archive'], true)) {
    $mode = 'open';
}

/* ---- Mode labels ---- */
$modeLabels = [
    'open'    => 'Select Entity',
    'edit'    => 'Select Entity to Alter',
    'archive' => 'Select Entity to Archive',
];
$pageLabel = $modeLabels[$mode] ?? 'Select Entity';

/* ---- Load accessible entities using centralized helper ---- */
$entities = getAccessibleEntities($pdo);

$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
    'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
    'trust' => 'Trust', 'society' => 'Society',
];

/* ---- Handle archive POST ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'archive') {
    requireCsrfToken();
    $archiveId = (int) ($_POST['entity_id'] ?? 0);
    if ($archiveId > 0 && canArchiveEntity($pdo, $archiveId)) {
        if (archiveEntity($pdo, $archiveId)) {
            $_SESSION['success'] = 'Entity archived successfully.';
        } else {
            $_SESSION['error'] = 'Failed to archive entity.';
        }
    } else {
        $_SESSION['error'] = 'You do not have permission to archive this entity.';
    }
    header("Location: " . BASE_URL . "entity_select.php?mode=archive");
    exit;
}
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => $pageLabel],
]) ?>

<?= uiPageHero($pageLabel, $mode === 'archive' ? 'Select an entity to archive. Archived entities are hidden from active views.' : 'Browse your entities and select one to proceed.') ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?= uiWorkspaceStart() ?>

<?php if (empty($entities)): ?>
    <?= uiEmptyState('🏢', 'No Entities Yet', 'Create your first entity to begin preparing financial statements.', 'Create Entity', BASE_URL . 'entity_create.php') ?>
<?php else: ?>
    <!-- Search -->
    <div style="margin-bottom:16px;">
        <input type="text" id="entitySearch" placeholder="Search entities by name, PAN, CIN..." style="width:100%;max-width:400px;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.85rem;background:var(--panel);">
    </div>

    <!-- Entity Grid -->
    <div id="entityGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;">
        <?php foreach ($entities as $ent):
            $catKey = strtolower(str_replace(['-', ' '], '_', $ent['category'] ?? ''));
            $entLabel = $entityLabelMap[$catKey] ?? ucfirst($ent['category'] ?? '');
            $identifier = '';
            if ($catKey === 'corporate' && !empty($ent['cin'])) $identifier = $ent['cin'];
            elseif ($catKey === 'llp' && !empty($ent['llp_code'])) $identifier = $ent['llp_code'];
            elseif (!empty($ent['pan'])) $identifier = $ent['pan'];
            $lastUpdate = $ent['updated_at'] ? date('d M Y', strtotime($ent['updated_at'])) : '';

            /* Determine action URL based on mode */
            if ($mode === 'open') {
                $actionUrl = BASE_URL . 'fy_workspace.php?entity_id=' . (int) $ent['id'];
                $actionLabel = 'Select';
                $actionClass = 'v2-btn--primary';
            } elseif ($mode === 'edit') {
                $actionUrl = BASE_URL . 'entity_edit.php?id=' . (int) $ent['id'];
                $actionLabel = 'Edit';
                $actionClass = 'v2-btn--primary';
            } else {
                $actionUrl = BASE_URL . 'entity_select.php?mode=archive&action=confirm&id=' . (int) $ent['id'];
                $actionLabel = 'Archive';
                $actionClass = 'v2-btn--danger';
            }
        ?>
        <div class="entity-card" data-search="<?= htmlspecialchars(strtolower($ent['name'] . ' ' . $ent['pan'] . ' ' . $ent['cin'] . ' ' . $ent['llp_code'])) ?>"
             style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px;display:flex;flex-direction:column;gap:12px;transition:box-shadow .15s;"
             onmouseover="this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--brand),#1a6ba0);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.9rem;flex-shrink:0;">
                    <?= strtoupper(substr($ent['name'], 0, 1)) ?>
                </div>
                <div style="min-width:0;flex:1;">
                    <div style="font-size:.9rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($ent['name']) ?></div>
                    <div style="font-size:.72rem;color:var(--muted);display:flex;gap:8px;align-items:center;">
                        <?= uiStatusBadge($entLabel, 'brand') ?>
                        <?php if ($identifier): ?>
                            <span style="color:var(--border);">·</span>
                            <span><?= htmlspecialchars($identifier) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="font-size:.72rem;color:var(--muted);display:flex;gap:12px;">
                <span><?= $ent['fy_count'] ?> FY<?= $ent['fy_count'] != 1 ? 's' : '' ?></span>
                <?php if ($lastUpdate): ?>
                    <span>Updated <?= $lastUpdate ?></span>
                <?php endif; ?>
            </div>
            <div style="margin-top:auto;">
                <a href="<?= $actionUrl ?>" class="v2-btn <?= $actionClass ?>" style="width:100%;font-size:.78rem;text-align:center;"><?= $actionLabel ?></a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Archive Confirm Modal (inline) -->
<?php if ($mode === 'archive' && isset($_GET['action']) && $_GET['action'] === 'confirm'): ?>
<?php
    $confirmId = (int) ($_GET['id'] ?? 0);
    $confirmEntity = null;
    if ($confirmId > 0 && canArchiveEntity($pdo, $confirmId)) {
        $stmt = $pdo->prepare("SELECT id, name FROM companies WHERE id = ?");
        $stmt->execute([$confirmId]);
        $confirmEntity = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($confirmEntity):
?>
<div style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;display:flex;align-items:center;justify-content:center;" onclick="if(event.target===this)window.location='<?= BASE_URL ?>entity_select.php?mode=archive'">
    <div style="background:var(--panel);border-radius:var(--radius-lg);padding:28px;max-width:420px;width:90%;box-shadow:var(--shadow-lg);">
        <h3 style="font-size:1.1rem;margin-bottom:8px;">Archive Entity</h3>
        <p style="font-size:.88rem;color:var(--muted);margin-bottom:20px;">
            Are you sure you want to archive <strong><?= htmlspecialchars($confirmEntity['name']) ?></strong>?<br>
            It will be hidden from active views but data will be preserved.
        </p>
        <form method="post" style="display:flex;gap:12px;justify-content:flex-end;">
            <?= csrfInput() ?>
            <input type="hidden" name="entity_id" value="<?= $confirmId ?>">
            <a href="<?= BASE_URL ?>entity_select.php?mode=archive" class="v2-btn v2-btn--outline">Cancel</a>
            <button type="submit" class="v2-btn v2-btn--danger">Archive</button>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
document.getElementById('entitySearch').addEventListener('input', function() {
    var q = this.value.toLowerCase().trim();
    var cards = document.querySelectorAll('.entity-card');
    cards.forEach(function(card) {
        var hay = card.getAttribute('data-search') || '';
        card.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
    });
});
</script>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
