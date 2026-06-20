<?php
/**
 * e-BAL — Entity Management Workspace
 *
 * Premium enterprise interface for creating and managing entities.
 * Replaces generic CRUD form with tabbed accounting workspace.
 *
 * Tabs: Entity Info | Financial Years | Governance | Registrations | Contacts
 */
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/session_bootstrap.php';
require_once '../../app/middleware/license_check.php';
require_once '../../app/helpers/company_reporting_helper.php';
require_once '../../app/helpers/plan_helper.php';
require_once '../../app/helpers/security_helper.php';
require_once '../../app/helpers/entity_master_helper.php';

$page_title = 'Entity Management';
$errors = [];
$success = false;

try {
    ensureEntityMasterSchema($pdo);
    ensureCompanyReportingColumns($pdo);
} catch (Throwable $e) {
    $errors[] = 'Schema update failed. Contact admin.';
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if (!isWorkspaceAdmin($pdo, $userId)) {
    $errors[] = 'Only workspace admin users can create entities.';
}
$ownerId = getOwnerUserId($pdo, $userId);
$bridgeToken = buildBridgeBrowserToken('company');

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['entity_action'] ?? 'create';

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $pan = strtoupper(trim((string) ($_POST['pan'] ?? '')));
        $cin = strtoupper(trim((string) ($_POST['cin'] ?? '')));
        $llp_code = strtoupper(trim((string) ($_POST['llp_code'] ?? '')));
        $registered_address = trim((string) ($_POST['registered_address'] ?? ''));
        $state_code = trim((string) ($_POST['state_code'] ?? ''));
        $official_email = trim((string) ($_POST['official_email'] ?? ''));
        $mobile_no = trim((string) ($_POST['mobile_no'] ?? ''));
        $noncorp_subcategory = trim((string) ($_POST['noncorp_subcategory'] ?? ''));
        $company_type = trim((string) ($_POST['company_type'] ?? ''));

        if ($name === '') $errors[] = 'Entity name is required.';
        if ($category === '') $errors[] = 'Entity category is required.';
        if ($registered_address === '') $errors[] = 'Registered address is required.';

        if ($ownerId > 0 && !canAddCompany($ownerId, $pdo)) {
            $errors[] = 'Company limit reached. Upgrade your plan.';
        }

        if ($errors === []) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO companies (
                        owner_user_id, name, category, company_type, noncorp_subcategory,
                        cin, llp_code, pan, registered_address, state_code,
                        official_email, mobile_no, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $ownerId,
                    $name, $category,
                    companyNullableDbValue($company_type),
                    companyNullableDbValue($noncorp_subcategory),
                    companyNullableDbValue($cin),
                    companyNullableDbValue($llp_code),
                    companyNullableDbValue($pan),
                    $registered_address,
                    companyNullableDbValue($state_code),
                    companyNullableDbValue($official_email),
                    companyNullableDbValue($mobile_no),
                ]);
                $newCompanyId = (int) $pdo->lastInsertId();

                /* Save governance if auditor selected */
                $auditorId = (int) ($_POST['governance_auditor_id'] ?? 0);
                $fyId = (int) ($_POST['governance_fy_id'] ?? 0);
                if ($auditorId > 0 && $fyId > 0) {
                    saveCompanyGovernance($pdo, $newCompanyId, $fyId, [
                        'auditor_id' => $auditorId,
                        'audit_type' => $_POST['audit_type'] ?? 'statutory',
                    ]);
                }

                /* Save directors if selected */
                $directorIds = $_POST['director_ids'] ?? [];
                $designations = $_POST['director_designations'] ?? [];
                $signingOrders = $_POST['director_signing_orders'] ?? [];
                $signingAuths = $_POST['director_signing_auths'] ?? [];
                foreach ($directorIds as $idx => $did) {
                    $did = (int) $did;
                    if ($did <= 0) continue;
                    addCompanyDirector($pdo, $newCompanyId, $did, [
                        'designation' => $designations[$idx] ?? '',
                        'signing_authority' => $signingAuths[$idx] ?? '',
                        'signing_order' => (int) ($signingOrders[$idx] ?? 0),
                    ]);
                }

                header("Location: company_list.php?success=1");
                exit;
            } catch (Throwable $e) {
                $errors[] = 'Unable to save entity. Please verify data.';
            }
        }
    }
}

$stateOptions = getIndianStateOptions();
$companyTypeOptions = getCorporateCompanyTypeOptions();
$nonCorpOptions = getNonCorporateSubcategoryOptions();

/* Fetch auditors and directors for dropdowns */
$auditors = ($ownerId > 0) ? searchAuditors($pdo, $ownerId, '', true) : [];
$directors = ($ownerId > 0) ? searchDirectors($pdo, $ownerId, '', true) : [];

include __DIR__ . '/../layouts/header.php';
?>

<style>
/* Entity Management Workspace */
.emw { max-width: 1100px; margin: 0 auto; }
.emw-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; gap:12px; flex-wrap:wrap; }
.emw-header h1 { font-size:1.3rem; font-weight:700; margin:0; }
.emw-header p { font-size:0.82rem; color:#64748b; margin:2px 0 0; }

/* Tabs */
.emw-tabs { display:flex; gap:0; border-bottom:2px solid #e2e8f0; margin-bottom:20px; overflow-x:auto; }
.emw-tab { padding:10px 18px; border:none; background:none; font-size:0.85rem; font-weight:600; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; white-space:nowrap; transition: color 0.15s, border-color 0.15s; }
.emw-tab:hover { color:#1e293b; }
.emw-tab.active { color:#12355b; border-bottom-color:#12355b; }

/* Tab panels */
.emw-panel { display:none; }
.emw-panel.active { display:block; }

/* Form grid */
.emw-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:14px; }
.emw-grid-3 { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; }
.emw-full { grid-column: 1 / -1; }
.emw-field { display:flex; flex-direction:column; gap:4px; }
.emw-field label { font-size:0.75rem; font-weight:600; color:#475569; }
.emw-field input, .emw-field select, .emw-field textarea {
    padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.88rem;
    background:#fff; color:#1e293b; transition: border-color 0.15s;
}
.emw-field input:focus, .emw-field select:focus, .emw-field textarea:focus {
    border-color:#12355b; outline:none; box-shadow:0 0 0 3px rgba(18,53,91,0.08);
}
.emw-field textarea { min-height:60px; resize:vertical; }

/* Section */
.emw-section { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:18px; margin-bottom:16px; }
.emw-section-title { font-size:0.92rem; font-weight:700; color:#1e293b; margin-bottom:14px; display:flex; align-items:center; gap:8px; }
.emw-section-title svg { color:#12355b; }

/* Buttons */
.emw-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border:1px solid transparent; border-radius:6px; font-size:0.82rem; font-weight:600; cursor:pointer; transition: all 0.15s; text-decoration:none; }
.emw-btn-primary { background:#12355b; color:#fff; border-color:#12355b; }
.emw-btn-primary:hover { background:#1a4a7a; }
.emw-btn-outline { background:#fff; color:#475569; border-color:#d1d5db; }
.emw-btn-outline:hover { border-color:#12355b; color:#12355b; }
.emw-btn-sm { padding:5px 12px; font-size:0.78rem; }
.emw-btn-danger { background:#dc2626; color:#fff; }
.emw-btn-danger:hover { background:#b91c1c; }

/* Search dropdown */
.emw-search-wrap { position:relative; }
.emw-search-results { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #d1d5db; border-radius:6px; box-shadow:0 4px 12px rgba(0,0,0,0.1); max-height:200px; overflow-y:auto; z-index:50; display:none; }
.emw-search-results.open { display:block; }
.emw-search-item { padding:8px 12px; cursor:pointer; font-size:0.85rem; border-bottom:1px solid #f1f5f9; }
.emw-search-item:hover { background:#f8fafc; }
.emw-search-item-name { font-weight:600; color:#1e293b; }
.emw-search-item-meta { font-size:0.72rem; color:#64748b; }

/* Table */
.emw-table { width:100%; border-collapse:collapse; font-size:0.85rem; }
.emw-table th { text-align:left; padding:8px 12px; background:#f8fafc; border-bottom:2px solid #e2e8f0; font-weight:600; color:#475569; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em; }
.emw-table td { padding:8px 12px; border-bottom:1px solid #f1f5f9; color:#1e293b; }
.emw-table tr:hover td { background:#f8fafc; }

/* Director row */
.emw-director-row { display:grid; grid-template-columns:1fr 120px 120px 1fr 40px; gap:8px; align-items:center; padding:8px 0; border-bottom:1px solid #f1f5f9; }
.emw-director-row:last-child { border-bottom:none; }

/* Help text */
.emw-help { font-size:0.75rem; color:#64748b; margin-top:4px; }

/* Entity category chips */
.emw-chips { display:flex; gap:6px; flex-wrap:wrap; }
.emw-chip { padding:6px 14px; border:1px solid #d1d5db; border-radius:20px; font-size:0.82rem; font-weight:500; cursor:pointer; background:#fff; color:#475569; transition: all 0.15s; }
.emw-chip:hover { border-color:#12355b; color:#12355b; }
.emw-chip.active { background:#12355b; color:#fff; border-color:#12355b; }

/* Info badges */
.emw-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:4px; font-size:0.7rem; font-weight:600; }
.emw-badge-success { background:#d1fae5; color:#047857; }
.emw-badge-warning { background:#fef3c7; color:#92400e; }
.emw-badge-info { background:#dbeafe; color:#1d4ed8; }

/* Save bar */
.emw-save-bar { display:flex; justify-content:flex-end; gap:10px; padding:16px 0; border-top:1px solid #e2e8f0; margin-top:20px; }

/* Empty state */
.emw-empty { text-align:center; padding:24px; color:#64748b; font-size:0.85rem; }

/* Error box */
.emw-errors { background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px 16px; margin-bottom:16px; }
.emw-errors p { color:#991b1b; font-size:0.85rem; margin:2px 0; }

@media (max-width: 768px) {
    .emw-grid, .emw-grid-3 { grid-template-columns: 1fr; }
    .emw-director-row { grid-template-columns: 1fr; }
}
</style>

<div class="emw">
    <div class="emw-header">
        <div>
            <h1>Entity Management</h1>
            <p>Create and configure entities for financial statement preparation</p>
        </div>
        <a href="company_list.php" class="emw-btn emw-btn-outline">← Back to Entities</a>
    </div>

    <?php if ($errors): ?>
        <div class="emw-errors">
            <?php foreach ($errors as $err): ?>
                <p><?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" id="entity-form">
        <?= csrfInput() ?>
        <input type="hidden" name="entity_action" value="create">

        <!-- Tab Navigation -->
        <div class="emw-tabs" id="emw-tabs">
            <button type="button" class="emw-tab active" data-tab="entity">Entity Information</button>
            <button type="button" class="emw-tab" data-tab="governance">Governance</button>
            <button type="button" class="emw-tab" data-tab="directors">Directors</button>
            <button type="button" class="emw-tab" data-tab="registrations">Registrations</button>
            <button type="button" class="emw-tab" data-tab="contacts">Contacts</button>
        </div>

        <!-- TAB 1: Entity Information -->
        <div class="emw-panel active" data-panel="entity">
            <div class="emw-section">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                    Entity Details
                </div>
                <div class="emw-grid">
                    <div class="emw-full">
                        <div class="emw-field">
                            <label for="name">Entity Name *</label>
                            <input type="text" id="name" name="name" required placeholder="Enter company or entity name">
                        </div>
                    </div>
                    <div class="emw-full">
                        <div class="emw-field">
                            <label>Entity Category *</label>
                            <div class="emw-chips" id="category-chips">
                                <button type="button" class="emw-chip" data-value="corporate" onclick="selectCategory('corporate')">Corporate</button>
                                <button type="button" class="emw-chip" data-value="llp" onclick="selectCategory('llp')">LLP</button>
                                <button type="button" class="emw-chip" data-value="non_corporate" onclick="selectCategory('non_corporate')">Non-Corporate</button>
                            </div>
                            <input type="hidden" id="category" name="category" value="">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Identification (conditional) -->
            <div class="emw-section" id="section-identification" style="display:none;">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    Identification
                </div>
                <div class="emw-grid">
                    <div id="cin-field" class="emw-full" style="display:none;">
                        <div class="emw-field">
                            <label for="cin">CIN</label>
                            <div style="display:flex;gap:8px;">
                                <input type="text" id="cin" name="cin" placeholder="Corporate Identity Number" style="flex:1;" oninput="applyCinRules()">
                                <button type="button" class="emw-btn emw-btn-outline emw-btn-sm" onclick="fetchEntityData('cin')">Fetch MCA</button>
                            </div>
                            <span class="emw-help">State and company type auto-detected from CIN.</span>
                        </div>
                    </div>
                    <div id="company-type-field" style="display:none;">
                        <div class="emw-field">
                            <label for="company_type">Company Type</label>
                            <select id="company_type" name="company_type">
                                <option value="">Select</option>
                                <?php foreach ($companyTypeOptions as $code => $label): ?>
                                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code . ' - ' . $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div id="llp-field" class="emw-full" style="display:none;">
                        <div class="emw-field">
                            <label for="llp_code">LLPIN</label>
                            <div style="display:flex;gap:8px;">
                                <input type="text" id="llp_code" name="llp_code" placeholder="LLP Identification Number" style="flex:1;">
                                <button type="button" class="emw-btn emw-btn-outline emw-btn-sm" onclick="fetchEntityData('llpin')">Fetch MCA</button>
                            </div>
                        </div>
                    </div>
                    <div id="pan-field" style="display:none;">
                        <div class="emw-field">
                            <label for="pan">PAN</label>
                            <input type="text" id="pan" name="pan" placeholder="Permanent Account Number" maxlength="10">
                        </div>
                    </div>
                    <div id="noncorp-field" style="display:none;">
                        <div class="emw-field">
                            <label for="noncorp_subcategory">Sub Category</label>
                            <select id="noncorp_subcategory" name="noncorp_subcategory">
                                <option value="">Select</option>
                                <?php foreach ($nonCorpOptions as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div id="lookup-status" style="display:none;margin-top:10px;padding:8px 12px;border-radius:6px;font-size:0.82rem;"></div>
            </div>

            <!-- Address -->
            <div class="emw-section">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Registered Address
                </div>
                <div class="emw-grid">
                    <div class="emw-full">
                        <div class="emw-field">
                            <label for="registered_address">Address *</label>
                            <textarea id="registered_address" name="registered_address" required placeholder="Enter registered office address"></textarea>
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label for="state_code">State</label>
                            <select id="state_code" name="state_code">
                                <option value="">Select</option>
                                <?php foreach ($stateOptions as $code => $label): ?>
                                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="emw-save-bar">
                <button type="submit" class="emw-btn emw-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Create Entity
                </button>
            </div>
        </div>

        <!-- TAB 2: Governance -->
        <div class="emw-panel" data-panel="governance">
            <div class="emw-section">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Statutory Auditor
                </div>
                <div class="emw-grid">
                    <div class="emw-full">
                        <div class="emw-field">
                            <label>Search Auditor Master</label>
                            <div class="emw-search-wrap">
                                <input type="text" id="auditor-search" placeholder="Type firm name or partner name..." autocomplete="off">
                                <input type="hidden" id="governance_auditor_id" name="governance_auditor_id" value="">
                                <div class="emw-search-results" id="auditor-results"></div>
                            </div>
                            <span class="emw-help">Auditors are reused across entities. Search to select, or create new below.</span>
                        </div>
                    </div>
                    <div id="auditor-selected" style="display:none;" class="emw-full">
                        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <strong id="auditor-display-name"></strong>
                                <div style="font-size:0.75rem;color:#64748b;" id="auditor-display-meta"></div>
                            </div>
                            <button type="button" class="emw-btn emw-btn-sm emw-btn-outline" onclick="clearAuditor()">Change</button>
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label for="audit_type">Audit Type</label>
                            <select id="audit_type" name="audit_type">
                                <option value="statutory">Statutory Audit</option>
                                <option value="tax">Tax Audit</option>
                                <option value="internal">Internal Audit</option>
                                <option value="gst">GST Audit</option>
                                <option value="secretarial">Secretarial Audit</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label for="governance_fy_id">Financial Year</label>
                            <select id="governance_fy_id" name="governance_fy_id">
                                <option value="">Select after creating entity</option>
                            </select>
                            <span class="emw-help">FY dropdown populates after entity creation.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 3: Directors -->
        <div class="emw-panel" data-panel="directors">
            <div class="emw-section">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Directors / Partners
                </div>
                <div class="emw-grid" style="margin-bottom:14px;">
                    <div class="emw-full">
                        <div class="emw-field">
                            <label>Search Director Master</label>
                            <div class="emw-search-wrap">
                                <input type="text" id="director-search" placeholder="Type name or DIN..." autocomplete="off">
                                <div class="emw-search-results" id="director-results"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="directors-list">
                    <div class="emw-empty" id="no-directors">No directors added yet. Search above to add.</div>
                </div>

                <div style="margin-top:12px;">
                    <button type="button" class="emw-btn emw-btn-outline emw-btn-sm" onclick="showNewDirectorForm()">+ Create New Director</button>
                </div>

                <!-- New Director Form (hidden) -->
                <div id="new-director-form" style="display:none;margin-top:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px;">
                    <div class="emw-grid-3">
                        <div class="emw-field">
                            <label>Director Name *</label>
                            <input type="text" id="new_dir_name" placeholder="Full name">
                        </div>
                        <div class="emw-field">
                            <label>DIN</label>
                            <input type="text" id="new_dir_din" placeholder="Director Identification Number">
                        </div>
                        <div class="emw-field">
                            <label>PAN</label>
                            <input type="text" id="new_dir_pan" placeholder="PAN" maxlength="10">
                        </div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;">
                        <button type="button" class="emw-btn emw-btn-primary emw-btn-sm" onclick="createAndAddDirector()">Create & Add</button>
                        <button type="button" class="emw-btn emw-btn-outline emw-btn-sm" onclick="hideNewDirectorForm()">Cancel</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB 4: Registrations -->
        <div class="emw-panel" data-panel="registrations">
            <div class="emw-section">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                    Registrations & Licenses
                </div>
                <div class="emw-grid">
                    <div>
                        <div class="emw-field">
                            <label>PAN</label>
                            <input type="text" name="pan_reg" placeholder="PAN" maxlength="10">
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label>GSTIN</label>
                            <input type="text" name="gstin" placeholder="GST Identification Number" maxlength="15">
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label>TAN</label>
                            <input type="text" name="tan" placeholder="Tax Deduction Account Number" maxlength="10">
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label>IEC (Import Export Code)</label>
                            <input type="text" name="iec" placeholder="IEC" maxlength="10">
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label>ESI Registration No.</label>
                            <input type="text" name="esi_no" placeholder="ESI">
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label>PF Registration No.</label>
                            <input type="text" name="pf_no" placeholder="PF">
                        </div>
                    </div>
                </div>
                <p class="emw-help" style="margin-top:10px;">Registration numbers can be added after entity creation from the Edit Entity page.</p>
            </div>
        </div>

        <!-- TAB 5: Contacts -->
        <div class="emw-panel" data-panel="contacts">
            <div class="emw-section">
                <div class="emw-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Contact Information
                </div>
                <div class="emw-grid">
                    <div>
                        <div class="emw-field">
                            <label for="official_email">Official Email</label>
                            <input type="email" id="official_email" name="official_email" placeholder="email@company.com">
                        </div>
                    </div>
                    <div>
                        <div class="emw-field">
                            <label for="mobile_no">Mobile No.</label>
                            <input type="text" id="mobile_no" name="mobile_no" placeholder="+91 XXXXX XXXXX">
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </form>
</div>

<script src="<?= BASE_URL ?>asset/js/entity_workspace.js?v=<?= filemtime(__DIR__ . '/../asset/js/entity_workspace.js') ?>"></script>
<script>
var ebalBaseUrl = '<?= BASE_URL ?>';
var ebalCsrfToken = '<?= csrfToken() ?>';
var stateFromCinMap = <?= json_encode($stateOptions) ?>;
var tallyBridgeToken = <?= json_encode($bridgeToken) ?>;
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
