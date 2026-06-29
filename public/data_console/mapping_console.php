<?php
require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/hierarchy_ai_mapping.php';

requireFullContext();

$company_id = $_SESSION['company_id'];
$fy_id      = $_SESSION['fy_id'];
$userId = (int) ($_SESSION['user_id'] ?? 0);
ensureMappingAiSchema($pdo);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory, $pdo, (int) $company_id);
$hierarchyEngine = new HierarchyAIMappingEngine($pdo, (int) $company_id, $companyCategory);
$mappingOptions = $mappingEngine->getMappingOptions();
asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);

$page_title = "Mapping Console";
require_once __DIR__ . '/../layouts/header_v2.php';

/* =========================
   FETCH ALL LEDGERS (full data set for filtering)
   ========================= */
$allLedgersStmt = $pdo->prepare("
    SELECT
        t.ledger_name,
        t.parent_group,
        COALESCE(tlm.primary_group, '') AS primary_group,
        COALESCE(tlm.tally_group_path, '') AS tally_group_path,
        COALESCE(tlm.tally_root_type, '') AS tally_root_type,
        lm.schedule_code AS mapped_code,
        lm.confidence_score,
        lm.mapping_source
    FROM tally_ledger_master t
    LEFT JOIN tally_ledger_master tlm 
        ON tlm.company_id = t.company_id AND tlm.ledger_name = t.parent_group
    LEFT JOIN ledger_mapping lm
        ON lm.company_id = t.company_id
        AND lm.ledger_name = t.ledger_name
    WHERE t.company_id=?
    ORDER BY t.ledger_name
");
$allLedgersStmt->execute([$company_id]);
$allLedgers = $allLedgersStmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   AUTO-MAP: Apply AI mapping to unmapped ledgers
   ========================= */
$autoMapStmt = $pdo->prepare("
    INSERT INTO ledger_mapping
    (company_id, ledger_name, schedule_code, mapping_source, confidence_score, mapping_reason, remember_scope, approved_by_user_id, approved_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        schedule_code = VALUES(schedule_code),
        mapping_source = VALUES(mapping_source),
        confidence_score = VALUES(confidence_score),
        mapping_reason = VALUES(mapping_reason),
        remember_scope = VALUES(remember_scope),
        approved_by_user_id = VALUES(approved_by_user_id),
        approved_at = VALUES(approved_at)
");

$autoMappedCount = 0;
$reviewCount = 0;
$conflictCount = 0;
$mappedCount = 0;

foreach ($allLedgers as &$row) {
    $isMapped = !empty($row['mapped_code']);
    $parentGroup = (string) ($row['parent_group'] ?? '');

    if ($isMapped) {
        $mappedCount++;
        $row['status'] = 'mapped';
        $row['ai_suggested_code'] = $row['mapped_code'];
        $row['ai_suggested_label'] = $mappingEngine->getLabel($row['mapped_code']);
        $row['ai_confidence'] = (int) ($row['confidence_score'] ?? 100);
        $row['ai_reason'] = $row['mapping_source'] === 'manual' ? 'Manually mapped by user' : 'Auto-mapped by engine';
        $row['ai_source'] = $row['mapping_source'] ?? 'manual';
        $row['ai_conflict'] = false;
        continue;
    }

    /* Run hierarchy-aware AI for unmapped ledgers */
    $hierarchy = $hierarchyEngine->getLedgerHierarchy($row['ledger_name']);
    $aiResult = $hierarchyEngine->mapLedger($row['ledger_name'], $parentGroup, $hierarchy);

    /* Also run legacy engine for comparison */
    $legacyResult = $mappingEngine->mapLedger($row['ledger_name'], $parentGroup);
    if ($legacyResult && (!$aiResult || ($legacyResult['confidence'] ?? 0) > ($aiResult['confidence'] ?? 0))) {
        $aiResult = $legacyResult;
    }

    $suggestedCode = $aiResult ? ($aiResult['suggested_head'] ?? $aiResult['head'] ?? '') : '';
    $confidence = $aiResult ? (int) ($aiResult['confidence'] ?? 0) : 0;
    $suggestedReason = $aiResult ? ($aiResult['reason'] ?? 'No reason available') : '';
    $suggestedSource = $aiResult ? ($aiResult['source_basis'][0] ?? $aiResult['method'] ?? 'rule') : 'rule';
    $hasConflict = $suggestedCode !== '' && !isScheduleCodeAllowedForParentGroup($parentGroup, $suggestedCode);

    /* Auto-map high-confidence, low-risk, non-conflict suggestions */
    if ($suggestedCode !== '' && $confidence >= 90 && !$hasConflict && ($aiResult['risk'] ?? 'Low') === 'Low') {
        $autoMapStmt->execute([
            $company_id,
            $row['ledger_name'],
            $suggestedCode,
            'auto_' . $suggestedSource,
            $confidence,
            $suggestedReason,
            null,
            $userId > 0 ? $userId : null,
        ]);
        $autoMappedCount++;
        $row['status'] = 'auto_mapped';
        $row['ai_suggested_code'] = $suggestedCode;
        $row['ai_suggested_label'] = $mappingEngine->getLabel($suggestedCode);
        $row['ai_confidence'] = $confidence;
        $row['ai_reason'] = $suggestedReason;
        $row['ai_source'] = $suggestedSource;
        $row['ai_conflict'] = $hasConflict;
        continue;
    }

    /* Review-required ledgers */
    $reviewCount++;
    $row['status'] = 'review_required';
    $row['ai_suggested_code'] = $suggestedCode;
    $row['ai_suggested_label'] = $suggestedCode !== '' ? $mappingEngine->getLabel($suggestedCode) : 'No confident match';
    $row['ai_confidence'] = $confidence;
    $row['ai_reason'] = $suggestedReason;
    $row['ai_source'] = $suggestedSource;
    $row['ai_conflict'] = $hasConflict;
}
unset($row);

if ($autoMappedCount > 0) {
    $_SESSION['success'] = $autoMappedCount . ' ledgers were auto-mapped using Tally hierarchy.';
}

/* Count conflicts */
$conflictCount = 0;
foreach ($allLedgers as $r) {
    if (!empty($r['ai_conflict'])) $conflictCount++;
}

?>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Mapping Console'],
]) ?>

<?= uiPageHero('Mapping Console', 'Only unmatched or review-required ledgers are shown here. The mapper applies company learning, global learning, keyword rules, and parent-group controls before asking for manual review.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?= uiWorkspaceStart() ?>

<div class="summary-bar">
    <div class="summary-card">
        <div class="summary-number"><?= (int) $autoMappedCount ?></div>
        <div class="summary-label">Auto Mapped</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= (int) $reviewCount ?></div>
        <div class="summary-label">Needs Review</div>
    </div>
    <div class="summary-card">
        <div class="summary-number"><?= (int) $conflictCount ?></div>
        <div class="summary-label">Conflicts</div>
    </div>
</div>

<?php if (empty($ledgers)): ?>
    <div class="card" style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <strong>Action Completed</strong><br>
            Mapping is already completed for this company. Do you want to continue to trial balance fetch, or go back and re-sync ledgers?
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php?bridge=1">Continue</a>
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
<?= csrfInput() ?>

<?php if (!empty($ledgers)): ?>
<div class="card" style="margin-bottom:16px;">
    <strong>Bulk Match</strong><br>
    Select the ledgers that belong to a common group, choose the required schedule head, and click <strong>Match Selected</strong>. Use the remember scope when you want the system to learn future decisions.
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
        <th>Confidence</th>
        <th>AI Reason</th>
        <th>Select Head</th>
        <th>Remember</th>
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
    <td>
        <?= htmlspecialchars($suggestedLabel) ?>
        <?php if (!empty($row['ai_conflict'])): ?>
            <div style="color:#b42318; font-size:12px; margin-top:4px;">Parent-group conflict</div>
        <?php endif; ?>
    </td>
    <td style="text-align:center;">
        <strong><?= (int) ($row['ai_confidence'] ?? 0) ?>%</strong><br>
        <span style="font-size:12px; color:#667085;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($row['ai_source'] ?? 'ai')))) ?></span>
    </td>
    <td style="max-width:280px;">
        <div style="white-space:normal; color:#344054;"><?= htmlspecialchars((string) ($row['ai_reason'] ?? 'No explanation')) ?></div>
    </td>

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
    <td>
        <select name="remember_scope[<?= htmlspecialchars($row['ledger_name']) ?>]" class="remember-select">
            <option value="">Do Not Learn</option>
            <option value="company">Remember for this company</option>
            <option value="global">Remember globally</option>
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
            <a class="btn" href="<?= BASE_URL ?>data_console/tally_connect.php?bridge=1">Continue: Fetch Trial Balance</a>
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

<?= uiWorkspaceEnd() ?>

<?php require_once __DIR__ . '/../layouts/footer_v2.php'; ?>
