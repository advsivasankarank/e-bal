<?php
/**
 * e-BAL V2 — Create Entity
 *
 * Create a new company/LLP/non-corporate entity.
 * Only Entity Name is mandatory. Other fields can be filled later.
 */
$page_title = 'Create Entity';
require_once __DIR__ . '/layouts/header_v2.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);

/* Check create permission */
if (!canCreateEntity($pdo)) {
    $_SESSION['error'] = 'You do not have permission to create entities.';
    header("Location: " . BASE_URL . "dashboard_company.php");
    exit;
}

/* Resolve owner for entity ownership */
$ownerId = getResolvedOwnerId($pdo);

$errors = [];
$old = [
    'name' => '', 'category' => 'corporate', 'pan' => '', 'cin' => '',
    'llp_code' => '', 'address' => '', 'email' => '', 'mobile' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $name = trim((string) ($_POST['name'] ?? ''));
    $category = strtolower(trim((string) ($_POST['category'] ?? 'corporate')));
    $pan = strtoupper(trim((string) ($_POST['pan'] ?? '')));
    $cin = strtoupper(trim((string) ($_POST['cin'] ?? '')));
    $llpCode = strtoupper(trim((string) ($_POST['llp_code'] ?? '')));
    $address = trim((string) ($_POST['address'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $mobile = trim((string) ($_POST['mobile'] ?? ''));

    $old = compact('name', 'category', 'pan', 'cin', 'llp_code', 'address', 'email', 'mobile');

    /* Validation */
    if ($name === '') $errors[] = 'Entity name is required.';
    if (!in_array($category, ['corporate', 'llp', 'non_corporate'], true)) $errors[] = 'Invalid entity type.';

    /* Check duplicate name */
    if ($name !== '' && $ownerId > 0) {
        $dupStmt = $pdo->prepare("SELECT id FROM companies WHERE name = ? AND owner_user_id = ?");
        $dupStmt->execute([$name, $ownerId]);
        if ($dupStmt->fetch()) $errors[] = 'An entity with this name already exists.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            INSERT INTO companies (name, category, pan, cin, llp_code, address, official_email, mobile_no, owner_user_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$name, $category, $pan ?: null, $cin ?: null, $llpCode ?: null, $address ?: null, $email ?: null, $mobile ?: null, $ownerId]);
        $newId = $pdo->lastInsertId();

        $_SESSION['company_id'] = (int) $newId;
        $_SESSION['company_name'] = $name;

        header("Location: " . BASE_URL . "fy_workspace.php?entity_id=" . (int) $newId);
        exit;
    }
}
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => 'Create Entity'],
]) ?>

<?= uiPageHero('Create Entity', 'Add a new entity to your workspace. Only the entity name is required.') ?>

<?= uiWorkspaceStart() ?>

<div style="max-width:640px;">
    <?php if (!empty($errors)): ?>
        <div class="error-box" style="margin-bottom:16px;">
            <ul style="margin:0;padding-left:18px;">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;">
        <?= csrfInput() ?>

        <div style="margin-bottom:16px;">
            <label for="name" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Entity Name <span style="color:var(--danger);">*</span></label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($old['name']) ?>" required style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
        </div>

        <div style="margin-bottom:16px;">
            <label for="category" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Entity Type</label>
            <select id="category" name="category" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
                <option value="corporate" <?= $old['category'] === 'corporate' ? 'selected' : '' ?>>Company (Corporate)</option>
                <option value="llp" <?= $old['category'] === 'llp' ? 'selected' : '' ?>>LLP</option>
                <option value="non_corporate" <?= $old['category'] === 'non_corporate' ? 'selected' : '' ?>>Non-Corporate</option>
            </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <div>
                <label for="pan" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">PAN</label>
                <input type="text" id="pan" name="pan" value="<?= htmlspecialchars($old['pan']) ?>" maxlength="10" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;text-transform:uppercase;">
            </div>
            <div>
                <label for="cin" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">CIN / LLPIN</label>
                <input type="text" id="cin" name="cin" value="<?= htmlspecialchars($old['cin']) ?>" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;text-transform:uppercase;">
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label for="address" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Address</label>
            <textarea id="address" name="address" rows="2" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;resize:vertical;"><?= htmlspecialchars($old['address']) ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
            <div>
                <label for="email" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($old['email']) ?>" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
            </div>
            <div>
                <label for="mobile" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Mobile</label>
                <input type="text" id="mobile" name="mobile" value="<?= htmlspecialchars($old['mobile']) ?>" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
            </div>
        </div>

        <div style="display:flex;gap:12px;">
            <a href="<?= BASE_URL ?>dashboard_company.php" class="v2-btn v2-btn--outline">Cancel</a>
            <button type="submit" class="v2-btn v2-btn--primary">Create Entity</button>
        </div>
    </form>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
