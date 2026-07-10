<?php
/**
 * e-BAL — Ledger Mapping Workbench
 *
 * Excel-like workspace for bulk ledger mapping.
 * Handles missing voucher_entries table gracefully.
 * Shows user-friendly error on failure instead of 500.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    error_log('Mapping Workbench FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<h1>ReconHub Error</h1>';
    echo '<p>The mapping workbench encountered an error. Please try again or contact support.</p>';
    echo '<p><small>' . htmlspecialchars($e->getMessage()) . '</small></p>';
    echo '<p><a href="' . (defined('BASE_URL') ? BASE_URL : '/') . '">Return to Dashboard</a></p>';
    echo '</body></html>';
    exit;
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    if (error_reporting() & $errno) {
        error_log("Mapping Workbench ERROR [$errno]: $errstr in $errfile:$errline");
    }
    return false;
});

require_once '../../app/context_check.php';
require_once '../../app/workflow_engine.php';
require_once '../../config/database.php';
require_once '../../app/engines/ai_mapping_engine.php';
require_once '../../app/helpers/mapping_ai_helper.php';
require_once '../../app/helpers/parent_group_validation_helper.php';
require_once '../../app/helpers/hierarchy_ai_mapping.php';
require_once '../../app/helpers/bulk_mapping_helper.php';

/* ---- Safe context resolution (GET → session → fallback) ---- */
$rawCompanyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : 0;
$rawEntityId  = isset($_GET['entity_id']) ? (int) $_GET['entity_id'] : 0;
$rawFyId      = isset($_GET['fy_id']) ? (int) $_GET['fy_id'] : 0;

$effectiveCompanyId = $rawCompanyId > 0 ? $rawCompanyId : ($rawEntityId > 0 ? $rawEntityId : 0);

if ($effectiveCompanyId > 0) {
    $_SESSION['company_id'] = $effectiveCompanyId;
}
if ($rawFyId > 0) {
    $_SESSION['fy_id'] = $rawFyId;
}

$company_id = isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : 0;
$fy_id      = isset($_SESSION['fy_id']) ? (int) $_SESSION['fy_id'] : 0;
$userId     = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

if ($company_id <= 0 || $fy_id <= 0) {
    $page_title = "ReconHub \u2014 Select Context";
    $showSidebar = true;
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <div style="max-width:500px;margin:60px auto;text-align:center;padding:40px;background:var(--panel-strong);border:1px solid var(--border);border-radius:12px;">
        <h2 style="margin-bottom:12px;">ReconHub \u2014 Mapping Workbench</h2>
        <p style="font-size:0.95rem;color:var(--text);margin-bottom:20px;">Please select an entity and financial year to continue.</p>
        <a href="<?= BASE_URL ?>dashboard_company.php" class="btn btn-primary" style="padding:10px 24px;font-size:0.9rem;text-decoration:none;">Go to e-BAL Gateway</a>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

$fyValid = false;
try {
    $fyCheckStmt = $pdo->prepare("SELECT COUNT(*) FROM financial_years WHERE id = ? AND company_id = ?");
    $fyCheckStmt->execute([$fy_id, $company_id]);
    $fyValid = (int) $fyCheckStmt->fetchColumn() > 0;
} catch (Throwable $e) {
    error_log('Mapping Workbench: FY validation query failed: ' . $e->getMessage());
}

if (!$fyValid) {
    unset($_SESSION['fy_id'], $_SESSION['fy_name']);
    $page_title = "ReconHub \u2014 Invalid Financial Year";
    $showSidebar = true;
    require_once __DIR__ . '/../layouts/header_v2.php';
    ?>
    <div style="max-width:500px;margin:60px auto;text-align:center;padding:40px;background:var(--panel-strong);border:1px solid var(--border);border-radius:12px;">
        <h2 style="margin-bottom:12px;">Invalid Financial Year</h2>
        <p style="font-size:0.95rem;color:var(--text);margin-bottom:20px;">The selected financial year is not valid for this entity. Please select a valid entity and financial year.</p>
        <a href="<?= BASE_URL ?>dashboard_company.php" class="btn btn-primary" style="padding:10px 24px;font-size:0.9rem;text-decoration:none;">Go to e-BAL Gateway</a>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer_v2.php';
    exit;
}

try {
    if (empty($_SESSION['company_name'])) {
        $compStmt = $pdo->prepare("SELECT name FROM companies WHERE id = ?");
        $compStmt->execute([$company_id]);
        $_SESSION['company_name'] = $compStmt->fetchColumn() ?: 'Unknown';
    }
    if (empty($_SESSION['fy_name'])) {
        $fyStmt = $pdo->prepare("SELECT label FROM financial_years WHERE id = ?");
        $fyStmt->execute([$fy_id]);
        $_SESSION['fy_name'] = $fyStmt->fetchColumn() ?: 'Unknown';
    }
} catch (Throwable $e) {
    error_log('Mapping Workbench: Session label load failed: ' . $e->getMessage());
}

/* Mode detection: group (default) or ledger */
$mode = isset($_GET['mode']) && $_GET['mode'] === 'ledger' ? 'ledger' : 'group';
$isGroupMode = ($mode === 'group');
$isLedgerMode = ($mode === 'ledger');

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

/* ---- Determine TB-impact ledgers for default view ---- */
$tbImpactLedgerNames = [];
try {
    $tbImpactStmt = $pdo->prepare("SELECT DISTINCT ledger_name FROM tally_ledgers WHERE company_id = ? AND fy_id = ?");
    $tbImpactStmt->execute([$company_id, $fy_id]);
    $tbImpactLedgerNames = $tbImpactStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    /* If TB query fails, we'll load all ledgers */
}

$tbImpactCount = count($tbImpactLedgerNames);
$totalLedgerCount = 0;
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tally_ledger_master WHERE company_id = ?");
    $countStmt->execute([$company_id]);
    $totalLedgerCount = (int) $countStmt->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

/* Default to TB-impact view if TB has fewer ledgers */
$defaultViewTbImpact = ($tbImpactCount > 0 && $tbImpactCount < $totalLedgerCount);

/* ---- Load ledgers based on view mode ---- */
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

/* Load trial balance amounts - handle missing columns gracefully */
$tbData = [];
try {
    $tbColumns = $pdo->query("SHOW COLUMNS FROM tally_ledgers")->fetchAll(PDO::FETCH_COLUMN);
    $hasOpeningDebit = in_array('opening_debit', $tbColumns);
    $hasOpeningCredit = in_array('opening_credit', $tbColumns);
    $hasClosingDebit = in_array('closing_debit', $tbColumns);
    $hasClosingCredit = in_array('closing_credit', $tbColumns);

    if ($hasOpeningDebit && $hasOpeningCredit && $hasClosingDebit && $hasClosingCredit) {
        /* New DR/CR columns exist */
        $tbStmt = $pdo->prepare("
            SELECT ledger_name,
                   opening_debit, opening_credit,
                   closing_debit, closing_credit,
                   dr_cr,
                   parent_group
            FROM tally_ledgers
            WHERE company_id = ? AND fy_id = ?
        ");
        $tbStmt->execute([$company_id, $fy_id]);
        while ($row = $tbStmt->fetch(PDO::FETCH_ASSOC)) {
            $tbData[$row['ledger_name']] = $row;
        }
    } else {
        /* Fallback: old single amount column */
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
        while ($row = $tbStmt->fetch(PDO::FETCH_ASSOC)) {
            $tbData[$row['ledger_name']] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('Mapping Workbench: TB load failed: ' . $e->getMessage());
}

/* Compute debits/credits from voucher_entries (graceful fallback) */
$veData = [];
try {
    $veStmt = $pdo->prepare("
        SELECT ledger_name,
               SUM(CASE WHEN dr_cr = 'DR' THEN amount ELSE 0 END) AS total_dr,
               SUM(CASE WHEN dr_cr = 'CR' THEN amount ELSE 0 END) AS total_cr
        FROM voucher_entries
        WHERE company_id = ? AND fy_id = ?
        GROUP BY ledger_name
    ");
    $veStmt->execute([$company_id, $fy_id]);
    while ($row = $veStmt->fetch(PDO::FETCH_ASSOC)) {
        $veData[$row['ledger_name']] = $row;
    }
} catch (Throwable $e) {
    /* voucher_entries may not exist — use tally_ledgers as fallback */
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
$stats = ['total' => 0, 'mapped' => 0, 'unmapped' => 0, 'auto_suggested' => 0, 'high_confidence' => 0, 'manual_review' => 0, 'risk' => 0];
$processingError = null;

/* Performance timing */
$timeStart = microtime(true);
$timeQuery = 0;
$timeSuggestions = 0;

/* Filter to TB-impact ledgers by default for performance */
$processingLedgers = $allLedgers;
if ($defaultViewTbImpact && !empty($tbImpactLedgerNames)) {
    $tbImpactSet = array_flip($tbImpactLedgerNames);
    $processingLedgers = array_filter($allLedgers, function ($row) use ($tbImpactSet) {
        return isset($tbImpactSet[$row['ledger_name']]);
    });
    $processingLedgers = array_values($processingLedgers);
    $processingError = 'Showing TB-impact ledgers only (' . count($processingLedgers) . ' of ' . count($allLedgers) . '). Switch to "All Ledger Master" to view all.';
}

/* Safety: limit processing for very large datasets */
$maxLedgers = 20000;
if (count($processingLedgers) > $maxLedgers) {
    $processingError = 'Dataset too large (' . count($processingLedgers) . ' ledgers). Processing first ' . number_format($maxLedgers) . ' only.';
    $processingLedgers = array_slice($processingLedgers, 0, $maxLedgers);
}

/* Pagination: apply BEFORE heavy processing for performance */
$perPage = max(25, min(100, (int) ($_GET['per_page'] ?? 50)));
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalLedgerRows = count($processingLedgers);
$totalPages = max(1, (int) ceil($totalLedgerRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$gridOffset = ($currentPage - 1) * $perPage;
$currentPageLedgers = array_slice($processingLedgers, $gridOffset, $perPage);

$timeQuery = round((microtime(true) - $timeStart) * 1000);

try {
foreach ($currentPageLedgers as $row) {
    $name = $row['ledger_name'];
    $group = $row['parent_group'] ?? '';
    $mappedCode = $row['mapped_code'] ?? '';
    $isMapped = $mappedCode !== '';

    $tb = $tbData[$name] ?? null;
    $ve = $veData[$name] ?? null;

    /* Compute DR/CR amounts from tally_ledgers (preferred) or voucher_entries (fallback) */
    $openingDr = 0; $openingCr = 0;
    $closingDr = 0; $closingCr = 0;
    $drcr = '';

    if ($tb) {
        if (isset($tb['opening_debit'])) {
            /* New DR/CR columns */
            $openingDr = (float) $tb['opening_debit'];
            $openingCr = (float) $tb['opening_credit'];
            $closingDr = (float) $tb['closing_debit'];
            $closingCr = (float) $tb['closing_credit'];
        } else {
            /* Fallback: use old amount + dr_cr */
            $closingAmount = (float) ($tb['closing_amount'] ?? $tb['amount'] ?? 0);
            $openingAmount = (float) ($tb['opening_amount'] ?? 0);
            $drcr = $tb['dr_cr'] ?? '';
            if ($drcr === 'DR') {
                $closingDr = abs($closingAmount);
                $openingDr = abs($openingAmount);
            } else {
                $closingCr = abs($closingAmount);
                $openingCr = abs($openingAmount);
            }
        }
        $drcr = $tb['dr_cr'] ?? ($closingDr > $closingCr ? 'DR' : ($closingCr > $closingDr ? 'CR' : ''));
    }

    if ($ve) {
        $closingDr = max($closingDr, (float) $ve['total_dr']);
        $closingCr = max($closingCr, (float) $ve['total_cr']);
    }

    $netBalance = $closingDr - $closingCr;

    /* Get suggestion with safety fallback */
    $suggestion = ['schedule_code' => '', 'confidence' => 0, 'source' => 'none', 'reason' => 'Suggestion generation failed.'];
    try {
        $suggestion = suggestBulkMapping(
            $name, $group, $currentMappings,
            $previousFyMappings, $globalMaster, $keywordRules,
            $hierarchyEngine, $mappingEngine
        );
    } catch (\Throwable $e) {
        /* Fallback: no suggestion */
    }

    $scheduleLabel = $mappedCode !== '' ? $mappingEngine->getLabel($mappedCode) : '';
    $suggestedLabel = $suggestion['schedule_code'] !== '' ? $mappingEngine->getLabel($suggestion['schedule_code']) : '';

    $status = $isMapped ? 'Mapped' : 'Unmapped';

    /* ---- DR/CR Risk Detection ---- */
    $riskLevel = 'none';
    $riskReason = '';

    $scheduleCode = $suggestion['schedule_code'] ?? '';
    $assetSchedules = ['ppe', 'inventory', 'receivables', 'cash', 'bank_balances_other', 'other_current_assets', 'investments_non_current', 'loans_non_current', 'intangible_assets', 'cwip', 'deferred_tax_asset', 'other_non_current_assets', 'investments_current', 'loans_current'];
    $liabilitySchedules = ['trade_payables', 'lt_borrowings', 'st_borrowings', 'other_current_liabilities', 'short_term_provisions', 'share_capital', 'reserves', 'deferred_tax_liability', 'other_non_current_liabilities', 'long_term_provisions'];

    /* Critical: P&L A/c not mapped to reserves */
    $plVariants = ['profit & loss a/c', 'profit and loss a/c', 'profit & loss account', 'profit and loss account', 'p&l a/c', 'p and l a/c', 'surplus in statement of profit and loss'];
    if (in_array(strtolower($group), $plVariants) && $scheduleCode !== 'reserves') {
        $riskLevel = 'critical';
        $riskReason = 'Profit & Loss A/c should map to Reserves (Equity). Current mapping: ' . ($scheduleCode ?: 'Unmapped');
    }

    /* Critical: Bank OD / CC with credit balance mapped to cash */
    $bankOdVariants = ['bank od', 'od account', 'overdraft', 'cash credit', 'bank overdraft', 'current account'];
    if (in_array(strtolower($group), $bankOdVariants) && $closingCr > 0 && $scheduleCode === 'cash') {
        $riskLevel = 'critical';
        $riskReason = 'Bank OD/CC with credit balance should map to st_borrowings, not cash.';
    }

    /* Critical: Patient advance credit mapped to receivables */
    $patientAdvanceVariants = ['advance in patient', 'patient advance'];
    if (in_array(strtolower($group), $patientAdvanceVariants) && $closingCr > 0 && $scheduleCode === 'receivables') {
        $riskLevel = 'critical';
        $riskReason = 'Patient advance with credit balance is a liability, not a receivable.';
    }

    /* Critical: Insurance patient credit mapped to receivables */
    if (strtolower($group) === 'insurance patient' && $closingCr > 0 && $scheduleCode === 'receivables') {
        $riskLevel = 'critical';
        $riskReason = 'Insurance patient with credit balance is a liability, not a receivable.';
    }

    /* Warning: Credit balance in asset schedule */
    if ($riskLevel === 'none' && $closingCr > 0 && in_array($scheduleCode, $assetSchedules)) {
        $riskLevel = 'warning';
        $riskReason = 'Credit balance in asset schedule.';
    }

    /* Warning: Debit balance in liability/equity schedule */
    if ($riskLevel === 'none' && $closingDr > 0 && in_array($scheduleCode, $liabilitySchedules)) {
        $riskLevel = 'warning';
        $riskReason = 'Debit balance in liability/equity schedule.';
    }

    /* Review: Nil balance */
    if ($riskLevel === 'none' && $closingDr === 0 && $closingCr === 0 && !$isMapped) {
        $riskLevel = 'review';
        $riskReason = 'Nil balance, unclear mapping.';
    }

    $hasTb = isset($tbData[$name]) || isset($veData[$name]);

    $gridData[] = [
        'id' => $name,
        'ledger_name' => $name,
        'parent_group' => $group,
        'opening_dr' => $openingDr,
        'opening_cr' => $openingCr,
        'closing_dr' => $closingDr,
        'closing_cr' => $closingCr,
        'net_balance' => $netBalance,
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
        'risk_level' => $riskLevel,
        'risk_reason' => $riskReason,
        'has_tb' => $hasTb,
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
    if ($riskLevel === 'critical' || $riskLevel === 'warning') {
        $stats['risk']++;
    }
}
} catch (\Throwable $e) {
    $processingError = $e->getMessage();
    error_log('Mapping Workbench processing error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

$timeSuggestions = round((microtime(true) - $timeStart - ($timeQuery / 1000)) * 1000);

/* ---- Parent group counts via SQL aggregation (fast) ---- */
$parentGroupCounts = [];
$parentGroupData = [];
try {
    $pgStmt = $pdo->prepare("
        SELECT
            COALESCE(tlm.parent_group, t.parent_group) AS parent_group,
            COUNT(*) AS ledger_count,
            SUM(CASE WHEN tb.dr_cr = 'DR' THEN tb.amount ELSE 0 END) AS closing_dr,
            SUM(CASE WHEN tb.dr_cr = 'CR' THEN tb.amount ELSE 0 END) AS closing_cr
        FROM tally_ledger_master t
        LEFT JOIN tally_ledger_master tlm ON tlm.company_id = t.company_id AND tlm.ledger_name = t.parent_group
        LEFT JOIN tally_ledgers tb ON tb.company_id = t.company_id AND tb.ledger_name = t.ledger_name AND tb.fy_id = ?
        WHERE t.company_id = ?
        GROUP BY COALESCE(tlm.parent_group, t.parent_group)
        ORDER BY ledger_count DESC
    ");
    $pgStmt->execute([$fy_id, $company_id]);
    while ($pgRow = $pgStmt->fetch(PDO::FETCH_ASSOC)) {
        $pg = $pgRow['parent_group'] ?: '(none)';
        $parentGroupCounts[$pg] = (int) $pgRow['ledger_count'];
        $parentGroupData[$pg] = [
            'count' => (int) $pgRow['ledger_count'],
            'opening_dr' => 0,
            'opening_cr' => 0,
            'closing_dr' => (float) ($pgRow['closing_dr'] ?? 0),
            'closing_cr' => (float) ($pgRow['closing_cr'] ?? 0),
            'net_balance' => (float) (($pgRow['closing_dr'] ?? 0) - ($pgRow['closing_cr'] ?? 0)),
        ];
    }
} catch (Throwable $e) {
    /* Fallback: build from gridData if SQL fails */
    foreach ($gridData as $row) {
        $pg = $row['parent_group'] ?: '(none)';
        if (!isset($parentGroupCounts[$pg])) {
            $parentGroupCounts[$pg] = 0;
            $parentGroupData[$pg] = [
                'count' => 0,
                'opening_dr' => 0, 'opening_cr' => 0,
                'closing_dr' => 0, 'closing_cr' => 0,
                'net_balance' => 0,
            ];
        }
        $parentGroupCounts[$pg]++;
        $parentGroupData[$pg]['opening_dr'] += $row['opening_dr'];
        $parentGroupData[$pg]['opening_cr'] += $row['opening_cr'];
        $parentGroupData[$pg]['closing_dr'] += $row['closing_dr'];
        $parentGroupData[$pg]['closing_cr'] += $row['closing_cr'];
        $parentGroupData[$pg]['net_balance'] += $row['net_balance'];
    }
}
arsort($parentGroupCounts);
$topParentGroups = array_slice($parentGroupCounts, 0, 25, true);

/* Build Schedule III Group Mapping panel data */
$groupMappingData = [];
try {
foreach ($parentGroupCounts as $pg => $cnt) {
    $pgData = $parentGroupData[$pg];
    $netBal = $pgData['net_balance'];
    $drcr = $pgData['closing_dr'] > $pgData['closing_cr'] ? 'DR' : ($pgData['closing_cr'] > $pgData['closing_dr'] ? 'CR' : '');

    /* Get dominant existing mapping */
    $dominantMapping = '';
    $mappingCounts = [];
    foreach ($gridData as $row) {
        if ($row['parent_group'] === $pg && !empty($row['current_mapping'])) {
            $mc = $row['current_mapping'];
            $mappingCounts[$mc] = ($mappingCounts[$mc] ?? 0) + 1;
        }
    }
    if (!empty($mappingCounts)) {
        arsort($mappingCounts);
        $mappingKeys = array_keys($mappingCounts);
        $dominantMapping = reset($mappingKeys);
    }

    /* Get suggested mapping from parent group rules */
    $suggested = matchParentGroupRule($pg);
    $suggestedCode = $suggested ? $suggested['schedule_code'] : '';
    $confidence = $suggested ? $suggested['confidence'] : 0;

    /* Risk count: ledgers with credit balance in asset group or debit in liability group */
    $riskCount = 0;
    foreach ($gridData as $row) {
        if ($row['parent_group'] !== $pg) continue;
        if ($row['closing_cr'] > 0 && in_array($suggestedCode, ['ppe', 'inventory', 'receivables', 'cash', 'bank_balances_other', 'other_current_assets', 'investments_non_current', 'loans_non_current', 'intangible_assets', 'cwip', 'deferred_tax_asset', 'other_non_current_assets', 'investments_current', 'loans_current'])) {
            $riskCount++;
        }
        if ($row['closing_dr'] > 0 && in_array($suggestedCode, ['trade_payables', 'lt_borrowings', 'st_borrowings', 'other_current_liabilities', 'short_term_provisions', 'share_capital', 'reserves', 'deferred_tax_liability', 'other_non_current_liabilities', 'long_term_provisions'])) {
            $riskCount++;
        }
    }

    $groupMappingData[] = [
        'parent_group' => $pg,
        'ledger_count' => $cnt,
        'opening_dr' => $pgData['opening_dr'],
        'opening_cr' => $pgData['opening_cr'],
        'closing_dr' => $pgData['closing_dr'],
        'closing_cr' => $pgData['closing_cr'],
        'net_balance' => $pgData['net_balance'],
        'drcr' => $drcr,
        'dominant_mapping' => $dominantMapping,
        'suggested_code' => $suggestedCode,
        'confidence' => $confidence,
        'risk_count' => $riskCount,
    ];
}
} catch (\Throwable $e) {
    error_log('Mapping Workbench: Group mapping panel error: ' . $e->getMessage());
    $groupMappingData = [];
}

/* Mapping options as JSON for JS */
$mappingOptionsJson = [];
foreach ($mappingOptions as $code => $label) {
    $mappingOptionsJson[] = ['id' => $code, 'label' => $label . ' (' . $code . ')', 'code' => $code, 'fullLabel' => $label];
}

$timeTotal = round((microtime(true) - $timeStart) * 1000);
error_log("ReconHub timing: query={$timeQuery}ms, suggestions={$timeSuggestions}ms, total={$timeTotal}ms, processed=" . count($currentPageLedgers) . " of " . count($processingLedgers));

$pctComplete = $stats['total'] > 0 ? round(($stats['mapped'] / $stats['total']) * 100) : 0;

$totalGridRows = $totalLedgerRows;
$paginatedGridData = $gridData;

$page_title = $isLedgerMode ? "Ledger-wise Mapping" : "ReconHub";
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
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.wb-card {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    cursor: default;
    transition: border-color 0.15s, background 0.15s;
}
.wb-card:hover { border-color: var(--brand); }
.wb-card[data-filter] { cursor: pointer; }
.wb-card[data-filter]:hover { background: #e8f0fe; }
.wb-card.active-tile { background: var(--brand); border-color: var(--brand); }
.wb-card.active-tile .num { color: #fff !important; }
.wb-card.active-tile .lbl { color: rgba(255,255,255,0.85); }
.wb-card.active-tile[data-filter="unmapped"] .num { color: #fff !important; }
.wb-card.active-tile[data-filter="risky"] .num { color: #fff !important; }
.wb-card.active-tile[data-filter="critical"] .num { color: #fff !important; }
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
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
    background: var(--panel-strong);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
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
.wb-toolbar .search-mode {
    padding: 8px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.82rem;
    background: var(--panel-strong);
    color: var(--text);
    min-width: 150px;
}
.wb-toolbar .pg-filter {
    padding: 8px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.82rem;
    background: var(--panel-strong);
    color: var(--text);
    min-width: 180px;
}

.filter-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}
.filter-chip {
    padding: 5px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 999px;
    font-size: 0.75rem;
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
    padding: 8px 14px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-top: 8px;
    flex-wrap: wrap;
    box-shadow: var(--shadow-sm);
    border-bottom: 2px solid var(--brand);
}
.wb-actions .btn { min-height: 34px; padding: 0 14px; font-size: 0.8rem; }
.wb-actions .sep { width: 1px; height: 24px; background: var(--border); margin: 0 4px; }
.wb-actions .status-text { font-size: 0.8rem; color: var(--muted); margin-left: auto; }

.hot-container {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: visible;
    box-shadow: var(--shadow-sm);
    min-height: 400px;
    position: relative;
}

.recon-grid-wrap {
    width: 100%;
    max-width: 100%;
}

#hotSearch {
    display: inline-block;
    min-width: 140px;
}

.recon-context-strip {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 14px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 0.82rem;
    color: var(--text);
    flex-wrap: wrap;
}
.recon-context-strip .rcs-sep { color: var(--muted); }
.recon-context-strip .rcs-label { color: var(--muted); font-weight: 400; }
.recon-context-strip .rcs-value { font-weight: 600; }

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
    ['label' => 'ReconHub'],
]) ?>

<?= uiPageHero('ReconHub', 'Ledger mapping, Schedule III group tagging, risk review and reconciliation readiness workspace.') ?>

<?php if (!empty($pageWarning)): ?>
    <?= uiAlert($pageWarning, 'warning') ?>
<?php endif; ?>

<!-- Compact Context Strip -->
<div class="recon-context-strip">
    <?php if ($isLedgerMode): ?>
    <a href="<?= BASE_URL ?>data_console/mapping_workbench.php" class="btn btn-outline" style="padding:4px 12px;font-size:0.78rem;text-decoration:none;">&#8592; Back to ReconHub</a>
    <span class="rcs-sep">|</span>
    <?php endif; ?>
    <span class="rcs-label">Entity:</span> <span class="rcs-value"><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></span>
    <span class="rcs-sep">|</span>
    <span class="rcs-label">FY:</span> <span class="rcs-value"><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></span>
    <span class="rcs-sep">|</span>
    <span class="rcs-value" style="color:var(--info);"><?= $isLedgerMode ? 'Ledger-wise Mapping' : ($defaultViewTbImpact ? 'TB Impact View' : 'All Master View') ?></span>
    <span class="rcs-sep">|</span>
    <span class="rcs-value"><?= number_format($stats['total']) ?> ledgers</span>
    <?php if ($isGroupMode): ?>
    <span class="rcs-sep">|</span>
    <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?mode=ledger" class="btn btn-primary" style="padding:4px 12px;font-size:0.78rem;text-decoration:none;">Ledger-wise Mapping &#8594;</a>
    <?php endif; ?>
</div>

<?php if (!empty($_SESSION['success'])): ?>
    <div class="success-box"><p><?= htmlspecialchars($_SESSION['success']) ?></p></div>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="error-box"><p><?= htmlspecialchars($_SESSION['error']) ?></p></div>
<?php endif; ?>
<?php if (!empty($processingError)): ?>
    <div class="error-box"><p><strong>Processing Warning:</strong> <?= htmlspecialchars($processingError) ?>. Some data may be incomplete.</p></div>
<?php endif; ?>

<?= uiWorkspaceStart() ?>

<!-- Summary Cards -->
<div class="wb-summary" id="wbSummary">
    <div class="wb-card total active-tile" data-filter="all"><div class="num" id="statTotal"><?= $stats['total'] ?></div><div class="lbl">Showing</div></div>
    <div class="wb-card" style="border-color:var(--info);" data-filter="tb_impact"><div class="num" id="statTbImpact" style="color:var(--info);"><?= number_format($tbImpactCount) ?></div><div class="lbl">TB Impact</div></div>
    <div class="wb-card mapped" data-filter="mapped"><div class="num" id="statMapped"><?= $stats['mapped'] ?></div><div class="lbl">Mapped</div></div>
    <div class="wb-card unmapped" data-filter="unmapped"><div class="num" id="statUnmapped"><?= $stats['unmapped'] ?></div><div class="lbl">Unmapped</div></div>
    <div class="wb-card suggested" data-filter="suggested"><div class="num" id="statSuggested"><?= $stats['auto_suggested'] ?></div><div class="lbl">Auto-Suggested</div></div>
    <div class="wb-card high" data-filter="high_conf"><div class="num" id="statHigh"><?= $stats['high_confidence'] ?></div><div class="lbl">High Confidence</div></div>
    <div class="wb-card review" data-filter="manual_review"><div class="num" id="statReview"><?= $stats['manual_review'] ?></div><div class="lbl">Manual Review</div></div>
    <div class="wb-card unsaved" data-filter="unsaved"><div class="num" id="statUnsaved">0</div><div class="lbl">Unsaved Changes</div></div>
    <div class="wb-card" style="border-color:var(--danger);" data-filter="risky"><div class="num" id="statRisk" style="color:var(--danger);">0</div><div class="lbl">Risk Issues</div></div>
</div>

<!-- Toolbar: Search + Filter Chips -->
<div class="wb-toolbar">
    <select class="search-mode" id="searchMode" title="Search mode">
        <option value="all">Search All</option>
        <option value="ledger_name">Search Ledger Name</option>
        <option value="parent_group">Search Parent Group</option>
        <option value="schedule">Search Schedule</option>
        <option value="remarks">Search Remarks</option>
    </select>
    <select class="pg-filter" id="pgFilter" title="Filter by parent group">
        <option value="">All Parent Groups</option>
    </select>
    <div class="search-box">
        <span class="s-icon">&#128269;</span>
        <input type="text" id="hotSearch" placeholder="Search ledger, parent group, schedule, remarks&hellip;">
    </div>
    <div class="filter-chips" id="viewChips" style="margin-bottom:4px;">
        <span class="filter-chip active" data-view="tb_impact" title="Only ledgers with Trial Balance data (fastest load)">TB Impact (<?= number_format($tbImpactCount) ?>)</span>
        <span class="filter-chip" data-view="all" title="All ledgers in master (may be slow for large datasets)">All Master (<?= number_format($totalLedgerCount) ?>)</span>
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
        <span class="filter-chip" data-filter="risky">⚠️ Risky</span>
        <span class="filter-chip" data-filter="critical">🔴 Critical</span>
        <span class="filter-chip" data-filter="credit_in_asset">Credit in Asset</span>
        <span class="filter-chip" data-filter="debit_in_liability">Debit in Liability</span>
        <span class="filter-chip" data-filter="manual_review">Manual Review</span>
    </div>
</div>

<!-- Pagination Controls -->
<?php if ($totalGridRows > $perPage): ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin:12px 0;padding:10px 16px;background:var(--panel);border:1px solid var(--border);border-radius:8px;font-size:0.82rem;">
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--muted);">Showing <?= number_format($gridOffset + 1) ?>–<?= number_format(min($gridOffset + $perPage, $totalGridRows)) ?> of <?= number_format($totalGridRows) ?> ledgers</span>
        <label style="color:var(--muted);">Per page:</label>
        <select onchange="var u=new URL(window.location);u.searchParams.set('per_page',this.value);u.searchParams.set('page','1');window.location=u;" style="padding:2px 6px;font-size:0.8rem;">
            <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
        </select>
    </div>
    <div style="display:flex;gap:6px;align-items:center;">
        <?php if ($currentPage > 1): ?>
        <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?<?= http_build_query(array_merge($_GET, ['page' => $currentPage - 1, 'per_page' => $perPage])) ?>" style="padding:4px 10px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:var(--text);">&#8592; Prev</a>
        <?php endif; ?>
        <span style="color:var(--muted);"><?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
        <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?<?= http_build_query(array_merge($_GET, ['page' => $currentPage + 1, 'per_page' => $perPage])) ?>" style="padding:4px 10px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:var(--text);">Next &#8594;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Schedule III Group Mapping Panel -->
<div id="groupMappingPanel" style="background:var(--panel-strong);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:12px;box-shadow:var(--shadow-sm);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <strong style="font-size:0.9rem;">Schedule III Group Mapping</strong>
        <span id="groupPanelInfo" style="font-size:0.75rem;color:var(--muted);">Tag parent groups to Schedule III heads</span>
    </div>
    <div id="groupMappingBody" style="overflow-x:auto;"></div>
</div>

<!-- Grid with horizontal scroll (ledger mode only) -->
<?php if ($isLedgerMode): ?>
<div class="recon-grid-wrap">
    <div class="hot-container">
        <div id="hot"></div>
    </div>
</div>
<?php endif; ?>

<!-- Action Bar -->
<?php if ($isLedgerMode): ?>
<div class="wb-actions">
    <button class="btn btn-success" id="btnAcceptHigh" title="Accept all suggestions with confidence >= 90%">Accept High Confidence</button>
    <button class="btn" id="btnAcceptGroup" title="Accept parent group rule suggestions for visible rows" style="background:#7c3aed;color:#fff;border-color:#7c3aed;">Accept Group Suggestions</button>
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
<?php else: ?>
<div class="wb-actions">
    <button class="btn btn-success" id="btnGroupSave" title="Save group mappings">&#128190; Save Changes</button>
    <button class="btn btn-outline" id="btnGroupReset" title="Reset group changes">&#8617; Reset</button>
    <div class="sep"></div>
    <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?mode=ledger" class="btn" style="background:#7c3aed;color:#fff;border-color:#7c3aed;text-decoration:none;">Ledger-wise Mapping &#8594;</a>
    <span class="status-text" id="statusText">Ready</span>
</div>
<?php endif; ?>

<!-- Hidden form for import -->
<form id="importForm" class="hidden-input" enctype="multipart/form-data">
    <?= csrfInput() ?>
    <input type="hidden" name="action" value="validate">
    <input type="file" name="file" id="importFile" accept=".xlsx,.xls">
</form>

<div style="height:16px;"></div>

<?= uiWorkspaceEnd() ?>

<!-- Early Bridge Status Bootstrap — runs before heavy Tabulator init -->
<script>
(function() {
    var url = window.ebalBridgeUrl || 'http://127.0.0.1:9123';
    window.ebalBridgeUrl = url;

    function updateDot(kind, status) {
        var dots = document.querySelectorAll('.bc-dot[data-status-kind="' + kind + '"]');
        var color = (status === 'online' || status === 'connected') ? 'green' : (status === 'waiting' ? 'yellow' : 'red');
        for (var i = 0; i < dots.length; i++) {
            dots[i].className = 'bc-dot bc-dot--' + color;
        }
    }

    function updateLabel(kind, text) {
        var vals = document.querySelectorAll('.bc-status-val[data-status-kind="' + kind + '"]');
        for (var i = 0; i < vals.length; i++) {
            vals[i].textContent = text;
        }
    }

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeoutId = null;
    if (controller) {
        timeoutId = setTimeout(function() { controller.abort(); }, 4000);
    }

    fetch(url + '/health', { mode: 'cors', cache: 'no-store', signal: controller ? controller.signal : undefined })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (timeoutId) clearTimeout(timeoutId);
            var bridgeOk = !!(data && data.ok);
            var tallyOk = !!(data && data.tally && data.tally !== 'unknown');
            updateDot('bridge', bridgeOk ? 'online' : 'offline');
            updateLabel('bridge', bridgeOk ? 'Online' : 'Offline');
            updateDot('tally', tallyOk ? 'connected' : 'offline');
            updateLabel('tally', tallyOk ? (data.tally === 'connected' ? 'Connected' : data.tally) : 'Offline');
        })
        .catch(function() {
            if (timeoutId) clearTimeout(timeoutId);
            updateDot('bridge', 'offline');
            updateLabel('bridge', 'Offline');
            updateDot('tally', 'offline');
            updateLabel('tally', 'Offline');
        });
})();
</script>

<?php if ($isLedgerMode): ?>
<script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6/dist/js/tabulator.min.js"></script>
<script>
(function() {
    'use strict';

    var ebalBaseUrl = <?= json_encode(BASE_URL) ?>;
    var csrfToken = <?= json_encode(csrfToken()) ?>;
    var mappingOptions = <?= json_encode($mappingOptionsJson) ?>;
    var allData = <?= json_encode($paginatedGridData) ?>;
    var totalGridRows = <?= (int) $totalGridRows ?>;
    var currentPage = <?= (int) $currentPage ?>;
    var totalPages = <?= (int) $totalPages ?>;
    var perPage = <?= (int) $perPage ?>;
    var tbImpactCount = <?= (int) $tbImpactCount ?>;
    var totalCount = <?= (int) $totalLedgerCount ?>;
    var defaultView = <?= $defaultViewTbImpact ? "'tb_impact'" : "'all'" ?>;
    var optionsMap = {};
    var optionList = [];
    mappingOptions.forEach(function(o) {
        optionsMap[o.id] = o.label;
        optionList.push({id: o.id, label: o.label});
    });

    var originalData = JSON.parse(JSON.stringify(allData));
    var dirtyRows = {};
    var currentFilter = 'all';
    var currentParentGroup = '';
    var searchMode = 'all';
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
            case 'risky': return row.risk_level && row.risk_level !== 'none';
            case 'critical': return row.risk_level === 'critical';
            case 'credit_in_asset': return row.risk_reason && row.risk_reason.indexOf('Credit balance in asset') !== -1;
            case 'debit_in_liability': return row.risk_reason && row.risk_reason.indexOf('Debit balance in liability') !== -1;
            case 'manual_review': return row.risk_level === 'review' || (row.risk_level === 'none' && (!row.suggested || row.suggested === '') && (!code || code === ''));
            default: return true;
        }
    }

    function getFilteredData() {
        var search = (document.getElementById('hotSearch').value || '').toLowerCase().trim();
        var pgFilter = document.getElementById('pgFilter').value || '';
        return allData.filter(function(r) {
            if (!filterRow(r)) return false;
            if (pgFilter && r.parent_group !== pgFilter) return false;
            if (search) {
                var hay = '';
                switch (searchMode) {
                    case 'ledger_name': hay = (r.ledger_name||'').toLowerCase(); break;
                    case 'parent_group': hay = (r.parent_group||'').toLowerCase(); break;
                    case 'schedule': hay = ((r.current_label||'')+' '+(r.suggested_label||'')).toLowerCase(); break;
                    case 'remarks': hay = (r.remarks||'').toLowerCase(); break;
                    default: hay = ((r.ledger_name||'')+' '+(r.parent_group||'')+' '+(r.current_label||'')+' '+(r.suggested_label||'')+' '+(r.remarks||'')).toLowerCase();
                }
                if (hay.indexOf(search) === -1) return false;
            }
            return true;
        });
    }

    /* ---- Stats update ---- */
    function updateStats() {
        var total=allData.length, mapped=0, unmapped=0, suggested=0, high=0, review=0, unsaved=Object.keys(dirtyRows).length, risk=0;
        for (var i=0;i<allData.length;i++) {
            var d=allData[i], code=d.final_mapping||d.current_mapping||'';
            if (code&&code!=='') { mapped++; }
            else { unmapped++; if(d.suggested&&(d.confidence||0)>=90){suggested++;high++;}else if(d.suggested&&(d.confidence||0)>=70){suggested++;}else{review++;} }
            if (d.risk_level==='critical'||d.risk_level==='warning') { risk++; }
        }
        document.getElementById('statTotal').textContent=total;
        document.getElementById('statMapped').textContent=mapped;
        document.getElementById('statUnmapped').textContent=unmapped;
        document.getElementById('statSuggested').textContent=suggested;
        document.getElementById('statHigh').textContent=high;
        document.getElementById('statReview').textContent=review;
        document.getElementById('statUnsaved').textContent=unsaved;
        document.getElementById('statRisk').textContent=risk;
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
            layout: 'fitDataStretch',
            height: 'calc(100vh - 280px)',
            selectable: true,
            movableColumns: true,
            resizable: true,
            headerSortTristate: true,
            rowClass: rowClass,
            placeholder: 'No ledgers found',
            pagination: 'local',
            paginationSize: 250,
            paginationSizeSelector: [100, 250, 500, 1000, true],
            columns: [
                {title:'', formatter:'rowSelection', titleFormatter:'rowSelection', headerSort:false, width:45, hozAlign:'center', cellClick:function(e, cell){cell.getRow().toggleSelect();}},
                {title:'Ledger Name', field:'ledger_name', width:260, minWidth:200, frozen:true, headerTooltip:true},
                {title:'Parent Group', field:'parent_group', width:190, minWidth:150},
                {title:'Net Balance', field:'net_balance', width:140, minWidth:100, hozAlign:'right', formatter:moneyFormatter, accessorDownload:moneyFormatter},
                {title:'Dr/Cr', field:'drcr', width:70, minWidth:50, hozAlign:'center'},
                {title:'Current Mapping', field:'current_label', width:200, minWidth:150},
                {title:'Suggested', field:'suggested_label', width:240, minWidth:180},
                {title:'Source', field:'suggestion_source', width:130, minWidth:100},
                {title:'Conf %', field:'confidence', width:90, minWidth:70, hozAlign:'center', formatter:confidenceFormatter, accessorDownload:function(v){return (v||0)+'%';}},
                {title:'Final Mapping', field:'final_mapping', width:280, minWidth:220, editor:'select', editorParams:{values:selectOptions}, formatter:finalMappingFormatter, cellEdited:function(cell){
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
                {title:'Risk', field:'risk_level', width:100, minWidth:80, hozAlign:'center', formatter:function(cell){
                    var v = cell.getValue();
                    var el = cell.getElement();
                    el.style.textAlign = 'center';
                    el.style.fontWeight = '600';
                    el.style.fontSize = '0.75rem';
                    if (v === 'critical') { el.style.color = '#dc2626'; return '🔴'; }
                    if (v === 'warning') { el.style.color = '#e65100'; return '⚠️'; }
                    if (v === 'review') { el.style.color = '#d97706'; return '👁️'; }
                    el.style.color = '#2e7d32';
                    return '✓';
                }, cellMouseOver:function(e, cell){
                    var row = cell.getRow().getData();
                    if (row.risk_reason) cell.getElement().title = row.risk_reason;
                }},
                {title:'Remarks', field:'remarks', width:240, minWidth:180, editor:'input', cellEdited:function(cell){
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
        refreshGroupPanel();
    }

    /* ---- View mode chip click ---- */
    document.getElementById('viewChips').addEventListener('click', function(e) {
        var chip = e.target.closest('.filter-chip');
        if (!chip) return;
        var view = chip.getAttribute('data-view');
        document.querySelectorAll('#viewChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');

        if (view === 'tb_impact') {
            allData = originalData.filter(function(r) {
                return r.has_tb === true;
            });
        } else {
            allData = originalData.slice();
        }
        dirtyRows = {};
        populateParentGroupDropdown();
        refreshGrid();
        highlightActiveTile();
    });

    /* ---- Filter chip click ---- */
    document.getElementById('filterChips').addEventListener('click', function(e) {
        var chip = e.target.closest('.filter-chip');
        if (!chip) return;
        var filter = chip.getAttribute('data-filter');
        document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');
        currentFilter = filter;
        refreshGrid();
        refreshGroupPanel();
        highlightActiveTile();
    });

    /* ---- Search ---- */
    var searchTimer;
    document.getElementById('hotSearch').addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { refreshGrid(); refreshGroupPanel(); }, 250);
    });

    /* ---- Search mode selector ---- */
    var searchModeMap = {
        'all': 'Search ledger, parent group, schedule, remarks\u2026',
        'ledger_name': 'Search ledger name\u2026',
        'parent_group': 'Search parent group\u2026',
        'schedule': 'Search schedule or mapping\u2026',
        'remarks': 'Search remarks\u2026',
    };
    document.getElementById('searchMode').addEventListener('change', function() {
        searchMode = this.value;
        document.getElementById('hotSearch').placeholder = searchModeMap[searchMode] || searchModeMap['all'];
        refreshGrid();
    });

    /* ---- Parent group dropdown filter ---- */
    function populateParentGroupDropdown() {
        var pgCounts = {};
        allData.forEach(function(r) {
            var pg = r.parent_group || '';
            if (!pg) return;
            pgCounts[pg] = (pgCounts[pg] || 0) + 1;
        });
        var sorted = Object.keys(pgCounts).sort(function(a, b) { return pgCounts[b] - pgCounts[a]; });
        var sel = document.getElementById('pgFilter');
        var current = sel.value;
        sel.innerHTML = '<option value="">All Parent Groups (' + allData.length + ')</option>';
        sorted.forEach(function(pg) {
            var opt = document.createElement('option');
            opt.value = pg;
            opt.textContent = pg + ' (' + pgCounts[pg] + ')';
            sel.appendChild(opt);
        });
        if (current && pgCounts[current]) sel.value = current;
    }
    document.getElementById('pgFilter').addEventListener('change', function() {
        currentParentGroup = this.value;
        refreshGrid();
        refreshGroupPanel();
    });

    /* ---- KPI tile click ---- */
    document.getElementById('wbSummary').addEventListener('click', function(e) {
        var card = e.target.closest('.wb-card[data-filter]');
        if (!card) return;
        var filter = card.getAttribute('data-filter');
        if (filter === 'unsaved') return;
        if (filter === 'tb_impact') {
            /* Switch to TB impact view */
            document.querySelectorAll('#viewChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            document.querySelector('#viewChips .filter-chip[data-view="tb_impact"]').classList.add('active');
            allData = originalData.filter(function(r) { return r.has_tb === true; });
            dirtyRows = {};
        } else if (filter === 'all') {
            document.querySelectorAll('#viewChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            document.querySelector('#viewChips .filter-chip[data-view="all"]').classList.add('active');
            allData = originalData.slice();
            dirtyRows = {};
        }
        currentFilter = (filter === 'all' || filter === 'tb_impact') ? 'all' : filter;
        /* Update filter chips */
        document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
        var targetChip = document.querySelector('#filterChips .filter-chip[data-filter="' + currentFilter + '"]');
        if (targetChip) targetChip.classList.add('active');
        else document.querySelector('#filterChips .filter-chip[data-filter="all"]').classList.add('active');
        refreshGrid();
        refreshGroupPanel();
        highlightActiveTile();
    });

    function highlightActiveTile() {
        document.querySelectorAll('#wbSummary .wb-card').forEach(function(c) { c.classList.remove('active-tile'); });
        var target = document.querySelector('#wbSummary .wb-card[data-filter="' + currentFilter + '"]');
        if (target) target.classList.add('active-tile');
        else document.querySelector('#wbSummary .wb-card[data-filter="all"]').classList.add('active-tile');
    }

    /* ---- Dynamic group panel builder ---- */
    function refreshGroupPanel() {
        var filtered = getFilteredData();
        var pgData = {};
        filtered.forEach(function(r) {
            var pg = r.parent_group || '';
            if (!pg) return;
            if (!pgData[pg]) pgData[pg] = { count: 0, mapped: 0, unmapped: 0, closing_dr: 0, closing_cr: 0, dominant: {}, risk: 0, suggested: null, confidence: 0 };
            var d = pgData[pg];
            d.count++;
            var code = r.final_mapping || r.current_mapping || '';
            if (code) { d.mapped++; } else { d.unmapped++; }
            d.closing_dr += parseFloat(r.closing_dr) || 0;
            d.closing_cr += parseFloat(r.closing_cr) || 0;
            if (r.risk_level === 'critical' || r.risk_level === 'warning') d.risk++;
            if (r.suggestion_source === 'parent_group_rule' && r.suggested) {
                d.suggested = r.suggested;
                d.confidence = r.confidence || 0;
            }
            if (code) { d.dominant[code] = (d.dominant[code] || 0) + 1; }
        });
        var sorted = Object.keys(pgData).sort(function(a, b) { return pgData[b].count - pgData[a].count; });
        var body = document.getElementById('groupMappingBody');
        var info = document.getElementById('groupPanelInfo');
        if (sorted.length === 0) {
            body.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:0.82rem;">No parent groups in current filter.</div>';
            info.textContent = '0 groups';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:0.78rem;"><thead><tr style="border-bottom:2px solid var(--border);">';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Parent Group</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Total</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Unmapped</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Dr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Cr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Net Balance</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Suggested</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Conf</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Risk</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Schedule III</th>';
        html += '<th style="text-align:center;padding:6px 8px;"></th>';
        html += '</tr></thead><tbody>';
        var showMax = Math.min(sorted.length, 30);
        for (var i = 0; i < showMax; i++) {
            var pg = sorted[i], d = pgData[pg];
            var netBal = d.closing_dr - d.closing_cr;
            var netColor = netBal < 0 ? 'color:var(--danger)' : '';
            var dominantCode = '';
            var maxCount = 0;
            Object.keys(d.dominant).forEach(function(k) { if (d.dominant[k] > maxCount) { maxCount = d.dominant[k]; dominantCode = k; } });
            var dominantLabel = dominantCode ? (optionsMap[dominantCode] || dominantCode) : '';
            var suggestedLabel = d.suggested ? (optionsMap[d.suggested] || d.suggested) : '';
            var confClass = d.confidence >= 90 ? 'color:var(--success)' : (d.confidence >= 70 ? 'color:var(--warning)' : 'color:var(--danger)');
            html += '<tr style="border-bottom:1px solid var(--border);" data-pg="' + escHtml(pg) + '">';
            html += '<td style="padding:6px 8px;font-weight:500;white-space:nowrap;">' + escHtml(pg) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + d.count + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;color:' + (d.unmapped > 0 ? 'var(--danger)' : 'var(--success)') + ';">' + d.unmapped + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(d.closing_dr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(d.closing_cr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;' + netColor + '">' + fmtMoney(netBal) + '</td>';
            html += '<td style="padding:6px 8px;">' + (dominantLabel || '<span style="color:var(--muted);">\u2014</span>') + '</td>';
            html += '<td style="padding:6px 8px;text-align:center;font-weight:600;' + confClass + '">' + d.confidence + '%</td>';
            html += '<td style="padding:6px 8px;text-align:center;">' + (d.risk > 0 ? '<span style="color:var(--danger);font-weight:600;">' + d.risk + '</span>' : '<span style="color:var(--success);">0</span>') + '</td>';
            html += '<td style="padding:6px 8px;"><select class="gm-select" data-pg="' + escHtml(pg) + '" style="padding:4px 8px;border:1px solid var(--border-strong);border-radius:4px;font-size:0.78rem;min-width:140px;"><option value="">Select...</option>';
            mappingOptions.forEach(function(o) {
                var sel = (o.id === d.suggested) ? ' selected' : '';
                html += '<option value="' + escHtml(o.id) + '"' + sel + '>' + escHtml(o.label) + '</option>';
            });
            html += '</select></td>';
            html += '<td style="padding:6px 8px;"><button class="btn btn-sm gm-apply-btn" data-pg="' + escHtml(pg) + '" style="padding:4px 10px;font-size:0.72rem;">Apply</button></td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        if (sorted.length > 30) {
            html += '<div style="margin-top:8px;font-size:0.75rem;color:var(--muted);">Showing 30 of ' + sorted.length + ' groups.</div>';
        }
        body.innerHTML = html;
        info.textContent = sorted.length + ' groups from ' + filtered.length + ' ledgers';
        bindGroupApplyButtons();
    }

    function bindGroupApplyButtons() {
        document.querySelectorAll('.gm-apply-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var pg = this.getAttribute('data-pg');
                var select = document.querySelector('.gm-select[data-pg="' + pg + '"]');
                if (!select || !select.value) {
                    showToast('Select a Schedule III head first', 'error');
                    return;
                }
                var scheduleCode = select.value;
                var count = 0;
                var filtered = getFilteredData();
                var filteredNames = {};
                filtered.forEach(function(r) { filteredNames[r.ledger_name] = true; });
                for (var i = 0; i < allData.length; i++) {
                    if (allData[i].parent_group !== pg) continue;
                    if (!filteredNames[allData[i].ledger_name]) continue;
                    var existingCode = allData[i].final_mapping || allData[i].current_mapping || '';
                    if (existingCode && existingCode !== '') continue;
                    if (allData[i].final_mapping !== scheduleCode) {
                        allData[i].final_mapping = scheduleCode;
                        allData[i].status = 'Mapped';
                        dirtyRows[allData[i].ledger_name] = true;
                        count++;
                    }
                }
                refreshGrid();
                updateStats();
                refreshGroupPanel();
                showToast(count + ' unmapped ledgers under "' + pg + '" mapped to ' + (optionsMap[scheduleCode] || scheduleCode), 'success');
            });
        });
    }

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

    /* ---- Accept group suggestions (visible rows only) ---- */
    document.getElementById('btnAcceptGroup').addEventListener('click', function() {
        if (!table) return;
        var rows = table.getRows();
        var count = 0;
        rows.forEach(function(row) {
            var d = row.getData();
            if (d.suggested && d.suggested !== '' && d.final_mapping !== d.suggested &&
                (d.suggestion_source === 'parent_group_rule' || d.suggestion_source === 'group_rule')) {
                d.final_mapping = d.suggested;
                d.status = 'Mapped';
                dirtyRows[d.ledger_name] = true;
                count++;
            }
        });
        refreshGrid();
        updateStats();
        refreshGroupPanel();
        showToast(count + ' group suggestions applied to visible rows', 'success');
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
        populateParentGroupDropdown();
        refreshGrid();
        updateStats();
        showToast('All changes reset', 'info');
    });

    /* ---- Risk detection for dirty rows ---- */
    function detectRisksInDirtyRows() {
        var dirty = Object.keys(dirtyRows);
        var criticals = [];
        var warnings = [];
        var assetSchedules = ['ppe','inventory','receivables','cash','bank_balances_other','other_current_assets','investments_non_current','loans_non_current','intangible_assets','cwip','deferred_tax_asset','other_non_current_assets','investments_current','loans_current'];
        var liabilitySchedules = ['trade_payables','lt_borrowings','st_borrowings','other_current_liabilities','short_term_provisions','share_capital','reserves','deferred_tax_liability','other_non_current_liabilities','long_term_provisions'];
        var plVariants = ['profit & loss a/c','profit and loss a/c','profit & loss account','profit and loss account','p&l a/c','p and l a/c','surplus in statement of profit and loss'];
        var bankOdVariants = ['bank od','od account','overdraft','cash credit','bank overdraft','current account'];

        dirty.forEach(function(name) {
            for (var i=0;i<allData.length;i++) {
                var d = allData[i];
                if (d.ledger_name !== name) continue;
                var code = d.final_mapping || d.current_mapping || '';
                var group = (d.parent_group||'').toLowerCase();
                var closingCr = parseFloat(d.closing_cr)||0;
                var closingDr = parseFloat(d.closing_dr)||0;
                var riskLevel = 'none';
                var riskReason = '';

                // Critical: P&L A/c not mapped to reserves
                if (plVariants.indexOf(group) !== -1 && code !== 'reserves') {
                    riskLevel = 'critical';
                    riskReason = 'Profit & Loss A/c should map to Reserves (Equity). Current: ' + (code||'Unmapped');
                }
                // Critical: Bank OD/CC credit mapped to cash
                if (bankOdVariants.indexOf(group) !== -1 && closingCr > 0 && code === 'cash') {
                    riskLevel = 'critical';
                    riskReason = 'Bank OD/CC with credit balance should map to st_borrowings, not cash.';
                }
                // Critical: Patient advance credit mapped to receivables
                if ((group === 'advance in patient' || group === 'patient advance') && closingCr > 0 && code === 'receivables') {
                    riskLevel = 'critical';
                    riskReason = 'Patient advance with credit balance is a liability, not a receivable.';
                }
                // Critical: Insurance patient credit mapped to receivables
                if (group === 'insurance patient' && closingCr > 0 && code === 'receivables') {
                    riskLevel = 'critical';
                    riskReason = 'Insurance patient with credit balance is a liability, not a receivable.';
                }
                // Warning: Credit balance in asset schedule
                if (riskLevel === 'none' && closingCr > 0 && assetSchedules.indexOf(code) !== -1) {
                    riskLevel = 'warning';
                    riskReason = 'Credit balance in asset schedule.';
                }
                // Warning: Debit balance in liability/equity schedule
                if (riskLevel === 'none' && closingDr > 0 && liabilitySchedules.indexOf(code) !== -1) {
                    riskLevel = 'warning';
                    riskReason = 'Debit balance in liability/equity schedule.';
                }

                if (riskLevel === 'critical') criticals.push({name: d.ledger_name, group: d.parent_group, code: code, reason: riskReason});
                else if (riskLevel === 'warning') warnings.push({name: d.ledger_name, group: d.parent_group, code: code, reason: riskReason});
                break;
            }
        });
        return {criticals: criticals, warnings: warnings};
    }

    /* ---- Show risk modal ---- */
    function showRiskModal(title, items, type) {
        var existing = document.getElementById('riskModal');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.id = 'riskModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

        var isCritical = type === 'critical';
        var accentColor = isCritical ? '#dc2626' : '#e65100';
        var icon = isCritical ? '&#9888;' : '&#9888;';

        var html = '<div style="background:#fff;border-radius:12px;max-width:600px;width:90%;max-height:80vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">';
        html += '<div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;">';
        html += '<div style="display:flex;align-items:center;gap:10px;">';
        html += '<span style="font-size:1.5rem;color:' + accentColor + ';">' + icon + '</span>';
        html += '<h3 style="margin:0;font-size:1.1rem;color:#1f2937;">' + title + '</h3>';
        html += '</div></div>';
        html += '<div style="padding:16px 24px;">';
        html += '<p style="font-size:0.9rem;color:#4b5563;margin-bottom:12px;">' + items.length + ' ledger(s) have mapping risks that should be reviewed before saving:</p>';
        html += '<div style="max-height:300px;overflow-y:auto;">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">';
        html += '<thead><tr style="border-bottom:2px solid #e5e7eb;background:#f9fafb;"><th style="text-align:left;padding:8px;">Ledger</th><th style="text-align:left;padding:8px;">Schedule</th><th style="text-align:left;padding:8px;">Risk</th></tr></thead>';
        html += '<tbody>';
        var showMax = Math.min(items.length, 20);
        for (var idx = 0; idx < showMax; idx++) {
            var item = items[idx];
            html += '<tr style="border-bottom:1px solid #f3f4f6;">';
            html += '<td style="padding:8px;font-weight:500;">' + escHtml(item.name) + '</td>';
            html += '<td style="padding:8px;color:#6b7280;">' + escHtml(item.code) + '</td>';
            html += '<td style="padding:8px;color:' + (isCritical ? '#dc2626' : '#e65100') + ';font-weight:500;">' + escHtml(item.reason) + '</td>';
            html += '</tr>';
        }
        if (items.length > 20) {
            html += '<tr><td colspan="3" style="padding:8px;color:#6b7280;text-align:center;font-style:italic;">... and ' + (items.length - 20) + ' more</td></tr>';
        }
        html += '</tbody></table></div></div>';
        html += '<div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">';
        html += '<button class="btn btn-outline" onclick="document.getElementById(\'riskModal\').remove()" style="padding:8px 16px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:0.85rem;">Review Now</button>';
        html += '<button class="btn ' + (isCritical ? 'btn-danger' : 'btn-success') + '" id="riskModalConfirm" style="padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;color:#fff;background:' + (isCritical ? accentColor : '#16a34a') + ';">Save Anyway (' + items.length + ' risks)</button>';
        html += '</div></div>';
        modal.innerHTML = html;
        document.body.appendChild(modal);

        modal.addEventListener('click', function(e) {
            if (e.target === modal) modal.remove();
        });
        document.getElementById('riskModalConfirm').addEventListener('click', function() {
            modal.remove();
            executeSave();
        });
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function executeSave() {
        var dirty = Object.keys(dirtyRows);
        if (!dirty.length) { showToast('No changes to save', 'info'); return; }
        var mappings = {};
        var remarksMap = {};
        dirty.forEach(function(name) {
            for (var i=0;i<allData.length;i++) {
                if (allData[i].ledger_name===name && allData[i].final_mapping && allData[i].final_mapping!=='') {
                    mappings[name] = allData[i].final_mapping;
                    if (allData[i].remarks) remarksMap[name] = allData[i].remarks;
                    break;
                }
            }
        });
        if (Object.keys(mappings).length === 0) { showToast('No valid mappings to save', 'error'); return; }
        document.getElementById('statusText').textContent = 'Saving ' + Object.keys(mappings).length + ' mappings...';
        document.getElementById('btnSave').disabled = true;
        document.getElementById('btnSave').textContent = 'Saving...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_save.php', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
            body: JSON.stringify({mappings:mappings, overrides:{}, remember:{}, remarks:remarksMap}),
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            document.getElementById('btnSave').disabled = false;
            document.getElementById('btnSave').textContent = '\uD83D\uDCBE Save Changes';
            if (resp.redirect) {
                showToast(resp.error || 'Session expired', 'error');
                setTimeout(function(){ window.location.href = ebalBaseUrl + 'login.php'; }, 1500);
                return;
            }
            if (resp.success) {
                var savedCount = resp.saved || 0;
                var conflictCount = resp.conflicts || 0;
                var errorCount = (resp.errors || []).length;
                /* Update all saved rows' current_mapping from final_mapping */
                Object.keys(mappings).forEach(function(name){
                    for(var i=0;i<allData.length;i++){
                        if(allData[i].ledger_name===name){
                            allData[i].current_mapping=mappings[name];
                            allData[i].current_label=optionsMap[mappings[name]]||mappings[name];
                            break;
                        }
                    }
                });
                /* Clear dirty rows only for successfully saved ones */
                dirtyRows = {};
                originalData = JSON.parse(JSON.stringify(allData));
                updateStats(); refreshGrid(); refreshGroupPanel(); populateParentGroupDropdown();
                var msg = savedCount + ' mappings saved.';
                if (conflictCount > 0) msg += ' ' + conflictCount + ' skipped (parent group conflict).';
                if (errorCount > 0) msg += ' ' + errorCount + ' errors.';
                if(resp.pending>0) msg+=' '+resp.pending+' remaining.';
                if(resp.mapping_complete) msg+=' All ledgers mapped!';
                document.getElementById('statusText').textContent='Saved '+savedCount+' rows';
                showToast(msg, savedCount > 0 ? 'success' : (conflictCount > 0 ? 'info' : 'error'));
            } else {
                document.getElementById('statusText').textContent='Save failed';
                showToast(resp.error||'Save failed','error');
            }
        })
        .catch(function(e){
            document.getElementById('btnSave').disabled=false;
            document.getElementById('btnSave').textContent = '\uD83D\uDCBE Save Changes';
            document.getElementById('statusText').textContent='Network error';
            showToast('Network error. Please try again.','error');
        });
    }

    /* ---- Save via AJAX ---- */
    document.getElementById('btnSave').addEventListener('click', function() {
        var dirty = Object.keys(dirtyRows);
        if (!dirty.length) { showToast('No changes to save', 'info'); return; }

        var risks = detectRisksInDirtyRows();
        if (risks.criticals.length > 0) {
            showRiskModal('Critical Mapping Risks Detected', risks.criticals, 'critical');
            return;
        }
        if (risks.warnings.length > 0) {
            showRiskModal('Mapping Warnings', risks.warnings, 'warning');
            return;
        }

        executeSave();
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
    var clientStart = performance.now();
    populateParentGroupDropdown();
    var clientFilters = performance.now();
    initTable();
    var clientGrid = performance.now();
    updateStats();
    refreshGroupPanel();
    var clientTotal = performance.now();
    if (window.location.search.indexOf('debug=1') !== -1) {
        console.log('ReconHub client timing: filters=' + Math.round(clientFilters - clientStart) + 'ms, grid=' + Math.round(clientGrid - clientFilters) + 'ms, group_panel=' + Math.round(clientTotal - clientGrid) + 'ms, total=' + Math.round(clientTotal - clientStart) + 'ms');
    }

})();
</script>
<?php endif; /* $isLedgerMode */ ?>
<?php if ($isGroupMode): ?>
<!-- Group-wise Save + Render Script -->
<script>
(function() {
    'use strict';
    var ebalBaseUrl = <?= json_encode(BASE_URL) ?>;
    var csrfToken = <?= json_encode(csrfToken()) ?>;
    var mappingOptions = <?= json_encode($mappingOptionsJson) ?>;
    var groupMappingData = <?= json_encode($groupMappingData) ?>;
    var gridData = <?= json_encode($paginatedGridData) ?>;
    var optionsMap = {};
    var optionList = [];
    mappingOptions.forEach(function(o) { optionsMap[o.id] = o.label; optionList.push(o); });
    var groupDirty = {};
    var currentFilter = 'all';
    var currentParentGroup = '';
    var searchMode = 'all';

    function showToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'toast ' + (type || 'info');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 3500);
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtMoney(v) {
        if (v === null || v === undefined || v === '') return '';
        return parseFloat(v).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    /* ---- Filter logic (group-aware) ---- */
    function filterGroup(pg, d) {
        if (currentFilter === 'all' && !currentParentGroup) return true;
        if (currentParentGroup && pg !== currentParentGroup) return false;
        if (currentFilter === 'all') return true;
        switch (currentFilter) {
            case 'unmapped': return d._unmapped > 0;
            case 'mapped': return d._mapped > 0;
            case 'suggested': return d.confidence >= 70 && d._unmapped > 0;
            case 'high_conf': return d.confidence >= 90 && d._unmapped > 0;
            case 'low_conf': return d.confidence > 0 && d.confidence < 70 && d._unmapped > 0;
            case 'review': return d.confidence < 70 && d._unmapped > 0;
            case 'risky': return d.risk_count > 0;
            case 'critical': return d.risk_count > 0;
            default: return true;
        }
    }

    function searchGroup(pg) {
        var search = (document.getElementById('hotSearch').value || '').toLowerCase().trim();
        if (!search) return true;
        return pg.toLowerCase().indexOf(search) !== -1;
    }

    /* ---- Populate parent group dropdown ---- */
    function populateParentGroupDropdown() {
        var sel = document.getElementById('pgFilter');
        if (!sel) return;
        var current = sel.value;
        sel.innerHTML = '<option value="">All Parent Groups (' + groupMappingData.length + ')</option>';
        var sorted = groupMappingData.slice().sort(function(a, b) { return b.ledger_count - a.ledger_count; });
        sorted.forEach(function(g) {
            var opt = document.createElement('option');
            opt.value = g.parent_group;
            opt.textContent = g.parent_group + ' (' + g.ledger_count + ')';
            sel.appendChild(opt);
        });
        if (current) sel.value = current;
    }

    /* ---- Dynamic group panel builder ---- */
    function refreshGroupPanel() {
        /* Compute unmapped counts from grid data, respecting final_mapping (pending save) */
        var unmappedCounts = {};
        gridData.forEach(function(r) {
            var pg = r.parent_group || '';
            if (!pg) return;
            var mappedCode = r.final_mapping || r.current_mapping || '';
            if (!mappedCode || mappedCode === '') {
                unmappedCounts[pg] = (unmappedCounts[pg] || 0) + 1;
            }
        });

        var filtered = [];
        groupMappingData.forEach(function(g) {
            g._unmapped = unmappedCounts[g.parent_group] || 0;
            g._mapped = g.ledger_count - g._unmapped;
            if (filterGroup(g.parent_group, g) && searchGroup(g.parent_group)) {
                filtered.push(g);
            }
        });
        var body = document.getElementById('groupMappingBody');
        var info = document.getElementById('groupPanelInfo');
        if (!body || !info) return;
        if (filtered.length === 0) {
            body.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:0.82rem;">No parent groups in current filter.</div>';
            info.textContent = '0 groups';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:0.78rem;"><thead><tr style="border-bottom:2px solid var(--border);">';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Parent Group</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Total</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Unmapped</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Dr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Cr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Net Balance</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Suggested</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Conf</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Risk</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Schedule III</th>';
        html += '<th style="text-align:center;padding:6px 8px;"></th>';
        html += '</tr></thead><tbody>';
        var showMax = Math.min(filtered.length, 30);
        for (var i = 0; i < showMax; i++) {
            var g = filtered[i];
            var netBal = g.closing_dr - g.closing_cr;
            var netColor = netBal < 0 ? 'color:var(--danger)' : '';
            var dominantLabel = g.dominant_mapping ? (optionsMap[g.dominant_mapping] || g.dominant_mapping) : '';
            var suggestedLabel = g.suggested_code ? (optionsMap[g.suggested_code] || g.suggested_code) : '';
            var confClass = g.confidence >= 90 ? 'color:var(--success)' : (g.confidence >= 70 ? 'color:var(--warning)' : 'color:var(--danger)');
            html += '<tr style="border-bottom:1px solid var(--border);" data-pg="' + escHtml(g.parent_group) + '">';
            html += '<td style="padding:6px 8px;font-weight:500;white-space:nowrap;">' + escHtml(g.parent_group) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + g.ledger_count + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;color:' + (g._unmapped > 0 ? 'var(--danger)' : 'var(--success)') + ';">' + g._unmapped + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(g.closing_dr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(g.closing_cr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;' + netColor + '">' + fmtMoney(netBal) + '</td>';
            html += '<td style="padding:6px 8px;">' + (dominantLabel || '<span style="color:var(--muted);">\u2014</span>') + '</td>';
            html += '<td style="padding:6px 8px;text-align:center;font-weight:600;' + confClass + '">' + g.confidence + '%</td>';
            html += '<td style="padding:6px 8px;text-align:center;">' + (g.risk_count > 0 ? '<span style="color:var(--danger);font-weight:600;">' + g.risk_count + '</span>' : '<span style="color:var(--success);">0</span>') + '</td>';
            html += '<td style="padding:6px 8px;"><select class="gm-select" data-pg="' + escHtml(g.parent_group) + '" style="padding:4px 8px;border:1px solid var(--border-strong);border-radius:4px;font-size:0.78rem;min-width:140px;"><option value="">Select...</option>';
            optionList.forEach(function(o) {
                var sel = (o.id === g.suggested_code) ? ' selected' : '';
                html += '<option value="' + escHtml(o.id) + '"' + sel + '>' + escHtml(o.label) + '</option>';
            });
            html += '</select></td>';
            html += '<td style="padding:6px 8px;display:flex;gap:4px;flex-wrap:nowrap;">';
            html += '<button class="btn btn-sm gm-apply-btn" data-pg="' + escHtml(g.parent_group) + '" style="padding:4px 10px;font-size:0.72rem;">Apply</button>';
            if (g.risk_count > 0 || g._unmapped > 0) {
                html += '<button class="btn btn-sm gm-override-btn" data-pg="' + escHtml(g.parent_group) + '" style="padding:4px 8px;font-size:0.68rem;background:#7c3aed;color:#fff;border:none;border-radius:4px;cursor:pointer;" title="Include with manual override despite risk/conflict">Override</button>';
            }
            html += '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        if (filtered.length > 30) {
            html += '<div style="margin-top:8px;font-size:0.75rem;color:var(--muted);">Showing 30 of ' + filtered.length + ' groups.</div>';
        }
        body.innerHTML = html;
        info.textContent = filtered.length + ' groups from ' + gridData.length + ' ledgers';
    }

    /* ---- Filter chip click ---- */
    var filterChips = document.getElementById('filterChips');
    if (filterChips) {
        filterChips.addEventListener('click', function(e) {
            var chip = e.target.closest('.filter-chip');
            if (!chip) return;
            var filter = chip.getAttribute('data-filter');
            document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            currentFilter = filter;
            currentParentGroup = '';
            refreshGroupPanel();
        });
    }

    /* ---- Parent group dropdown filter ---- */
    var pgFilter = document.getElementById('pgFilter');
    if (pgFilter) {
        pgFilter.addEventListener('change', function() {
            currentParentGroup = this.value;
            refreshGroupPanel();
        });
    }

    /* ---- Search ---- */
    var searchTimer;
    var hotSearch = document.getElementById('hotSearch');
    if (hotSearch) {
        hotSearch.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(refreshGroupPanel, 250);
        });
    }

    /* ---- KPI tile click ---- */
    var wbSummary = document.getElementById('wbSummary');
    if (wbSummary) {
        wbSummary.addEventListener('click', function(e) {
            var card = e.target.closest('.wb-card[data-filter]');
            if (!card) return;
            var filter = card.getAttribute('data-filter');
            if (filter === 'unsaved' || filter === 'tb_impact') return;
            document.querySelectorAll('#wbSummary .wb-card').forEach(function(c) { c.classList.remove('active-tile'); });
            card.classList.add('active-tile');
            currentFilter = filter;
            currentParentGroup = '';
            document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            var target = document.querySelector('#filterChips .filter-chip[data-filter="' + filter + '"]');
            if (target) target.classList.add('active');
            else document.querySelector('#filterChips .filter-chip[data-filter="all"]').classList.add('active');
            refreshGroupPanel();
        });
    }

    /* ---- Mark group dirty on apply ---- */
    window.markGroupDirty = function(pg, scheduleCode) {
        groupDirty[pg] = scheduleCode;
        var unsaved = Object.keys(groupDirty).length;
        var el = document.getElementById('statUnsaved');
        if (el) el.textContent = unsaved;
    };

    /* ---- Group panel apply button handler ---- */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.gm-apply-btn');
        if (!btn) return;
        var pg = btn.getAttribute('data-pg');
        var select = document.querySelector('.gm-select[data-pg="' + pg + '"]');
        if (!select || !select.value) { showToast('Select a Schedule III head first', 'error'); return; }
        var scheduleCode = select.value;
        var count = 0;
        for (var i = 0; i < gridData.length; i++) {
            if (gridData[i].parent_group === pg && (!gridData[i].current_mapping || gridData[i].current_mapping === '')) {
                gridData[i].final_mapping = scheduleCode;
                count++;
            }
        }
        if (count === 0) { showToast('No unmapped ledgers under "' + pg + '"', 'info'); return; }
        markGroupDirty(pg, scheduleCode);
        refreshGroupPanel();
        showToast(count + ' unmapped ledgers under "' + pg + '" mapped to ' + (optionsMap[scheduleCode] || scheduleCode), 'success');
    });

    /* ---- Override & Include button handler ---- */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.gm-override-btn');
        if (!btn) return;
        var pg = btn.getAttribute('data-pg');
        var select = document.querySelector('.gm-select[data-pg="' + pg + '"]');
        if (!select || !select.value) { showToast('Select a Schedule III head first', 'error'); return; }
        var scheduleCode = select.value;
        showOverrideModal(pg, scheduleCode);
    });

    function showOverrideModal(pg, scheduleCode) {
        var existing = document.getElementById('overrideModal');
        if (existing) existing.remove();
        var modal = document.createElement('div');
        modal.id = 'overrideModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        var label = optionsMap[scheduleCode] || scheduleCode;
        var html = '<div style="background:#fff;border-radius:12px;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);">';
        html += '<div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;"><h3 style="margin:0;font-size:1.05rem;color:#1f2937;">&#9998; Manual Override Confirmation</h3></div>';
        html += '<div style="padding:20px 24px;">';
        html += '<p style="font-size:0.88rem;color:#4b5563;margin-bottom:12px;">Group <strong>' + escHtml(pg) + '</strong> will be mapped to <strong>' + escHtml(label) + '</strong> via manual override.</p>';
        html += '<p style="font-size:0.82rem;color:#6b7285;margin-bottom:14px;">This group/ledger has a mapping risk or validation conflict. By overriding, you confirm that the classification has been professionally reviewed and should be included in the financial statements under the selected Schedule III head.</p>';
        html += '<div style="margin-bottom:12px;"><label style="font-size:0.82rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Override Reason / Remarks *</label>';
        html += '<textarea id="overrideReason" rows="3" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85rem;box-sizing:border-box;" placeholder="Describe the professional review and rationale for inclusion..."></textarea></div>';
        html += '<div style="margin-bottom:4px;"><label style="font-size:0.82rem;color:#374151;display:flex;align-items:start;gap:8px;cursor:pointer;"><input type="checkbox" id="overrideConfirm" style="margin-top:3px;"><span>I confirm that this mapping has been reviewed and approved for financial statement inclusion.</span></label></div>';
        html += '</div>';
        html += '<div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">';
        html += '<button onclick="document.getElementById(\'overrideModal\').remove()" style="padding:8px 16px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:0.85rem;background:#fff;">Cancel</button>';
        html += '<button id="overrideConfirmBtn" style="padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;color:#fff;background:#7c3aed;">Confirm Override</button>';
        html += '</div></div>';
        modal.innerHTML = html;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(ev) { if (ev.target === modal) modal.remove(); });
        document.getElementById('overrideConfirmBtn').addEventListener('click', function() {
            var reason = (document.getElementById('overrideReason').value || '').trim();
            var confirmed = document.getElementById('overrideConfirm').checked;
            if (!reason) { showToast('Override reason is required', 'error'); return; }
            if (!confirmed) { showToast('Please confirm the override', 'error'); return; }
            modal.remove();
            applyOverride(pg, scheduleCode, reason);
        });
    }

    function applyOverride(pg, scheduleCode, reason) {
        var count = 0;
        for (var i = 0; i < gridData.length; i++) {
            if (gridData[i].parent_group === pg) {
                gridData[i].final_mapping = scheduleCode;
                gridData[i].override_reason = reason;
                count++;
            }
        }
        markGroupDirty(pg, scheduleCode);
        groupDirty[pg + '::__override'] = reason;
        refreshGroupPanel();
        showToast(count + ' ledgers under "' + pg + '" included by manual override to ' + (optionsMap[scheduleCode] || scheduleCode), 'success');
    }

    /* ---- Group save ---- */
    var btnSave = document.getElementById('btnGroupSave');
    if (btnSave) {
        btnSave.addEventListener('click', function() {
            var dirty = Object.keys(groupDirty);
            if (!dirty.length) { showToast('No changes to save', 'info'); return; }
            var mappings = {};
            var overrides = {};
            var overrideReasons = {};
            dirty.forEach(function(pg) {
                var code = groupDirty[pg];
                var overrideReason = groupDirty[pg + '::__override'] || '';
                for (var i = 0; i < gridData.length; i++) {
                    var r = gridData[i];
                    if (r.parent_group === pg && (!r.current_mapping || r.current_mapping === '')) {
                        mappings[r.ledger_name] = r.final_mapping || code;
                        if (overrideReason || r.override_reason) {
                            overrides[r.ledger_name] = 1;
                            overrideReasons[r.ledger_name] = overrideReason || r.override_reason || '';
                        }
                    }
                }
            });
            if (!Object.keys(mappings).length) { showToast('No unmapped ledgers to save', 'info'); return; }
            document.getElementById('statusText').textContent = 'Saving ' + Object.keys(mappings).length + ' mappings...';
            btnSave.disabled = true;
            fetch(ebalBaseUrl + 'data_console/ajax_mapping_save.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({mappings: mappings, overrides: overrides, override_reasons: overrideReasons, remember: {}, remarks: {}}),
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                btnSave.disabled = false;
                btnSave.textContent = '\uD83D\uDCBE Save Changes';
                if (resp.redirect) {
                    showToast(resp.error || 'Session expired', 'error');
                    setTimeout(function() { window.location.href = ebalBaseUrl + 'login.php'; }, 1500);
                    return;
                }
                if (resp.success) {
                    var savedCount = resp.saved || 0;
                    var conflictCount = resp.conflicts || 0;
                    var errorCount = (resp.errors || []).length;
                    groupDirty = {};
                    var el = document.getElementById('statUnsaved');
                    if (el) el.textContent = '0';
                    var msg = savedCount + ' mappings saved.';
                    if (conflictCount > 0) {
                        msg += ' ' + conflictCount + ' skipped (parent group conflict).';
                        var details = (resp.conflict_details || []).map(function(c) {
                            return c.ledger_name + ': ' + c.parent_group + ' \u2194 ' + c.schedule_code;
                        }).join(', ');
                        if (details) msg += ' ' + details;
                    }
                    if (errorCount > 0) {
                        msg += ' ' + errorCount + ' error(s): ' + (resp.errors || []).join('; ');
                    }
                    if (resp.pending > 0) msg += ' ' + resp.pending + ' remaining.';
                    if (resp.mapping_complete) msg += ' All ledgers mapped!';
                    document.getElementById('statusText').textContent = 'Saved ' + savedCount + (conflictCount > 0 ? ', ' + conflictCount + ' conflicts' : '') + ' rows';
                    showToast(msg, savedCount > 0 ? 'success' : (conflictCount > 0 ? 'info' : 'error'));
                    setTimeout(function() { window.location.reload(); }, conflictCount > 0 ? 3000 : 1500);
                } else {
                    document.getElementById('statusText').textContent = 'Save failed';
                    showToast(resp.error || 'Save failed', 'error');
                }
            })
            .catch(function() {
                btnSave.disabled = false;
                document.getElementById('statusText').textContent = 'Network error';
                showToast('Network error. Please try again.', 'error');
            });
        });
    }

    /* ---- Group reset ---- */
    var btnReset = document.getElementById('btnGroupReset');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            groupDirty = {};
            for (var i = 0; i < gridData.length; i++) {
                gridData[i].final_mapping = '';
            }
            var el = document.getElementById('statUnsaved');
            if (el) el.textContent = '0';
            refreshGroupPanel();
            showToast('All changes reset', 'info');
        });
    }

    /* ---- Init ---- */
    populateParentGroupDropdown();
    refreshGroupPanel();

})();
</script>
<?php endif; /* $isGroupMode */ ?>

<?php
unset($_SESSION['success'], $_SESSION['error']);
require_once __DIR__ . '/../layouts/footer_v2.php';
?>
