<?php
require_once '../../app/context_check.php';
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/schedule3_master_helper.php';
require_once '../../app/helpers/figure_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/opening_balance_diagnostics_helper.php';

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

try {
    $openingBalanceDiagnostics = computeOpeningBalanceDiagnostics($pdo, $company_id, $fy_id);
} catch (Throwable $e) {
    $openingBalanceDiagnostics = ['mismatches' => [], 'first_year_gaps' => [], 'compared' => 0, 'has_previous_fy' => false];
}
$openingBalanceIssueCount = count($openingBalanceDiagnostics['mismatches']) + count($openingBalanceDiagnostics['first_year_gaps']);

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

$cardFilter = strtolower(trim((string) ($_GET['filter'] ?? '')));
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

/* Apply card filter first */
if ($cardFilter !== '' && in_array($cardFilter, ['all', 'mapped', 'unmapped', 'conflicts', 'debit', 'credit'], true)) {
    $currentPage = 1; /* Reset to page 1 when card filter is clicked */
    $rows = array_values(array_filter($rows, static function (array $row) use ($cardFilter): bool {
        switch ($cardFilter) {
            case 'mapped': return $row['schedule_code'] !== '';
            case 'unmapped': return $row['schedule_code'] === '';
            case 'conflicts': return !empty($row['parent_group_conflict']);
            case 'debit': return (float) ($row['dr'] ?? 0) > 0;
            case 'credit': return (float) ($row['cr'] ?? 0) > 0;
            default: return true;
        }
    }));
}

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

<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/bs_diagnostics_panel.css?v=<?= filemtime(__DIR__ . '/../asset/css/bs_diagnostics_panel.css') ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>asset/css/opening_balance_diagnostics_panel.css?v=<?= filemtime(__DIR__ . '/../asset/css/opening_balance_diagnostics_panel.css') ?>">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<script>
    window.BS_DIAG_BASE_URL = <?= json_encode(BASE_URL) ?>;
    window.BS_DIAG_ENTITY_ID = <?= (int) $company_id ?>;
    window.BS_DIAG_FY_ID = <?= (int) $fy_id ?>;
    window.OB_DIAG_BASE_URL = <?= json_encode(BASE_URL) ?>;
    window.OB_DIAG_ENTITY_ID = <?= (int) $company_id ?>;
    window.OB_DIAG_FY_ID = <?= (int) $fy_id ?>;
</script>
<script src="<?= BASE_URL ?>asset/js/bs_diagnostics_panel.js?v=<?= filemtime(__DIR__ . '/../asset/js/bs_diagnostics_panel.js') ?>"></script>
<script src="<?= BASE_URL ?>asset/js/opening_balance_diagnostics_panel.js?v=<?= filemtime(__DIR__ . '/../asset/js/opening_balance_diagnostics_panel.js') ?>"></script>

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

<?php
/* Compute mapping health */
$mappingHealthPct = $mappingPct;
$reviewStatus = 'ready';
$reviewLabel = 'Ready — No mapping issues found';
if ($conflictLedgers > 0) {
    $reviewStatus = 'critical';
    $reviewLabel = 'Critical — ' . $conflictLedgers . ' Conflict' . ($conflictLedgers !== 1 ? 's' : '') . ' Found';
} elseif ($unmappedLedgers > 0) {
    $reviewStatus = 'needs_review';
    $reviewLabel = 'Needs Review — ' . $unmappedLedgers . ' Unmapped Ledger' . ($unmappedLedgers !== 1 ? 's' : '');
}
$reviewColor = $reviewStatus === 'ready' ? 'var(--success)' : ($reviewStatus === 'needs_review' ? 'var(--warning)' : 'var(--danger)');

$cardBase = BASE_URL . 'data_console/trial_balance_preview.php?company_id=' . (int)$company_id . '&fy_id=' . (int)$fy_id;
$perPage = max(25, min(100, (int) ($_GET['per_page'] ?? 50)));
$cardParams = ['company_id' => (int)$company_id, 'fy_id' => (int)$fy_id, 'per_page' => $perPage];
?>

<div class="stats-row" style="grid-template-columns: repeat(7, 1fr);">
    <a href="<?= $cardBase ?>&filter=all" class="stat-card" style="text-decoration:none;color:inherit;<?= $cardFilter === 'all' ? 'border:2px solid var(--brand);box-shadow:0 0 0 3px rgba(15,76,129,0.15);' : '' ?>" title="Show all ledgers">
        <div class="num"><?= $totalLedgers ?></div>
        <div class="lbl">Total Ledgers</div>
    </a>
    <a href="<?= $cardBase ?>&filter=mapped" class="stat-card" style="text-decoration:none;color:inherit;<?= $cardFilter === 'mapped' ? 'border:2px solid var(--success);box-shadow:0 0 0 3px rgba(22,163,74,0.15);' : '' ?>" title="Show mapped ledgers">
        <div class="num" style="color:var(--success);"><?= $mappedLedgers ?></div>
        <div class="lbl">Mapped</div>
    </a>
    <a href="<?= $cardBase ?>&filter=unmapped" class="stat-card" style="text-decoration:none;color:inherit;<?= $cardFilter === 'unmapped' ? 'border:2px solid var(--warning);box-shadow:0 0 0 3px rgba(217,119,6,0.15);' : '' ?>" title="Show unmapped ledgers">
        <div class="num" style="color:var(--warning);"><?= $unmappedLedgers ?></div>
        <div class="lbl">Unmapped</div>
    </a>
    <a href="<?= $cardBase ?>&filter=conflicts" class="stat-card" style="text-decoration:none;color:inherit;<?= $cardFilter === 'conflicts' ? 'border:2px solid var(--danger);box-shadow:0 0 0 3px rgba(220,38,38,0.15);' : '' ?>" title="Show conflicting ledgers">
        <div class="num" style="color:var(--danger);"><?= $conflictLedgers ?></div>
        <div class="lbl">Conflicts</div>
    </a>
    <a href="<?= $cardBase ?>&filter=debit" class="stat-card" style="text-decoration:none;color:inherit;<?= $cardFilter === 'debit' ? 'border:2px solid var(--brand);box-shadow:0 0 0 3px rgba(15,76,129,0.15);' : '' ?>" title="Show debit balance ledgers">
        <div class="num"><?= format_inr($drTotal) ?></div>
        <div class="lbl">Debit Total</div>
    </a>
    <a href="<?= $cardBase ?>&filter=credit" class="stat-card" style="text-decoration:none;color:inherit;<?= $cardFilter === 'credit' ? 'border:2px solid var(--brand);box-shadow:0 0 0 3px rgba(15,76,129,0.15);' : '' ?>" title="Show credit balance ledgers">
        <div class="num"><?= format_inr($crTotal) ?></div>
        <div class="lbl">Credit Total</div>
    </a>
    <div class="stat-card">
        <div class="num" style="color:var(--success);"><?= $mappingHealthPct ?>%</div>
        <div class="lbl">Mapping Completion</div>
    </div>
</div>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:10px 16px;background:<?= $reviewStatus === 'ready' ? '#dcfce7' : ($reviewStatus === 'needs_review' ? '#fef3c7' : '#fee2e2') ?>;border-radius:8px;font-size:0.85rem;">
    <strong style="color:<?= $reviewColor ?>;">Review Status: <?= htmlspecialchars($reviewLabel) ?></strong>
    <?php if ($conflictLedgers > 0): ?>
        <button type="button" onclick="openBsDiagnosticsPanel()" style="color:var(--danger);font-weight:600;text-decoration:underline;font-size:0.82rem;background:none;border:none;cursor:pointer;padding:0;">View Full Diagnostics</button>
    <?php endif; ?>
    <?php if ($cardFilter !== '' && $cardFilter !== 'all'): ?>
        <span style="color:#475569;">&middot; Active Filter: <?= htmlspecialchars(ucfirst($cardFilter)) ?></span>
        <a href="<?= $cardBase ?>&filter=all" style="color:var(--brand);font-weight:600;text-decoration:none;font-size:0.82rem;">Clear Filter</a>
    <?php endif; ?>
</div>

<?php if ($openingBalanceDiagnostics['compared'] > 0 || $openingBalanceIssueCount > 0): ?>
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:10px 16px;background:<?= $openingBalanceIssueCount > 0 ? '#fef3c7' : '#dcfce7' ?>;border-radius:8px;font-size:0.85rem;">
    <?php if ($openingBalanceIssueCount > 0): ?>
        <strong style="color:var(--warning);">Opening Balances: <?= $openingBalanceIssueCount ?> ledger(s) need review</strong>
        <button type="button" onclick="openOpeningBalanceDiagnosticsPanel()" style="color:var(--warning);font-weight:600;text-decoration:underline;font-size:0.82rem;background:none;border:none;cursor:pointer;padding:0;">View Opening Balance Diagnostics</button>
    <?php else: ?>
        <strong style="color:var(--success);">Opening Balances: <?= $openingBalanceDiagnostics['compared'] ?> ledger(s) verified against Tally, no differences</strong>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="stepper">
    <a href="<?= BASE_URL ?>data/index.php?entity_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" class="step" style="text-decoration:none;color:inherit;">
        <span class="step-circle done">&#10003;</span>
        <span class="step-label">Import</span>
    </a>
    <div class="step-line done"></div>
    <a href="<?= BASE_URL ?>data_console/trial_balance_preview.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>#validation" class="step" style="text-decoration:none;color:inherit;">
        <span class="step-circle done">&#10003;</span>
        <span class="step-label">Validate</span>
    </a>
    <div class="step-line done"></div>
    <a href="<?= BASE_URL ?>data_console/trial_balance_preview.php?company_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>#mapping" class="step" style="text-decoration:none;color:inherit;">
        <span class="step-circle active">3</span>
        <span class="step-label">Map Notes</span>
    </a>
    <div class="step-line"></div>
    <a href="<?= BASE_URL ?>statements/financials.php?entity_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" class="step" style="text-decoration:none;color:<?= ($reviewStatus ?? 'ready') === 'ready' ? 'inherit' : 'var(--muted)' ?>;">
        <span class="step-circle pending">4</span>
        <span class="step-label muted">Complete</span>
    </a>
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
        <button type="button" onclick="openBsDiagnosticsPanel()" style="margin-top:10px;font-size:0.78rem;padding:5px 12px;border:1px solid #dc2626;border-radius:6px;background:#fff;color:#dc2626;cursor:pointer;font-weight:600;">View Full Diagnostics</button>
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
    <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <button type="submit" class="btn">Apply Filters</button>
        <a class="btn-outline btn-sm" href="<?= BASE_URL ?>data_console/trial_balance_preview.php<?= $selectedNote !== '' ? '?note=' . urlencode($selectedNote) : '' ?>">Clear Filters</a>
        <?php if ($filterValidation === 'conflict'): ?>
            <button type="button" onclick="openBsDiagnosticsPanel()" style="color:var(--danger);font-weight:600;text-decoration:underline;font-size:0.82rem;background:none;border:none;cursor:pointer;padding:0;">Open full diagnostics</button>
        <?php endif; ?>
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

        <?php
        /* Pagination */
        $perPage = max(25, min(100, (int) ($_GET['per_page'] ?? 50)));
        $currentPage = max(1, (int) ($_GET['page'] ?? 1));
        $totalRows = count($rows);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $pageRows = array_slice($rows, $offset, $perPage);
        ?>

        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="7" style="text-align:center;"><?= $cardFilter !== '' && $cardFilter !== 'all' ? 'No ledgers found for this filter.' : 'No trial balance rows found for the selected company and financial year.' ?></td>
            </tr>
        <?php else: ?>
            <?php foreach ($pageRows as $index => $row): ?>
                <?php $globalIndex = $offset + $index + 1; ?>
                <tr>
                    <td><?= $globalIndex ?></td>
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
                            <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;font-size:0.72rem;font-weight:600;">Conflict</span>
                        <?php elseif ($row['schedule_code'] === ''): ?>
                            <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-size:0.72rem;font-weight:600;">Unmapped</span>
                        <?php else: ?>
                            <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:99px;font-size:0.72rem;font-weight:600;">OK</span>
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

    <?php if ($totalRows > $perPage): ?>
    <div style="margin-top:16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div style="font-size:0.82rem; color:var(--muted);">
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?> ledgers
        </div>
        <div style="display:flex; gap:6px; align-items:center;">
            <label style="font-size:0.8rem; color:var(--muted);">Per page:</label>
            <select id="perPageSelect" onchange="var u=new URL(window.location);u.searchParams.set('per_page',this.value);u.searchParams.set('1','1');window.location=u;" style="padding:4px 8px; font-size:0.8rem;">
                <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
            </select>
            <?php if ($currentPage > 1): ?>
                <a class="btn-outline btn-sm" href="<?= BASE_URL ?>data_console/trial_balance_preview.php?<?= http_build_query(array_merge($_GET, ['page' => $currentPage - 1, 'per_page' => $perPage])) ?>" style="padding:4px 10px;">&#8592; Prev</a>
            <?php endif; ?>
            <span style="font-size:0.82rem; color:var(--muted);"><?= $currentPage ?> / <?= $totalPages ?></span>
            <?php if ($currentPage < $totalPages): ?>
                <a class="btn-outline btn-sm" href="<?= BASE_URL ?>data_console/trial_balance_preview.php?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1, 'per_page' => $perPage])) ?>" style="padding:4px 10px;">Next &#8594;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:18px; display:flex; gap:12px; flex-wrap:wrap;">
        <label style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" name="allow_override" value="1">
            Allow parent group override
        </label>
        <button type="submit" class="btn">Save Note Changes</button>
        <a class="btn" href="<?= BASE_URL ?>statements/financials.php?entity_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>">Go to Financial Statements</a>
        <a class="btn-outline btn-sm" href="<?= BASE_URL ?>data/index.php?entity_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>">Back to Data Console</a>
    </div>
</form>

<!-- Sticky Save Bar -->
<div id="stickySaveBar" style="display:none;position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:2px solid var(--brand);padding:10px 24px;z-index:999;box-shadow:0 -4px 12px rgba(0,0,0,0.1);">
    <div style="display:flex;align-items:center;justify-content:space-between;max-width:1200px;margin:0 auto;">
        <div style="display:flex;align-items:center;gap:12px;">
            <span id="pendingCount" style="font-size:0.85rem;font-weight:600;color:var(--warning);">0 changes pending</span>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" onclick="document.querySelector('form[method=\"post\"].tb-preview-form').submit();" class="btn" style="font-size:0.82rem;padding:6px 16px;">Save Note Changes</button>
            <button type="button" onclick="window.location.reload();" class="btn-outline btn-sm" style="font-size:0.82rem;padding:6px 12px;">Revalidate</button>
            <a href="<?= BASE_URL ?>statements/financials.php?entity_id=<?= (int)$company_id ?>&fy_id=<?= (int)$fy_id ?>" class="btn-outline btn-sm" style="font-size:0.82rem;padding:6px 12px;text-decoration:none;">Financial Statements</a>
        </div>
    </div>
</div>

<script>
(function() {
    var selects = document.querySelectorAll('.tb-preview-note-select');
    var originalValues = {};
    var pendingCount = 0;
    var pendingEl = document.getElementById('pendingCount');
    var stickyBar = document.getElementById('stickySaveBar');

    function updatePendingCount() {
        pendingCount = 0;
        for (var i = 0; i < selects.length; i++) {
            if (originalValues[selects[i].name] !== selects[i].value) {
                pendingCount++;
            }
        }
        if (pendingEl) pendingEl.textContent = pendingCount + ' change' + (pendingCount !== 1 ? 's' : '') + ' pending';
        if (stickyBar) stickyBar.style.display = pendingCount > 0 ? 'block' : 'none';
    }

    for (var i = 0; i < selects.length; i++) {
        originalValues[selects[i].name] = selects[i].value;
        selects[i].addEventListener('change', updatePendingCount);
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

    /* Warn before leaving with unsaved changes */
    window.addEventListener('beforeunload', function(e) {
        if (pendingCount > 0) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Please save before leaving.';
        }
    });
})();
</script>

<?= uiWorkspaceEnd() ?>

<?php
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['tb_preview_parent_group_conflicts']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
