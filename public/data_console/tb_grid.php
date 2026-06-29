<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/schedule3_master_helper.php';
require_once '../../app/helpers/figure_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/fy_closure_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory);
$mappingOptions = $mappingEngine->getMappingOptions();
asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);

$stmt = $pdo->prepare("
    SELECT
        tl.ledger_name,
        tl.parent_group,
        tl.amount,
        tl.dr_cr,
        lm.schedule_code
    FROM tally_ledgers tl
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = tl.company_id
        AND lm.ledger_name = tl.ledger_name
    WHERE tl.company_id = ?
      AND tl.fy_id = ?
    ORDER BY tl.ledger_name
");
$stmt->execute([$company_id, $fy_id]);
$rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rows = [];
$drTotal = 0.0;
$crTotal = 0.0;
foreach ($rawRows as $row) {
    $scheduleCode = trim((string) ($row['schedule_code'] ?? ''));
    $dr = strtoupper((string) ($row['dr_cr'] ?? '')) === 'DR' ? (float) ($row['amount'] ?? 0) : 0.0;
    $cr = strtoupper((string) ($row['dr_cr'] ?? '')) === 'CR' ? (float) ($row['amount'] ?? 0) : 0.0;
    $drTotal += $dr;
    $crTotal += $cr;
    $parentGroup = (string) ($row['parent_group'] ?? '');
    $scheduleCode = trim((string) ($row['schedule_code'] ?? ''));
    $hasConflict = $scheduleCode !== '' && !isScheduleCodeAllowedForParentGroup($parentGroup, $scheduleCode);
    $rows[] = [
        'ledger_name' => $row['ledger_name'],
        'parent_group' => $parentGroup ?: '-',
        'schedule_code' => $scheduleCode,
        'schedule_label' => $scheduleCode !== '' ? $mappingEngine->getLabel($scheduleCode) : '',
        'validation' => $hasConflict ? 'Conflict' : ($scheduleCode !== '' ? 'OK' : ''),
        'dr' => $dr,
        'cr' => $cr,
    ];
}

$mappingOptionsJson = [];
foreach ($mappingOptions as $code => $label) {
    $mappingOptionsJson[] = ['id' => $code, 'label' => $label];
}

$page_title = 'TB Grid';
require_once __DIR__ . '/../layouts/header_v2.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@14/dist/handsontable.full.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/handsontable-overrides.css">
<style>
    .tb-grid-wrap {
        margin-top: 16px;
    }
    .tb-grid-toolbar {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 16px;
    }
    .tb-grid-toolbar .btn {
        padding: 8px 18px;
    }
</style>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Trial Balance Grid'],
]) ?>

<?= uiPageHero('Trial Balance Grid', 'A spreadsheet-style view of the trial balance. Edit schedule mappings inline using the dropdown, then save changes.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?= uiWorkspaceStart() ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<div class="tb-grid-toolbar">
    <span><strong>Rows:</strong> <span id="rowCount"><?= count($rows) ?></span></span>
    <button class="btn" id="saveGridBtn">Save Changes</button>
    <button class="btn" id="undoBtn">Undo</button>
</div>

<form method="post" action="trial_balance_preview.php" id="gridForm">
    <?= csrfInput() ?>
    <input type="hidden" name="mapping_data" id="mappingData" value="">
    <input type="hidden" name="allow_override" value="1">
</form>

<div id="tbGrid" class="tb-grid-wrap"></div>

<script src="https://cdn.jsdelivr.net/npm/handsontable@14/dist/handsontable.full.min.js"></script>
<script>
const scheduleOptions = <?= json_encode($mappingOptionsJson) ?>;
const scheduleLookup = {};
scheduleOptions.forEach(function (opt) {
    scheduleLookup[opt.id] = opt.label;
});

const gridData = <?= json_encode($rows) ?>;

const container = document.getElementById('tbGrid');
let hot = new Handsontable(container, {
    data: gridData,
    rowHeaders: true,
    colHeaders: ['Ledger Name', 'Parent Group', 'Schedule Code', 'Schedule Label', 'Validation', 'Dr', 'Cr'],
    columns: [
        { data: 'ledger_name', readOnly: true, width: 200 },
        { data: 'parent_group', readOnly: true, width: 160 },
        {
            data: 'schedule_code',
            type: 'dropdown',
            source: scheduleOptions.map(function (o) { return o.id; }),
            width: 180,
            allowInvalid: false,
            afterChange: function (changes) {
                if (!changes) return;
                changes.forEach(function (change) {
                    var row = change[0];
                    var prop = change[1];
                    var newVal = change[3];
                    if (prop === 'schedule_code') {
                        var label = scheduleLookup[newVal] || '';
                        hot.setDataAtRowProp(row, 'schedule_label', label);
                        hot.setDataAtRowProp(row, 'validation', label !== '' ? 'OK' : '');
                    }
                });
            }
        },
        { data: 'schedule_label', readOnly: true, width: 200 },
        { data: 'validation', readOnly: true, width: 100, className: function (instance, td, row, col, prop, value) {
            if (value === 'Conflict') {
                td.style.color = '#b42318';
                td.style.fontWeight = 'bold';
            } else if (value === 'OK') {
                td.style.color = '#157347';
                td.style.fontWeight = 'bold';
            }
        }},
        {
            data: 'dr',
            readOnly: true,
            width: 130,
            type: 'numeric',
            numericFormat: { pattern: '0,0.00' },
            className: 'htRight'
        },
        {
            data: 'cr',
            readOnly: true,
            width: 130,
            type: 'numeric',
            numericFormat: { pattern: '0,0.00' },
            className: 'htRight'
        }
    ],
    licenseKey: 'non-commercial-and-evaluation',
    height: Math.min(gridData.length * 35 + 40, 600),
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

document.getElementById('saveGridBtn').addEventListener('click', function () {
    var data = hot.getData();
    var mappingData = {};
    data.forEach(function (row) {
        var ledger = String(row[0] || '').trim();
        var code = String(row[2] || '').trim();
        if (ledger !== '' && code !== '') {
            mappingData[ledger] = code;
        }
    });
    document.getElementById('mappingData').value = JSON.stringify(mappingData);
    document.getElementById('gridForm').submit();
});

document.getElementById('undoBtn').addEventListener('click', function () {
    hot.undo();
});
</script>

<?= uiWorkspaceEnd() ?>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
