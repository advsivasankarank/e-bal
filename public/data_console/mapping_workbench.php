<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/hierarchy_ai_mapping.php';
require_once '../../app/helpers/bulk_mapping_helper.php';

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

$hierarchyEngine = null;
$pageWarning = '';
try {
    $hierarchyEngine = new HierarchyAIMappingEngine($pdo, (int) $company_id, $companyCategory);
} catch (Throwable $e) {
    error_log('Mapping Workbench: hierarchy engine init failed: ' . $e->getMessage());
    $pageWarning = 'Hierarchy AI mapping unavailable. Basic mapping mode active.';
}

/* Previous FY info */
$prevFy = getPreviousFyForCompany($pdo, (int) $company_id, (int) $fy_id);

/* Check hierarchy columns */
$hasHierarchyCols = false;
try {
    $chkStmt = $pdo->query("SHOW COLUMNS FROM tally_ledger_master LIKE 'tally_group_path'");
    $hasHierarchyCols = $chkStmt->rowCount() > 0;
} catch (Throwable $e) { /* ignore */ }

/* Load all ledgers with mapping, amounts, and suggestions */
$ledgerStmt = $pdo->prepare("
    SELECT
        t.ledger_name,
        COALESCE(tlm.parent_group, t.parent_group) AS parent_group,
        " . ($hasHierarchyCols ? "
        COALESCE(tlm.primary_group, '') AS primary_group,
        COALESCE(tlm.tally_group_path, '') AS tally_group_path,
        COALESCE(tlm.tally_root_type, '') AS tally_root_type,
        " : "
        '' AS primary_group,
        '' AS tally_group_path,
        '' AS tally_root_type,
        ") . "
        lm.schedule_code AS mapped_code,
        lm.mapping_source,
        lm.confidence_score,
        lm.mapping_reason,
        lm.override_parent_group
    FROM tally_ledger_master t
    LEFT JOIN tally_ledger_master tlm ON tlm.company_id = t.company_id AND tlm.ledger_name = t.parent_group
    LEFT JOIN ledger_mapping lm ON lm.company_id = t.company_id AND lm.ledger_name = t.ledger_name
    WHERE t.company_id = ?
    ORDER BY t.ledger_name
");
$ledgerStmt->execute([$company_id]);
$allLedgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

/* Load trial balance amounts */
$tbStmt = $pdo->prepare("
    SELECT ledger_name,
           opening_amount,
           amount AS closing_amount,
           dr_cr,
           parent_group
    FROM tally_ledgers
    WHERE company_id = ? AND fy_id = ?
");
$tbStmt->execute([$company_id, $fy_id]);
$tbData = [];
while ($row = $tbStmt->fetch(PDO::FETCH_ASSOC)) {
    $tbData[$row['ledger_name']] = $row;
}

/* Compute debits/credits from voucher_entries */
$veStmt = $pdo->prepare("
    SELECT ledger_name,
           SUM(CASE WHEN dr_cr = 'DR' THEN amount ELSE 0 END) AS total_dr,
           SUM(CASE WHEN dr_cr = 'CR' THEN amount ELSE 0 END) AS total_cr
    FROM voucher_entries
    WHERE company_id = ? AND fy_id = ?
    GROUP BY ledger_name
");
$veData = [];
try {
    $veStmt->execute([$company_id, $fy_id]);
    while ($row = $veStmt->fetch(PDO::FETCH_ASSOC)) {
        $veData[$row['ledger_name']] = $row;
    }
} catch (Throwable $e) {
    /* voucher_entries may not exist */
}

/* Build grid data with suggestions */
$previousFyMappings = $prevFy ? loadPreviousFyMappings($pdo, (int) $company_id, $prevFy['id']) : [];
$globalMaster = loadGlobalMappingMaster($pdo);
$keywordRules = getEnhancedKeywordRules();

/* Current mappings lookup */
$currentMappings = [];
foreach ($allLedgers as $row) {
    if (!empty($row['mapped_code'])) {
        $currentMappings[$row['ledger_name']] = ['schedule_code' => $row['mapped_code']];
    }
}

$gridData = [];
$stats = ['total' => 0, 'mapped' => 0, 'unmapped' => 0, 'auto_suggested' => 0, 'high_confidence' => 0, 'manual_review' => 0];

foreach ($allLedgers as $row) {
    $name = $row['ledger_name'];
    $group = $row['parent_group'] ?? '';
    $mappedCode = $row['mapped_code'] ?? '';
    $isMapped = $mappedCode !== '';

    $tb = $tbData[$name] ?? null;
    $ve = $veData[$name] ?? null;

    $opening = $tb ? (float) $tb['opening_amount'] : 0;
    $closing = $tb ? (float) $tb['closing_amount'] : 0;
    $drcr = $tb ? ($tb['dr_cr'] ?? '') : '';
    $totalDr = $ve ? (float) $ve['total_dr'] : 0;
    $totalCr = $ve ? (float) $ve['total_cr'] : 0;

    /* Get suggestion */
    $suggestion = suggestBulkMapping(
        $name, $group, $currentMappings,
        $previousFyMappings, $globalMaster, $keywordRules,
        $hierarchyEngine, $mappingEngine
    );

    $scheduleLabel = $mappedCode !== '' ? $mappingEngine->getLabel($mappedCode) : '';
    $suggestedLabel = $suggestion['schedule_code'] !== '' ? $mappingEngine->getLabel($suggestion['schedule_code']) : '';

    $status = $isMapped ? 'Mapped' : 'Unmapped';

    $gridData[] = [
        'id' => $name,
        'ledger_name' => $name,
        'parent_group' => $group,
        'opening' => $opening,
        'total_dr' => $totalDr,
        'total_cr' => $totalCr,
        'closing' => $closing,
        'drcr' => $drcr,
        'current_mapping' => $mappedCode,
        'current_label' => $scheduleLabel,
        'suggested' => $suggestion['schedule_code'],
        'suggested_label' => $suggestedLabel,
        'suggestion_source' => $suggestion['source'],
        'confidence' => $suggestion['confidence'],
        'final_mapping' => $mappedCode,
        'status' => $status,
        'remarks' => '',
    ];

    $stats['total']++;
    if ($isMapped) {
        $stats['mapped']++;
    } else {
        $stats['unmapped']++;
        if ($suggestion['confidence'] >= 90) {
            $stats['auto_suggested']++;
            $stats['high_confidence']++;
        } elseif ($suggestion['confidence'] >= 70) {
            $stats['auto_suggested']++;
        } else {
            $stats['manual_review']++;
        }
    }
}

$pctComplete = $stats['total'] > 0 ? round(($stats['mapped'] / $stats['total']) * 100) : 0;

/* Mapping options as JSON for JS */
$mappingOptionsJson = [];
foreach ($mappingOptions as $code => $label) {
    $mappingOptionsJson[] = ['id' => $code, 'label' => $label . ' (' . $code . ')', 'code' => $code, 'fullLabel' => $label];
}

$page_title = "Ledger Mapping Workbench";
$showSidebar = true;
require_once __DIR__ . '/../layouts/header_v2.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6/dist/css/tabulator_midnight.min.css">
<style>
/* Tabulator theme overrides to match e-BAL design */
.tabulator { border: none !important; font-family: inherit !important; }
.tabulator .tabulator-header { background: var(--panel-strong) !important; border-bottom: 1px solid var(--border) !important; }
.tabulator .tabulator-header .tabulator-col { background: var(--panel-strong) !important; border-color: var(--border) !important; }
.tabulator .tabulator-header .tabulator-col .tabulator-col-content { padding: 6px 8px !important; }
.tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title { font-size: 0.78rem !important; font-weight: 600 !important; color: var(--muted) !important; text-transform: uppercase !important; }
.tabulator .tabulator-tableholder { background: var(--panel-strong) !important; }
.tabulator .tabulator-row { background: var(--panel-strong) !important; border-color: var(--border) !important; min-height: 34px !important; }
.tabulator .tabulator-row .tabulator-cell { border-color: var(--border) !important; padding: 4px 8px !important; font-size: 0.82rem !important; }
.tabulator .tabulator-row:hover { background: #f1f5f9 !important; }
.tabulator .tabulator-row.tabulator-selected { background: #e8f0fe !important; }
.tabulator .tabulator-row .tabulator-cell.tabulator-frozen { background: var(--panel-strong) !important; z-index: 1 !important; }
.tabulator-row.row-unmapped { background: #fff8e1 !important; }
.tabulator-row.row-lowconf { background: #fff3e0 !important; }
.tabulator .tabulator-footer { background: var(--panel-strong) !important; border-top: 1px solid var(--border) !important; }
.tabulator .tabulator-footer .tabulator-page { color: var(--text) !important; border-color: var(--border) !important; }
.tabulator .tabulator-footer .tabulator-page.active { background: var(--brand) !important; color: #fff !important; }
.tabulator-row .tabulator-cell .tabulator-select-editor select { width: 100% !important; border: 1px solid var(--border-strong) !important; border-radius: 4px !important; padding: 2px 4px !important; font-size: 0.8rem !important; background: var(--panel-strong) !important; color: var(--text) !important; }
</style>
<style>
.wb-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}
.wb-card {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 14px 16px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    cursor: default;
    transition: border-color 0.15s;
}
.wb-card:hover { border-color: var(--brand); }
.wb-card .num { font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
.wb-card .lbl { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
.wb-card.total .num { color: var(--text); }
.wb-card.mapped .num { color: var(--success); }
.wb-card.unmapped .num { color: var(--danger); }
.wb-card.suggested .num { color: var(--brand); }
.wb-card.high .num { color: #2e7d32; }
.wb-card.review .num { color: var(--warning); }
.wb-card.unsaved .num { color: #c62828; }

.wb-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.wb-toolbar .search-box {
    position: relative;
    flex: 1;
    min-width: 220px;
}
.wb-toolbar .search-box input {
    width: 100%;
    padding: 8px 12px 8px 32px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--panel-strong);
}
.wb-toolbar .search-box .s-icon {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 0.85rem; pointer-events: none;
}

.filter-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.filter-chip {
    padding: 6px 12px;
    border: 1px solid var(--border-strong);
    border-radius: 999px;
    font-size: 0.78rem;
    background: var(--panel-strong);
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
    color: var(--text);
}
.filter-chip:hover { border-color: var(--brand); color: var(--brand); }
.filter-chip.active { background: var(--brand); color: #fff; border-color: var(--brand); }

.wb-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-top: 12px;
    flex-wrap: wrap;
    box-shadow: var(--shadow-sm);
}
.wb-actions .btn { min-height: 34px; padding: 0 14px; font-size: 0.8rem; }
.wb-actions .sep { width: 1px; height: 24px; background: var(--border); margin: 0 4px; }
.wb-actions .status-text { font-size: 0.8rem; color: var(--muted); margin-left: auto; }

.hot-container {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

#hotSearch {
    display: inline-block;
    min-width: 140px;
}

.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: 8px;
    color: #fff;
    font-size: 0.85rem;
    z-index: 9999;
    animation: fadeInUp 0.3s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }
.toast.info { background: var(--brand); }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.hidden-input { display: none; }
</style>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Ledger Mapping Workbench'],
]) ?>

<?= uiPageHero('Ledger Mapping Workbench', 'Excel-like workspace for bulk ledger mapping. Edit cells inline, use keyboard navigation, and save only changed rows.') ?>

<?php if (!empty($pageWarning)): ?>
    <?= uiAlert($pageWarning, 'warning') ?>
<?php endif; ?>

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

<!-- Summary Cards -->
<div class="wb-summary" id="wbSummary">
    <div class="wb-card total"><div class="num" id="statTotal"><?= $stats['total'] ?></div><div class="lbl">Total Ledgers</div></div>
    <div class="wb-card mapped"><div class="num" id="statMapped"><?= $stats['mapped'] ?></div><div class="lbl">Mapped</div></div>
    <div class="wb-card unmapped"><div class="num" id="statUnmapped"><?= $stats['unmapped'] ?></div><div class="lbl">Unmapped</div></div>
    <div class="wb-card suggested"><div class="num" id="statSuggested"><?= $stats['auto_suggested'] ?></div><div class="lbl">Auto-Suggested</div></div>
    <div class="wb-card high"><div class="num" id="statHigh"><?= $stats['high_confidence'] ?></div><div class="lbl">High Confidence</div></div>
    <div class="wb-card review"><div class="num" id="statReview"><?= $stats['manual_review'] ?></div><div class="lbl">Manual Review</div></div>
    <div class="wb-card unsaved"><div class="num" id="statUnsaved">0</div><div class="lbl">Unsaved Changes</div></div>
</div>

<!-- Toolbar: Search + Filter Chips -->
<div class="wb-toolbar">
    <div class="search-box">
        <span class="s-icon">&#128269;</span>
        <input type="text" id="hotSearch" placeholder="Search ledger, group, schedule&hellip;">
    </div>
    <div class="filter-chips" id="filterChips">
        <span class="filter-chip active" data-filter="all">All</span>
        <span class="filter-chip" data-filter="unmapped">Unmapped</span>
        <span class="filter-chip" data-filter="mapped">Mapped</span>
        <span class="filter-chip" data-filter="suggested">Auto-Suggested</span>
        <span class="filter-chip" data-filter="high_conf">High Confidence</span>
        <span class="filter-chip" data-filter="low_conf">Low Confidence</span>
        <span class="filter-chip" data-filter="review">Manual Review</span>
        <span class="filter-chip" data-filter="bs">Balance Sheet</span>
        <span class="filter-chip" data-filter="pl">Profit & Loss</span>
        <span class="filter-chip" data-filter="asset">Assets</span>
        <span class="filter-chip" data-filter="liability">Liabilities</span>
        <span class="filter-chip" data-filter="income">Income</span>
        <span class="filter-chip" data-filter="expense">Expenses</span>
    </div>
</div>

<!-- Handsontable Grid -->
<div class="hot-container">
    <div id="hot"></div>
</div>

<!-- Action Bar -->
<div class="wb-actions">
    <button class="btn btn-success" id="btnAcceptHigh" title="Accept all suggestions with confidence >= 90%">Accept High Confidence</button>
    <button class="btn" id="btnAcceptSelected" title="Accept suggestions for selected rows">Accept Selected</button>
    <div class="sep"></div>
    <select id="bulkGroupSelect" style="padding:6px 10px;border:1px solid var(--border-strong);border-radius:6px;font-size:0.8rem;min-width:160px;">
        <option value="">Set Selected To&hellip;</option>
        <?php foreach ($mappingOptions as $code => $label): ?>
            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" id="btnBulkApply" title="Apply selected group to checked rows">Apply</button>
    <div class="sep"></div>
    <button class="btn btn-outline" id="btnReset" title="Reset all unsaved changes">&#8617; Reset</button>
    <button class="btn btn-success" id="btnSave" title="Save only changed rows">&#128190; Save Changes</button>
    <div class="sep"></div>
    <button class="btn btn-outline" id="btnExport" title="Export mapping to Excel">&#128229; Export</button>
    <button class="btn btn-outline" id="btnImport" title="Import mapping from Excel">&#128228; Import</button>
    <span class="status-text" id="statusText">Ready</span>
</div>

<!-- Hidden form for import -->
<form id="importForm" class="hidden-input" enctype="multipart/form-data">
    <?= csrfInput() ?>
    <input type="hidden" name="action" value="validate">
    <input type="file" name="file" id="importFile" accept=".xlsx,.xls">
</form>

<div style="height:16px;"></div>

<?= uiWorkspaceEnd() ?>

<script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6/dist/js/tabulator.min.js"></script>
<script>
(function() {
    'use strict';

    var ebalBaseUrl = <?= json_encode(BASE_URL) ?>;
    var csrfToken = <?= json_encode(csrfToken()) ?>;
    var mappingOptions = <?= json_encode($mappingOptionsJson) ?>;
    var allData = <?= json_encode($gridData) ?>;
    var optionsMap = {};
    var optionList = [];
    mappingOptions.forEach(function(o) {
        optionsMap[o.id] = o.label;
        optionList.push({id: o.id, label: o.label});
    });

    var originalData = JSON.parse(JSON.stringify(allData));
    var dirtyRows = {};
    var currentFilter = 'all';
    var table = null;

    /* ---- Category lookup for filter ---- */
    var bsCodes = ['share_capital','reserves','lt_borrowings','deferred_tax_liability','other_non_current_liabilities','long_term_provisions','st_borrowings','trade_payables','trade_payables_msme','other_financial_liabilities','other_current_liabilities','short_term_provisions','ppe','cwip','intangible_assets','investments_non_current','loans_non_current','deferred_tax_asset','other_non_current_assets','inventory','investments_current','receivables','cash','bank_balances_other','loans_current','other_current_assets'];
    var plCodes = ['revenue','other_income','materials','purchase_stock','inventory_change','employee_cost','finance_cost','depreciation','other_expenses'];
    var assetCodes = ['ppe','cwip','intangible_assets','investments_non_current','loans_non_current','deferred_tax_asset','other_non_current_assets','inventory','investments_current','receivables','cash','bank_balances_other','loans_current','other_current_assets'];
    var liabilityCodes = ['share_capital','reserves','lt_borrowings','deferred_tax_liability','other_non_current_liabilities','long_term_provisions','st_borrowings','trade_payables','trade_payables_msme','other_financial_liabilities','other_current_liabilities','short_term_provisions'];
    var incomeCodes = ['revenue','other_income'];
    var expenseCodes = ['materials','purchase_stock','inventory_change','employee_cost','finance_cost','depreciation','other_expenses'];

    function codeInCategory(code, list) { return list.indexOf(code) !== -1; }

    function fmtMoney(v) {
        if (v === null || v === undefined || v === '') return '';
        return parseFloat(v).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    /* ---- Filter logic ---- */
    function filterRow(row) {
        if (currentFilter === 'all') return true;
        var code = row.final_mapping || row.current_mapping || '';
        switch (currentFilter) {
            case 'unmapped': return !code || code === '';
            case 'mapped': return code && code !== '';
            case 'suggested': return row.suggested && row.suggested !== '' && (!code || code === '');
            case 'high_conf': return row.suggested && (row.confidence || 0) >= 90 && (!code || code === '');
            case 'low_conf': return row.suggested && (row.confidence || 0) > 0 && (row.confidence || 0) < 70 && (!code || code === '');
            case 'review': return (!row.suggested || row.suggested === '' || (row.confidence || 0) < 70) && (!code || code === '');
            case 'bs': return code && codeInCategory(code, bsCodes);
            case 'pl': return code && codeInCategory(code, plCodes);
            case 'asset': return code && codeInCategory(code, assetCodes);
            case 'liability': return code && codeInCategory(code, liabilityCodes);
            case 'income': return code && codeInCategory(code, incomeCodes);
            case 'expense': return code && codeInCategory(code, expenseCodes);
            default: return true;
        }
    }

    function getFilteredData() {
        var search = (document.getElementById('hotSearch').value || '').toLowerCase().trim();
        return allData.filter(function(r) {
            if (!filterRow(r)) return false;
            if (search) {
                var hay = ((r.ledger_name||'')+' '+(r.parent_group||'')+' '+(r.current_label||'')+' '+(r.suggested_label||'')+' '+(r.remarks||'')).toLowerCase();
                if (hay.indexOf(search) === -1) return false;
            }
            return true;
        });
    }

    /* ---- Stats update ---- */
    function updateStats() {
        var total=allData.length, mapped=0, unmapped=0, suggested=0, high=0, review=0, unsaved=Object.keys(dirtyRows).length;
        for (var i=0;i<allData.length;i++) {
            var d=allData[i], code=d.final_mapping||d.current_mapping||'';
            if (code&&code!=='') { mapped++; }
            else { unmapped++; if(d.suggested&&(d.confidence||0)>=90){suggested++;high++;}else if(d.suggested&&(d.confidence||0)>=70){suggested++;}else{review++;} }
        }
        document.getElementById('statTotal').textContent=total;
        document.getElementById('statMapped').textContent=mapped;
        document.getElementById('statUnmapped').textContent=unmapped;
        document.getElementById('statSuggested').textContent=suggested;
        document.getElementById('statHigh').textContent=high;
        document.getElementById('statReview').textContent=review;
        document.getElementById('statUnsaved').textContent=unsaved;
    }

    function showToast(msg,type) {
        var t=document.createElement('div');
        t.className='toast '+(type||'info');
        t.textContent=msg;
        document.body.appendChild(t);
        setTimeout(function(){t.remove();},3500);
    }

    /* ---- Tabulator dropdown editor formatter ---- */
    function finalMappingFormatter(cell) {
        var val = cell.getValue();
        if (val && optionsMap[val]) {
            var parts = optionsMap[val].split(' (');
            cell.getElement().style.color = '#1565c0';
            return parts[0];
        }
        if (val) { cell.getElement().style.color = '#1565c0'; return val; }
        cell.getElement().style.color = '#9e9e9e';
        cell.getElement().style.fontStyle = 'italic';
        return 'Select...';
    }

    function confidenceFormatter(cell) {
        var v = parseInt(cell.getValue()) || 0;
        var el = cell.getElement();
        el.style.textAlign = 'center';
        el.style.fontWeight = '600';
        if (v >= 90) el.style.color = '#2e7d32';
        else if (v >= 70) el.style.color = '#e65100';
        else if (v > 0) el.style.color = '#c62828';
        else el.style.color = '#9e9e9e';
        return v + '%';
    }

    function statusFormatter(cell) {
        var row = cell.getRow().getData();
        var code = row.final_mapping || row.current_mapping || '';
        var text = code ? 'Mapped' : 'Unmapped';
        var el = cell.getElement();
        el.style.textAlign = 'center';
        el.style.fontWeight = '600';
        el.style.fontSize = '0.78rem';
        if (text === 'Mapped') { el.style.color = '#2e7d32'; el.style.background = '#e8f5e9'; }
        else { el.style.color = '#c62828'; el.style.background = '#ffebee'; }
        return text;
    }

    function moneyFormatter(cell) {
        var v = cell.getValue();
        if (v === null || v === undefined || v === '') return '';
        return parseFloat(v).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    /* ---- Row class function ---- */
    function rowClass(row) {
        var d = row.getData();
        var code = d.final_mapping || d.current_mapping || '';
        if (!code || code === '') {
            if (d.suggested && (d.confidence || 0) < 70) return 'row-lowconf';
            return 'row-unmapped';
        }
        return '';
    }

    /* ---- Initialize Tabulator ---- */
    function initTable() {
        var filtered = getFilteredData();
        var selectOptions = {};
        optionList.forEach(function(o) { selectOptions[o.label] = o.label; });

        table = new Tabulator('#hot', {
            data: filtered,
            layout: 'fitDataFill',
            height: Math.min(filtered.length * 35 + 50, 620),
            selectable: true,
            movableColumns: true,
            resizable: true,
            headerSortTristate: true,
            rowClass: rowClass,
            placeholder: 'No ledgers found',
            columns: [
                {title:'', formatter:'rowSelection', titleFormatter:'rowSelection', headerSort:false, width:40, hozAlign:'center', cellClick:function(e, cell){cell.getRow().toggleSelect();}},
                {title:'Ledger Name', field:'ledger_name', width:220, frozen:true, headerTooltip:true},
                {title:'Tally Group', field:'parent_group', width:160},
                {title:'Opening Bal', field:'opening', width:110, hozAlign:'right', formatter:moneyFormatter, accessorDownload:moneyFormatter},
                {title:'Debit Total', field:'total_dr', width:110, hozAlign:'right', formatter:moneyFormatter, accessorDownload:moneyFormatter},
                {title:'Credit Total', field:'total_cr', width:110, hozAlign:'right', formatter:moneyFormatter, accessorDownload:moneyFormatter},
                {title:'Closing Bal', field:'closing', width:110, hozAlign:'right', formatter:moneyFormatter, accessorDownload:moneyFormatter},
                {title:'Dr/Cr', field:'drcr', width:50, hozAlign:'center'},
                {title:'Current Mapping', field:'current_label', width:160},
                {title:'Suggested', field:'suggested_label', width:180},
                {title:'Source', field:'suggestion_source', width:110},
                {title:'Conf %', field:'confidence', width:80, hozAlign:'center', formatter:confidenceFormatter, accessorDownload:function(v){return (v||0)+'%';}},
                {title:'Final Mapping', field:'final_mapping', width:240, editor:'select', editorParams:{values:selectOptions}, formatter:finalMappingFormatter, cellEdited:function(cell){
                    var row = cell.getRow().getData();
                    var val = cell.getValue();
                    var code = '';
                    if (val) {
                        for (var k=0;k<mappingOptions.length;k++) {
                            if (mappingOptions[k].label===val||mappingOptions[k].code===val||mappingOptions[k].id===val) { code=mappingOptions[k].id; break; }
                        }
                        if (!code) code = val;
                    }
                    row.final_mapping = code;
                    row.status = code ? 'Mapped' : 'Unmapped';
                    if (code !== row.current_mapping) { dirtyRows[row.ledger_name] = true; }
                    else { delete dirtyRows[row.ledger_name]; }
                    table.updateData([{ledger_name:row.ledger_name, final_mapping:code}]);
                    updateStats();
                }},
                {title:'Status', width:80, hozAlign:'center', formatter:statusFormatter, download:false},
                {title:'Remarks', field:'remarks', width:160, editor:'input', cellEdited:function(cell){
                    var row = cell.getRow().getData();
                    row.remarks = cell.getValue() || '';
                    dirtyRows[row.ledger_name] = true;
                    updateStats();
                }},
            ],
        });
    }

    function refreshGrid() {
        if (table) { table.destroy(); }
        initTable();
    }

    /* ---- Filter chip click ---- */
    document.getElementById('filterChips').addEventListener('click', function(e) {
        var chip = e.target.closest('.filter-chip');
        if (!chip) return;
        document.querySelectorAll('.filter-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');
        currentFilter = chip.getAttribute('data-filter');
        refreshGrid();
    });

    /* ---- Search ---- */
    var searchTimer;
    document.getElementById('hotSearch').addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(refreshGrid, 250);
    });

    /* ---- Accept all high confidence ---- */
    document.getElementById('btnAcceptHigh').addEventListener('click', function() {
        var count = 0;
        for (var i=0;i<allData.length;i++) {
            var d = allData[i];
            if (d.suggested && (d.confidence||0)>=90 && d.final_mapping!==d.suggested) {
                d.final_mapping = d.suggested;
                d.status = 'Mapped';
                dirtyRows[d.ledger_name] = true;
                count++;
            }
        }
        refreshGrid();
        updateStats();
        showToast(count+' ledgers auto-accepted (>= 90% confidence)', 'success');
    });

    /* ---- Accept selected ---- */
    document.getElementById('btnAcceptSelected').addEventListener('click', function() {
        if (!table) return;
        var selected = table.getSelectedRows();
        if (!selected.length) { showToast('Select rows first', 'error'); return; }
        var count = 0;
        selected.forEach(function(row) {
            var d = row.getData();
            if (d.suggested && d.suggested!=='' && d.final_mapping!==d.suggested) {
                d.final_mapping = d.suggested;
                d.status = 'Mapped';
                dirtyRows[d.ledger_name] = true;
                count++;
            }
        });
        refreshGrid();
        updateStats();
        showToast(count+' selected ledgers accepted', 'success');
    });

    /* ---- Bulk apply group ---- */
    document.getElementById('btnBulkApply').addEventListener('click', function() {
        var groupCode = document.getElementById('bulkGroupSelect').value;
        if (!groupCode) { showToast('Select a group first', 'error'); return; }
        if (!table) return;
        var selected = table.getSelectedRows();
        if (!selected.length) { showToast('Select rows first', 'error'); return; }
        var count = 0;
        selected.forEach(function(row) {
            var d = row.getData();
            d.final_mapping = groupCode;
            d.status = 'Mapped';
            dirtyRows[d.ledger_name] = true;
            count++;
        });
        refreshGrid();
        updateStats();
        showToast(count+' ledgers set to '+(optionsMap[groupCode]||groupCode), 'success');
    });

    /* ---- Reset ---- */
    document.getElementById('btnReset').addEventListener('click', function() {
        allData = JSON.parse(JSON.stringify(originalData));
        dirtyRows = {};
        refreshGrid();
        updateStats();
        showToast('All changes reset', 'info');
    });

    /* ---- Save via AJAX ---- */
    document.getElementById('btnSave').addEventListener('click', function() {
        var dirty = Object.keys(dirtyRows);
        if (!dirty.length) { showToast('No changes to save', 'info'); return; }
        var mappings = {};
        dirty.forEach(function(name) {
            for (var i=0;i<allData.length;i++) {
                if (allData[i].ledger_name===name && allData[i].final_mapping && allData[i].final_mapping!=='') {
                    mappings[name] = allData[i].final_mapping;
                    break;
                }
            }
        });
        document.getElementById('statusText').textContent = 'Saving...';
        document.getElementById('btnSave').disabled = true;
        fetch(ebalBaseUrl+'data_console/ajax_mapping_save.php', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
            body: JSON.stringify({mappings:mappings,overrides:{},remember:{}}),
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            document.getElementById('btnSave').disabled = false;
            if (resp.redirect) {
                showToast(resp.error || 'Session expired', 'error');
                setTimeout(function(){ window.location.href = ebalBaseUrl + 'login.php'; }, 1500);
                return;
            }
            if (resp.success) {
                dirtyRows = {};
                Object.keys(mappings).forEach(function(name){
                    for(var i=0;i<allData.length;i++){
                        if(allData[i].ledger_name===name){
                            allData[i].current_mapping=mappings[name];
                            allData[i].current_label=optionsMap[mappings[name]]||mappings[name];
                            if(!allData[i].final_mapping||allData[i].final_mapping===''){allData[i].final_mapping=mappings[name];}
                            break;
                        }
                    }
                });
                originalData = JSON.parse(JSON.stringify(allData));
                updateStats(); refreshGrid();
                var msg = resp.saved+' mappings saved.';
                if(resp.pending>0) msg+=' '+resp.pending+' ledgers remaining.';
                if(resp.mapping_complete) msg+=' All ledgers mapped!';
                document.getElementById('statusText').textContent='Saved '+resp.saved+' rows';
                showToast(msg,'success');
            } else {
                document.getElementById('statusText').textContent='Save failed';
                showToast(resp.error||'Save failed','error');
            }
        })
        .catch(function(){
            document.getElementById('btnSave').disabled=false;
            document.getElementById('statusText').textContent='Network error';
            showToast('Network error. Please try again.','error');
        });
    });

    /* ---- Export via fetch with CSRF header ---- */
    document.getElementById('btnExport').addEventListener('click', function() {
        document.getElementById('statusText').textContent = 'Exporting...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_export.php?filter=all', {
            method: 'GET',
            headers: { 'X-CSRF-Token': csrfToken },
        })
        .then(function(r) {
            if (!r.ok) return r.json().then(function(d){ throw new Error(d.error || 'Export failed'); });
            return r.blob();
        })
        .then(function(blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'ledger_mapping_'+new Date().toISOString().slice(0,10)+'.xlsx';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            document.getElementById('statusText').textContent = 'Export complete';
        })
        .catch(function(e) {
            showToast(e.message || 'Export failed', 'error');
            document.getElementById('statusText').textContent = 'Export failed';
        });
    });

    /* ---- Import ---- */
    document.getElementById('btnImport').addEventListener('click', function() {
        document.getElementById('importFile').click();
    });

    document.getElementById('importFile').addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        var fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'validate');
        document.getElementById('statusText').textContent = 'Validating import...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_import.php', {
            method:'POST',
            headers:{'X-CSRF-Token':csrfToken},
            body: fd,
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            if (!resp.success) { showToast(resp.error||'Import failed','error'); document.getElementById('statusText').textContent='Import failed'; return; }
            if (resp.invalid_count>0) {
                if (confirm(resp.valid_count+' valid, '+resp.invalid_count+' invalid rows.\n\nSave only valid rows?')) { saveImportedRows(file); }
            } else if (resp.valid_count>0) {
                if (confirm(resp.valid_count+' rows to import. Save now?')) { saveImportedRows(file); }
            } else { showToast('No valid rows found','error'); }
        })
        .catch(function(){showToast('Import failed: network error','error');});
        document.getElementById('importFile').value='';
    });

    function saveImportedRows(file) {
        var fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'save');
        document.getElementById('statusText').textContent = 'Importing...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_import.php', {
            method:'POST',
            headers:{'X-CSRF-Token':csrfToken},
            body: fd,
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            if (resp.success) {
                showToast(resp.saved+' mappings imported','success');
                document.getElementById('statusText').textContent='Imported '+resp.saved+' rows';
                setTimeout(function(){window.location.reload();},1500);
            } else { showToast(resp.error||'Import failed','error'); document.getElementById('statusText').textContent='Import failed'; }
        })
        .catch(function(){showToast('Import failed: network error','error');});
    }

    /* ---- Init ---- */
    initTable();
    updateStats();

})();
</script>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
