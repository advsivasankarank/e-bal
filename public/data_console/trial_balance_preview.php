<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/schedule3_master_helper.php';
require_once '../../app/helpers/figure_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';

requireFullContext();

$company_id = (int) ($_SESSION['company_id'] ?? 0);
$fy_id = (int) ($_SESSION['fy_id'] ?? 0);

$companyStmt = $pdo->prepare("SELECT category FROM companies WHERE id = ?");
$companyStmt->execute([$company_id]);
$companyCategory = strtolower((string) $companyStmt->fetchColumn());
$mappingEngine = new AIMappingEngine($companyCategory);
$mappingOptions = $mappingEngine->getMappingOptions();
asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);
$previewMappingOptions = $mappingOptions;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mapping_data'])) {
    $decoded = json_decode((string) $_POST['mapping_data'], true);
    if (is_array($decoded)) {
        $_POST['mapping'] = $decoded;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mapping'])) {
    requireCsrfToken();
    $allowOverride = isset($_POST['allow_override']) && (string) $_POST['allow_override'] === '1';
    ensureLedgerMappingOverrideColumn($pdo);
    $saveStmt = $pdo->prepare("
        INSERT INTO ledger_mapping (company_id, ledger_name, schedule_code, override_parent_group)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            schedule_code = VALUES(schedule_code),
            override_parent_group = VALUES(override_parent_group)
    ");

    $pdo->beginTransaction();

    try {
        $parentStmt = $pdo->prepare("
            SELECT parent_group
            FROM tally_ledger_master
            WHERE company_id = ? AND ledger_name = ?
            LIMIT 1
        ");
        $conflicts = [];

        foreach ($_POST['mapping'] as $ledgerName => $scheduleCode) {
            $ledgerName = trim((string) $ledgerName);
            $scheduleCode = trim((string) $scheduleCode);

            if ($ledgerName === '' || $scheduleCode === '') {
                continue;
            }

            $parentStmt->execute([$company_id, $ledgerName]);
            $parentGroup = (string) ($parentStmt->fetchColumn() ?: '');

            if (!isScheduleCodeAllowedForParentGroup($parentGroup, $scheduleCode)) {
                $conflicts[] = buildParentGroupConflict($ledgerName, $parentGroup, $scheduleCode);
                if (!$allowOverride) {
                    continue;
                }
            }

            $saveStmt->execute([$company_id, $ledgerName, $scheduleCode, $allowOverride ? 1 : 0]);
        }

        if ($conflicts !== [] && !$allowOverride) {
            $pdo->rollBack();
            $_SESSION['tb_preview_parent_group_conflicts'] = $conflicts;
            $_SESSION['error'] = 'Parent group conflict found while saving note changes.';
            $redirectUrl = BASE_URL . 'data_console/trial_balance_preview.php';
            $selectedNote = trim((string) ($_POST['selected_note'] ?? ''));
            if ($selectedNote !== '') {
                $redirectUrl .= '?note=' . urlencode($selectedNote);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }

        $pdo->commit();
        $overrideNotice = '';
        if ($allowOverride && $conflicts !== []) {
            $_SESSION['tb_preview_parent_group_conflicts'] = $conflicts;
            $overrideNotice = ' Parent group overrides were applied for ' . count($conflicts) . ' ledger(s).';
        }
        $_SESSION['success'] = 'Trial balance note mapping updated successfully.' . $overrideNotice;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION['error'] = $e->getMessage();
    }

    $redirectUrl = BASE_URL . 'data_console/trial_balance_preview.php';
    $selectedNote = trim((string) ($_POST['selected_note'] ?? ''));
    if ($selectedNote !== '') {
        $redirectUrl .= '?note=' . urlencode($selectedNote);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$schedule3NoteMap = [];
if ($companyCategory === 'corporate') {
    $master = getSchedule3NotesMaster();
    $codeMap = schedule3MasterCodeToScheduleCodes();

    foreach ($master as $noteNo => $meta) {
        $masterCode = (string) ($meta['code'] ?? '');
        foreach ($codeMap[$masterCode] ?? [] as $scheduleCode) {
            $schedule3NoteMap[$scheduleCode] = [
                'number' => (string) $noteNo,
                'title' => (string) ($meta['title'] ?? $mappingEngine->getLabel($scheduleCode)),
                'key' => 'note-' . $noteNo,
            ];
        }
    }

    $previewMappingOptions = $mappingOptions;
    uksort($previewMappingOptions, static function (string $a, string $b) use ($schedule3NoteMap, $mappingEngine): int {
        $aMeta = $schedule3NoteMap[$a] ?? ['number' => '', 'title' => $mappingEngine->getLabel($a)];
        $bMeta = $schedule3NoteMap[$b] ?? ['number' => '', 'title' => $mappingEngine->getLabel($b)];

        $aNumber = $aMeta['number'] !== '' ? (int) $aMeta['number'] : 9999;
        $bNumber = $bMeta['number'] !== '' ? (int) $bMeta['number'] : 9999;

        if ($aNumber !== $bNumber) {
            return $aNumber <=> $bNumber;
        }

        return strcasecmp((string) $aMeta['title'], (string) $bMeta['title']);
    });
}

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
$noteGroups = [];
$inlineConflicts = [];

foreach ($rawRows as $row) {
    $scheduleCode = trim((string) ($row['schedule_code'] ?? ''));
    $defaultLabel = $scheduleCode !== '' ? $mappingEngine->getLabel($scheduleCode) : 'Unmapped';
    $noteMeta = $schedule3NoteMap[$scheduleCode] ?? [
        'number' => '',
        'title' => $defaultLabel,
        'key' => $scheduleCode !== '' ? $scheduleCode : 'unmapped',
    ];

    $noteDisplay = $noteMeta['number'] !== ''
        ? 'Note ' . $noteMeta['number'] . ' - ' . $noteMeta['title']
        : $noteMeta['title'];

    $normalizedRow = [
        'ledger_name' => (string) $row['ledger_name'],
        'parent_group' => (string) ($row['parent_group'] ?? ''),
        'schedule_code' => $scheduleCode,
        'note_number' => (string) $noteMeta['number'],
        'note_title' => (string) $noteMeta['title'],
        'note_key' => (string) $noteMeta['key'],
        'note_display' => $noteDisplay,
        'dr' => strtoupper((string) ($row['dr_cr'] ?? '')) === 'DR' ? (float) ($row['amount'] ?? 0) : 0.0,
        'cr' => strtoupper((string) ($row['dr_cr'] ?? '')) === 'CR' ? (float) ($row['amount'] ?? 0) : 0.0,
        'parent_group_conflict' => $scheduleCode !== '' && !isScheduleCodeAllowedForParentGroup((string) ($row['parent_group'] ?? ''), $scheduleCode),
    ];

    $rows[] = $normalizedRow;

    if ($normalizedRow['parent_group_conflict']) {
        $inlineConflicts[] = buildParentGroupConflict(
            $normalizedRow['ledger_name'],
            $normalizedRow['parent_group'],
            $normalizedRow['schedule_code']
        );
    }

    if (!isset($noteGroups[$normalizedRow['note_key']])) {
        $noteGroups[$normalizedRow['note_key']] = [
            'label' => $noteDisplay,
            'count' => 0,
        ];
    }
    $noteGroups[$normalizedRow['note_key']]['count']++;
}

uasort($noteGroups, static function (array $a, array $b): int {
    $extractNumber = static function (string $label): int {
        if (preg_match('/^Note\s+(\d+)/i', $label, $matches)) {
            return (int) $matches[1];
        }

        return 9999;
    };

    $aNumber = $extractNumber((string) ($a['label'] ?? ''));
    $bNumber = $extractNumber((string) ($b['label'] ?? ''));

    if ($aNumber !== $bNumber) {
        return $aNumber <=> $bNumber;
    }

    return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
});

usort($rows, static function (array $a, array $b): int {
    $aNumber = $a['note_number'] !== '' ? (int) $a['note_number'] : 9999;
    $bNumber = $b['note_number'] !== '' ? (int) $b['note_number'] : 9999;

    if ($aNumber !== $bNumber) {
        return $aNumber <=> $bNumber;
    }

    return strcasecmp($a['ledger_name'], $b['ledger_name']);
});

$selectedNote = trim((string) ($_GET['note'] ?? ''));
if ($selectedNote !== '') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($selectedNote): bool {
        return $row['note_key'] === $selectedNote;
    }));
}

$filterSlFrom = (int) ($_GET['sl_from'] ?? 0);
$filterSlTo = (int) ($_GET['sl_to'] ?? 0);
$filterLedger = trim((string) ($_GET['filter_ledger'] ?? ''));
$filterGroup = trim((string) ($_GET['filter_group'] ?? ''));
$filterNote = trim((string) ($_GET['filter_note'] ?? ''));
$filterValidation = strtolower(trim((string) ($_GET['filter_validation'] ?? '')));
$filterDrMin = is_numeric($_GET['dr_min'] ?? null) ? (float) $_GET['dr_min'] : null;
$filterDrMax = is_numeric($_GET['dr_max'] ?? null) ? (float) $_GET['dr_max'] : null;
$filterCrMin = is_numeric($_GET['cr_min'] ?? null) ? (float) $_GET['cr_min'] : null;
$filterCrMax = is_numeric($_GET['cr_max'] ?? null) ? (float) $_GET['cr_max'] : null;

if (
    $filterSlFrom > 0
    || $filterSlTo > 0
    || $filterLedger !== ''
    || $filterGroup !== ''
    || $filterNote !== ''
    || $filterValidation !== ''
    || $filterDrMin !== null
    || $filterDrMax !== null
    || $filterCrMin !== null
    || $filterCrMax !== null
) {
    $rows = array_values(array_filter($rows, static function (array $row, int $index) use (
        $filterSlFrom,
        $filterSlTo,
        $filterLedger,
        $filterGroup,
        $filterNote,
        $filterValidation,
        $filterDrMin,
        $filterDrMax,
        $filterCrMin,
        $filterCrMax
    ): bool {
        $serial = $index + 1;
        if ($filterSlFrom > 0 && $serial < $filterSlFrom) {
            return false;
        }
        if ($filterSlTo > 0 && $serial > $filterSlTo) {
            return false;
        }

        if ($filterLedger !== '' && !str_contains(strtolower((string) ($row['ledger_name'] ?? '')), strtolower($filterLedger))) {
            return false;
        }

        if ($filterGroup !== '' && !str_contains(strtolower((string) ($row['parent_group'] ?? '')), strtolower($filterGroup))) {
            return false;
        }

        if ($filterNote !== '' && !str_contains(strtolower((string) ($row['note_display'] ?? '')), strtolower($filterNote))) {
            return false;
        }

        $validationLabel = !empty($row['parent_group_conflict']) ? 'conflict' : 'ok';
        if ($filterValidation !== '' && $validationLabel !== $filterValidation) {
            return false;
        }

        $dr = (float) ($row['dr'] ?? 0);
        $cr = (float) ($row['cr'] ?? 0);

        if ($filterDrMin !== null && $dr < $filterDrMin) {
            return false;
        }
        if ($filterDrMax !== null && $dr > $filterDrMax) {
            return false;
        }
        if ($filterCrMin !== null && $cr < $filterCrMin) {
            return false;
        }
        if ($filterCrMax !== null && $cr > $filterCrMax) {
            return false;
        }

        return true;
    }, ARRAY_FILTER_USE_BOTH));
}

$drTotal = 0.0;
$crTotal = 0.0;
foreach ($rows as $row) {
    $drTotal += (float) $row['dr'];
    $crTotal += (float) $row['cr'];
}

$page_title = 'Trial Balance Preview & Mapping';
$showSidebar = true;
require_once __DIR__ . '/../layouts/header_v2.php';
?>

<style>
:root {
    --font-sans: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    --bg: #f1f5f9; --panel: #fff; --border: #e2e8f0;
    --text: #0f172a; --muted: #64748b; --brand: #0f4c81;
    --success: #16a34a; --warning: #d97706; --danger: #dc2626;
    --radius: 10px; --shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* ---- STATS ROW ---- */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    box-shadow: var(--shadow);
}
.stat-card .num { font-size: 1.6rem; font-weight: 700; color: var(--brand); }
.stat-card .lbl { font-size: 0.8rem; color: var(--muted); margin-top: 2px; }

/* ---- METHOD CARDS ---- */
.method-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin-bottom: 18px;
}
.method-card {
    background: var(--panel);
    border: 2px solid var(--border);
    border-radius: 14px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    text-decoration: none;
    display: block;
    color: inherit;
}
.method-card:hover {
    border-color: var(--brand);
    box-shadow: 0 4px 12px rgba(15,76,129,0.12);
    transform: translateY(-2px);
}
.method-card .icon { font-size: 1.8rem; margin-bottom: 10px; }
.method-card h4 { font-size: 0.95rem; margin: 0 0 4px; }
.method-card p { font-size: 0.78rem; color: var(--muted); line-height: 1.5; margin: 0; }
.method-card .tag {
    position: absolute; top: 10px; right: 10px;
    font-size: 0.65rem; padding: 2px 8px; border-radius: 999px; font-weight: 600;
}
.method-card .tag.recommended { background: #dcfce7; color: var(--success); }
.method-card .tag.popular { background: #eff6ff; color: #2563eb; }

/* ---- STEPPER ---- */
.stepper {
    display: flex;
    align-items: center;
    margin-bottom: 24px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px 20px;
    box-shadow: var(--shadow);
}
.step { display: flex; align-items: center; gap: 8px; }
.step-circle {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.78rem; font-weight: 700;
}
.step-circle.done { background: var(--success); color: #fff; }
.step-circle.active { background: var(--brand); color: #fff; box-shadow: 0 0 0 3px rgba(15,76,129,0.2); }
.step-circle.pending { background: var(--bg); color: var(--muted); border: 2px solid var(--border); }
.step-label { font-size: 0.82rem; }
.step-label.muted { color: var(--muted); }
.step-line { width: 36px; height: 2px; background: var(--border); margin: 0 6px; }
.step-line.done { background: var(--success); }

/* ---- MAPPING SECTION ---- */
.mapping-section-title {
    font-size: 1.1rem;
    font-weight: 700;
    margin: 24px 0 14px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--brand);
    display: flex;
    align-items: center;
    gap: 8px;
}
.tb-preview-form {
    max-width: none;
    background: transparent;
    border: 0;
    box-shadow: none;
    padding: 0;
}
.tb-preview-filter-card {
    padding: 18px;
}
.tb-preview-filter-grid {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 10px;
    align-items: start;
}
.tb-preview-filter-grid input,
.tb-preview-filter-grid select {
    width: 100%;
    padding: 8px;
    min-width: 0;
}
.tb-preview-table-wrap {
    width: 100%;
    overflow-x: auto;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow);
}
.tb-preview-table {
    width: 100%;
    min-width: 1080px;
    border-collapse: collapse;
    background: #fff;
}
.tb-preview-table th,
.tb-preview-table td {
    vertical-align: top;
}
.tb-preview-note-select {
    min-width: 260px;
    max-width: 100%;
}
</style>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'data_console/tally_console.php'],
    ['label' => 'Trial Balance Preview'],
]) ?>

<?= uiPageHero('TB Import Dashboard', 'Review and map trial balance entries to financial statement notes.') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy' => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?= uiWorkspaceStart() ?>

<?php
$totalLedgers = count($rows);
$mappedLedgers = count(array_filter($rows, fn($r) => $r['schedule_code'] !== ''));
$unmappedLedgers = $totalLedgers - $mappedLedgers;
$conflictLedgers = count($inlineConflicts);
$mappingPct = $totalLedgers > 0 ? round(($mappedLedgers / $totalLedgers) * 100) : 0;
?>

<div class="stats-row">
    <div class="stat-card">
        <div class="num"><?= $totalLedgers ?></div>
        <div class="lbl">Ledgers Synced</div>
    </div>
    <div class="stat-card">
        <div class="num"><?= $mappedLedgers ?></div>
        <div class="lbl">Ledgers Mapped</div>
    </div>
    <div class="stat-card">
        <div class="num"><?= $mappingPct ?>%</div>
        <div class="lbl">Mapping Complete</div>
    </div>
    <div class="stat-card">
        <div class="num"><?= $conflictLedgers ?></div>
        <div class="lbl" style="color:var(--danger);">Conflicts</div>
    </div>
</div>

<div class="stepper">
    <div class="step">
        <span class="step-circle done">&#10003;</span>
        <span class="step-label">Import</span>
    </div>
    <div class="step-line done"></div>
    <div class="step">
        <span class="step-circle done">&#10003;</span>
        <span class="step-label">Validate</span>
    </div>
    <div class="step-line done"></div>
    <div class="step">
        <span class="step-circle active">3</span>
        <span class="step-label">Map Notes</span>
    </div>
    <div class="step-line"></div>
    <div class="step">
        <span class="step-circle pending">4</span>
        <span class="step-label muted">Complete</span>
    </div>
</div>

<h2 style="font-size:1rem; margin-bottom:12px;">Import Method</h2>
<div class="method-grid">
    <a class="method-card" href="<?= BASE_URL ?>data_console/tally_online.php">
        <span class="tag recommended">Recommended</span>
        <div class="icon">&#128279;</div>
        <h4>Tally Online Sync</h4>
        <p>Connect directly to Tally via ODBC. Real-time ledger and TB sync.</p>
    </a>
    <a class="method-card" href="<?= BASE_URL ?>data_console/xml_import.php">
        <span class="tag popular">Popular</span>
        <div class="icon">&#128196;</div>
        <h4>XML Upload</h4>
        <p>Upload Tally-exported XML files for ledgers and trial balance.</p>
    </a>
    <a class="method-card" href="<?= BASE_URL ?>data_console/tally_offline.php">
        <div class="icon">&#128203;</div>
        <h4>CSV / Manual</h4>
        <p>Import from CSV/Excel or enter data manually. Best for offline data.</p>
    </a>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>

<?php if (!empty($_SESSION['tb_preview_parent_group_conflicts']) || !empty($inlineConflicts)): ?>
    <?php $conflictList = $_SESSION['tb_preview_parent_group_conflicts'] ?? $inlineConflicts; ?>
    <div class="error-box" style="margin-bottom:16px;">
        <p>Parent group validation conflict detected. A ledger under Assets, Liabilities, Income, or Expenses cannot be saved into a note that belongs to a different accounting nature.</p>
        <ul style="margin:8px 0 0 18px;">
            <?php foreach (array_slice($conflictList, 0, 8) as $conflict): ?>
                <li><?= htmlspecialchars($conflict['ledger_name'] . ' [' . ($conflict['parent_group'] !== '' ? $conflict['parent_group'] : 'No Parent Group') . '] -> ' . $conflict['schedule_code']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="mapping-section-title">
    <span>Note Mapping</span>
</div>

<?php if (!empty($noteGroups)): ?>
    <div class="card" style="margin-bottom:16px;">
        <strong>View By Note</strong>
        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start;">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a class="btn-outline btn-sm <?= $selectedNote === '' ? 'btn' : '' ?>" href="<?= BASE_URL ?>data_console/trial_balance_preview.php">All Notes</a>
                <?php foreach ($noteGroups as $noteKey => $noteGroup): ?>
                    <a class="btn-outline btn-sm <?= $selectedNote === $noteKey ? 'btn' : '' ?>" href="<?= BASE_URL ?>data_console/trial_balance_preview.php?note=<?= urlencode($noteKey) ?>">
                        <?= htmlspecialchars($noteGroup['label']) ?> (<?= (int) $noteGroup['count'] ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<form method="get" action="" class="tb-preview-form card tb-preview-filter-card" style="margin-bottom:16px;">
    <strong>Quick Filters</strong>
    <div class="tb-preview-filter-grid">
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Sl No</div>
            <input type="hidden" name="note" value="<?= htmlspecialchars($selectedNote) ?>">
            <input type="number" name="sl_from" value="<?= $filterSlFrom > 0 ? $filterSlFrom : '' ?>" placeholder="From" style="margin-bottom:6px;">
            <input type="number" name="sl_to" value="<?= $filterSlTo > 0 ? $filterSlTo : '' ?>" placeholder="To">
        </div>
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Ledger Name</div>
            <input type="text" name="filter_ledger" value="<?= htmlspecialchars($filterLedger) ?>" placeholder="Filter ledger">
        </div>
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Tally Group</div>
            <input type="text" name="filter_group" value="<?= htmlspecialchars($filterGroup) ?>" placeholder="Filter group">
        </div>
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Note No with Heading</div>
            <input type="text" name="filter_note" value="<?= htmlspecialchars($filterNote) ?>" placeholder="Filter note">
        </div>
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Validation</div>
            <select name="filter_validation">
                <option value="">All</option>
                <option value="ok" <?= $filterValidation === 'ok' ? 'selected' : '' ?>>OK</option>
                <option value="conflict" <?= $filterValidation === 'conflict' ? 'selected' : '' ?>>Conflict</option>
            </select>
        </div>
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Dr</div>
            <input type="number" step="0.01" name="dr_min" value="<?= $filterDrMin !== null ? htmlspecialchars((string) $filterDrMin) : '' ?>" placeholder="Min" style="margin-bottom:6px;">
            <input type="number" step="0.01" name="dr_max" value="<?= $filterDrMax !== null ? htmlspecialchars((string) $filterDrMax) : '' ?>" placeholder="Max">
        </div>
        <div>
            <div style="font-size:12px; color:#667085; margin-bottom:6px;">Cr</div>
            <input type="number" step="0.01" name="cr_min" value="<?= $filterCrMin !== null ? htmlspecialchars((string) $filterCrMin) : '' ?>" placeholder="Min" style="margin-bottom:6px;">
            <input type="number" step="0.01" name="cr_max" value="<?= $filterCrMax !== null ? htmlspecialchars((string) $filterCrMax) : '' ?>" placeholder="Max">
        </div>
    </div>
    <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
        <button type="submit" class="btn">Apply Filters</button>
        <a class="btn-outline btn-sm" href="<?= BASE_URL ?>data_console/trial_balance_preview.php<?= $selectedNote !== '' ? '?note=' . urlencode($selectedNote) : '' ?>">Clear Filters</a>
    </div>
</form>

<form method="post" action="" class="tb-preview-form">
    <?= csrfInput() ?>
    <input type="hidden" name="selected_note" value="<?= htmlspecialchars($selectedNote) ?>">

    <div class="tb-preview-table-wrap">
    <table border="1" cellpadding="6" cellspacing="0" class="tb-preview-table">
        <tr>
            <th>Sl No</th>
            <th>Ledger Name</th>
            <th>Tally Group</th>
            <th>Note No with Heading</th>
            <th>Validation</th>
            <th style="text-align:right; white-space:nowrap;">Dr</th>
            <th style="text-align:right; white-space:nowrap;">Cr</th>
        </tr>

        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="7" style="text-align:center;">No trial balance rows found for the selected company and financial year.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $index => $row): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($row['ledger_name']) ?></td>
                    <td><?= htmlspecialchars($row['parent_group'] !== '' ? $row['parent_group'] : '-') ?></td>
                    <td>
                        <div style="margin-bottom:8px;">
                            <strong><?= htmlspecialchars($row['note_display']) ?></strong>
                        </div>
                        <select name="mapping[<?= htmlspecialchars($row['ledger_name']) ?>]" class="tb-preview-note-select">
                            <option value="">Select Note</option>
                            <?php foreach ($previewMappingOptions as $code => $label): ?>
                                <?php
                                $previewMeta = $schedule3NoteMap[$code] ?? ['number' => '', 'title' => $label];
                                $optionText = $previewMeta['number'] !== ''
                                    ? 'Note ' . $previewMeta['number'] . ' - ' . $previewMeta['title']
                                    : $label;
                                ?>
                                <option value="<?= htmlspecialchars($code) ?>" <?= $row['schedule_code'] === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($optionText) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php if ($row['parent_group_conflict']): ?>
                            <span style="color:#b42318; font-weight:700;">Conflict</span>
                        <?php else: ?>
                            <span style="color:#157347; font-weight:700;">OK</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; white-space:nowrap;"><?= $row['dr'] != 0.0 ? format_inr($row['dr']) : '' ?></td>
                    <td style="text-align:right; white-space:nowrap;"><?= $row['cr'] != 0.0 ? format_inr($row['cr']) : '' ?></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td colspan="5" style="text-align:right;"><strong>Total</strong></td>
                <td style="text-align:right; white-space:nowrap;"><strong><?= format_inr($drTotal) ?></strong></td>
                <td style="text-align:right; white-space:nowrap;"><strong><?= format_inr($crTotal) ?></strong></td>
            </tr>
        <?php endif; ?>
    </table>
    </div>

    <div style="margin-top:18px; display:flex; gap:12px; flex-wrap:wrap;">
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="allow_override" value="1">
            Allow parent group override
        </label>
        <button type="submit" class="btn">Save Note Changes</button>
        <a class="btn" href="<?= BASE_URL ?>dashboard_report.php">Go to Reports</a>
        <a class="btn-outline btn-sm" href="<?= BASE_URL ?>data_console/process_result.php">Back to Summary</a>
    </div>
</form>

<script>
(function() {
    /* Track original values and only submit changed selects to avoid large POST / 406 */
    var selects = document.querySelectorAll('.tb-preview-note-select');
    var originalValues = {};
    for (var i = 0; i < selects.length; i++) {
        originalValues[selects[i].name] = selects[i].value;
    }
    var form = document.querySelector('form[method="post"].tb-preview-form');
    if (form) {
        form.addEventListener('submit', function() {
            for (var i = 0; i < selects.length; i++) {
                var s = selects[i];
                if (originalValues[s.name] === s.value) {
                    s.disabled = true;
                }
            }
        });
    }
})();
</script>

<?= uiWorkspaceEnd() ?>

<?php
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['tb_preview_parent_group_conflicts']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
