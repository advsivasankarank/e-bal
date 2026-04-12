<?php
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/helpers/company_reporting_helper.php';

$id = (int) ($_GET['id'] ?? 0);
$errors = [];
ensureCompanyReportingColumns($pdo);

if ($id <= 0) {
    die("Invalid company selected");
}

// Fetch company
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

// 🔒 If not found
if (!$company) {
    die("Company not found");
}

$company = normalizeCompanyFormData($company);
$company = $_SERVER['REQUEST_METHOD'] === 'POST' ? normalizeCompanyFormData($_POST) : $company;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = validateCompanyFormData($company);

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
            $company['name'], $company['category'], $company['company_type'], $company['noncorp_subcategory'], $company['cin'], $company['llp_code'], $company['pan'],
            $company['registered_address'], $company['branch_address'], $company['state_code'], $company['official_email'], $company['mobile_no'],
            $company['address'], $company['phone'],
            $company['statutory_auditor_name'], $company['statutory_auditor_firm'], $company['statutory_auditor_frn'], $company['statutory_auditor_membership_no'],
            $company['signatory_1_name'], $company['signatory_1_designation'], $company['signatory_1_custom_designation'], $company['signatory_1_id_no'], $company['signatory_1_signing_authority'], $company['signatory_1_is_signing'],
            $company['signatory_2_name'], $company['signatory_2_designation'], $company['signatory_2_custom_designation'], $company['signatory_2_id_no'], $company['signatory_2_signing_authority'], $company['signatory_2_is_signing'],
            $id
        ]);

        header("Location: company_list.php?updated=1");
        exit;
    }
}

include __DIR__ . '/../layouts/header.php';
$stateOptions = getIndianStateOptions();
$companyTypeOptions = getCorporateCompanyTypeOptions();
$nonCorpOptions = getNonCorporateSubcategoryOptions();
$nonCorpDesignationOptions = getNonCorporateDesignationOptions();
?>

<div class="page-title">Edit Company</div>

<?php if (!empty($errors)): ?>
    <div class="error-box">
        <?php foreach ($errors as $error): ?>
            <p><?= htmlspecialchars($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.wizard-card { background:#fff; border:1px solid #d8e2ef; border-radius:14px; padding:20px; margin-bottom:18px; }
.wizard-card h3 { margin:0 0 14px; font-size:18px; }
.wizard-grid { display:grid; grid-template-columns:repeat(2, minmax(220px, 1fr)); gap:16px; }
.wizard-grid-3 { display:grid; grid-template-columns:repeat(3, minmax(180px, 1fr)); gap:16px; }
.wizard-full { grid-column:1 / -1; }
.inline-with-button { display:flex; gap:10px; align-items:center; }
.inline-with-button input, .inline-with-button select { flex:1; }
.helper-text { color:#5f6f82; font-size:13px; margin-top:6px; }
.signing-box { border:1px dashed #cfd8e3; border-radius:12px; padding:14px; margin-top:10px; }
@media (max-width: 900px) { .wizard-grid, .wizard-grid-3 { grid-template-columns:1fr; } }
</style>

<form method="post" id="company-form">
    <div class="wizard-card">
        <h3>1. Company Name</h3>
        <div class="wizard-grid">
            <div class="wizard-full">
                <label for="name">Company / Entity Name</label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($company['name'] ?? '') ?>" required>
            </div>
        </div>
    </div>

    <div class="wizard-card">
        <h3>2. Category</h3>
        <div class="wizard-grid">
            <div>
                <label for="category">Entity Category</label>
                <select name="category" id="category" required onchange="toggleEntitySections()">
                    <option value="corporate" <?= ($company['category'] ?? '') === 'corporate' ? 'selected' : '' ?>>Corporate</option>
                    <option value="llp" <?= ($company['category'] ?? '') === 'llp' ? 'selected' : '' ?>>LLP</option>
                    <option value="non_corporate" <?= ($company['category'] ?? '') === 'non_corporate' ? 'selected' : '' ?>>Non-Corporate</option>
                </select>
            </div>
        </div>
    </div>

    <div class="wizard-card" id="entity-identification">
        <h3>3. Entity Identification</h3>
        <div class="wizard-grid">
            <div id="cin_group" class="wizard-full" style="display:none;">
                <label for="cin">CIN</label>
                <div class="inline-with-button">
                    <input type="text" id="cin" name="cin" value="<?= htmlspecialchars($company['cin'] ?? '') ?>" oninput="applyCinRules()">
                    <button type="button" onclick="fetchEntityData('cin')">Fetch from MCA</button>
                </div>
                <div class="helper-text">State and company type are auto-read from a valid CIN.</div>
            </div>

            <div id="company_type_group" style="display:none;">
                <label for="company_type">Company Type</label>
                <select id="company_type" name="company_type">
                    <option value="">Select</option>
                    <?php foreach ($companyTypeOptions as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= ($company['company_type'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($code . ' - ' . $label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="llp_group" class="wizard-full" style="display:none;">
                <label for="llp_code">LLPIN</label>
                <div class="inline-with-button">
                    <input type="text" id="llp_code" name="llp_code" value="<?= htmlspecialchars($company['llp_code'] ?? '') ?>">
                    <button type="button" onclick="fetchEntityData('llpin')">Fetch from MCA</button>
                </div>
            </div>

            <div id="noncorp_subcategory_group" style="display:none;">
                <label for="noncorp_subcategory">Non-Corporate Sub Category</label>
                <select id="noncorp_subcategory" name="noncorp_subcategory">
                    <option value="">Select</option>
                    <?php foreach ($nonCorpOptions as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($company['noncorp_subcategory'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="pan_group" style="display:none;">
                <label for="pan">PAN</label>
                <input type="text" id="pan" name="pan" value="<?= htmlspecialchars($company['pan'] ?? '') ?>">
            </div>
        </div>

        <div class="form-group" id="lookup_status_group" style="display:none; margin-top:14px;">
            <div id="lookup_status" class="card"></div>
        </div>
    </div>

    <div class="wizard-card">
        <h3>4. Registered Address</h3>
        <div class="wizard-grid">
            <div class="wizard-full">
                <label for="registered_address">Registered Address</label>
                <textarea id="registered_address" name="registered_address" required><?= htmlspecialchars($company['registered_address'] ?? '') ?></textarea>
            </div>
            <div>
                <label for="state_code">State</label>
                <select id="state_code" name="state_code">
                    <option value="">Select</option>
                    <?php foreach ($stateOptions as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= ($company['state_code'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="wizard-card">
        <h3>5. Branch Address</h3>
        <div class="wizard-grid">
            <div class="wizard-full">
                <label for="branch_address">Branch Address, if any</label>
                <textarea id="branch_address" name="branch_address"><?= htmlspecialchars($company['branch_address'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="wizard-card">
        <h3>6. Contact Details</h3>
        <div class="wizard-grid">
            <div>
                <label for="official_email">Official Email</label>
                <input type="email" id="official_email" name="official_email" value="<?= htmlspecialchars($company['official_email'] ?? '') ?>">
            </div>
            <div>
                <label for="mobile_no">Mobile No.</label>
                <input type="text" id="mobile_no" name="mobile_no" value="<?= htmlspecialchars($company['mobile_no'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="wizard-card">
        <h3>7. Statutory Auditor Details</h3>
        <div class="wizard-grid">
            <div>
                <label for="statutory_auditor_name">Auditor Name</label>
                <input type="text" id="statutory_auditor_name" name="statutory_auditor_name" value="<?= htmlspecialchars($company['statutory_auditor_name'] ?? '') ?>">
            </div>
            <div>
                <label for="statutory_auditor_firm">Firm Name</label>
                <input type="text" id="statutory_auditor_firm" name="statutory_auditor_firm" value="<?= htmlspecialchars($company['statutory_auditor_firm'] ?? '') ?>">
            </div>
            <div>
                <label for="statutory_auditor_frn">Firm Registration Number (FRN)</label>
                <input type="text" id="statutory_auditor_frn" name="statutory_auditor_frn" value="<?= htmlspecialchars($company['statutory_auditor_frn'] ?? '') ?>">
            </div>
            <div>
                <label for="statutory_auditor_membership_no">Membership No.</label>
                <input type="text" id="statutory_auditor_membership_no" name="statutory_auditor_membership_no" value="<?= htmlspecialchars($company['statutory_auditor_membership_no'] ?? '') ?>">
            </div>
        </div>
    </div>

    <div class="wizard-card">
        <h3>8. Signatory Details</h3>
        <div class="helper-text" id="signatory_help">Corporate requires minimum two directors.</div>

        <?php for ($i = 1; $i <= 2; $i++): ?>
            <div class="signing-box">
                <div class="wizard-grid-3">
                    <div>
                        <label for="signatory_<?= $i ?>_name">Signatory <?= $i ?> Name</label>
                        <input type="text" id="signatory_<?= $i ?>_name" name="signatory_<?= $i ?>_name" value="<?= htmlspecialchars($company["signatory_{$i}_name"] ?? '') ?>">
                    </div>
                    <div>
                        <label for="signatory_<?= $i ?>_designation">Designation</label>
                        <select id="signatory_<?= $i ?>_designation" name="signatory_<?= $i ?>_designation" onchange="toggleCustomDesignation(<?= $i ?>)">
                            <option value="">Select</option>
                            <option value="Director" <?= ($company["signatory_{$i}_designation"] ?? '') === 'Director' ? 'selected' : '' ?>>Director</option>
                            <option value="Designated Partner" <?= ($company["signatory_{$i}_designation"] ?? '') === 'Designated Partner' ? 'selected' : '' ?>>Designated Partner</option>
                            <?php foreach ($nonCorpDesignationOptions as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= ($company["signatory_{$i}_designation"] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="signatory_<?= $i ?>_custom_wrap" style="display:none;">
                        <label for="signatory_<?= $i ?>_custom_designation">Custom Designation</label>
                        <input type="text" id="signatory_<?= $i ?>_custom_designation" name="signatory_<?= $i ?>_custom_designation" value="<?= htmlspecialchars($company["signatory_{$i}_custom_designation"] ?? '') ?>">
                    </div>
                    <div>
                        <label for="signatory_<?= $i ?>_id_no">DIN / DPIN / ID</label>
                        <input type="text" id="signatory_<?= $i ?>_id_no" name="signatory_<?= $i ?>_id_no" value="<?= htmlspecialchars($company["signatory_{$i}_id_no"] ?? '') ?>">
                    </div>
                    <div>
                        <label for="signatory_<?= $i ?>_signing_authority">Signing Authority</label>
                        <input type="text" id="signatory_<?= $i ?>_signing_authority" name="signatory_<?= $i ?>_signing_authority" value="<?= htmlspecialchars($company["signatory_{$i}_signing_authority"] ?? '') ?>">
                    </div>
                    <div style="display:flex; align-items:end;">
                        <label style="display:flex; gap:8px; align-items:center; font-weight:600;">
                            <input type="checkbox" name="signatory_<?= $i ?>_is_signing" value="1" <?= !empty($company["signatory_{$i}_is_signing"]) ? 'checked' : '' ?>>
                            Signing person
                        </label>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
    </div>

    <button type="submit">Update</button>

</form>

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

async function fetchEntityData(type) {
    const category = document.getElementById('category').value;
    const identifierField = document.getElementById(type === 'cin' ? 'cin' : 'llp_code');
    const identifier = identifierField ? identifierField.value.trim() : '';
    const statusWrap = document.getElementById('lookup_status_group');
    const statusBox = document.getElementById('lookup_status');

    statusWrap.style.display = 'block';

    if (!identifier) {
        statusBox.textContent = 'Enter the identifier first.';
        return;
    }

    statusBox.textContent = 'Fetching master data...';

    try {
        const response = await fetch('<?= BASE_URL ?>company_dashboard/mca_lookup.php?type=' + encodeURIComponent(type) + '&category=' + encodeURIComponent(category) + '&identifier=' + encodeURIComponent(identifier));
        const result = await response.json();

        if (!result.ok) {
            statusBox.textContent = result.message || 'Lookup failed.';
            return;
        }

        const fields = result.fields || {};
        Object.keys(fields).forEach((id) => {
            const element = document.getElementById(id);
            if (element && fields[id] && !element.value.trim()) {
                element.value = fields[id];
            }
        });

        applyCinRules();
        toggleEntitySections();
        statusBox.textContent = 'Master data fetched. Review the details before updating.';
    } catch (error) {
        statusBox.textContent = 'Lookup failed: ' + error.message;
    }
}

toggleEntitySections();
applyCinRules();
for (let i = 1; i <= 2; i++) {
    toggleCustomDesignation(i);
}
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
