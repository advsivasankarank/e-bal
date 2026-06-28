<?php
/**
 * e-BAL — Quick Entity Creation
 *
 * Frictionless entity creation for pilot usage.
 * Only Entity Name is mandatory. All other details can be completed later via Edit Entity.
 */
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/session_bootstrap.php';
require_once '../../app/middleware/license_check.php';
require_once '../../app/helpers/company_reporting_helper.php';
require_once '../../app/helpers/plan_helper.php';
require_once '../../app/helpers/security_helper.php';
require_once '../../app/helpers/entity_master_helper.php';

$page_title = 'Create Entity';
$errors = [];

try {
    ensureEntityMasterSchema($pdo);
    ensureCompanyReportingColumns($pdo);
} catch (Throwable $e) {
    $errors[] = 'Schema update failed. Contact admin.';
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { header('Location: ' . BASE_URL . 'login.php'); exit; }
if (!isWorkspaceAdmin($pdo, $userId)) { $errors[] = 'Only workspace admin users can create entities.'; }
$ownerId = getOwnerUserId($pdo, $userId);

/* Handle POST — Quick Create */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    requireCsrfToken();

    $name = trim((string) ($_POST['name'] ?? ''));
    $category = trim((string) ($_POST['category'] ?? 'corporate'));
    $noncorpSubcategory = trim((string) ($_POST['noncorp_subcategory'] ?? ''));

    /* Validate: only name is mandatory */
    $validationErrors = quickCreateValidate($_POST);
    if ($validationErrors !== []) {
        $errors = array_merge($errors, $validationErrors);
    }

    /* Default category to corporate if empty */
    if ($category === '' || !in_array($category, ['corporate', 'llp', 'non_corporate'], true)) {
        $category = 'corporate';
    }
    if ($category !== 'non_corporate') {
        $noncorpSubcategory = '';
    }

    /* Plan limit check */
    if ($ownerId > 0 && !canAddCompany($ownerId, $pdo)) {
        $errors[] = 'Company limit reached. Upgrade your plan.';
    }

    /* Duplicate name check (case-insensitive) */
    if ($name !== '') {
        $dupError = checkDuplicateEntityName($pdo, $name);
        if ($dupError !== '') {
            $errors[] = $dupError;
        }
    }

    if ($errors === []) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO companies (owner_user_id, name, category, noncorp_subcategory, created_at, updated_at, profile_completeness)
                VALUES (?, ?, ?, ?, NOW(), NOW(), 20)
            ");
            $stmt->execute([$ownerId, $name, $category, $noncorpSubcategory]);
            $newCompanyId = (int) $pdo->lastInsertId();

            header("Location: company_edit.php?id={$newCompanyId}&created=1");
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Unable to create entity. Please try again.';
        }
    }
}

include __DIR__ . '/../layouts/header.php';
?>

<style>
.qec{max-width:560px;margin:40px auto}
.qec-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.qec-header{text-align:center;margin-bottom:28px}
.qec-header h1{font-size:1.35rem;font-weight:700;color:#1e293b;margin:0 0 6px}
.qec-header p{font-size:.85rem;color:#64748b;margin:0}
.qec-field{display:flex;flex-direction:column;gap:6px;margin-bottom:18px}
.qec-field label{font-size:.8rem;font-weight:600;color:#475569}
.qec-field input,.qec-field select{padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:.92rem;background:#fff;color:#1e293b;transition:border-color .15s}
.qec-field input:focus,.qec-field select:focus{border-color:#12355b;outline:none;box-shadow:0 0 0 3px rgba(18,53,91,.08)}
.qec-field .qec-help{font-size:.75rem;color:#94a3b8;margin-top:2px}
.qec-chips{display:flex;gap:8px;flex-wrap:wrap}
.qec-chip{padding:8px 18px;border:1px solid #d1d5db;border-radius:20px;font-size:.85rem;font-weight:500;cursor:pointer;background:#fff;color:#475569;transition:all .15s}
.qec-chip:hover{border-color:#12355b;color:#12355b}
.qec-chip.active{background:#12355b;color:#fff;border-color:#12355b}
.qec-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:24px;padding-top:18px;border-top:1px solid #e2e8f0}
.qec-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border:1px solid transparent;border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none}
.qec-btn-primary{background:#12355b;color:#fff;border-color:#12355b}.qec-btn-primary:hover{background:#1a4a7a}
.qec-btn-outline{background:#fff;color:#475569;border-color:#d1d5db}.qec-btn-outline:hover{border-color:#12355b;color:#12355b}
.qec-errors{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:18px}.qec-errors p{color:#991b1b;font-size:.85rem;margin:2px 0}
.qec-info{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:18px}
.qec-info p{color:#1e40af;font-size:.82rem;margin:0;line-height:1.5}
</style>

<div class="qec">
    <div class="qec-card">
        <div class="qec-header">
            <h1>Create Entity</h1>
            <p>Only the entity name is required. Complete other details later via Edit Entity.</p>
        </div>

        <div class="qec-info">
            <p><strong>Quick Start:</strong> Create an entity now with just a name. You can add CIN, PAN, GSTIN, address, auditor details, and more from the Edit Entity page at any time.</p>
        </div>

        <?php if ($errors): ?>
            <div class="qec-errors"><?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrfInput() ?>

            <div class="qec-field">
                <label for="name">Entity Name *</label>
                <input type="text" id="name" name="name" required placeholder="e.g. Acme Private Limited" autofocus>
                <span class="qec-help">Company, LLP, or firm name as per records</span>
            </div>

            <div class="qec-field">
                <label>Entity Type</label>
                <div class="qec-chips" id="category-chips">
                    <button type="button" class="qec-chip active" data-category="corporate" data-subcategory="" onclick="selectType(this, 'corporate', '')">Company</button>
                    <button type="button" class="qec-chip" data-category="llp" data-subcategory="" onclick="selectType(this, 'llp', '')">LLP</button>
                    <button type="button" class="qec-chip" data-category="non_corporate" data-subcategory="sole_proprietorship" onclick="selectType(this, 'non_corporate', 'sole_proprietorship')">Proprietorship</button>
                    <button type="button" class="qec-chip" data-category="non_corporate" data-subcategory="partnership" onclick="selectType(this, 'non_corporate', 'partnership')">Partnership</button>
                    <button type="button" class="qec-chip" data-category="non_corporate" data-subcategory="association_trust" onclick="selectType(this, 'non_corporate', 'association_trust')">Trust</button>
                    <button type="button" class="qec-chip" data-category="non_corporate" data-subcategory="society" onclick="selectType(this, 'non_corporate', 'society')">Society</button>
                </div>
                <input type="hidden" id="category" name="category" value="corporate">
                <input type="hidden" id="noncorp_subcategory" name="noncorp_subcategory" value="">
                <span class="qec-help">Select the legal/reporting type. You can complete statutory details later.</span>
            </div>

            <div class="qec-actions">
                <a href="company_list.php" class="qec-btn qec-btn-outline">Cancel</a>
                <button type="submit" class="qec-btn qec-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                    Create Entity
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function selectType(el, category, subcategory) {
    document.getElementById('category').value = category;
    document.getElementById('noncorp_subcategory').value = subcategory || '';
    var chips = document.querySelectorAll('.qec-chip');
    for (var i = 0; i < chips.length; i++) {
        chips[i].classList.toggle('active', chips[i] === el);
    }
}
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
