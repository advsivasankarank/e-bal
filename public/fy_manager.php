<?php
/**
 * e-BAL V2 — Financial Year Manager
 *
 * After entity selection, user must select/create/copy FY before entering workspace.
 */
$page_title = 'Financial Year Manager';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/financial_year_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$entityId = (int) ($_GET['entity_id'] ?? 0);

/* ---- Validate entity access ---- */
validateEntityAccessOrRedirect($pdo, $entityId, 'view');

$stmt = $pdo->prepare("SELECT id, name, category, pan, cin, llp_code FROM companies WHERE id = ?");
$stmt->execute([$entityId]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entity) {
    $_SESSION['error'] = 'Entity not found.';
    header("Location: " . BASE_URL . "dashboard_company.php");
    exit;
}

/* ---- Load FYs for this entity ---- */
$fys = [];
$fystmt = $pdo->prepare("SELECT id, fy_label, fy_start, fy_end, status FROM financial_years WHERE company_id = ? ORDER BY fy_start DESC");
$fystmt->execute([$entityId]);
$fys = $fystmt->fetchAll(PDO::FETCH_ASSOC);

/* ---- Handle Create FY POST ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['fy_action'] ?? '') === 'create') {
    requireCsrfToken();
    $fyLabel = trim((string) ($_POST['fy_label'] ?? ''));
    $fyStart = trim((string) ($_POST['fy_start'] ?? ''));
    $fyEnd = trim((string) ($_POST['fy_end'] ?? ''));

    if ($fyLabel === '') {
        $_SESSION['error'] = 'FY label is required.';
    } else {
        /* Check duplicate */
        $dupStmt = $pdo->prepare("SELECT id FROM financial_years WHERE company_id = ? AND fy_label = ?");
        $dupStmt->execute([$entityId, $fyLabel]);
        if ($dupStmt->fetch()) {
            $_SESSION['error'] = 'This Financial Year already exists for this entity.';
        } else {
            try {
                $result = ensureFinancialYearRecord($pdo, $entityId, $fyLabel);
                $newFyId = is_array($result) ? (int) ($result['id'] ?? 0) : (int) $result;

                if ($newFyId <= 0) {
                    $_SESSION['error'] = 'Financial Year was created, but workspace could not be opened. Please open it from the FY list.';
                    header("Location: " . BASE_URL . "fy_manager.php?entity_id=" . $entityId);
                    exit;
                }

                $_SESSION['company_id'] = $entityId;
                $_SESSION['company_name'] = $entity['name'];
                $_SESSION['fy_id'] = $newFyId;
                $_SESSION['fy_name'] = $fyLabel;

                header("Location: " . BASE_URL . "fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $newFyId);
                exit;
            } catch (\Throwable $e) {
                error_log('FY create failed: ' . $e->getMessage());
                $_SESSION['error'] = 'Financial Year was created, but workspace could not be opened. Please open it from the FY list.';
                header("Location: " . BASE_URL . "fy_manager.php?entity_id=" . $entityId);
                exit;
            }
        }
    }
    header("Location: " . BASE_URL . "fy_manager.php?entity_id=" . $entityId);
    exit;
}

/* ---- Handle Select FY ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['fy_action'] ?? '') === 'select') {
    requireCsrfToken();
    $selFyId = (int) ($_POST['fy_id'] ?? 0);
    if ($selFyId > 0) {
        $fyStmt = $pdo->prepare("SELECT id, fy_label FROM financial_years WHERE id = ? AND company_id = ?");
        $fyStmt->execute([$selFyId, $entityId]);
        $selFy = $fyStmt->fetch(PDO::FETCH_ASSOC);
        if ($selFy) {
            $_SESSION['company_id'] = $entityId;
            $_SESSION['company_name'] = $entity['name'];
            $_SESSION['fy_id'] = $selFy['id'];
            $_SESSION['fy_name'] = $selFy['fy_label'];

            header("Location: " . BASE_URL . "fy_workspace.php?entity_id=" . $entityId . "&fy_id=" . $selFy['id']);
            exit;
        }
    }
    $_SESSION['error'] = 'Invalid Financial Year selected.';
    header("Location: " . BASE_URL . "fy_manager.php?entity_id=" . $entityId);
    exit;
}

$entityLabelMap = [
    'corporate' => 'Company', 'llp' => 'LLP', 'non_corporate' => 'Non-Corporate',
];
$catKey = strtolower(str_replace(['-', ' '], '_', $entity['category'] ?? ''));
$entLabel = $entityLabelMap[$catKey] ?? ucfirst($entity['category'] ?? '');
$identifier = $entity['cin'] ?? $entity['llp_code'] ?? $entity['pan'] ?? '';
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => htmlspecialchars($entity['name'])],
    ['label' => 'FY Manager'],
]) ?>

<?= uiPageHero('Financial Year Manager', 'Select an existing Financial Year or create a new one for ' . htmlspecialchars($entity['name'])) ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box" style="margin-bottom:16px;"><?= htmlspecialchars($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<?= uiWorkspaceStart() ?>

<!-- Entity Info -->
<div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:18px 22px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
    <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,var(--brand),#1a6ba0);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:1rem;flex-shrink:0;">
        <?= strtoupper(substr($entity['name'], 0, 1)) ?>
    </div>
    <div>
        <div style="font-size:1rem;font-weight:600;"><?= htmlspecialchars($entity['name']) ?></div>
        <div style="font-size:.8rem;color:var(--muted);"><?= $entLabel ?> <?php if ($identifier): ?> · <?= htmlspecialchars($identifier) ?><?php endif; ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

    <!-- Existing FYs -->
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;">
        <div style="font-size:.95rem;font-weight:700;margin-bottom:14px;">Open Existing FY</div>
        <?php if (empty($fys)): ?>
            <p style="font-size:.85rem;color:var(--muted);margin-bottom:16px;">No Financial Years found for this entity. Create one first.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
                <?php foreach ($fys as $fy): ?>
                <form method="post" style="display:flex;">
                    <?= csrfInput() ?>
                    <input type="hidden" name="fy_action" value="select">
                    <input type="hidden" name="fy_id" value="<?= (int) $fy['id'] ?>">
                    <button type="submit" style="flex:1;display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--panel-strong);border:1px solid var(--border);border-radius:8px;cursor:pointer;text-align:left;transition:border-color .15s;font-size:.88rem;"
                            onmouseover="this.style.borderColor='var(--brand)'" onmouseout="this.style.borderColor='var(--border)'">
                        <div>
                            <div style="font-weight:600;">FY <?= htmlspecialchars($fy['fy_label']) ?></div>
                            <div style="font-size:.75rem;color:var(--muted);"><?= $fy['fy_start'] ? date('d M Y', strtotime($fy['fy_start'])) : '' ?> to <?= $fy['fy_end'] ? date('d M Y', strtotime($fy['fy_end'])) : '' ?></div>
                        </div>
                        <span style="color:var(--brand);font-weight:600;">Open &rarr;</span>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create New FY -->
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;">
        <div style="font-size:.95rem;font-weight:700;margin-bottom:14px;">Create New FY</div>
        <form method="post">
            <?= csrfInput() ?>
            <input type="hidden" name="fy_action" value="create">

            <div style="margin-bottom:12px;">
                <label for="fy_label" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">FY Label <span style="color:var(--danger);">*</span></label>
                <input type="text" id="fy_label" name="fy_label" placeholder="e.g. 2024-2025" required style="width:100%;padding:9px 12px;border:1px solid var(--border-strong);border-radius:8px;font-size:.85rem;">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
                <div>
                    <label for="fy_start" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">Start Date</label>
                    <input type="date" id="fy_start" name="fy_start" style="width:100%;padding:9px 12px;border:1px solid var(--border-strong);border-radius:8px;font-size:.85rem;">
                </div>
                <div>
                    <label for="fy_end" style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px;">End Date</label>
                    <input type="date" id="fy_end" name="fy_end" style="width:100%;padding:9px 12px;border:1px solid var(--border-strong);border-radius:8px;font-size:.85rem;">
                </div>
            </div>

            <button type="submit" class="v2-btn v2-btn--primary" style="width:100%;">Create Financial Year</button>
        </form>
    </div>

</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
