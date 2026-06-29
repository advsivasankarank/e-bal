<?php
/**
 * e-BAL V2 — Entity Dashboard
 *
 * Landing page after login. Shows all entities with management actions.
 * This is the default page after authentication.
 */
$page_title = 'Entity Dashboard';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';

/* ---- Resolve owner ---- */
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = $userId > 0 ? getOwnerUserId($pdo, $userId) : 0;

/* ---- Load all entities ---- */
$entities = [];
if ($ownerId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            c.id, c.name, c.category, c.pan, c.cin, c.llp_code,
            c.profile_completeness, c.created_at, c.updated_at,
            (SELECT COUNT(*) FROM financial_years fy WHERE fy.company_id = c.id) AS fy_count,
            (SELECT COUNT(*) FROM workflow_status ws WHERE ws.company_id = c.id AND ws.tally_fetched = 1) AS has_data
        FROM companies c
        WHERE c.owner_user_id = ?
        ORDER BY c.name ASC
    ");
    $stmt->execute([$ownerId]);
    $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalEntities = count($entities);
$totalFy = 0;
$entitiesWithData = 0;
foreach ($entities as $e) {
    $totalFy += (int) ($e['fy_count'] ?? 0);
    if ((int) ($e['has_data'] ?? 0) > 0) $entitiesWithData++;
}

$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
    'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
    'trust' => 'Trust', 'society' => 'Society',
];
?>

<?= uiBreadcrumb([
    ['label' => 'Entity Dashboard'],
]) ?>

<?= uiPageHero('Entity Dashboard', 'Manage your entities and navigate to financial year selection.') ?>

<?= uiKpiCards([
    ['value' => $totalEntities, 'label' => 'Entities', 'href' => BASE_URL . 'company_dashboard/company_list.php'],
    ['value' => $totalFy, 'label' => 'Financial Years'],
    ['value' => $entitiesWithData, 'label' => 'With Data'],
    ['value' => $totalEntities - $entitiesWithData, 'label' => 'New Entities'],
]) ?>

<?= uiWorkspaceStart() ?>

<!-- Primary Actions -->
<div style="margin-bottom:24px;">
    <div style="font-size:.88rem;font-weight:700;color:var(--text);margin-bottom:12px;">Quick Actions</div>
    <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <?= uiButton('Create Entity', BASE_URL . 'company_dashboard/company_create.php', 'primary', '➕') ?>
        <?= uiButton('Fetch From Tally', BASE_URL . 'company_dashboard/company_create.php', 'outline', '🔄') ?>
    </div>
</div>

<!-- Entity Grid -->
<div style="margin-bottom:24px;">
    <div style="font-size:.88rem;font-weight:700;color:var(--text);margin-bottom:12px;">Your Entities (<?= $totalEntities ?>)</div>

    <?php if (empty($entities)): ?>
        <?= uiEmptyState('🏢', 'No Entities Yet', 'Create your first entity to begin preparing financial statements.', 'Create Entity', BASE_URL . 'company_dashboard/company_create.php') ?>
    <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;">
            <?php foreach ($entities as $ent):
                $catKey = strtolower(str_replace(['-', ' '], '_', $ent['category'] ?? ''));
                $entLabel = $entityLabelMap[$catKey] ?? ucfirst($ent['category'] ?? '');
                $pct = (int) ($ent['profile_completeness'] ?? 0);
                $pctColor = $pct >= 80 ? 'var(--success)' : ($pct >= 40 ? 'var(--warning)' : 'var(--danger)');
                $identifier = '';
                if ($catKey === 'corporate' && !empty($ent['cin'])) $identifier = $ent['cin'];
                elseif ($catKey === 'llp' && !empty($ent['llp_code'])) $identifier = $ent['llp_code'];
                elseif (!empty($ent['pan'])) $identifier = $ent['pan'];
                $lastUpdate = $ent['updated_at'] ? date('d M Y', strtotime($ent['updated_at'])) : '';
            ?>
            <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px;display:flex;flex-direction:column;gap:12px;transition:box-shadow .15s;" onmouseover="this.style.boxShadow='var(--shadow)'" onmouseout="this.style.boxShadow='none'">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--brand),#1a6ba0);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.9rem;flex-shrink:0;">
                        <?= strtoupper(substr($ent['name'], 0, 1)) ?>
                    </div>
                    <div style="min-width:0;flex:1;">
                        <div style="font-size:.9rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($ent['name']) ?></div>
                        <div style="font-size:.72rem;color:var(--muted);display:flex;gap:8px;align-items:center;">
                            <?= uiStatusBadge($entLabel, 'brand') ?>
                            <?php if ($identifier): ?>
                                <span style="color:var(--border);">•</span>
                                <span><?= htmlspecialchars($identifier) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:center;">
                        <div style="width:44px;height:44px;border-radius:50%;border:3px solid <?= $pctColor ?>;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:<?= $pctColor ?>;"><?= $pct ?>%</div>
                    </div>
                </div>
                <div style="font-size:.72rem;color:var(--muted);display:flex;gap:12px;">
                    <span><?= $ent['fy_count'] ?> FY<?= $ent['fy_count'] != 1 ? 's' : '' ?></span>
                    <?php if ($lastUpdate): ?>
                        <span>Updated <?= $lastUpdate ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;margin-top:auto;">
                    <a href="<?= BASE_URL ?>entity_home.php?company_id=<?= (int) $ent['id'] ?>" class="v2-btn v2-btn--primary" style="flex:1;font-size:.78rem;">Open</a>
                    <a href="<?= BASE_URL ?>company_dashboard/company_edit.php?id=<?= (int) $ent['id'] ?>" class="v2-btn v2-btn--outline" style="font-size:.78rem;">Edit</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
