<?php
/**
 * e-BAL V2 — e-Bal Gateway
 *
 * Landing page after login. Action-only gateway for entity management.
 * Shows: Create Entity, Open/Select Entity, Alter Entity, Archive Entity.
 * No entity cards or tables on this page.
 */
$page_title = 'e-Bal Gateway';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';

/* ---- Resolve owner (superadmin sees all, admin/staff see workspace) ---- */
$userId  = (int) ($_SESSION['user_id'] ?? 0);
$ownerId = getResolvedOwnerId($pdo);

/* ---- Check for last valid entity/FY context ---- */
$lastEntityId = (int) ($_SESSION['company_id'] ?? 0);
$lastFyId = (int) ($_SESSION['fy_id'] ?? 0);
$lastEntityName = $_SESSION['company_name'] ?? '';
$lastFyName = $_SESSION['fy_name'] ?? '';
$hasContinue = false;

if ($lastEntityId > 0 && $lastFyId > 0) {
    /* Validate entity exists and user can view it */
    if (canViewEntity($pdo, $lastEntityId)) {
        /* Validate FY exists for this entity */
        $fyStmt = $pdo->prepare("SELECT id, fy_label FROM financial_years WHERE id = ? AND company_id = ?");
        $fyStmt->execute([$lastFyId, $lastEntityId]);
        $fy = $fyStmt->fetch(PDO::FETCH_ASSOC);
        if ($fy) {
            $hasContinue = true;
            $stmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
            $stmt->execute([$lastEntityId]);
            $lastEntityName = $stmt->fetchColumn() ?: '';
            $lastFyName = $fy['fy_label'];
        }
    }
}

/* ---- Entity label map ---- */
$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
    'partnership' => 'Partnership', 'proprietorship' => 'Proprietorship',
    'trust' => 'Trust', 'society' => 'Society',
];
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway'],
]) ?>

<?= uiPageHero('e-Bal Gateway', 'Create, select, alter, or archive an entity before entering the financial year workspace.') ?>

<?= uiWorkspaceStart() ?>

<!-- Action Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin-bottom:32px;">

    <!-- Create Entity -->
    <a href="<?= BASE_URL ?>entity_create.php" style="text-decoration:none;">
        <div style="background:var(--panel);border:2px solid var(--border);border-radius:var(--radius-lg);padding:28px 24px;text-align:center;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:12px;min-height:180px;"
             onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,var(--brand),#1a6ba0);display:flex;align-items:center;justify-content:center;font-size:1.5rem;">➕</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">Create Entity</div>
            <div style="font-size:.8rem;color:var(--muted);line-height:1.4;">Add a new company, LLP, or non-corporate entity to your workspace.</div>
        </div>
    </a>

    <!-- Open / Select Entity -->
    <a href="<?= BASE_URL ?>entity_select.php" style="text-decoration:none;">
        <div style="background:var(--panel);border:2px solid var(--border);border-radius:var(--radius-lg);padding:28px 24px;text-align:center;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:12px;min-height:180px;"
             onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#059669,#10b981);display:flex;align-items:center;justify-content:center;font-size:1.5rem;">📂</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">Open / Select Entity</div>
            <div style="font-size:.8rem;color:var(--muted);line-height:1.4;">Browse and select an existing entity to work on its financial year.</div>
        </div>
    </a>

    <!-- Alter Entity -->
    <a href="<?= BASE_URL ?>entity_select.php?mode=edit" style="text-decoration:none;">
        <div style="background:var(--panel);border:2px solid var(--border);border-radius:var(--radius-lg);padding:28px 24px;text-align:center;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:12px;min-height:180px;"
             onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#d97706,#f59e0b);display:flex;align-items:center;justify-content:center;font-size:1.5rem;">✏️</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">Alter Entity</div>
            <div style="font-size:.8rem;color:var(--muted);line-height:1.4;">Edit entity master details. No financial data will be affected.</div>
        </div>
    </a>

    <!-- Archive Entity -->
    <a href="<?= BASE_URL ?>entity_select.php?mode=archive" style="text-decoration:none;">
        <div style="background:var(--panel);border:2px solid var(--border);border-radius:var(--radius-lg);padding:28px 24px;text-align:center;cursor:pointer;transition:all .2s;display:flex;flex-direction:column;align-items:center;gap:12px;min-height:180px;"
             onmouseover="this.style.borderColor='var(--brand)';this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            <div style="width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#dc2626,#ef4444);display:flex;align-items:center;justify-content:center;font-size:1.5rem;">🗄️</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text);">Archive Entity</div>
            <div style="font-size:.8rem;color:var(--muted);line-height:1.4;">Soft-archive an entity. It will be hidden from active views.</div>
        </div>
    </a>

</div>

<!-- Continue Last Workspace (conditional) -->
<?php if ($hasContinue): ?>
<div style="margin-bottom:32px;">
    <div style="font-size:.88rem;font-weight:700;color:var(--text);margin-bottom:12px;">Continue Working</div>
    <a href="<?= BASE_URL ?>fy_workspace.php?entity_id=<?= $lastEntityId ?>&fy_id=<?= $lastFyId ?>" style="text-decoration:none;">
        <div style="background:var(--panel);border:2px solid var(--brand);border-radius:var(--radius-lg);padding:20px 24px;display:flex;align-items:center;gap:16px;transition:all .2s;cursor:pointer;"
             onmouseover="this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.boxShadow='none'">
            <div style="width:48px;height:48px;border-radius:12px;background:var(--brand);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff;">▶</div>
            <div style="flex:1;">
                <div style="font-size:.95rem;font-weight:600;color:var(--text);"><?= htmlspecialchars($lastEntityName) ?></div>
                <div style="font-size:.8rem;color:var(--muted);">FY <?= htmlspecialchars($lastFyName) ?> — Continue to workspace</div>
            </div>
            <div style="font-size:.8rem;color:var(--brand);font-weight:600;">Open &rarr;</div>
        </div>
    </a>
</div>
<?php endif; ?>

<!-- Help Text -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:16px;">
    <div style="font-size:.82rem;color:var(--muted);line-height:1.6;">
        <strong>Getting Started:</strong> Create an entity or select an existing one. Then choose a financial year to enter the workspace with Data Centre, Financial Statements, Review Centre, and Deliverables.
    </div>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
