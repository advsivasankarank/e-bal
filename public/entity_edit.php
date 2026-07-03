<?php
/**
 * e-BAL V2 — Edit Entity
 *
 * Edit entity master details. Does not affect financial data.
 */
$page_title = 'Edit Entity';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/workflow_engine.php';
require_once __DIR__ . '/../app/helpers/plan_helper.php';
require_once __DIR__ . '/../app/helpers/entity_access_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$entityId = (int) ($_GET['id'] ?? 0);

/* Validate entity exists and user can edit it */
validateEntityAccessOrRedirect($pdo, $entityId, 'edit');

$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$entityId]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entity) {
    $_SESSION['error'] = 'Entity not found.';
    header("Location: " . BASE_URL . "entity_select.php?mode=edit");
    exit;
}

/* ---- Process POST before any HTML output ---- */
$errors = [];
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

    if ($name === '') $errors[] = 'Entity name is required.';
    if (!in_array($category, ['corporate', 'llp', 'non_corporate'], true)) $errors[] = 'Invalid entity type.';

    if ($category !== strtolower($entity['category'])) {
        $_SESSION['warning'] = 'Changing entity type may affect statement format.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE companies SET name=?, category=?, pan=?, cin=?, llp_code=?, address=?, official_email=?, mobile_no=?, updated_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$name, $category, $pan ?: null, $cin ?: null, $llpCode ?: null, $address ?: null, $email ?: null, $mobile ?: null, $entityId]);

        $_SESSION['company_name'] = $name;
        $_SESSION['success'] = 'Entity updated successfully.';
        header("Location: " . BASE_URL . "entity_select.php?mode=edit");
        exit;
    }
}

/* ---- Now render HTML (after all redirects) ---- */
require_once __DIR__ . '/layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => 'Edit Entity'],
]) ?>

<?= uiPageHero('Edit Entity', 'Update master details for ' . htmlspecialchars($entity['name'])) ?>

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

    <?php if (!empty($_SESSION['warning'])): ?>
        <div class="warning-box" style="margin-bottom:16px;"><?= htmlspecialchars($_SESSION['warning']) ?></div>
        <?php unset($_SESSION['warning']); ?>
    <?php endif; ?>

    <form method="post" style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px;">
        <?= csrfInput() ?>

        <div style="margin-bottom:16px;">
            <label for="name" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Entity Name <span style="color:var(--danger);">*</span></label>
            <input type="text" id="name" name="name" value="<?= htmlspecialchars($entity['name']) ?>" required style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
        </div>

        <div style="margin-bottom:16px;">
            <label for="category" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Entity Type</label>
            <select id="category" name="category" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
                <option value="corporate" <?= strtolower($entity['category']) === 'corporate' ? 'selected' : '' ?>>Company (Corporate)</option>
                <option value="llp" <?= strtolower($entity['category']) === 'llp' ? 'selected' : '' ?>>LLP</option>
                <option value="non_corporate" <?= strtolower($entity['category']) === 'non_corporate' ? 'selected' : '' ?>>Non-Corporate</option>
            </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <div>
                <label for="pan" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">PAN</label>
                <input type="text" id="pan" name="pan" value="<?= htmlspecialchars($entity['pan'] ?? '') ?>" maxlength="10" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;text-transform:uppercase;">
            </div>
            <div>
                <label for="cin" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">CIN / LLPIN</label>
                <input type="text" id="cin" name="cin" value="<?= htmlspecialchars($entity['cin'] ?? '') ?>" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;text-transform:uppercase;">
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label for="address" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Address</label>
            <textarea id="address" name="address" rows="2" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;resize:vertical;"><?= htmlspecialchars($entity['address'] ?? '') ?></textarea>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
            <div>
                <label for="email" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Email</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($entity['official_email'] ?? '') ?>" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
            </div>
            <div>
                <label for="mobile" style="display:block;font-size:.85rem;font-weight:600;margin-bottom:6px;">Mobile</label>
                <input type="text" id="mobile" name="mobile" value="<?= htmlspecialchars($entity['mobile_no'] ?? '') ?>" style="width:100%;padding:10px 14px;border:1px solid var(--border-strong);border-radius:8px;font-size:.88rem;">
            </div>
        </div>

        <div style="display:flex;gap:12px;">
            <a href="<?= BASE_URL ?>entity_select.php?mode=edit" class="v2-btn v2-btn--outline">Cancel</a>
            <button type="submit" class="v2-btn v2-btn--primary">Save Changes</button>
        </div>
    </form>
</div>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
