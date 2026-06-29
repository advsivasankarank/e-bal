<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];
$userId = (int) ($_SESSION['user_id'] ?? 0);
ensureMappingAiSchema($pdo);
ensureLedgerMappingOverrideColumn($pdo);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory, $pdo, (int) $company_id);
$mappingOptions = $mappingEngine->getMappingOptions();
asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);

$ledgerStmt = $pdo->prepare("
    SELECT
        tl.ledger_name,
        COALESCE(tlm.parent_group, tl.parent_group) AS parent_group,
        lm.schedule_code AS mapped_code,
        lm.mapping_source,
        lm.confidence_score,
        lm.mapping_reason,
        lm.override_parent_group
    FROM tally_ledgers tl
    LEFT JOIN tally_ledger_master tlm ON tlm.company_id = tl.company_id AND tlm.ledger_name = tl.ledger_name
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = tl.company_id
        AND lm.ledger_name = tl.ledger_name
    WHERE tl.company_id = ? AND tl.fy_id = ?
    ORDER BY tl.ledger_name
");
$ledgerStmt->execute([$company_id, $fy_id]);
$allLedgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$natureDisplay = [
    'asset' => 'Asset',
    'liability' => 'Liability',
    'income' => 'Income',
    'expense' => 'Expense',
];

$natureColours = [
    'asset'    => ['bg' => '#e3f2fd', 'text' => '#1565c0'],
    'liability' => ['bg' => '#fce4ec', 'text' => '#c62828'],
    'income'   => ['bg' => '#e8f5e9', 'text' => '#2e7d32'],
    'expense'  => ['bg' => '#fff3e0', 'text' => '#e65100'],
];

$gridData = [];
$conflictCount = 0;
foreach ($allLedgers as $row) {
    $parentGroup = (string) ($row['parent_group'] ?? '');
    $mapped = (string) ($row['mapped_code'] ?? '');
    $pgNature = normalizeParentGroupNature($parentGroup);
    $colour = $pgNature ? ($natureColours[$pgNature] ?? ['bg' => '#f5f5f5', 'text' => '#616161']) : ['bg' => '#f5f5f5', 'text' => '#616161'];

    $conflict = false;
    if ($mapped !== '' && $pgNature) {
        $scNature = normalizeScheduleCodeNature($mapped);
        if ($scNature && $pgNature !== $scNature) {
            $conflict = true;
            $conflictCount++;
        }
    }

    $status = $mapped !== '' ? ($conflict ? 'Conflict' : 'Mapped') : 'Unmapped';

    // Run AI suggestion for unmapped/conflict ledgers
    $aiResult = null;
    if ($status !== 'Mapped') {
        $aiResult = $mappingEngine->mapLedger($row['ledger_name'], $parentGroup);
    }

    $gridData[] = [
        'ledger_name' => $row['ledger_name'],
        'parent_group' => $parentGroup ?: '-',
        'pg_nature' => $pgNature,
        'pg_nature_label' => $pgNature ? ($natureDisplay[$pgNature] ?? '') : '',
        'status' => $status,
        'schedule_code' => $mapped,
        'schedule_label' => $mapped !== '' ? $mappingEngine->getLabel($mapped) : '',
        'override_parent_group' => (int) ($row['override_parent_group'] ?? 0),
        '_colour' => $colour,
        '_conflict' => $conflict,
        'ai_suggestion' => $aiResult ? $aiResult['head'] : null,
        'ai_confidence' => $aiResult ? (int) $aiResult['confidence'] : null,
        'ai_reason' => $aiResult ? $aiResult['reason'] : null,
        'ai_method' => $aiResult ? $aiResult['method'] : null,
    ];
}

$mappedCount = 0;
foreach ($gridData as $item) {
    if ($item['status'] === 'Mapped') $mappedCount++;
}

$mappingOptionsJson = [];
foreach ($mappingOptions as $code => $label) {
    $mappingOptionsJson[] = ['id' => $code, 'label' => $label];
}

$totalLedgers = count($gridData);
$pctComplete = $totalLedgers > 0 ? round(($mappedCount / $totalLedgers) * 100) : 0;

$page_title = "Mapping Workbench";
$showSidebar = true;
require_once __DIR__ . '/../layouts/header_v2.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@14/dist/handsontable.full.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/handsontable-overrides.css">
<style>
.mw-progress {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 24px;
}
.mw-progress .big-stat { font-size: 2rem; font-weight: 700; color: var(--brand); line-height: 1; }
.mw-progress .big-stat-label { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
.mw-progress .track { flex: 1; }
.mw-progress .track .bar { height: 8px; background: var(--bg); border-radius: 999px; overflow: hidden; }
.mw-progress .track .bar .fill { height: 100%; border-radius: 999px; background: linear-gradient(90deg, var(--warning), var(--brand), var(--success)); }
.mw-progress .track .labels { display: flex; justify-content: space-between; font-size: 0.75rem; color: var(--muted); margin-top: 4px; }
.mw-progress .stats { display: flex; gap: 20px; font-size: 0.85rem; }
.mw-progress .stats span { display: flex; align-items: center; gap: 6px; }

.mw-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.mw-toolbar .search-box {
    position: relative;
    flex: 1;
    min-width: 200px;
}
.mw-toolbar .search-box input {
    width: 100%;
    padding: 9px 12px 9px 34px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--panel-strong);
}
.mw-toolbar .search-box .icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 0.9rem;
}
.mw-toolbar .filter-group { display: flex; gap: 8px; }
.mw-toolbar .filter-select {
    padding: 9px 12px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--panel-strong);
    width: auto;
}
.mw-toolbar .btn { padding: 8px 16px; min-height: 38px; font-size: 0.85rem; }

.split-pane {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 16px;
    min-height: 520px;
}

/* ---- Ledger list (left) ---- */
.ledger-list {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
}
.ledger-list .list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}
.ledger-list .list-header h3 { font-size: 0.95rem; margin: 0; }
.ledger-list .list-header .count { font-size: 0.8rem; color: var(--muted); }
.ledger-list .list-body { flex: 1; overflow-y: auto; max-height: 600px; }
.ledger-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background 0.1s;
}
.ledger-row:hover { background: #f8fafc; }
.ledger-row.selected { background: var(--brand); color: #fff; }
.ledger-row.selected .ledger-parent { color: rgba(255,255,255,0.7); }
.ledger-row.selected .status-dot.mapped { background: #fff; }
.ledger-row.selected .status-dot.conflict { background: #fff; }
.ledger-row.selected .status-dot.pending { background: rgba(255,255,255,0.7); }
.ledger-row .ledger-name { font-weight: 500; flex: 1; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ledger-row .ledger-parent { font-size: 0.75rem; color: var(--muted); width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-shrink: 0; }
.ledger-row .status-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.status-dot.mapped { background: var(--success); }
.status-dot.conflict { background: var(--danger); }
.status-dot.pending { background: var(--warning); }

/* ---- Detail panel (right) ---- */
.detail-panel {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: 0;
}
.detail-panel .panel-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    margin-bottom: 14px;
}
.detail-panel .panel-header h3 { font-size: 1.1rem; margin: 0; }
.detail-panel .panel-header .pg { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }
.detail-panel .panel-header .badge {
    font-size: 0.75rem;
    padding: 4px 10px;
    border-radius: 999px;
    font-weight: 600;
    white-space: nowrap;
}
.detail-panel .panel-header .badge.mapped { background: #e8f5e9; color: var(--success); }
.detail-panel .panel-header .badge.conflict { background: #fef2f2; color: var(--danger); }
.detail-panel .panel-header .badge.pending { background: #fffbeb; color: var(--warning); }

.conflict-banner {
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    margin-bottom: 14px;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    gap: 10px;
}
.conflict-banner .resolve-btn { margin-left: auto; flex-shrink: 0; }

.ai-card {
    background: linear-gradient(135deg, #f0f9ff, #e9f4fb);
    border: 1px solid #b7d6f0;
    border-radius: var(--radius-sm);
    padding: 14px;
    margin-bottom: 14px;
}
.ai-card .ai-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.ai-card .ai-header .ai-label { font-weight: 600; font-size: 0.9rem; }
.ai-card .ai-conf { display: flex; align-items: center; gap: 8px; }
.ai-card .ai-conf .conf-bar { flex: 1; height: 6px; background: #dbeafe; border-radius: 999px; max-width: 120px; }
.ai-card .ai-conf .conf-bar .fill { height: 100%; border-radius: 999px; background: var(--brand); }
.ai-card .ai-reason { font-size: 0.8rem; color: var(--muted); margin-top: 6px; }
.ai-card .ai-actions { display: flex; gap: 8px; margin-top: 10px; }
.ai-card .ai-actions .btn { min-height: 32px; padding: 0 12px; font-size: 0.8rem; }

.detail-row { margin-bottom: 12px; }
.detail-row label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--muted); margin-bottom: 4px; }
.detail-row select, .detail-row input { width: 100%; padding: 9px 12px; border: 1px solid var(--border-strong); border-radius: 8px; font-size: 0.85rem; }
.detail-row input[readonly] { background: var(--bg); }

.detail-actions { display: flex; gap: 10px; margin-top: auto; padding-top: 14px; border-top: 1px solid var(--border); }
.detail-actions .btn { flex: 1; min-height: 38px; font-size: 0.85rem; }

/* ---- Batch bar ---- */
.batch-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-top: 16px;
    box-shadow: var(--shadow-sm);
}
.batch-bar .selected-count { font-size: 0.85rem; font-weight: 600; margin: 0; }
.batch-bar .btn { min-height: 34px; padding: 0 14px; font-size: 0.8rem; }

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
    color: var(--muted);
    text-align: center;
}
.empty-state .empty-icon { font-size: 2rem; margin-bottom: 8px; opacity: 0.4; }

.override-check { display: flex; align-items: center; gap: 8px; }
.override-check input { width: auto; }
.override-check label { margin: 0; white-space: nowrap; font-size: 0.8rem; }
</style>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Mapping Workbench'],
]) ?>

<?= uiPageHero('Mapping Workbench', 'Advanced ledger mapping with split-pane view and AI recommendations.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<?= uiWorkspaceStart() ?>

<!-- Progress overview -->
<div class="mw-progress" id="progressBar">
    <div>
        <div class="big-stat"><?= $pctComplete ?>%</div>
        <div class="big-stat-label">Complete</div>
    </div>
    <div class="track">
        <div class="bar"><div class="fill" style="width:<?= $pctComplete ?>%"></div></div>
        <div class="labels">
            <span>0%</span>
            <span><?= $mappedCount ?> Mapped</span>
            <span><?= $totalLedgers - $mappedCount ?> Remaining</span>
            <span>100%</span>
        </div>
    </div>
    <div class="stats" id="progressStats">
        <span><span style="color:var(--success)">&#10003;</span> <strong><?= $mappedCount ?></strong> Mapped</span>
        <span><span style="color:var(--danger)">&#9888;</span> <strong><?= $conflictCount ?></strong> Conflicts</span>
        <span><span style="color:var(--warning)">&#9203;</span> <strong><?= $totalLedgers - $mappedCount ?></strong> Pending</span>
    </div>
</div>

<!-- Toolbar -->
<div class="mw-toolbar" id="mwToolbar">
    <div class="search-box">
        <span class="icon">&#128269;</span>
        <input type="text" id="mwSearch" placeholder="Search by ledger name or parent group&hellip;">
    </div>
    <div class="filter-group">
        <select class="filter-select" id="filterStatus">
            <option value="">All Status</option>
            <option value="Mapped">Mapped</option>
            <option value="Conflict">Conflict</option>
            <option value="Unmapped">Pending</option>
        </select>
        <select class="filter-select" id="filterNature">
            <option value="">All Groups</option>
            <option value="asset">Asset</option>
            <option value="liability">Liability</option>
            <option value="income">Income</option>
            <option value="expense">Expense</option>
        </select>
    </div>
    <a href="<?= BASE_URL ?>data_console/mapping_console.php" class="btn" id="runAiBtn">&#129302; Run AI Mapping</a>
    <button class="btn btn-outline" id="undoMwBtn">&#8617; Undo</button>
</div>

<!-- Split pane -->
<div class="split-pane">
    <div class="ledger-list">
        <div class="list-header">
            <h3>Ledgers</h3>
            <span class="count" id="listCount">Showing <?= $totalLedgers ?> of <?= $totalLedgers ?></span>
        </div>
        <div class="list-body" id="ledgerListBody"></div>
    </div>
    <div class="detail-panel" id="detailPanel">
        <div class="empty-state">
            <div class="empty-icon">&#128203;</div>
            <div>Select a ledger to view details</div>
        </div>
    </div>
</div>

<!-- Batch bar -->
<div class="batch-bar">
    <input type="checkbox" id="selectAllCheck" style="width:auto;">
    <span class="selected-count" id="selectedCount">0 Selected</span>
    <button class="btn btn-outline btn-sm" id="bulkAssignBtn" disabled>Bulk Assign</button>
    <span style="flex:1;"></span>
    <a href="<?= BASE_URL ?>data_console/mapping_console.php" class="btn" style="position:static;min-height:34px;padding:0 14px;font-size:0.8rem;background:transparent;border:1px solid var(--border-strong);color:var(--text);box-shadow:none;">&#128451; Switch to Classic View</a>
    <button class="btn btn-sm" id="saveMwBtn">&#128190; Save All Changes</button>
</div>

<form method="post" action="mapping_save.php" id="mwForm">
    <?= csrfInput() ?>
    <input type="hidden" name="mapping_data" id="mwMappingData" value="">
    <input type="hidden" name="allow_override" value="1">
    <input type="hidden" name="return_to" value="mapping_workbench">
</form>

<script src="https://cdn.jsdelivr.net/npm/handsontable@14/dist/handsontable.full.min.js"></script>
<script>
var mwOptions = <?= json_encode($mappingOptionsJson) ?>;
var mwOptionsMap = {};
mwOptions.forEach(function (o) { mwOptionsMap[o.id] = o.label; });

var allData = <?= json_encode($gridData) ?>;
var filteredData = allData.slice();
var selectedLedger = null;
var selectAllChecked = false;

function statusDot(status) {
    if (status === 'Mapped') return 'mapped';
    if (status === 'Conflict') return 'conflict';
    return 'pending';
}

function renderList(data) {
    var body = document.getElementById('ledgerListBody');
    if (data.length === 0) {
        body.innerHTML = '<div class="empty-state"><div class="empty-icon">&#128269;</div><div>No ledgers match your filter</div></div>';
        document.getElementById('listCount').textContent = '0 of ' + allData.length;
        return;
    }
    var html = '';
    for (var i = 0; i < data.length; i++) {
        var r = data[i];
        var sel = selectedLedger === r.ledger_name ? ' selected' : '';
        html += '<div class="ledger-row' + sel + '" data-idx="' + i + '">';
        html += '<span class="status-dot ' + statusDot(r.status) + '"></span>';
        html += '<span class="ledger-name">' + escHtml(r.ledger_name) + '</span>';
        html += '<span class="ledger-parent">' + escHtml(r.parent_group) + '</span>';
        html += '</div>';
    }
    body.innerHTML = html;
    document.getElementById('listCount').textContent = 'Showing ' + data.length + ' of ' + allData.length;
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function getNatureLabel(nature) {
    var labels = { asset: 'Asset', liability: 'Liability', income: 'Income', expense: 'Expense' };
    return labels[nature] || '';
}

function getNatureColour(nature) {
    var colours = { asset: '#1565c0', liability: '#c62828', income: '#2e7d32', expense: '#e65100' };
    return colours[nature] || '#616161';
}

function showDetailPanel(rowData) {
    var panel = document.getElementById('detailPanel');
    var isMapped = rowData.status === 'Mapped';
    var isConflict = rowData.status === 'Conflict';
    var badgeClass = isConflict ? 'conflict' : (isMapped ? 'mapped' : 'pending');
    var badgeText = isConflict ? '&#9888; Conflict' : (isMapped ? '&#10003; Mapped' : '&#9203; Pending');
    var natureLabel = rowData.pg_nature_label ? ' (' + rowData.pg_nature_label + ')' : '';

    var html = '';
    html += '<div class="panel-header">';
    html += '<div><h3>' + escHtml(rowData.ledger_name) + '</h3>';
    html += '<span class="pg">Parent Group: ' + escHtml(rowData.parent_group) + natureLabel + '</span></div>';
    html += '<span class="badge ' + badgeClass + '">' + badgeText + '</span>';
    html += '</div>';

    // Conflict banner
    if (isConflict) {
        var pgNature = rowData.pg_nature_label || 'unknown';
        var scNature = mwOptionsMap[rowData.schedule_code] || rowData.schedule_code;
        html += '<div class="conflict-banner">';
        html += '<span style="font-size:1.1rem;">&#9888;&#65039;</span>';
        html += '<span>Parent group "' + escHtml(rowData.parent_group) + '" (' + pgNature + ') conflicts with selected schedule "' + escHtml(rowData.schedule_code) + '" (' + escHtml(scNature) + ').</span>';
        html += '<button class="btn btn-sm btn-warning resolve-btn" onclick="resolveConflict(\'' + escJs(rowData.ledger_name) + '\')">Override</button>';
        html += '</div>';
    }

    // AI suggestion card
    if (rowData.ai_suggestion && rowData.ai_confidence !== null) {
        var pct = rowData.ai_confidence;
        var aiLabel = mwOptionsMap[rowData.ai_suggestion] || rowData.ai_suggestion;
        html += '<div class="ai-card">';
        html += '<div class="ai-header"><span style="font-size:1.1rem;">&#129302;</span><span class="ai-label">AI Recommendation</span></div>';
        html += '<div class="ai-conf">';
        html += '<span style="font-weight:700;">' + escHtml(aiLabel) + '</span>';
        html += '<div class="conf-bar"><div class="fill" style="width:' + pct + '%"></div></div>';
        html += '<span style="font-size:0.8rem;color:var(--muted);">' + pct + '% confidence</span>';
        html += '</div>';
        html += '<div class="ai-reason">' + escHtml(rowData.ai_reason || '') + '</div>';
        html += '<div class="ai-actions">';
        html += '<button class="btn btn-sm btn-success" onclick="acceptAiSuggestion(\'' + escJs(rowData.ledger_name) + '\')">Accept</button>';
        html += '</div>';
        html += '</div>';
    }

    // Schedule select
    html += '<div class="detail-row">';
    html += '<label for="detailSchedule">Schedule Code</label>';
    html += '<select id="detailSchedule" onchange="onScheduleChange(\'' + escJs(rowData.ledger_name) + '\')">';
    html += '<option value="">-- Select --</option>';
    for (var i = 0; i < mwOptions.length; i++) {
        var sel = mwOptions[i].id === rowData.schedule_code ? ' selected' : '';
        html += '<option value="' + mwOptions[i].id + '"' + sel + '>' + mwOptions[i].id + ' &mdash; ' + escHtml(mwOptions[i].label) + '</option>';
    }
    html += '</select>';
    html += '</div>';

    html += '<div class="detail-row">';
    html += '<label>Schedule Label</label>';
    html += '<input type="text" id="detailLabel" value="' + escHtml(rowData.schedule_label || '') + '" readonly>';
    html += '</div>';

    html += '<div class="detail-row override-check">';
    html += '<input type="checkbox" id="detailOverride"' + (rowData.override_parent_group ? ' checked' : '') + '>';
    html += '<label for="detailOverride">Override Parent Group Validation</label>';
    html += '</div>';

    html += '<div class="detail-actions">';
    html += '<button class="btn" onclick="saveSingleMapping()">Save Mapping</button>';
    html += '<button class="btn btn-outline" onclick="resetDetail()">&#8617; Reset</button>';
    html += '</div>';

    panel.innerHTML = html;

    selectedLedger = rowData.ledger_name;
    renderList(filteredData);
}

function escJs(s) {
    if (!s) return '';
    return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, "&quot;");
}

function getRowByLedgerName(name, data) {
    for (var i = 0; i < data.length; i++) {
        if (data[i].ledger_name === name) return i;
    }
    return -1;
}

function onScheduleChange(ledgerName) {
    var select = document.getElementById('detailSchedule');
    var label = document.getElementById('detailLabel');
    var code = select.value;
    label.value = mwOptionsMap[code] || '';

    var idx = getRowByLedgerName(ledgerName, allData);
    if (idx >= 0) {
        allData[idx].schedule_code = code;
        allData[idx].schedule_label = label.value;
        allData[idx].status = code ? 'Mapped' : 'Unmapped';
        // Simplified conflict check
        allData[idx]._conflict = false;
        applyFilters();
    }
}

function saveSingleMapping() {
    var select = document.getElementById('detailSchedule');
    var override = document.getElementById('detailOverride').checked ? 1 : 0;
    if (!selectedLedger) return;
    var mappingData = {};
    mappingData[selectedLedger] = select.value;
    document.getElementById('mwMappingData').value = JSON.stringify(mappingData);
    // Add override info
    var form = document.getElementById('mwForm');
    var overrideInput = document.createElement('input');
    overrideInput.type = 'hidden';
    overrideInput.name = 'override_flags';
    overrideInput.value = JSON.stringify((function() { var o = {}; o[selectedLedger] = override; return o; })());
    form.appendChild(overrideInput);
    form.submit();
}

function acceptAiSuggestion(ledgerName) {
    var idx = getRowByLedgerName(ledgerName, allData);
    if (idx < 0) return;
    var suggestion = allData[idx].ai_suggestion;
    if (!suggestion) return;
    allData[idx].schedule_code = suggestion;
    allData[idx].schedule_label = mwOptionsMap[suggestion] || '';
    allData[idx].status = 'Mapped';
    allData[idx]._conflict = false;
    var fIdx = getRowByLedgerName(ledgerName, filteredData);
    if (fIdx >= 0) {
        filteredData[fIdx] = allData[idx];
    }
    applyFilters();
    showDetailPanel(allData[idx]);
    updateProgress();
}

function resolveConflict(ledgerName) {
    var idx = getRowByLedgerName(ledgerName, allData);
    if (idx < 0) return;
    allData[idx].override_parent_group = 1;
    allData[idx].status = 'Mapped';
    allData[idx]._conflict = false;
    var fIdx = getRowByLedgerName(ledgerName, filteredData);
    if (fIdx >= 0) {
        filteredData[fIdx] = allData[idx];
    }
    applyFilters();
    showDetailPanel(allData[idx]);
    updateProgress();
}

function resetDetail() {
    if (!selectedLedger) return;
    var idx = filteredData.findIndex(function (r) { return r.ledger_name === selectedLedger; });
    if (idx < 0) return;
    showDetailPanel(filteredData[idx]);
}

function updateProgress() {
    var total = allData.length;
    var mapped = 0, conflicts = 0;
    for (var i = 0; i < allData.length; i++) {
        if (allData[i].status === 'Mapped') mapped++;
        else if (allData[i].status === 'Conflict') conflicts++;
    }
    var pct = total > 0 ? Math.round((mapped / total) * 100) : 0;
    var progressFill = document.querySelector('.mw-progress .track .bar .fill');
    if (progressFill) progressFill.style.width = pct + '%';
    var labels = document.querySelector('.mw-progress .track .labels');
    if (labels) {
        labels.innerHTML = '<span>0%</span><span>' + mapped + ' Mapped</span><span>' + (total - mapped) + ' Remaining</span><span>100%</span>';
    }
    var stats = document.getElementById('progressStats');
    if (stats) {
        stats.innerHTML = '<span><span style="color:var(--success)">&#10003;</span> <strong>' + mapped + '</strong> Mapped</span>' +
            '<span><span style="color:var(--danger)">&#9888;</span> <strong>' + conflicts + '</strong> Conflicts</span>' +
            '<span><span style="color:var(--warning)">&#9203;</span> <strong>' + (total - mapped) + '</strong> Pending</span>';
    }
}

function applyFilters() {
    var search = (document.getElementById('mwSearch').value || '').toLowerCase().trim();
    var statusFilter = document.getElementById('filterStatus').value;
    var natureFilter = document.getElementById('filterNature').value;

    filteredData = allData.filter(function (r) {
        if (search && r.ledger_name.toLowerCase().indexOf(search) < 0 && r.parent_group.toLowerCase().indexOf(search) < 0) return false;
        if (statusFilter && r.status !== statusFilter) return false;
        if (natureFilter && r.pg_nature !== natureFilter) return false;
        return true;
    });

    if (selectedLedger) {
        var stillHere = filteredData.some(function (r) { return r.ledger_name === selectedLedger; });
        if (!stillHere) {
            selectedLedger = null;
            document.getElementById('detailPanel').innerHTML = '<div class="empty-state"><div class="empty-icon">&#128203;</div><div>Select a ledger to view details</div></div>';
        }
    }

    renderList(filteredData);
    updateProgress();
}

// Event delegation for ledger row clicks
document.getElementById('ledgerListBody').addEventListener('click', function (e) {
    var row = e.target.closest('.ledger-row');
    if (!row) return;
    var idx = parseInt(row.getAttribute('data-idx'), 10);
    if (isNaN(idx) || idx < 0 || idx >= filteredData.length) return;
    selectedLedger = filteredData[idx].ledger_name;
    showDetailPanel(filteredData[idx]);
});

// Search with debounce
var searchTimer;
document.getElementById('mwSearch').addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 200);
});

document.getElementById('filterStatus').addEventListener('change', applyFilters);
document.getElementById('filterNature').addEventListener('change', applyFilters);

// Undo
document.getElementById('undoMwBtn').addEventListener('click', function () {
    allData = <?= json_encode($gridData) ?>;
    selectedLedger = null;
    applyFilters();
    document.getElementById('detailPanel').innerHTML = '<div class="empty-state"><div class="empty-icon">&#128203;</div><div>Select a ledger to view details</div></div>';
});

// Select all
document.getElementById('selectAllCheck').addEventListener('change', function () {
    selectAllChecked = this.checked;
    var rows = document.querySelectorAll('.ledger-row');
    for (var i = 0; i < rows.length; i++) {
        if (selectAllChecked) {
            rows[i].style.backgroundColor = '#e8f0fe';
        } else {
            rows[i].style.backgroundColor = '';
        }
    }
    document.getElementById('selectedCount').textContent = (selectAllChecked ? filteredData.length : 0) + ' Selected';
    document.getElementById('bulkAssignBtn').disabled = !selectAllChecked;
});

// Bulk Assign — apply AI suggestion to all unmapped ledgers
document.getElementById('bulkAssignBtn').addEventListener('click', function () {
    if (!selectAllChecked) return;
    var assigned = 0;
    for (var i = 0; i < allData.length; i++) {
        if (allData[i].ai_suggestion && allData[i].schedule_code !== allData[i].ai_suggestion) {
            allData[i].schedule_code = allData[i].ai_suggestion;
            allData[i].schedule_label = mwOptionsMap[allData[i].ai_suggestion] || '';
            allData[i].status = 'Mapped';
            allData[i]._conflict = false;
            assigned++;
        }
    }
    if (assigned > 0) {
        filteredData = allData.slice();
        renderList(filteredData);
        updateProgress();
        var counter = document.getElementById('selectedCount');
        if (counter) counter.textContent = assigned + ' ledgers assigned';
    }
});

// Save all
document.getElementById('saveMwBtn').addEventListener('click', function () {
    var mappingData = {};
    for (var i = 0; i < allData.length; i++) {
        if (allData[i].schedule_code) {
            mappingData[allData[i].ledger_name] = allData[i].schedule_code;
        }
    }
    document.getElementById('mwMappingData').value = JSON.stringify(mappingData);
    document.getElementById('mwForm').submit();
});

// Init
renderList(filteredData);
</script>

<?= uiWorkspaceEnd() ?>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
