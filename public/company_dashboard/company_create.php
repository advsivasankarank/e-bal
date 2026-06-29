<?php
/**
 * e-BAL — Entity Creation
 *
 * Dual creation method: Manual or Fetch from Tally.
 * Only Entity Name is mandatory for manual creation.
 * Fetch from Tally uses Smart Bridge to import company data.
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

/* Bridge configuration for Tally import */
$bridgeToken = buildBridgeBrowserToken('company');
$bridgeUrl = defined('TALLY_BRIDGE_URL') ? trim((string) TALLY_BRIDGE_URL) : '';
if ($bridgeUrl === '') {
    $bridgeUrl = 'http://127.0.0.1:9123';
}
$bridgeUrl = rtrim($bridgeUrl, '/');

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

include __DIR__ . '/../layouts/header_v2.php';
?>

<?= uiBreadcrumb([
    ['label' => 'Dashboard', 'href' => BASE_URL . 'dashboard_main.php'],
    ['label' => 'Create Entity']
]) ?>

<?= uiPageHero('Create Entity') ?>

<style>
.qec{max-width:600px;margin:40px auto}
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

/* Creation Method Selector */
.qec-method{display:flex;gap:0;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:22px}
.qec-method-opt{flex:1;padding:14px 16px;cursor:pointer;background:#f8fafc;border:none;font-size:.85rem;font-weight:600;color:#64748b;transition:all .15s;display:flex;flex-direction:column;align-items:center;gap:6px;border-right:1px solid #e2e8f0}
.qec-method-opt:last-child{border-right:none}
.qec-method-opt:hover{background:#f0f7ff;color:#12355b}
.qec-method-opt.active{background:#12355b;color:#fff}
.qec-method-opt input[type="radio"]{display:none}
.qec-method-opt .method-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#475569;transition:all .15s}
.qec-method-opt.active .method-icon{background:rgba(255,255,255,.2);color:#fff}
.qec-method-opt .method-label{font-size:.82rem}
.qec-method-opt .method-desc{font-size:.7rem;font-weight:400;opacity:.7}

/* Tally Bridge Status */
.qec-tally-status{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:.82rem;font-weight:500;margin-bottom:16px}
.qec-tally-status.detecting{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.qec-tally-status.online{background:#d1fae5;color:#047857;border:1px solid #a7f3d0}
.qec-tally-status.offline{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.qec-tally-status .spinner{width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Tally Company List */
.qec-tally-list{max-height:280px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px}
.qec-tally-item{padding:12px 16px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .15s;display:flex;align-items:center;gap:12px}
.qec-tally-item:last-child{border-bottom:none}
.qec-tally-item:hover{background:#f0f7ff}
.qec-tally-item.selected{background:#eef2ff;border-left:3px solid #12355b;padding-left:13px}
.qec-tally-item .radio{width:16px;height:16px;border:2px solid #d1d5db;border-radius:50%;flex-shrink:0;position:relative}
.qec-tally-item.selected .radio{border-color:#12355b}
.qec-tally-item.selected .radio::after{content:'';position:absolute;top:3px;left:3px;width:6px;height:6px;border-radius:50%;background:#12355b}
.qec-tally-item-name{font-weight:600;font-size:.88rem;color:#1e293b}
.qec-tally-item-meta{font-size:.72rem;color:#64748b}

.qec-hidden{display:none}
</style>

<div class="qec">
    <div class="qec-card">
        <div class="qec-header">
            <h1>Create Entity</h1>
            <p>Create manually or fetch from Tally using Smart Bridge.</p>
        </div>

        <?php if ($errors): ?>
            <div class="qec-errors"><?php foreach ($errors as $err): ?><p><?= htmlspecialchars($err) ?></p><?php endforeach; ?></div>
        <?php endif; ?>

        <form method="post" id="entity-form">
            <?= csrfInput() ?>

            <!-- Creation Method Selector -->
            <div class="qec-method" id="creation-method">
                <label class="qec-method-opt active" data-method="manual" onclick="selectMethod('manual')">
                    <input type="radio" name="creation_method" value="manual" checked>
                    <div class="method-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </div>
                    <span class="method-label">Create Manually</span>
                    <span class="method-desc">Enter entity details by hand</span>
                </label>
                <label class="qec-method-opt" data-method="tally" onclick="selectMethod('tally')">
                    <input type="radio" name="creation_method" value="tally">
                    <div class="method-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                    </div>
                    <span class="method-label">Fetch from Tally</span>
                    <span class="method-desc">Import via Smart Bridge</span>
                </label>
            </div>

            <!-- Manual Creation Panel -->
            <div id="panel-manual">
                <div class="qec-info">
                    <p><strong>Quick Start:</strong> Create an entity now with just a name. You can add CIN, PAN, GSTIN, address, auditor details, and more from the Edit Entity page at any time.</p>
                </div>

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
            </div>

            <!-- Tally Import Panel -->
            <div id="panel-tally" class="qec-hidden">
                <div class="qec-info">
                    <p><strong>Fetch from Tally:</strong> Connect to your Tally installation via Smart Bridge to import company data automatically. The bridge must be running on this machine.</p>
                </div>

                <!-- Bridge Status -->
                <div class="qec-tally-status detecting" id="bridge-status">
                    <div class="spinner"></div>
                    <span>Detecting Smart Bridge...</span>
                </div>

                <!-- Bridge Offline Message -->
                <div id="bridge-offline" class="qec-hidden" style="text-align:center;padding:20px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:16px;">
                    <div style="color:#991b1b;font-weight:600;margin-bottom:6px;">Smart Bridge Not Detected</div>
                    <div style="font-size:.82rem;color:#991b1b;margin-bottom:12px;">Ensure e-BAL Smart Bridge is running on port 9123.</div>
                    <button type="button" class="qec-btn qec-btn-outline" onclick="if(window.ebalBridge)window.ebalBridge.check();" style="font-size:.82rem;padding:6px 14px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                        Retry Detection
                    </button>
                </div>

                <!-- Company List -->
                <div id="tally-companies-wrap" class="qec-hidden">
                    <div style="margin-bottom:8px;font-size:.85rem;font-weight:600;color:#475569;">Select a company from Tally:</div>
                    <div class="qec-tally-list" id="tally-companies"></div>
                    <div class="qec-actions" style="border-top:none;padding-top:10px;">
                        <a href="company_list.php" class="qec-btn qec-btn-outline">Cancel</a>
                        <button type="button" class="qec-btn qec-btn-primary" id="tally-import-btn" disabled onclick="importSelectedTally()">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                            Import Company
                        </button>
                    </div>
                </div>

                <!-- No Companies Message -->
                <div id="tally-empty" class="qec-hidden" style="text-align:center;padding:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px;">
                    <div style="color:#64748b;font-weight:600;margin-bottom:4px;">No Companies Found</div>
                    <div style="font-size:.82rem;color:#64748b;">Tally has no companies loaded. Please create a company in Tally first.</div>
                </div>
            </div>

            <input type="hidden" id="imported_name" name="imported_name" value="">
            <input type="hidden" id="imported_category" name="imported_category" value="">
            <input type="hidden" id="imported_address" name="imported_address" value="">
            <input type="hidden" id="imported_pan" name="imported_pan" value="">
            <input type="hidden" id="imported_gstin" name="imported_gstin" value="">
        </form>
    </div>
</div>

<script>
var ebalBaseUrl = '<?= BASE_URL ?>';
var ebalCsrfToken = '<?= csrfToken() ?>';
var tallyBridgeToken = '<?= htmlspecialchars($bridgeToken) ?>';
var selectedTallyCompany = null;

function selectMethod(method) {
    var opts = document.querySelectorAll('.qec-method-opt');
    for (var i = 0; i < opts.length; i++) {
        opts[i].classList.toggle('active', opts[i].getAttribute('data-method') === method);
        opts[i].querySelector('input[type="radio"]').checked = opts[i].getAttribute('data-method') === method;
    }
    document.getElementById('panel-manual').classList.toggle('qec-hidden', method !== 'manual');
    document.getElementById('panel-tally').classList.toggle('qec-hidden', method !== 'tally');
    if (method === 'tally') {
        syncBridgeStatus();
        if (window.ebalBridge && typeof window.ebalBridge.check === 'function') {
            window.ebalBridge.check();
        }
    }
}

function selectType(el, category, subcategory) {
    document.getElementById('category').value = category;
    document.getElementById('noncorp_subcategory').value = subcategory || '';
    var chips = document.querySelectorAll('.qec-chip');
    for (var i = 0; i < chips.length; i++) {
        chips[i].classList.toggle('active', chips[i] === el);
    }
}

/* ---- Bridge Status Sync (consumes shared ebalBridge service) ---- */
function syncBridgeStatus() {
    var statusEl = document.getElementById('bridge-status');
    var offlineEl = document.getElementById('bridge-offline');
    var companiesWrap = document.getElementById('tally-companies-wrap');

    if (!window.ebalBridge) {
        statusEl.className = 'qec-tally-status detecting';
        statusEl.innerHTML = '<div class="spinner"></div><span>Initializing bridge service...</span>';
        statusEl.classList.remove('qec-hidden');
        offlineEl.classList.add('qec-hidden');
        companiesWrap.classList.add('qec-hidden');
        return;
    }

    var bs = window.ebalBridge.state;
    if (bs.bridge === 'online') {
        statusEl.className = 'qec-tally-status online';
        statusEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg><span>Smart Bridge Connected</span>';
        statusEl.classList.remove('qec-hidden');
        offlineEl.classList.add('qec-hidden');
        loadTallyCompanies();
    } else if (bs.bridge === 'offline') {
        statusEl.classList.add('qec-hidden');
        offlineEl.classList.remove('qec-hidden');
        companiesWrap.classList.add('qec-hidden');
    } else {
        statusEl.className = 'qec-tally-status detecting';
        statusEl.innerHTML = '<div class="spinner"></div><span>Detecting Smart Bridge...</span>';
        statusEl.classList.remove('qec-hidden');
        offlineEl.classList.add('qec-hidden');
        companiesWrap.classList.add('qec-hidden');
    }
}

document.addEventListener('bridge:status', function (e) {
    syncBridgeStatus();
});

function loadTallyCompanies() {
    var companiesWrap = document.getElementById('tally-companies-wrap');
    var companiesEl = document.getElementById('tally-companies');
    var emptyEl = document.getElementById('tally-empty');

    companiesEl.innerHTML = '<div style="text-align:center;padding:20px;color:#64748b;"><div class="spinner" style="margin:0 auto 8px;width:18px;height:18px;border:2px solid #12355b;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;"></div>Loading companies from Tally...</div>';
    companiesWrap.classList.remove('qec-hidden');
    emptyEl.classList.add('qec-hidden');

    var bridgeUrl = (window.ebalBridgeUrl || 'http://127.0.0.1:9123').replace(/\/+$/, '');
    var sep = bridgeUrl.indexOf('?') === -1 ? '?' : '&';
    fetch(bridgeUrl + '/companies' + sep + 'token=' + encodeURIComponent(tallyBridgeToken))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var companies = data.companies || data || [];
            if (!Array.isArray(companies) || companies.length === 0) {
                companiesWrap.classList.add('qec-hidden');
                emptyEl.classList.remove('qec-hidden');
                return;
            }
            var html = '';
            for (var i = 0; i < companies.length; i++) {
                var c = companies[i];
                var name = typeof c === 'string' ? c : (c.name || c.CompanyName || '');
                html += '<div class="qec-tally-item" onclick="selectTallyCompany(this, \'' + escHtml(name) + '\')">';
                html += '<div class="radio"></div>';
                html += '<div><div class="qec-tally-item-name">' + escHtml(name) + '</div></div>';
                html += '</div>';
            }
            companiesEl.innerHTML = html;
        })
        .catch(function() {
            companiesEl.innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;">Failed to load companies from Tally.</div>';
        });
}

function selectTallyCompany(el, name) {
    var items = document.querySelectorAll('.qec-tally-item');
    for (var i = 0; i < items.length; i++) {
        items[i].classList.remove('selected');
    }
    el.classList.add('selected');
    selectedTallyCompany = name;
    document.getElementById('tally-import-btn').disabled = false;
}

function importSelectedTally() {
    if (!selectedTallyCompany) return;

    var btn = document.getElementById('tally-import-btn');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:12px;height:12px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;"></div> Importing...';

    var bridgeUrl = (window.ebalBridgeUrl || 'http://127.0.0.1:9123').replace(/\/+$/, '');
    var sep = bridgeUrl.indexOf('?') === -1 ? '?' : '&';
    fetch(bridgeUrl + '/company_detail' + sep + 'token=' + encodeURIComponent(tallyBridgeToken) + '&name=' + encodeURIComponent(selectedTallyCompany))
        .then(function(r) { return r.json(); })
        .then(function(detail) {
            var name = detail.name || detail.CompanyName || selectedTallyCompany;
            var category = 'corporate';
            var addr = detail.address || detail.Address || '';
            var pan = detail.pan || detail.PAN || '';
            var gstin = detail.gstin || detail.GSTIN || '';

            if (detail.type) {
                var t = detail.type.toLowerCase();
                if (t.indexOf('llp') !== -1) category = 'llp';
                else if (t.indexOf('proprietor') !== -1 || t.indexOf('partnership') !== -1 || t.indexOf('trust') !== -1 || t.indexOf('society') !== -1) category = 'non_corporate';
            }

            document.getElementById('imported_name').value = name;
            document.getElementById('imported_category').value = category;
            document.getElementById('imported_address').value = addr;
            document.getElementById('imported_pan').value = pan;
            document.getElementById('imported_gstin').value = gstin;

            document.getElementById('name').value = name;
            document.getElementById('category').value = category;
            selectType(document.querySelector('.qec-chip[data-category="' + category + '"]') || document.querySelector('.qec-chip'), category, '');

            selectMethod('manual');
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Import Company';
        });
}

function escHtml(s) {
    if (!s) return '';
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
</script>

<?php include __DIR__ . '/../layouts/footer_v2.php'; ?>
