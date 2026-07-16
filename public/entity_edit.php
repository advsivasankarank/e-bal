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
require_once __DIR__ . '/../app/helpers/company_reporting_helper.php';

$userId  = (int) ($_SESSION['user_id'] ?? 0);
$entityId = (int) ($_GET['id'] ?? 0);

/* Validate entity exists and user can edit it */
validateEntityAccessOrRedirect($pdo, $entityId, 'edit');

ensureCompanyReportingColumns($pdo);

$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$entityId]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$entity) {
    $_SESSION['error'] = 'Entity not found.';
    header("Location: " . BASE_URL . "entity_select.php?mode=edit");
    exit;
}

$previousCategory = strtolower($entity['category']);
$entity = normalizeCompanyFormData($entity);
$entity = $_SERVER['REQUEST_METHOD'] === 'POST' ? normalizeCompanyFormData($_POST) : $entity;

/* ---- Process POST before any HTML output ---- */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $errors = validateCompanyFormData($entity);

    if ($entity['category'] !== $previousCategory) {
        $_SESSION['warning'] = 'Changing entity type may affect statement format.';
    }

    if ($errors === []) {
        $stmt = $pdo->prepare("
            UPDATE companies
            SET name=?, category=?, company_type=?, noncorp_subcategory=?, cin=?, llp_code=?, pan=?,
                registered_address=?, branch_address=?, state_code=?, official_email=?, mobile_no=?,
                address=?, phone=?,
                statutory_auditor_name=?, statutory_auditor_firm=?, statutory_auditor_frn=?, statutory_auditor_membership_no=?,
                signatory_1_name=?, signatory_1_designation=?, signatory_1_custom_designation=?, signatory_1_id_no=?, signatory_1_signing_authority=?, signatory_1_is_signing=?,
                signatory_2_name=?, signatory_2_designation=?, signatory_2_custom_designation=?, signatory_2_id_no=?, signatory_2_signing_authority=?, signatory_2_is_signing=?,
                updated_at=NOW()
            WHERE id=?
        ");

        $stmt->execute([
            $entity['name'], $entity['category'], companyNullableDbValue($entity['company_type']), companyNullableDbValue($entity['noncorp_subcategory']), companyNullableDbValue($entity['cin']), companyNullableDbValue($entity['llp_code']), companyNullableDbValue($entity['pan']),
            $entity['registered_address'], companyNullableDbValue($entity['branch_address']), companyNullableDbValue($entity['state_code']), companyNullableDbValue($entity['official_email']), companyNullableDbValue($entity['mobile_no']),
            $entity['address'], companyNullableDbValue($entity['phone']),
            companyNullableDbValue($entity['statutory_auditor_name']), companyNullableDbValue($entity['statutory_auditor_firm']), companyNullableDbValue($entity['statutory_auditor_frn']), companyNullableDbValue($entity['statutory_auditor_membership_no']),
            $entity['signatory_1_name'], $entity['signatory_1_designation'], companyNullableDbValue($entity['signatory_1_custom_designation']), companyNullableDbValue($entity['signatory_1_id_no']), companyNullableDbValue($entity['signatory_1_signing_authority']), $entity['signatory_1_is_signing'],
            companyNullableDbValue($entity['signatory_2_name']), companyNullableDbValue($entity['signatory_2_designation']), companyNullableDbValue($entity['signatory_2_custom_designation']), companyNullableDbValue($entity['signatory_2_id_no']), companyNullableDbValue($entity['signatory_2_signing_authority']), $entity['signatory_2_is_signing'],
            $entityId,
        ]);

        $hasFy = false;
        try {
            $fyCheck = $pdo->prepare("SELECT COUNT(*) FROM financial_years WHERE company_id = ?");
            $fyCheck->execute([$entityId]);
            $hasFy = (int) $fyCheck->fetchColumn() > 0;
        } catch (Throwable $e) { /* ignore */ }
        $completeness = calculateProfileCompleteness($entity, $hasFy);
        $updStmt = $pdo->prepare("UPDATE companies SET profile_completeness = ? WHERE id = ?");
        $updStmt->execute([$completeness, $entityId]);

        $_SESSION['company_name'] = $entity['name'];
        $_SESSION['success'] = 'Entity updated successfully.';
        header("Location: " . BASE_URL . "entity_select.php?mode=edit");
        exit;
    }
}

/* ---- Now render HTML (after all redirects) ---- */
require_once __DIR__ . '/layouts/header_v2.php';
$stateOptions = getIndianStateOptions();
$companyTypeOptions = getCorporateCompanyTypeOptions();
$nonCorpOptions = getNonCorporateSubcategoryOptions();
$nonCorpDesignationOptions = getNonCorporateDesignationOptions();
?>

<?= uiBreadcrumb([
    ['label' => 'e-Bal Gateway', 'href' => BASE_URL . 'dashboard_company.php'],
    ['label' => 'Edit Entity'],
]) ?>

<?= uiPageHero('Edit Entity', 'Update master details for ' . htmlspecialchars($entity['name'])) ?>

<?= uiWorkspaceStart() ?>

<style>
.ee-card { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius-lg); padding:24px; margin-bottom:16px; }
.ee-card h3 { margin:0 0 14px; font-size:1rem; }
.ee-label { display:block; font-size:.85rem; font-weight:600; margin-bottom:6px; }
.ee-input { width:100%; padding:10px 14px; border:1px solid var(--border-strong); border-radius:8px; font-size:.88rem; }
.ee-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.ee-grid-3 { display:grid; grid-template-columns:repeat(3, minmax(180px, 1fr)); gap:12px; }
.ee-field { margin-bottom:16px; }
.ee-help { color:var(--muted); font-size:.78rem; margin-top:6px; }
.ee-signing-box { border:1px dashed var(--border-strong); border-radius:10px; padding:14px; margin-bottom:12px; }
@media (max-width: 900px) { .ee-grid-2, .ee-grid-3 { grid-template-columns:1fr; } }
</style>

<div style="max-width:800px;">
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

    <form method="post" id="entity-form">
        <?= csrfInput() ?>

        <div class="ee-card">
            <h3>Entity Name &amp; Category</h3>
            <div class="ee-field">
                <label for="name" class="ee-label">Entity Name <span style="color:var(--danger);">*</span></label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($entity['name'] ?? '') ?>" required class="ee-input">
            </div>
            <div class="ee-field">
                <label for="category" class="ee-label">Entity Type</label>
                <select id="category" name="category" class="ee-input" onchange="toggleEntitySections()">
                    <option value="corporate" <?= ($entity['category'] ?? '') === 'corporate' ? 'selected' : '' ?>>Company (Corporate)</option>
                    <option value="llp" <?= ($entity['category'] ?? '') === 'llp' ? 'selected' : '' ?>>LLP</option>
                    <option value="non_corporate" <?= ($entity['category'] ?? '') === 'non_corporate' ? 'selected' : '' ?>>Non-Corporate</option>
                </select>
            </div>
        </div>

        <div class="ee-card" id="entity-identification">
            <h3>Entity Identification</h3>
            <div id="cin_group" class="ee-field" style="display:none;">
                <label for="cin" class="ee-label">CIN</label>
                <input type="text" id="cin" name="cin" value="<?= htmlspecialchars($entity['cin'] ?? '') ?>" class="ee-input" oninput="applyCinRules()">
                <div class="ee-help">State and company type are auto-read from a valid CIN.</div>
            </div>
            <div id="company_type_group" class="ee-field" style="display:none;">
                <label for="company_type" class="ee-label">Company Type</label>
                <select id="company_type" name="company_type" class="ee-input">
                    <option value="">Select</option>
                    <?php foreach ($companyTypeOptions as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= ($entity['company_type'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($code . ' - ' . $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="llp_group" class="ee-field" style="display:none;">
                <label for="llp_code" class="ee-label">LLPIN</label>
                <input type="text" id="llp_code" name="llp_code" value="<?= htmlspecialchars($entity['llp_code'] ?? '') ?>" class="ee-input">
            </div>
            <div id="noncorp_subcategory_group" class="ee-field" style="display:none;">
                <label for="noncorp_subcategory" class="ee-label">Non-Corporate Sub Category</label>
                <select id="noncorp_subcategory" name="noncorp_subcategory" class="ee-input">
                    <option value="">Select</option>
                    <?php foreach ($nonCorpOptions as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($entity['noncorp_subcategory'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="pan_group" class="ee-field" style="display:none;">
                <label for="pan" class="ee-label">PAN</label>
                <input type="text" id="pan" name="pan" value="<?= htmlspecialchars($entity['pan'] ?? '') ?>" maxlength="10" class="ee-input" style="text-transform:uppercase;">
            </div>
        </div>

        <div class="ee-card">
            <h3>Registered Address</h3>
            <div class="ee-field">
                <label for="registered_address" class="ee-label">Registered Address <span style="color:var(--danger);">*</span></label>
                <textarea id="registered_address" name="registered_address" rows="2" required class="ee-input" style="resize:vertical;"><?= htmlspecialchars($entity['registered_address'] ?? '') ?></textarea>
            </div>
            <div class="ee-field" style="margin-bottom:0;">
                <label for="state_code" class="ee-label">State</label>
                <select id="state_code" name="state_code" class="ee-input">
                    <option value="">Select</option>
                    <?php foreach ($stateOptions as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= ($entity['state_code'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="ee-card">
            <h3>Branch Address</h3>
            <div class="ee-field" style="margin-bottom:0;">
                <label for="branch_address" class="ee-label">Branch Address, if any</label>
                <textarea id="branch_address" name="branch_address" rows="2" class="ee-input" style="resize:vertical;"><?= htmlspecialchars($entity['branch_address'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="ee-card">
            <h3>Contact Details</h3>
            <div class="ee-grid-2" style="margin-bottom:0;">
                <div>
                    <label for="official_email" class="ee-label">Official Email</label>
                    <input type="email" id="official_email" name="official_email" value="<?= htmlspecialchars($entity['official_email'] ?? '') ?>" class="ee-input">
                </div>
                <div>
                    <label for="mobile_no" class="ee-label">Mobile No.</label>
                    <input type="text" id="mobile_no" name="mobile_no" value="<?= htmlspecialchars($entity['mobile_no'] ?? '') ?>" class="ee-input">
                </div>
            </div>
        </div>

        <div class="ee-card">
            <h3>Statutory Auditor Details</h3>
            <div class="ee-grid-2" style="margin-bottom:0;">
                <div>
                    <label for="statutory_auditor_name" class="ee-label">Auditor Name</label>
                    <input type="text" id="statutory_auditor_name" name="statutory_auditor_name" value="<?= htmlspecialchars($entity['statutory_auditor_name'] ?? '') ?>" class="ee-input">
                </div>
                <div>
                    <label for="statutory_auditor_firm" class="ee-label">Firm Name</label>
                    <input type="text" id="statutory_auditor_firm" name="statutory_auditor_firm" value="<?= htmlspecialchars($entity['statutory_auditor_firm'] ?? '') ?>" class="ee-input">
                </div>
                <div>
                    <label for="statutory_auditor_frn" class="ee-label">Firm Registration Number (FRN)</label>
                    <input type="text" id="statutory_auditor_frn" name="statutory_auditor_frn" value="<?= htmlspecialchars($entity['statutory_auditor_frn'] ?? '') ?>" class="ee-input">
                </div>
                <div>
                    <label for="statutory_auditor_membership_no" class="ee-label">Membership No.</label>
                    <input type="text" id="statutory_auditor_membership_no" name="statutory_auditor_membership_no" value="<?= htmlspecialchars($entity['statutory_auditor_membership_no'] ?? '') ?>" class="ee-input">
                </div>
            </div>
        </div>

        <div class="ee-card">
            <h3>Signatory Details</h3>
            <div class="ee-help" id="signatory_help" style="margin-top:0;margin-bottom:12px;">Corporate requires minimum two directors.</div>

            <?php for ($i = 1; $i <= 2; $i++): ?>
                <div class="ee-signing-box">
                    <div class="ee-grid-3" style="margin-bottom:12px;">
                        <div>
                            <label for="signatory_<?= $i ?>_name" class="ee-label">Signatory <?= $i ?> Name</label>
                            <input type="text" id="signatory_<?= $i ?>_name" name="signatory_<?= $i ?>_name" value="<?= htmlspecialchars($entity["signatory_{$i}_name"] ?? '') ?>" class="ee-input">
                        </div>
                        <div>
                            <label for="signatory_<?= $i ?>_designation" class="ee-label">Designation</label>
                            <select id="signatory_<?= $i ?>_designation" name="signatory_<?= $i ?>_designation" class="ee-input" onchange="toggleCustomDesignation(<?= $i ?>)">
                                <option value="">Select</option>
                                <option value="Director" <?= ($entity["signatory_{$i}_designation"] ?? '') === 'Director' ? 'selected' : '' ?>>Director</option>
                                <option value="Designated Partner" <?= ($entity["signatory_{$i}_designation"] ?? '') === 'Designated Partner' ? 'selected' : '' ?>>Designated Partner</option>
                                <?php foreach ($nonCorpDesignationOptions as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>" <?= ($entity["signatory_{$i}_designation"] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="signatory_<?= $i ?>_custom_wrap" style="display:none;">
                            <label for="signatory_<?= $i ?>_custom_designation" class="ee-label">Custom Designation</label>
                            <input type="text" id="signatory_<?= $i ?>_custom_designation" name="signatory_<?= $i ?>_custom_designation" value="<?= htmlspecialchars($entity["signatory_{$i}_custom_designation"] ?? '') ?>" class="ee-input">
                        </div>
                        <div>
                            <label for="signatory_<?= $i ?>_id_no" class="ee-label">DIN / DPIN / ID</label>
                            <input type="text" id="signatory_<?= $i ?>_id_no" name="signatory_<?= $i ?>_id_no" value="<?= htmlspecialchars($entity["signatory_{$i}_id_no"] ?? '') ?>" class="ee-input">
                        </div>
                        <div>
                            <label for="signatory_<?= $i ?>_signing_authority" class="ee-label">Signing Authority</label>
                            <input type="text" id="signatory_<?= $i ?>_signing_authority" name="signatory_<?= $i ?>_signing_authority" value="<?= htmlspecialchars($entity["signatory_{$i}_signing_authority"] ?? '') ?>" class="ee-input">
                        </div>
                        <div style="display:flex;align-items:end;">
                            <label style="display:flex;gap:8px;align-items:center;font-weight:600;font-size:.85rem;">
                                <input type="checkbox" name="signatory_<?= $i ?>_is_signing" value="1" <?= !empty($entity["signatory_{$i}_is_signing"]) ? 'checked' : '' ?>>
                                Signing person
                            </label>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <div style="display:flex;gap:12px;">
            <a href="<?= BASE_URL ?>entity_select.php?mode=edit" class="v2-btn v2-btn--outline">Cancel</a>
            <button type="submit" class="v2-btn v2-btn--primary">Save Changes</button>
        </div>
    </form>

    <div style="display:flex;gap:12px;margin-top:16px;">
        <?= uiButton('Continue to Financial Year', BASE_URL . 'entity_home.php?company_id=' . $entityId, 'outline', '→') ?>
    </div>
</div>

<script>
function applyCinRules() {
    const cinInput = document.getElementById('cin');
    const cin = (cinInput.value || '').trim().toUpperCase();
    cinInput.value = cin;

    const match = cin.match(/^([LU])(\d{5})([A-Z]{2})(\d{4})(PLC|PTC|OPC|SGC|FTC|GAP|GOI)(\d{6})$/);
    if (!match) {
        return;
    }

    document.getElementById('state_code').value = match[3];
    document.getElementById('company_type').value = match[5];
}

function toggleCustomDesignation(index) {
    const select = document.getElementById('signatory_' + index + '_designation');
    const wrap = document.getElementById('signatory_' + index + '_custom_wrap');
    wrap.style.display = select.value === 'custom' ? 'block' : 'none';
}

function applyDefaultDesignations() {
    const category = document.getElementById('category').value;
    const defaultValue = category === 'corporate' ? 'Director' : (category === 'llp' ? 'Designated Partner' : '');

    for (let i = 1; i <= 2; i++) {
        const select = document.getElementById('signatory_' + i + '_designation');
        if (category === 'non_corporate') {
            if (select.value === 'Director' || select.value === 'Designated Partner') {
                select.value = '';
            }
        } else if (!select.value || ['Director', 'Designated Partner'].includes(select.value)) {
            select.value = defaultValue;
        }
        toggleCustomDesignation(i);
    }
}

function toggleEntitySections() {
    let category = document.getElementById('category').value;
    document.getElementById('cin_group').style.display = category === 'corporate' ? 'block' : 'none';
    document.getElementById('company_type_group').style.display = category === 'corporate' ? 'block' : 'none';
    document.getElementById('llp_group').style.display = category === 'llp' ? 'block' : 'none';
    document.getElementById('noncorp_subcategory_group').style.display = category === 'non_corporate' ? 'block' : 'none';
    document.getElementById('pan_group').style.display = category === 'non_corporate' ? 'block' : 'none';

    const help = document.getElementById('signatory_help');
    if (category === 'corporate') {
        help.textContent = 'Minimum two directors are required for reporting.';
    } else if (category === 'llp') {
        help.textContent = 'Minimum one designated partner is required. Tick the partners who will sign the report.';
    } else {
        help.textContent = 'Select the person signing the report and capture signing authority if needed.';
    }

    applyDefaultDesignations();
}

toggleEntitySections();
applyCinRules();
for (let i = 1; i <= 2; i++) {
    toggleCustomDesignation(i);
}
</script>

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
