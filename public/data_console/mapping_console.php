<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';

requireFullContext();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory);
$mappingOptions = $mappingEngine->getMappingOptions();
asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);

$page_title = "Mapping Console";
require_once __DIR__ . '/../layouts/header.php';

/* =========================
   FETCH LEDGERS
========================= */
$stmt = $pdo->prepare("
    SELECT
        t.ledger_name,
        t.parent_group,
        lm.schedule_code AS mapped_code
    FROM tally_ledger_master t
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = t.company_id
        AND lm.ledger_name = t.ledger_name
    WHERE t.company_id=?
      AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
    ORDER BY t.ledger_name
");
$stmt->execute([$company_id]);
$ledgers = $stmt->fetchAll();

$autoMapStmt = $pdo->prepare("
    INSERT INTO ledger_mapping
    (company_id, ledger_name, schedule_code)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE
        schedule_code = VALUES(schedule_code)
");

$autoMappedCount = 0;
$unmatchedLedgers = [];

foreach ($ledgers as $row) {
    if (!empty($row['mapped_code'])) {
        continue;
    }

    $suggestion = $mappingEngine->mapLedger($row['ledger_name'], $row['parent_group']);
    $suggestedCode = ($suggestion['head'] ?? 'unmapped') !== 'unmapped' ? $suggestion['head'] : '';
    $confidence = (int) ($suggestion['confidence'] ?? 0);

    if ($suggestedCode !== '' && $confidence >= 70) {
        $autoMapStmt->execute([$company_id, $row['ledger_name'], $suggestedCode]);
        $autoMappedCount++;
        continue;
    }

    $row['ai_suggested_code'] = $suggestedCode;
    $row['ai_suggested_label'] = $suggestedCode !== '' ? $mappingEngine->getLabel($suggestedCode) : 'No confident match';
    $unmatchedLedgers[] = $row;
}

$ledgers = $unmatchedLedgers;

if (empty($ledgers)) {
    updateWorkflow($company_id, $fy_id, 'mapping_completed');
}

?>

<div class="page-title">Mapping Console</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:20px;">
    Only unmatched ledgers are shown here. Backend AI suggestions are generated from the predefined financial format list, then you can review and save the remaining mappings.
</div>

<?php if (empty($ledgers)): ?>
    <div class="card" style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Mapping is already completed for this company. Do you want to continue to trial balance fetch, or go back and re-sync ledgers?
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php">Continue</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_console.php">Re-sync Ledgers</a>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <a class="btn" href="<?= BASE_URL ?>data_console/view_synced_ledgers.php">View Synced Ledgers</a>
</div>

<?php if ($autoMappedCount > 0): ?>
    <div class="success-box"><p><?= htmlspecialchars($autoMappedCount . ' ledgers were auto-mapped by the backend engine.') ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['mapping_parent_group_conflicts'])): ?>
    <div class="error-box" style="margin-bottom:16px;">
        <p>Parent group validation conflict detected. A ledger under Assets, Liabilities, Income, or Expenses cannot be saved into a note head of a different accounting nature.</p>
        <ul style="margin:8px 0 0 18px;">
            <?php foreach (array_slice($_SESSION['mapping_parent_group_conflicts'], 0, 8) as $conflict): ?>
                <li><?= htmlspecialchars($conflict['ledger_name'] . ' [' . ($conflict['parent_group'] !== '' ? $conflict['parent_group'] : 'No Parent Group') . '] -> ' . $conflict['schedule_code']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="mapping_save.php">

<?php if (!empty($ledgers)): ?>
<div class="card" style="margin-bottom:16px;">
    <strong>Bulk Match</strong><br>
    Select the ledgers that belong to a common group, choose the required schedule head, and click <strong>Match Selected</strong>.
    <div style="margin-top:12px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="select_all_ledgers">
            <span>Select All</span>
        </label>
        <select id="bulk_mapping_code">
            <option value="">Select Head</option>
            <?php foreach ($mappingOptions as $optionCode => $optionLabel): ?>
                <option value="<?= htmlspecialchars($optionCode) ?>"><?= htmlspecialchars($optionLabel) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn" onclick="applyBulkMapping()">Match Selected</button>
    </div>
</div>
<?php endif; ?>

<table border="1" cellpadding="8" cellspacing="0" width="100%">
    <tr>
        <th>Select</th>
        <th>Ledger</th>
        <th>Parent Group</th>
        <th>Suggested Head</th>
        <th>Select Head</th>
    </tr>

<?php foreach ($ledgers as $row): 
    $suggestedCode = $row['ai_suggested_code'] ?? '';
    $suggestedLabel = $row['ai_suggested_label'] ?? 'No confident match';
    $selectedCode = $row['mapped_code'] ?: $suggestedCode;
?>

<tr>
    <td>
        <input type="checkbox" class="ledger-selector">
    </td>
    <td><?= htmlspecialchars($row['ledger_name']) ?></td>
    <td><?= htmlspecialchars($row['parent_group'] ?: '-') ?></td>
    <td><?= htmlspecialchars($suggestedLabel) ?></td>

    <td>
        <select name="mapping[<?= htmlspecialchars($row['ledger_name']) ?>]" class="mapping-select" required>
            <option value="">Select</option>
            <?php foreach ($mappingOptions as $optionCode => $optionLabel): ?>
                <option value="<?= htmlspecialchars($optionCode) ?>" <?= $selectedCode === $optionCode ? "selected" : "" ?>>
                    <?= htmlspecialchars($optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </td>
</tr>

<?php endforeach; ?>

</table>

<?php if (empty($ledgers)): ?>
    <div class="card" style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            All imported ledgers are mapped for this company. The mapping step is complete. Continue directly to the live trial balance fetch stage.
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/view_synced_ledgers.php">View Synced Ledgers</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php">Continue: Fetch Trial Balance</a>
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_console.php">Re-sync Ledgers</a>
        </div>
    </div>
<?php endif; ?>

<br>

<div style="margin-bottom:12px; display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
    <label style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="allow_override" value="1">
        Allow parent group override
    </label>
    <span style="color:#667085; font-size:12px;">Use only when you intentionally want to keep a ledger under a different accounting nature.</span>
</div>

<button type="submit" class="btn">Save Mapping</button>

</form>

<?php if (!empty($ledgers)): ?>
<script>
const selectAllLedgers = document.getElementById('select_all_ledgers');

if (selectAllLedgers) {
    selectAllLedgers.addEventListener('change', function () {
        document.querySelectorAll('.ledger-selector').forEach(function (checkbox) {
            checkbox.checked = selectAllLedgers.checked;
        });
    });
}

function applyBulkMapping() {
    const selectedCode = document.getElementById('bulk_mapping_code').value;

    if (!selectedCode) {
        alert('Select a schedule head first.');
        return;
    }

    const checkedRows = document.querySelectorAll('.ledger-selector:checked');

    if (checkedRows.length === 0) {
        alert('Select at least one ledger to match.');
        return;
    }

    checkedRows.forEach(function (checkbox) {
        const row = checkbox.closest('tr');
        const mappingSelect = row.querySelector('.mapping-select');

        if (mappingSelect) {
            mappingSelect.value = selectedCode;
        }
    });
}
</script>
<?php endif; ?>

<?php
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['mapping_parent_group_conflicts']);
?>

<?php require_once __DIR__ . '/../layouts/footer.php'; ?>
