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

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory, $pdo, (int) $company_id);
$mappingOptions = $mappingEngine->getMappingOptions();
asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);

$ledgerStmt = $pdo->prepare("
    SELECT
        t.ledger_name,
        t.parent_group,
        lm.schedule_code AS mapped_code
    FROM tally_ledger_master t
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = t.company_id
        AND lm.ledger_name = t.ledger_name
    WHERE t.company_id = ?
    ORDER BY t.ledger_name
");
$ledgerStmt->execute([$company_id]);
$allLedgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

$categoryColours = [
    'Assets'      => ['bg' => '#e3f2fd', 'text' => '#1565c0'],
    'Liabilities' => ['bg' => '#fce4ec', 'text' => '#c62828'],
    'Income'      => ['bg' => '#e8f5e9', 'text' => '#2e7d32'],
    'Expenses'    => ['bg' => '#fff3e0', 'text' => '#e65100'],
];

function getNatureColour(string $parentGroup): array
{
    $group = strtolower(trim($parentGroup));
    if (str_contains($group, 'asset') || str_contains($group, 'bank') || str_contains($group, 'cash') || str_contains($group, 'inventory') || str_contains($group, 'receivable') || str_contains($group, 'stock') || str_contains($group, 'deposit') || str_contains($group, 'loan')) {
        return ['bg' => '#e3f2fd', 'text' => '#1565c0'];
    }
    if (str_contains($group, 'liabilit') || str_contains($group, 'capital') || str_contains($group, 'reserve') || str_contains($group, 'payable') || str_contains($group, 'borrowing')) {
        return ['bg' => '#fce4ec', 'text' => '#c62828'];
    }
    if (str_contains($group, 'income') || str_contains($group, 'revenue') || str_contains($group, 'sale')) {
        return ['bg' => '#e8f5e9', 'text' => '#2e7d32'];
    }
    if (str_contains($group, 'expense') || str_contains($group, 'cost') || str_contains($group, 'depreciation') || str_contains($group, 'purchase') || str_contains($group, 'manufacturing')) {
        return ['bg' => '#fff3e0', 'text' => '#e65100'];
    }
    return ['bg' => '#f5f5f5', 'text' => '#616161'];
}

$gridData = [];
foreach ($allLedgers as $row) {
    $parentGroup = (string) ($row['parent_group'] ?? '');
    $mapped = (string) ($row['mapped_code'] ?? '');
    $colour = getNatureColour($parentGroup);
    $gridData[] = [
        'ledger_name' => $row['ledger_name'],
        'parent_group' => $parentGroup ?: '-',
        'status' => $mapped !== '' ? 'Mapped' : 'Unmapped',
        'schedule_code' => $mapped,
        'schedule_label' => $mapped !== '' ? $mappingEngine->getLabel($mapped) : '',
        '_colour' => $colour,
    ];
}

$mappingOptionsJson = [];
foreach ($mappingOptions as $code => $label) {
    $mappingOptionsJson[] = ['id' => $code, 'label' => $label];
}

$page_title = "Mapping Workbench";
require_once __DIR__ . '/../layouts/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@14/dist/handsontable.full.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/handsontable-overrides.css">
<style>
    .mw-toolbar {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .mw-toolbar .btn {
        padding: 8px 18px;
    }
    .mw-summary {
        display: flex;
        gap: 24px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .mw-summary-item {
        font-size: 14px;
    }
    .mw-summary-item strong {
        font-size: 18px;
    }
    .mw-summary-item.mapped strong { color: #157347; }
    .mw-summary-item.unmapped strong { color: #b42318; }
</style>

<div class="page-title">Mapping Workbench</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:16px;">
    A spreadsheet-style mapping interface. Parent group colouring helps identify accounting nature at a glance. Edit mappings inline with the dropdown, then save.
    <a href="<?= BASE_URL ?>data_console/mapping_console.php" class="btn" style="margin-left:12px;">Switch to Classic View</a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<div class="mw-summary" id="summaryBar"></div>

<div class="mw-toolbar">
    <button class="btn" id="saveMwBtn">Save Changes</button>
    <button class="btn" id="undoMwBtn">Undo</button>
    <button class="btn" id="filterMappedBtn">Hide Mapped</button>
    <button class="btn" id="showAllBtn">Show All</button>
</div>

<form method="post" action="mapping_save.php" id="mwForm">
    <?= csrfInput() ?>
    <input type="hidden" name="mapping_data" id="mwMappingData" value="">
    <input type="hidden" name="allow_override" value="1">
    <input type="hidden" name="return_to" value="mapping_workbench">
</form>

<div id="mwGrid"></div>

<script src="https://cdn.jsdelivr.net/npm/handsontable@14/dist/handsontable.full.min.js"></script>
<script>
const mwOptions = <?= json_encode($mappingOptionsJson) ?>;
const mwLookup = {};
mwOptions.forEach(function (o) {
    mwLookup[o.id] = o.label;
});

const mwData = <?= json_encode($gridData) ?>;

function updateSummary() {
    var total = mwData.length;
    var mapped = mwData.filter(function (r) { return r.status === 'Mapped'; }).length;
    var unmapped = total - mapped;
    document.getElementById('summaryBar').innerHTML =
        '<div class="mw-summary-item"><strong>' + total + '</strong> Total Ledgers</div>' +
        '<div class="mw-summary-item mapped"><strong>' + mapped + '</strong> Mapped</div>' +
        '<div class="mw-summary-item unmapped"><strong>' + unmapped + '</strong> Unmapped</div>';
}

var currentMwData = mwData.slice();
var mwContainer = document.getElementById('mwGrid');
var mwHot = new Handsontable(mwContainer, {
    data: currentMwData,
    rowHeaders: true,
    colHeaders: ['Ledger Name', 'Parent Group', 'Status', 'Schedule Code', 'Schedule Label'],
    columns: [
        { data: 'ledger_name', readOnly: true, width: 220 },
        {
            data: 'parent_group',
            readOnly: true,
            width: 180,
            renderer: function (instance, td, row, col, prop, value) {
                td.textContent = value || '';
                var colour = currentMwData[row] && currentMwData[row]._colour;
                if (colour) {
                    td.style.backgroundColor = colour.bg;
                    td.style.color = colour.text;
                }
                return td;
            }
        },
        {
            data: 'status',
            readOnly: true,
            width: 100,
            renderer: function (instance, td, row, col, prop, value) {
                td.textContent = value || '';
                td.style.fontWeight = 'bold';
                td.style.color = value === 'Mapped' ? '#157347' : '#b42318';
                return td;
            }
        },
        {
            data: 'schedule_code',
            type: 'dropdown',
            source: mwOptions.map(function (o) { return o.id; }),
            width: 200,
            allowInvalid: false,
            afterChange: function (changes) {
                if (!changes) return;
                changes.forEach(function (change) {
                    var row = change[0];
                    var prop = change[1];
                    var newVal = change[3];
                    if (prop === 'schedule_code') {
                        var label = mwLookup[newVal] || '';
                        mwHot.setDataAtRowProp(row, 'schedule_label', label);
                        mwHot.setDataAtRowProp(row, 'status', label !== '' ? 'Mapped' : 'Unmapped');
                        updateSummary();
                    }
                });
            }
        },
        {
            data: 'schedule_label',
            readOnly: true,
            width: 220,
        }
    ],
    licenseKey: 'non-commercial-and-evaluation',
    height: Math.min(currentMwData.length * 35 + 40, 650),
    width: '100%',
    stretchH: 'all',
    autoWrapRow: true,
    autoWrapCol: true,
    contextMenu: true,
    filters: true,
    dropdownMenu: true,
    columnSorting: true,
    manualColumnResize: true,
    manualRowResize: false,
    renderAllRows: false,
    rowHeights: 30,
});

updateSummary();

document.getElementById('saveMwBtn').addEventListener('click', function () {
    var data = mwHot.getData();
    var mappingData = {};
    data.forEach(function (row) {
        var ledger = String(row[0] || '').trim();
        var code = String(row[3] || '').trim();
        if (ledger !== '' && code !== '') {
            mappingData[ledger] = code;
        }
    });
    document.getElementById('mwMappingData').value = JSON.stringify(mappingData);
    document.getElementById('mwForm').submit();
});

document.getElementById('undoMwBtn').addEventListener('click', function () {
    mwHot.undo();
});

var mappedHidden = false;
document.getElementById('filterMappedBtn').addEventListener('click', function () {
    if (mappedHidden) return;
    var filtered = currentMwData.filter(function (r) { return r.status !== 'Mapped'; });
    mwHot.loadData(filtered);
    currentMwData = filtered;
    mappedHidden = true;
    updateSummary();
});

document.getElementById('showAllBtn').addEventListener('click', function () {
    mwHot.loadData(mwData);
    currentMwData = mwData.slice();
    mappedHidden = false;
    updateSummary();
});
</script>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer.php';
?>
