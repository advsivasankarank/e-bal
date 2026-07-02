<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/reconciliation_engine.php';
require_once __DIR__ . '/../app/engines/ai_mapping_engine.php';
require_once __DIR__ . '/../app/helpers/schedule3_master_helper.php';
require_once __DIR__ . '/../app/helpers/figure_helper.php';
require_once __DIR__ . '/../app/workflow_engine.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function formatMoney($value): string
{
    return format_inr_number((float) $value);
}

function findBreakdownBlock(array $response, string $type): ?array
{
    foreach (($response['difference_breakdown'] ?? []) as $item) {
        if (($item['type'] ?? '') === $type) {
            return $item;
        }
    }

    return null;
}

function buildReconLink(int $companyId, int $fyId, string $detail): string
{
    return '?company_id=' . $companyId . '&fy_id=' . $fyId . '&detail=' . urlencode($detail);
}

function buildReconciliationNoteMap(string $companyCategory, AIMappingEngine $mappingEngine): array
{
    $noteMap = [];

    if ($companyCategory === 'corporate') {
        $master = getSchedule3NotesMaster();
        $codeMap = schedule3MasterCodeToScheduleCodes();

        foreach ($master as $noteNo => $meta) {
            $masterCode = (string) ($meta['code'] ?? '');
            foreach ($codeMap[$masterCode] ?? [] as $scheduleCode) {
                $noteMap[$scheduleCode] = 'Note ' . $noteNo . ' - ' . (string) ($meta['title'] ?? $mappingEngine->getLabel($scheduleCode));
            }
        }
    }

    return $noteMap;
}

/* HARDENED: Always use session context. GET params removed to prevent IDOR. */
$detailView = trim((string) ($_GET['detail'] ?? ''));
$response = null;
$companyCategory = '';
$noteDisplayMap = [];
$mappingEngine = new AIMappingEngine('corporate');
$nextProcessLabel = 'Go to Report Dashboard';
$nextProcessUrl = BASE_URL . 'dashboard_report.php';
$nextProcessHelp = 'Open the reporting dashboard to continue the report preparation workflow.';

requireAssignmentAccess();
$companyId = (int) ($_SESSION['company_id'] ?? 0);
$fyId = (int) ($_SESSION['fy_id'] ?? 0);

if ($companyId > 0) {
    try {
        $categoryStmt = $pdo->prepare("SELECT LOWER(TRIM(category)) FROM companies WHERE id = ?");
        $categoryStmt->execute([$companyId]);
        $companyCategory = strtolower(str_replace(['-', ' '], '_', (string) $categoryStmt->fetchColumn()));
        $mappingEngine = new AIMappingEngine($companyCategory);
        $noteDisplayMap = buildReconciliationNoteMap($companyCategory, $mappingEngine);
        $response = runBalanceSheetValidation($pdo, $companyId, $fyId);

        $workflow = getWorkflow($companyId, $fyId);
        $notesPrepared = (int) ($workflow['notes_prepared'] ?? 0) === 1;
        $profitLossPrepared = (int) ($workflow['profit_loss_prepared'] ?? 0) === 1;
        $balanceSheetPrepared = (int) ($workflow['balance_sheet_prepared'] ?? 0) === 1;
        $directorsReportPrepared = (int) ($workflow['directors_report_prepared'] ?? 0) === 1;
        $isReconciled = (($response['status'] ?? 'NOT TALLY') === 'TALLY');

        if (!$isReconciled) {
            $nextProcessLabel = 'Review Trial Balance';
            $nextProcessUrl = BASE_URL . 'data_console/trial_balance_preview.php';
            $nextProcessHelp = 'Reconciliation difference still exists. Review the trial balance note tagging and correct the affected ledgers before moving ahead.';
        } elseif (!$notesPrepared || !$profitLossPrepared || !$balanceSheetPrepared) {
            $nextProcessLabel = 'Continue to Financial Statements';
            $nextProcessUrl = BASE_URL . 'reports.php#balance-sheet';
            $nextProcessHelp = 'Reconciliation is clear. Continue to the financial statements to prepare notes, profit and loss account, and balance sheet.';
        } elseif ($companyCategory === 'corporate' && !$directorsReportPrepared) {
            $nextProcessLabel = 'Continue to Directors Report';
            $nextProcessUrl = BASE_URL . 'directors_report.php';
            $nextProcessHelp = 'Financial statements are ready. The next workflow step is to prepare the Directors Report for the corporate entity.';
        } else {
            $nextProcessLabel = 'Go to Report Dashboard';
            $nextProcessUrl = BASE_URL . 'dashboard_report.php';
            $nextProcessHelp = 'This workflow stage is already complete. You can continue from the reporting dashboard or revisit any report section as needed.';
        }
    } catch (Throwable $e) {
        $response = [
            'trial_balance_status' => 'ERROR',
            'unmapped_ledgers' => [],
            'profit' => 0.0,
            'assets' => 0.0,
            'liabilities' => 0.0,
            'capital' => 0.0,
            'difference' => 0.0,
            'difference_breakdown' => [[
                'type' => 'exception',
                'message' => $e->getMessage(),
            ]],
            'status' => 'NOT TALLY',
        ];
    }
}

if (isset($_GET['format']) && strtolower((string) $_GET['format']) === 'json') {
    if ($companyId <= 0) {
        jsonResponse([
            'trial_balance_status' => 'ERROR',
            'unmapped_ledgers' => [],
            'profit' => 0.0,
            'assets' => 0.0,
            'liabilities' => 0.0,
            'capital' => 0.0,
            'difference' => 0.0,
            'difference_breakdown' => [[
                'type' => 'input',
                'message' => 'company_id is required',
            ]],
            'status' => 'NOT TALLY',
        ], 422);
    }

    jsonResponse($response ?? []);
}

$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ---- Compute summary metrics for dashboard ---- */
$bridgeBlock = $response !== null ? findBreakdownBlock($response, 'reconciliation_bridge') : null;
$profitBlock = $response !== null ? findBreakdownBlock($response, 'profit_computation') : null;
$balanceBlock = $response !== null ? findBreakdownBlock($response, 'balance_sheet_build') : null;
$unmappedCount = count($response['unmapped_ledgers'] ?? []);
$conflictCount = (int) ($bridgeBlock['status_counts']['parent_group_conflict'] ?? 0);
$tbOk = ($response['trial_balance_status'] ?? '') === 'OK';
$diff = (float) ($response['difference'] ?? 0);
$isReconciled = ($response['status'] ?? 'NOT TALLY') === 'TALLY';

/* ---- Mapping Risk Detection ---- */
$riskItems = [];
if ($bridgeBlock !== null) {
    $plVariants = ['profit & loss a/c', 'profit and loss a/c', 'profit & loss account', 'profit and loss account', 'p&l a/c', 'p and l a/c', 'surplus in statement of profit and loss'];
    $bankOdVariants = ['bank od', 'od account', 'overdraft', 'cash credit', 'bank overdraft', 'current account'];
    $patientAdvanceVariants = ['advance in patient', 'patient advance'];

    foreach ($bridgeBlock['items'] ?? [] as $row) {
        $ledgerName = $row['ledger_name'] ?? '';
        $parentGroup = $row['parent_group'] ?? '';
        $mappedNature = $row['mapped_nature'] ?? null;
        $mappedHead = $row['mapped_head'] ?? '';
        $mappedCode = $row['mapped_code'] ?? '';
        $drCr = $row['dr_cr'] ?? '';
        $absAmt = abs((float) ($row['amount'] ?? 0));

        if ($mappedNature === 'asset' && $drCr === 'CR' && $absAmt > 0) {
            $riskItems[] = ['ledger_name' => $ledgerName, 'parent_group' => $parentGroup, 'mapped_head' => $mappedCode, 'balance_nature' => 'Credit in Asset', 'risk_reason' => 'Credit balance in asset schedule. Should be reviewed.', 'risk_type' => 'credit_in_asset'];
        }
        if ($mappedNature === 'liability' && $drCr === 'DR' && $absAmt > 0) {
            $riskItems[] = ['ledger_name' => $ledgerName, 'parent_group' => $parentGroup, 'mapped_head' => $mappedCode, 'balance_nature' => 'Debit in Liability', 'risk_reason' => 'Debit balance in liability/equity schedule. Should be reviewed.', 'risk_type' => 'debit_in_liability'];
        }
        if (in_array(strtolower($parentGroup), $bankOdVariants) && $mappedHead === 'asset') {
            $riskItems[] = ['ledger_name' => $ledgerName, 'parent_group' => $parentGroup, 'mapped_head' => $mappedCode, 'balance_nature' => $drCr, 'risk_reason' => 'Bank OD/Cash Credit mapped to Cash. Should be Short-Term Borrowings.', 'risk_type' => 'bank_od_cash'];
        }
        if (in_array(strtolower($parentGroup), $patientAdvanceVariants) && $mappedHead === 'asset') {
            $riskItems[] = ['ledger_name' => $ledgerName, 'parent_group' => $parentGroup, 'mapped_head' => $mappedCode, 'balance_nature' => $drCr, 'risk_reason' => 'Patient advance mapped to Receivables. Credit balance is a liability.', 'risk_type' => 'patient_advance'];
        }
        if (strtolower($parentGroup) === 'insurance patient' && $mappedHead === 'asset') {
            $riskItems[] = ['ledger_name' => $ledgerName, 'parent_group' => $parentGroup, 'mapped_head' => $mappedCode, 'balance_nature' => $drCr, 'risk_reason' => 'Insurance patient mapped to Receivables. Credit balance is a liability.', 'risk_type' => 'insurance_patient'];
        }
        if (in_array(strtolower($parentGroup), $plVariants) && $mappedHead !== 'capital') {
            $riskItems[] = ['ledger_name' => $ledgerName, 'parent_group' => $parentGroup, 'mapped_head' => $mappedCode, 'balance_nature' => $drCr, 'risk_reason' => 'P&L A/c should map to Reserves. Current: ' . ($mappedCode ?: 'Unmapped'), 'risk_type' => 'pl_not_reserves'];
        }
    }
}

/* ---- Group vs Schedule Mismatch Detection ---- */
$mismatchItems = [];
if ($bridgeBlock !== null) {
    foreach ($bridgeBlock['items'] ?? [] as $row) {
        $pgNature = $row['parent_group_nature'] ?? null;
        $mapNature = $row['mapped_nature'] ?? null;
        if ($pgNature === null || $mapNature === null || $pgNature === $mapNature) {
            continue;
        }
        $mismatchItems[] = [
            'ledger_name' => $row['ledger_name'] ?? '',
            'parent_group' => $row['parent_group'] ?? '',
            'parent_group_nature' => $pgNature,
            'mapped_code' => $row['mapped_code'] ?? '',
            'mapped_nature' => $mapNature,
            'mismatch_reason' => 'Tally group "' . ($row['parent_group'] ?? '') . '" is ' . strtoupper($pgNature) . ' nature, but mapped to ' . strtoupper($mapNature) . ' schedule.',
        ];
    }
}

/* ---- Sidebar counts ---- */
$errorCount = $tbOk ? 0 : 1;
$warningCount = 0;
$passCount = 0;
$errorCount += $conflictCount;
if ($unmappedCount > 0) { $warningCount++; }
if (!$isReconciled) { $errorCount++; }
if ($tbOk) { $passCount++; }
if ($isReconciled) { $passCount++; }
foreach ($response['difference_breakdown'] ?? [] as $item) {
    if (in_array($item['type'] ?? '', ['unmapped_ledgers', 'duplicate_mapping', 'excluded_ledgers', 'mapping_gap'], true)) {
        $warningCount++;
    }
}
$warningCount += count($riskItems);
$warningCount += count($mismatchItems);
$infoCount = ($unmappedCount > 0 || $conflictCount > 0 || count($riskItems) > 0) ? 0 : 1;

$page_title = 'Balance Sheet Reconciliation Console';
$showSidebar = true;
require_once __DIR__ . '/layouts/header_v2.php';
?>
<style>
/* ============================================================
   RECONCILIATION CONSOLE — DASHBOARD REFINEMENT
   ============================================================ */
:root {
    --rc-brand: #0f4c81; --rc-success: #16a34a; --rc-warning: #d97706; --rc-danger: #dc2626;
    --rc-bg: #f1f5f9; --rc-panel: #ffffff; --rc-border: #e2e8f0;
    --rc-text: #0f172a; --rc-muted: #64748b; --rc-radius: 10px;
}

/* ---- STATUS BANNER ---- */
.rc-banner {
    display: flex; align-items: center; gap: 16px;
    padding: 16px 24px; border-radius: 12px; margin-bottom: 16px;
    font-size: 0.92rem; font-weight: 600;
}
.rc-banner.reconciled { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
.rc-banner.differ { background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; }
.rc-banner.error { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }
.rc-banner .rc-banner-icon { font-size: 1.6rem; flex-shrink: 0; }
.rc-banner .rc-banner-body { flex: 1; }
.rc-banner .rc-banner-title { font-size: 1.05rem; font-weight: 700; margin-bottom: 2px; }
.rc-banner .rc-banner-sub { font-size: 0.8rem; font-weight: 400; opacity: 0.85; }
.rc-banner .rc-banner-actions { display: flex; gap: 8px; flex-shrink: 0; }
.rc-banner .rc-banner-actions .btn { font-size: 0.78rem; padding: 6px 14px; }

/* ---- KPI STRIP ---- */
.rc-kpi-strip {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 10px; margin-bottom: 16px;
}
.rc-kpi {
    background: var(--rc-panel); border: 1px solid var(--rc-border); border-radius: 10px;
    padding: 12px 14px; text-align: center;
}
.rc-kpi .rc-kpi-val { font-size: 1.3rem; font-weight: 700; line-height: 1.2; }
.rc-kpi .rc-kpi-lbl { font-size: 0.7rem; color: var(--rc-muted); margin-top: 2px; text-transform: uppercase; letter-spacing: 0.03em; }

/* ---- TABS ---- */
.rc-tabs {
    display: flex; gap: 0; border-bottom: 2px solid var(--rc-border); margin-bottom: 16px;
    overflow-x: auto; -webkit-overflow-scrolling: touch;
}
.rc-tab {
    padding: 10px 18px; font-size: 0.82rem; font-weight: 600; color: var(--rc-muted);
    background: none; border: none; border-bottom: 2px solid transparent;
    margin-bottom: -2px; cursor: pointer; white-space: nowrap; transition: all 0.15s;
}
.rc-tab:hover { color: var(--rc-brand); background: #f8fafc; }
.rc-tab.active { color: var(--rc-brand); border-bottom-color: var(--rc-brand); }
.rc-tab-content { display: none; }
.rc-tab-content.active { display: block; }

/* ---- SECTION CARDS ---- */
.rc-card {
    background: var(--rc-panel); border: 1px solid var(--rc-border);
    border-radius: 12px; padding: 18px 20px; margin-bottom: 14px;
}
.rc-card-header {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
}
.rc-card-header h3 { margin: 0; font-size: 0.92rem; }
.rc-card-header .rc-badge {
    font-size: 0.7rem; padding: 3px 10px; border-radius: 999px; font-weight: 600;
}
.rc-badge.ok { background: #dcfce7; color: #166534; }
.rc-badge.warn { background: #fef3c7; color: #92400e; }
.rc-badge.err { background: #fee2e2; color: #991b1b; }

/* ---- NEXT ACTION ---- */
.rc-next-action {
    display: flex; justify-content: space-between; align-items: center; gap: 16px;
    flex-wrap: wrap; background: linear-gradient(135deg, #f0f7ff 0%, #f8fafc 100%);
    border: 1px solid #bfdbfe; border-radius: 12px; padding: 18px 22px; margin-bottom: 16px;
}
.rc-next-action .rc-next-info { flex: 1; min-width: 200px; }
.rc-next-action .rc-next-info .rc-next-label { font-size: 0.78rem; color: var(--rc-muted); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
.rc-next-action .rc-next-info .rc-next-help { font-size: 0.85rem; color: var(--rc-text); }
.rc-next-action .rc-next-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.rc-next-action .rc-next-btns .btn { font-size: 0.8rem; padding: 8px 18px; }
.rc-next-action .rc-next-btns .btn-primary { background: var(--rc-brand); color: #fff; border-color: var(--rc-brand); }
.rc-next-action .rc-next-btns .btn-outline { background: #fff; color: var(--rc-brand); border: 1px solid var(--rc-border); }

/* ---- EXCEPTION TABLE ---- */
.rc-table-wrap { overflow-x: auto; }
.rc-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
.rc-table th { background: #f3f6fb; text-align: left; padding: 8px 10px; font-weight: 600; color: #17324d; border-bottom: 2px solid var(--rc-border); position: sticky; top: 0; z-index: 1; }
.rc-table td { padding: 7px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.rc-table tr:hover { background: #f8fafc; }
.rc-table .num { text-align: right; white-space: nowrap; }
.rc-table tr.rc-row-exception { background: #fffbeb; }
.rc-table tr.rc-row-exception:hover { background: #fef3c7; }

/* ---- MISMATCH / RISK BADGES ---- */
.rc-risk-badge {
    display: inline-block; font-size: 0.68rem; padding: 2px 8px; border-radius: 4px; font-weight: 600;
}
.rc-risk-badge.mismatch { background: #fef3c7; color: #92400e; }
.rc-risk-badge.risky { background: #fee2e2; color: #991b1b; }

/* ---- PAGINATION ---- */
.rc-pagination {
    display: flex; align-items: center; gap: 12px; padding: 10px 0; font-size: 0.8rem; flex-wrap: wrap;
}
.rc-pagination select { padding: 5px 8px; border: 1px solid var(--rc-border); border-radius: 6px; font-size: 0.8rem; }
.rc-pagination button { padding: 5px 12px; border: 1px solid var(--rc-border); border-radius: 6px; background: #fff; cursor: pointer; font-size: 0.8rem; }
.rc-pagination button:hover { background: #f1f5f9; }
.rc-pagination button:disabled { opacity: 0.4; cursor: default; }
.rc-pagination .rc-page-info { color: var(--rc-muted); }

/* ---- FILTER BAR ---- */
.rc-filter-bar {
    display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;
}
.rc-filter-bar input, .rc-filter-bar select {
    padding: 6px 10px; border: 1px solid var(--rc-border); border-radius: 6px; font-size: 0.8rem;
}
.rc-filter-bar input { min-width: 180px; }
.rc-filter-bar .rc-filter-chip {
    padding: 5px 12px; border: 1px solid var(--rc-border); border-radius: 999px;
    font-size: 0.72rem; background: #fff; cursor: pointer; font-weight: 500; transition: all 0.15s;
}
.rc-filter-bar .rc-filter-chip:hover { border-color: var(--rc-brand); color: var(--rc-brand); }
.rc-filter-bar .rc-filter-chip.active { background: var(--rc-brand); color: #fff; border-color: var(--rc-brand); }

/* ---- BRIDGE SUMMARY ---- */
.rc-bridge-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px;
}
.rc-bridge-bucket {
    background: #f9fafb; border: 1px solid var(--rc-border); border-radius: 8px; padding: 12px; text-align: center;
}
.rc-bridge-bucket .rc-bucket-val { font-size: 1.1rem; font-weight: 700; }
.rc-bridge-bucket .rc-bucket-lbl { font-size: 0.7rem; color: var(--rc-muted); margin-top: 2px; }

/* ---- INTELAI ---- */
.rc-intelai {
    display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;
}
.rc-intelai .btn { font-size: 0.76rem; padding: 6px 14px; border-radius: 8px; background: #f0f7ff; color: var(--rc-brand); border: 1px solid #bfdbfe; cursor: pointer; font-weight: 500; }
.rc-intelai .btn:hover { background: #e0efff; }

/* ---- COLLAPSIBLE ---- */
.rc-collapse-toggle {
    display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.82rem;
    font-weight: 600; color: var(--rc-brand); padding: 8px 0; user-select: none;
}
.rc-collapse-toggle .rc-arrow { transition: transform 0.2s; font-size: 0.7rem; }
.rc-collapse-toggle.open .rc-arrow { transform: rotate(90deg); }
.rc-collapse-body { display: none; }
.rc-collapse-body.open { display: block; }

/* ---- MAPPING RISK SECTION ---- */
.rc-risk-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 10px;
}
.rc-risk-item {
    display: flex; align-items: start; gap: 10px; padding: 10px 12px;
    border: 1px solid var(--rc-border); border-radius: 8px; font-size: 0.8rem;
}
.rc-risk-item .rc-risk-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
.rc-risk-item .rc-risk-info { flex: 1; }
.rc-risk-item .rc-risk-ledger { font-weight: 600; }
.rc-risk-item .rc-risk-group { font-size: 0.72rem; color: var(--rc-muted); }
.rc-risk-item .rc-risk-reason { font-size: 0.75rem; color: #92400e; margin-top: 3px; }
.rc-risk-item .rc-risk-action { font-size: 0.72rem; margin-top: 4px; }
.rc-risk-item .rc-risk-action a { color: var(--rc-brand); text-decoration: none; font-weight: 600; }
.rc-risk-item .rc-risk-action a:hover { text-decoration: underline; }

/* ---- LAYOUT ---- */
.rc-layout { display: flex; gap: 0; margin: 0 -28px; }
.rc-main { flex: 1; min-width: 0; padding: 0 28px; }
.validation-sidebar { width: 340px; border-left: 1px solid var(--rc-border); background: var(--rc-panel); display: flex; flex-direction: column; overflow: hidden; flex-shrink: 0; }
.vs-header { padding: 16px 18px; border-bottom: 1px solid var(--rc-border); display: flex; justify-content: space-between; align-items: center; }
.vs-header h3 { font-size: 0.95rem; margin: 0; }
.vs-header .action { font-size: 0.8rem; color: var(--rc-brand); cursor: pointer; font-weight: 600; }
.vs-summary { display: flex; gap: 0; border-bottom: 1px solid var(--rc-border); }
.vs-summary-item { flex: 1; text-align: center; padding: 14px 8px; cursor: pointer; transition: background 0.1s; }
.vs-summary-item:hover { background: var(--rc-bg); }
.vs-summary-item.active { background: var(--rc-bg); border-bottom: 2px solid var(--rc-brand); }
.vs-summary-item .count { font-size: 1.4rem; font-weight: 700; }
.vs-summary-item .label { font-size: 0.7rem; color: var(--rc-muted); margin-top: 2px; }
.vs-summary-item.error .count { color: var(--rc-danger); }
.vs-summary-item.warning .count { color: var(--rc-warning); }
.vs-summary-item.success .count { color: var(--rc-success); }
.vs-summary-item.info .count { color: #2563eb; }
.vs-body { flex: 1; overflow-y: auto; padding: 12px 16px; }
.v-cat { margin-bottom: 8px; border: 1px solid var(--rc-border); border-radius: 10px; overflow: hidden; }
.v-cat-header { display: flex; align-items: center; gap: 10px; padding: 10px 12px; cursor: pointer; font-size: 0.85rem; font-weight: 500; background: #fafbfc; border-bottom: 1px solid transparent; transition: background 0.1s; user-select: none; }
.v-cat-header:hover { background: var(--rc-bg); }
.v-cat.open .v-cat-header { border-bottom-color: var(--rc-border); }
.v-cat-header .arrow { margin-left: auto; font-size: 0.7rem; color: var(--rc-muted); transition: transform 0.2s; }
.v-cat.open .v-cat-header .arrow { transform: rotate(90deg); }
.v-cat-header .cat-icon { flex-shrink: 0; }
.v-cat-body { display: none; padding: 8px 12px; }
.v-cat.open .v-cat-body { display: block; }
.issue-item { display: flex; align-items: start; gap: 8px; padding: 8px 6px; border-radius: 6px; cursor: pointer; font-size: 0.82rem; transition: background 0.1s; }
.issue-item:hover { background: var(--rc-bg); }
.issue-item .dot { width: 6px; height: 6px; border-radius: 50%; margin-top: 5px; flex-shrink: 0; }
.issue-item .dot.error { background: var(--rc-danger); }
.issue-item .dot.warning { background: var(--rc-warning); }
.issue-item .dot.success { background: var(--rc-success); }
.issue-item .dot.info { background: #2563eb; }
.issue-item .issue-text { flex: 1; line-height: 1.4; }
.issue-item .issue-text .issue-path { display: block; font-size: 0.7rem; color: var(--rc-brand); margin-top: 2px; }

/* ---- BOTTOM BAR ---- */
.rc-bottom-bar { border-top: 1px solid var(--rc-border); background: var(--rc-panel); display: flex; align-items: center; gap: 20px; padding: 0 28px; height: 44px; font-size: 0.82rem; flex-shrink: 0; margin-top: 18px; }
.rc-bottom-bar .b-label { font-weight: 600; color: var(--rc-muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
.rc-bottom-bar .b-item { display: flex; align-items: center; gap: 5px; }
.rc-bottom-bar .b-dot { width: 7px; height: 7px; border-radius: 50%; }
.rc-bottom-bar .b-dot.error { background: var(--rc-danger); }
.rc-bottom-bar .b-dot.warning { background: var(--rc-warning); }
.rc-bottom-bar .b-dot.success { background: var(--rc-success); }

@media (max-width: 1100px) { .validation-sidebar { display: none; } .rc-layout { margin: 0; } .rc-main { padding: 0; } }
@media (max-width: 768px) { .rc-kpi-strip { grid-template-columns: repeat(3, 1fr); } .rc-next-action { flex-direction: column; align-items: stretch; } }
</style>

<?= uiBreadcrumb([
    ['label' => 'Data', 'href' => BASE_URL . 'dashboard_data.php'],
    ['label' => 'Reconciliation Console']
]) ?>

<?= uiPageHero('Balance Sheet Reconciliation Console') ?>

<?= uiContextCard([
    'company' => $_SESSION['company_name'] ?? 'Not Selected',
    'fy'      => $_SESSION['fy_name'] ?? 'Not Selected',
]) ?>

<?php if ($response !== null): ?>
<!-- ============================================================
     STATUS BANNER
     ============================================================ -->
<?php if ($isReconciled && $unmappedCount === 0 && $conflictCount === 0): ?>
<div class="rc-banner reconciled">
    <div class="rc-banner-icon">&#9989;</div>
    <div class="rc-banner-body">
        <div class="rc-banner-title">Balance Sheet Reconciled</div>
        <div class="rc-banner-sub">
            Difference <?= formatMoney($diff) ?> &middot;
            Trial Balance <?= $tbOk ? 'OK' : 'ERROR' ?> &middot;
            Final Status <?= htmlspecialchars($response['status']) ?> &middot;
            Unmapped <?= $unmappedCount ?> &middot;
            Conflicts <?= $conflictCount ?>
        </div>
    </div>
    <div class="rc-banner-actions">
        <a class="btn btn-outline" href="<?= htmlspecialchars(BASE_URL . 'reports.php#balance-sheet') ?>">Open Financial Statements</a>
    </div>
</div>
<?php elseif (!$isReconciled || $diff != 0): ?>
<div class="rc-banner differ">
    <div class="rc-banner-icon">&#9888;&#65039;</div>
    <div class="rc-banner-body">
        <div class="rc-banner-title">Reconciliation Difference Found</div>
        <div class="rc-banner-sub">
            Difference <?= formatMoney($diff) ?> &middot;
            Unmapped <?= $unmappedCount ?> &middot;
            Conflicts <?= $conflictCount ?> &middot;
            Risk <?= count($riskItems) ?> &middot;
            Mismatch <?= count($mismatchItems) ?>
        </div>
    </div>
    <div class="rc-banner-actions">
        <a class="btn" href="<?= htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php') ?>" style="background:var(--rc-brand);color:#fff;border-color:var(--rc-brand);">Review Mapping / Open ReconHub</a>
    </div>
</div>
<?php else: ?>
<div class="rc-banner error">
    <div class="rc-banner-icon">&#10060;</div>
    <div class="rc-banner-body">
        <div class="rc-banner-title">Reconciliation Error</div>
        <div class="rc-banner-sub">Trial balance or data issue detected. Review below.</div>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     KPI SUMMARY STRIP
     ============================================================ -->
<div class="rc-kpi-strip">
    <div class="rc-kpi">
        <div class="rc-kpi-val" style="color:<?= $tbOk ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= htmlspecialchars($response['trial_balance_status']) ?></div>
        <div class="rc-kpi-lbl">Trial Balance</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val" style="color:<?= abs($diff) < 0.01 ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= formatMoney($diff) ?></div>
        <div class="rc-kpi-lbl">Difference</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val"><?= formatMoney($response['profit']) ?></div>
        <div class="rc-kpi-lbl">Profit / Loss</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val"><?= formatMoney($response['assets']) ?></div>
        <div class="rc-kpi-lbl">Assets</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val"><?= formatMoney($response['liabilities']) ?></div>
        <div class="rc-kpi-lbl">Liabilities</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val"><?= formatMoney($response['capital']) ?></div>
        <div class="rc-kpi-lbl">Capital</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val" style="color:<?= $unmappedCount > 0 ? 'var(--rc-danger)' : 'var(--rc-success)' ?>"><?= $unmappedCount ?></div>
        <div class="rc-kpi-lbl">Unmapped</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val" style="color:<?= $conflictCount > 0 ? 'var(--rc-danger)' : 'var(--rc-success)' ?>"><?= $conflictCount ?></div>
        <div class="rc-kpi-lbl">Conflicts</div>
    </div>
    <div class="rc-kpi">
        <div class="rc-kpi-val" style="color:<?= count($riskItems) > 0 ? 'var(--rc-warning)' : 'var(--rc-success)' ?>"><?= count($riskItems) ?></div>
        <div class="rc-kpi-lbl">Risk</div>
    </div>
</div>

<!-- ============================================================
     NEXT ACTION CARD
     ============================================================ -->
<div class="rc-next-action">
    <div class="rc-next-info">
        <div class="rc-next-label">Next Action</div>
        <div class="rc-next-help"><?= htmlspecialchars($nextProcessHelp) ?></div>
    </div>
    <div class="rc-next-btns">
        <a class="btn btn-primary" href="<?= htmlspecialchars($nextProcessUrl) ?>"><?= htmlspecialchars($nextProcessLabel) ?></a>
        <a class="btn btn-outline" href="<?= htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php') ?>">Open ReconHub</a>
        <a class="btn btn-outline" href="<?= htmlspecialchars(BASE_URL . 'review_centre.php') ?>">Review Centre</a>
        <a class="btn btn-outline" href="<?= htmlspecialchars(buildReconLink($companyId, $fyId, '')) ?>&format=json" target="_blank">Export Report</a>
    </div>
</div>
<?php endif; ?>

<!-- ============================================================
     MAIN LAYOUT
     ============================================================ -->
<div class="rc-layout">
    <div class="rc-main">
        <?php if ($response !== null): ?>
        <!-- TABS NAVIGATION -->
        <div class="rc-tabs" id="rcTabs">
            <button class="rc-tab active" data-tab="overview">Overview</button>
            <button class="rc-tab" data-tab="mapping">Mapping Health</button>
            <button class="rc-tab" data-tab="profit">Profit Check</button>
            <button class="rc-tab" data-tab="bsheet">Balance Sheet</button>
            <button class="rc-tab" data-tab="bridge">Reconciliation Bridge</button>
            <button class="rc-tab" data-tab="ledger">Ledger Details</button>
        </div>

        <!-- ============================================================
             TAB: OVERVIEW
             ============================================================ -->
        <div class="rc-tab-content active" id="tab-overview">
            <!-- Trial Balance Summary -->
            <div class="rc-card">
                <div class="rc-card-header">
                    <h3>Trial Balance Summary</h3>
                    <span class="rc-badge <?= $tbOk ? 'ok' : 'err' ?>"><?= $tbOk ? 'Passed' : 'Failed' ?></span>
                </div>
                <div class="rc-kpi-strip" style="margin-bottom:0;">
                    <div class="rc-kpi">
                        <div class="rc-kpi-val" style="color:<?= $tbOk ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= htmlspecialchars($response['trial_balance_status']) ?></div>
                        <div class="rc-kpi-lbl">Status</div>
                    </div>
                    <div class="rc-kpi">
                        <div class="rc-kpi-val" style="color:<?= $isReconciled ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= formatMoney($diff) ?></div>
                        <div class="rc-kpi-lbl">Difference</div>
                    </div>
                    <div class="rc-kpi">
                        <div class="rc-kpi-val" style="color:<?= $isReconciled ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= htmlspecialchars($response['status']) ?></div>
                        <div class="rc-kpi-lbl">Final Status</div>
                    </div>
                </div>
            </div>

            <!-- Mapping Health Quick View -->
            <div class="rc-card">
                <div class="rc-card-header">
                    <h3>Mapping Health</h3>
                    <span class="rc-badge <?= ($unmappedCount + $conflictCount + count($riskItems) + count($mismatchItems)) === 0 ? 'ok' : 'warn' ?>">
                        <?= ($unmappedCount + $conflictCount + count($riskItems) + count($mismatchItems)) === 0 ? 'All Clear' : ($unmappedCount + $conflictCount + count($riskItems) + count($mismatchItems)) . ' Issues' ?>
                    </span>
                </div>
                <div class="rc-kpi-strip" style="margin-bottom:0;">
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= $unmappedCount > 0 ? 'var(--rc-danger)' : 'var(--rc-success)' ?>"><?= $unmappedCount ?></div><div class="rc-kpi-lbl">Unmapped</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= $conflictCount > 0 ? 'var(--rc-danger)' : 'var(--rc-success)' ?>"><?= $conflictCount ?></div><div class="rc-kpi-lbl">Conflicts</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= count($riskItems) > 0 ? 'var(--rc-warning)' : 'var(--rc-success)' ?>"><?= count($riskItems) ?></div><div class="rc-kpi-lbl">Risky Mappings</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= count($mismatchItems) > 0 ? 'var(--rc-warning)' : 'var(--rc-success)' ?>"><?= count($mismatchItems) ?></div><div class="rc-kpi-lbl">Group–Schedule Mismatch</div></div>
                </div>
            </div>

            <!-- Profit Quick View -->
            <div class="rc-card">
                <div class="rc-card-header"><h3>Profit / Loss</h3></div>
                <div class="rc-kpi-strip" style="margin-bottom:0;">
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($profitBlock['income'] ?? 0) ?></div><div class="rc-kpi-lbl">Income</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($profitBlock['expense'] ?? 0) ?></div><div class="rc-kpi-lbl">Expense</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= ($response['profit'] ?? 0) >= 0 ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= formatMoney($response['profit']) ?></div><div class="rc-kpi-lbl">Net Profit</div></div>
                </div>
            </div>

            <!-- BS Quick View -->
            <div class="rc-card">
                <div class="rc-card-header"><h3>Balance Sheet</h3></div>
                <div class="rc-kpi-strip" style="margin-bottom:0;">
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($response['assets']) ?></div><div class="rc-kpi-lbl">Assets</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($response['liabilities']) ?></div><div class="rc-kpi-lbl">Liabilities</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($response['capital']) ?></div><div class="rc-kpi-lbl">Capital</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= $isReconciled ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= formatMoney($diff) ?></div><div class="rc-kpi-lbl">Difference</div></div>
                </div>
            </div>

            <!-- IntelAI -->
            <div class="rc-card">
                <div class="rc-card-header"><h3>e-BAL IntelAI</h3></div>
                <div style="font-size:0.82rem;color:var(--rc-muted);margin-bottom:8px;">Ask IntelAI to explain your reconciliation, mapping risks, or balance sheet differences.</div>
                <div class="rc-intelai">
                    <a class="btn" href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=recon" target="_blank">Ask IntelAI</a>
                    <a class="btn" href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=explain_recon" target="_blank">Explain Reconciliation</a>
                    <a class="btn" href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=why_tallies" target="_blank">Why Balance Sheet Tallies?</a>
                    <a class="btn" href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=review_risks" target="_blank">Review Mapping Risks</a>
                    <a class="btn" href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=explain_diff&diff=<?= urlencode(formatMoney($diff)) ?>" target="_blank">Explain Difference</a>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TAB: MAPPING HEALTH
             ============================================================ -->
        <div class="rc-tab-content" id="tab-mapping">
            <!-- Unmapped Ledgers -->
            <div class="rc-card">
                <div class="rc-card-header">
                    <h3>Unmapped Ledgers</h3>
                    <span class="rc-badge <?= $unmappedCount > 0 ? 'err' : 'ok' ?>"><?= $unmappedCount ?> <?= $unmappedCount === 1 ? 'ledger' : 'ledgers' ?></span>
                </div>
                <?php if (empty($response['unmapped_ledgers'])): ?>
                    <div style="padding:12px;color:var(--rc-success);font-size:0.85rem;">&#10003; All ledgers mapped.</div>
                <?php else: ?>
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <tr><th>Ledger Name</th><th class="num">Amount</th><th>Action</th></tr>
                            <?php foreach ($response['unmapped_ledgers'] as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['ledger_name'] ?? '')) ?></td>
                                <td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td>
                                <td><a class="recon-link" href="<?= htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php?mode=ledger&search=' . urlencode($row['ledger_name'] ?? '')) ?>">Open in ReconHub</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Mapping Risk Review -->
            <div class="rc-card">
                <div class="rc-card-header">
                    <h3>Mapping Risk Review</h3>
                    <span class="rc-badge <?= count($riskItems) > 0 ? 'warn' : 'ok' ?>"><?= count($riskItems) ?> risks</span>
                </div>
                <?php if (empty($riskItems)): ?>
                    <div style="padding:12px;color:var(--rc-success);font-size:0.85rem;">&#10003; No critical mapping risks detected.</div>
                <?php else: ?>
                    <div class="rc-risk-grid">
                        <?php foreach ($riskItems as $risk): ?>
                        <div class="rc-risk-item">
                            <div class="rc-risk-icon">&#9888;&#65039;</div>
                            <div class="rc-risk-info">
                                <div class="rc-risk-ledger"><?= htmlspecialchars($risk['ledger_name']) ?></div>
                                <div class="rc-risk-group">Group: <?= htmlspecialchars($risk['parent_group']) ?> &middot; Mapped: <?= htmlspecialchars($risk['mapped_head']) ?></div>
                                <div class="rc-risk-reason"><?= htmlspecialchars($risk['risk_reason']) ?></div>
                                <div class="rc-risk-action">
                                    <a href="<?= htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php?mode=ledger&search=' . urlencode($risk['ledger_name'])) ?>">Open in ReconHub</a>
                                    &middot;
                                    <a href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=risk&ledger=<?= urlencode($risk['ledger_name']) ?>" target="_blank">Ask IntelAI</a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Group vs Schedule Mismatch -->
            <div class="rc-card">
                <div class="rc-card-header">
                    <h3>Group &ndash; Schedule Mismatch</h3>
                    <span class="rc-badge <?= count($mismatchItems) > 0 ? 'warn' : 'ok' ?>"><?= count($mismatchItems) ?> mismatches</span>
                </div>
                <?php if (empty($mismatchItems)): ?>
                    <div style="padding:12px;color:var(--rc-success);font-size:0.85rem;">&#10003; No group-schedule mismatches detected.</div>
                <?php else: ?>
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <tr><th>Ledger</th><th>Tally Group</th><th>Group Nature</th><th>Mapped Schedule</th><th>Schedule Nature</th><th>Reason</th><th>Action</th></tr>
                            <?php foreach ($mismatchItems as $mx): ?>
                            <tr class="rc-row-exception">
                                <td><?= htmlspecialchars($mx['ledger_name']) ?></td>
                                <td><?= htmlspecialchars($mx['parent_group']) ?></td>
                                <td><span class="rc-risk-badge mismatch"><?= strtoupper($mx['parent_group_nature']) ?></span></td>
                                <td><?= htmlspecialchars($mx['mapped_code']) ?></td>
                                <td><span class="rc-risk-badge mismatch"><?= strtoupper($mx['mapped_nature']) ?></span></td>
                                <td style="font-size:0.75rem;"><?= htmlspecialchars($mx['mismatch_reason']) ?></td>
                                <td>
                                    <a class="recon-link" href="<?= htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php?mode=ledger&search=' . urlencode($mx['ledger_name'])) ?>">Fix</a>
                                    &middot;
                                    <a href="<?= htmlspecialchars(BASE_URL . 'data_console/ai_mapping.php') ?>?context=mismatch&ledger=<?= urlencode($mx['ledger_name']) ?>" target="_blank" style="font-size:0.72rem;color:var(--rc-brand);">Ask IntelAI</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
             TAB: PROFIT CHECK
             ============================================================ -->
        <div class="rc-tab-content" id="tab-profit">
            <div class="rc-card">
                <div class="rc-card-header"><h3>Profit Computation</h3></div>
                <div class="rc-kpi-strip">
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($profitBlock['income'] ?? 0) ?></div><div class="rc-kpi-lbl">Income</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($profitBlock['expense'] ?? 0) ?></div><div class="rc-kpi-lbl">Expense</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= ($response['profit'] ?? 0) >= 0 ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= formatMoney($response['profit']) ?></div><div class="rc-kpi-lbl">Profit / Loss</div></div>
                </div>
                <?php if (!empty($profitBlock['income_rows'])): ?>
                <div class="rc-collapse-toggle" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open');">
                    <span class="rc-arrow">&#9654;</span> Income Details (<?= count($profitBlock['income_rows']) ?> ledgers)
                </div>
                <div class="rc-collapse-body">
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <tr><th>Ledger Name</th><th>Mapped Head</th><th class="num">Amount</th></tr>
                            <?php foreach ($profitBlock['income_rows'] as $row): ?>
                            <tr><td><?= htmlspecialchars($row['ledger_name'] ?? '') ?></td><td><?= htmlspecialchars($row['fs_head'] ?? '') ?></td><td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($profitBlock['expense_rows'])): ?>
                <div class="rc-collapse-toggle" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open');">
                    <span class="rc-arrow">&#9654;</span> Expense Details (<?= count($profitBlock['expense_rows']) ?> ledgers)
                </div>
                <div class="rc-collapse-body">
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <tr><th>Ledger Name</th><th>Mapped Head</th><th class="num">Amount</th></tr>
                            <?php foreach ($profitBlock['expense_rows'] as $row): ?>
                            <tr><td><?= htmlspecialchars($row['ledger_name'] ?? '') ?></td><td><?= htmlspecialchars($row['fs_head'] ?? '') ?></td><td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============================================================
             TAB: BALANCE SHEET CHECK
             ============================================================ -->
        <div class="rc-tab-content" id="tab-bsheet">
            <div class="rc-card">
                <div class="rc-card-header"><h3>Balance Sheet Summary</h3></div>
                <div class="rc-kpi-strip">
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($response['assets']) ?></div><div class="rc-kpi-lbl">Assets</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($response['liabilities']) ?></div><div class="rc-kpi-lbl">Liabilities</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val"><?= formatMoney($response['capital']) ?></div><div class="rc-kpi-lbl">Capital</div></div>
                    <div class="rc-kpi"><div class="rc-kpi-val" style="color:<?= $isReconciled ? 'var(--rc-success)' : 'var(--rc-danger)' ?>"><?= formatMoney($diff) ?></div><div class="rc-kpi-lbl">Difference</div></div>
                </div>
                <?php foreach (['asset_rows' => 'Assets', 'liability_rows' => 'Liabilities', 'capital_rows' => 'Capital'] as $key => $label): ?>
                <?php if (!empty($balanceBlock[$key])): ?>
                <div class="rc-collapse-toggle" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open');">
                    <span class="rc-arrow">&#9654;</span> <?= $label ?> Details (<?= count($balanceBlock[$key]) ?> ledgers)
                </div>
                <div class="rc-collapse-body">
                    <div class="rc-table-wrap">
                        <table class="rc-table">
                            <tr><th>Ledger Name</th><th>Mapped Head</th><th class="num">Amount</th></tr>
                            <?php foreach ($balanceBlock[$key] as $row): ?>
                            <tr><td><?= htmlspecialchars($row['ledger_name'] ?? '') ?></td><td><?= htmlspecialchars($row['fs_head'] ?? '') ?></td><td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ============================================================
             TAB: RECONCILIATION BRIDGE
             ============================================================ -->
        <div class="rc-tab-content" id="tab-bridge">
            <?php if ($bridgeBlock !== null): ?>
            <!-- Bridge Summary Buckets -->
            <div class="rc-card">
                <div class="rc-card-header"><h3>Reconciliation Bridge Summary</h3></div>
                <div class="rc-bridge-grid">
                    <div class="rc-bridge-bucket">
                        <div class="rc-bucket-val" style="color:var(--rc-success)"><?= (int) ($bridgeBlock['status_counts']['included_asset'] ?? 0) ?></div>
                        <div class="rc-bucket-lbl">Assets</div>
                    </div>
                    <div class="rc-bridge-bucket">
                        <div class="rc-bucket-val" style="color:var(--rc-success)"><?= (int) ($bridgeBlock['status_counts']['included_liability'] ?? 0) ?></div>
                        <div class="rc-bucket-lbl">Liabilities</div>
                    </div>
                    <div class="rc-bridge-bucket">
                        <div class="rc-bucket-val" style="color:var(--rc-success)"><?= (int) ($bridgeBlock['status_counts']['included_capital'] ?? 0) ?></div>
                        <div class="rc-bucket-lbl">Capital</div>
                    </div>
                    <div class="rc-bridge-bucket">
                        <div class="rc-bucket-val" style="color:var(--rc-success)"><?= (int) (($bridgeBlock['status_counts']['included_income'] ?? 0) + ($bridgeBlock['status_counts']['included_expense'] ?? 0)) ?></div>
                        <div class="rc-bucket-lbl">P&amp;L</div>
                    </div>
                    <div class="rc-bridge-bucket">
                        <div class="rc-bucket-val" style="color:var(--rc-danger)"><?= (int) ($bridgeBlock['status_counts']['parent_group_conflict'] ?? 0) ?></div>
                        <div class="rc-bucket-lbl">Conflicts</div>
                    </div>
                    <div class="rc-bridge-bucket">
                        <div class="rc-bucket-val" style="color:var(--rc-warning)"><?= (int) (($bridgeBlock['status_counts']['unmapped'] ?? 0) + ($bridgeBlock['status_counts']['excluded'] ?? 0)) ?></div>
                        <div class="rc-bucket-lbl">Unmapped / Excluded</div>
                    </div>
                    <?php
                    /* Manual Overrides Included — count ledgers with mapping_source = 'manual_override' */
                    $manualOverrideItems = [];
                    $manualOverrideCount = 0;
                    $manualOverrideAmount = 0.0;
                    if ($bridgeBlock !== null) {
                        foreach (($bridgeBlock['items'] ?? []) as $brItem) {
                            $brStatus = (string) ($brItem['status'] ?? '');
                            if (str_starts_with($brStatus, 'included_')) {
                                $brLedger = (string) ($brItem['ledger_name'] ?? '');
                                if ($brLedger !== '' && isMappingManuallyOverridden($pdo, $companyId, $brLedger)) {
                                    $manualOverrideCount++;
                                    $manualOverrideAmount += (float) ($brItem['signed_amount'] ?? 0);
                                    $manualOverrideItems[] = $brItem;
                                }
                            }
                        }
                    }
                    ?>
                    <?php if ($manualOverrideCount > 0): ?>
                    <div class="rc-bridge-bucket" style="border:1px solid #c4b5fd;background:#f5f3ff;">
                        <div class="rc-bucket-val" style="color:#7c3aed"><?= $manualOverrideCount ?></div>
                        <div class="rc-bucket-lbl">Manual Overrides</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Difference Driver -->
            <div class="rc-card">
                <div class="rc-card-header"><h3>Difference Driver</h3></div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <tr><th>Driver</th><th class="num">Estimated Impact</th></tr>
                        <tr><td>Parent Group Conflicts</td><td class="num"><?= formatMoney($bridgeBlock['status_impact']['parent_group_conflict'] ?? 0) ?></td></tr>
                        <tr><td>Duplicate Mappings</td><td class="num"><?= formatMoney($bridgeBlock['status_impact']['duplicate_mapping'] ?? 0) ?></td></tr>
                        <tr><td>Unmapped Ledgers</td><td class="num"><?= formatMoney($bridgeBlock['status_impact']['unmapped'] ?? 0) ?></td></tr>
                        <tr><td>Excluded Ledgers</td><td class="num"><?= formatMoney($bridgeBlock['status_impact']['excluded'] ?? 0) ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($manualOverrideCount > 0): ?>
            <!-- Manual Overrides Included -->
            <div class="rc-card" style="border:1px solid #c4b5fd;">
                <div class="rc-card-header">
                    <h3 style="color:#7c3aed;">Manual Overrides Included</h3>
                    <span class="rc-badge" style="background:#ede9fe;color:#7c3aed;"><?= $manualOverrideCount ?> items</span>
                </div>
                <div style="font-size:0.82rem;color:var(--rc-muted);margin-bottom:10px;">
                    These items were included by professional manual override. Review retained. Eliminate on consolidation where applicable.
                </div>
                <div class="rc-table-wrap">
                    <table class="rc-table">
                        <tr><th>Ledger</th><th>Tally Group</th><th>Schedule</th><th class="num">Amount</th><th>Override Reason</th></tr>
                        <?php foreach ($manualOverrideItems as $moItem):
                            $moAmt = (float) ($moItem['signed_amount'] ?? 0);
                            $moLedger = (string) ($moItem['ledger_name'] ?? '');
                            $moReason = '';
                            if ($moLedger !== '') {
                                $moStmt = $pdo->prepare("SELECT mapping_reason FROM ledger_mapping WHERE company_id = ? AND ledger_name = ? AND mapping_source = 'manual_override' LIMIT 1");
                                $moStmt->execute([$companyId, $moLedger]);
                                $moReason = (string) $moStmt->fetchColumn();
                            }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($moLedger) ?></td>
                            <td><?= htmlspecialchars((string) ($moItem['parent_group'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($moItem['mapped_code'] ?? '')) ?></td>
                            <td class="num" style="font-weight:600;"><?= formatMoney($moAmt) ?></td>
                            <td style="font-size:0.78rem;color:var(--rc-muted);"><?= htmlspecialchars($moReason) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detailed Comparison (Collapsible) -->
            <div class="rc-card">
                <div class="rc-collapse-toggle" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open');">
                    <span class="rc-arrow">&#9654;</span> Trial Balance vs Notes Comparison (<?= count($bridgeBlock['items']) ?> ledgers)
                </div>
                <div class="rc-collapse-body">
                    <div class="rc-table-wrap">
                        <?php
                        $comparisonDrTotal = 0.0; $comparisonCrTotal = 0.0; $notesDrTotal = 0.0; $notesCrTotal = 0.0;
                        foreach ($bridgeBlock['items'] as $bridgeRow) {
                            $sa = (float) ($bridgeRow['signed_amount'] ?? 0);
                            if ($sa >= 0) { $comparisonDrTotal += abs($sa); } else { $comparisonCrTotal += abs($sa); }
                            if (str_starts_with((string) ($bridgeRow['status'] ?? ''), 'included_')) {
                                if ($sa >= 0) { $notesDrTotal += abs($sa); } else { $notesCrTotal += abs($sa); }
                            }
                        }
                        ?>
                        <table class="rc-table">
                            <tr>
                                <th>#</th><th>Ledger Name</th><th>Tally Group</th>
                                <th class="num">TB Dr</th><th class="num">TB Cr</th>
                                <th>Note / Schedule</th>
                                <th class="num">Notes Dr</th><th class="num">Notes Cr</th>
                                <th class="num">Diff</th>
                            </tr>
                            <?php foreach ($bridgeBlock['items'] as $idx => $row): ?>
                            <?php
                            $sa = (float) ($row['signed_amount'] ?? 0);
                            $tbDr = $sa >= 0 ? abs($sa) : 0.0; $tbCr = $sa < 0 ? abs($sa) : 0.0;
                            $mc = (string) ($row['mapped_code'] ?? '');
                            $noteDisp = $mc !== '' ? ($noteDisplayMap[$mc] ?? ($mappingEngine->getLabel($mc) ?? $mc)) : '';
                            $isInc = str_starts_with((string) ($row['status'] ?? ''), 'included_');
                            $nDr = $isInc && $sa >= 0 ? abs($sa) : 0.0;
                            $nCr = $isInc && $sa < 0 ? abs($sa) : 0.0;
                            $diffAmt = ($tbDr - $tbCr) - ($nDr - $nCr);
                            $isExc = abs($diffAmt) > 0.01 || ($row['status'] ?? '') === 'unmapped' || str_starts_with(($row['status'] ?? ''), 'parent_group_conflict');
                            ?>
                            <tr class="<?= $isExc ? 'rc-row-exception' : '' ?>">
                                <td><?= $idx + 1 ?></td>
                                <td><?= htmlspecialchars($row['ledger_name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['parent_group'] ?? '') ?></td>
                                <td class="num"><?= $tbDr > 0 ? formatMoney($tbDr) : '' ?></td>
                                <td class="num"><?= $tbCr > 0 ? formatMoney($tbCr) : '' ?></td>
                                <td><?= htmlspecialchars($noteDisp !== '' ? $noteDisp : '<em>Unmapped</em>') ?></td>
                                <td class="num"><?= $nDr > 0 ? formatMoney($nDr) : '' ?></td>
                                <td class="num"><?= $nCr > 0 ? formatMoney($nCr) : '' ?></td>
                                <td class="num" style="font-weight:600;<?= abs($diffAmt) > 0.01 ? 'color:var(--rc-danger)' : '' ?>"><?= formatMoney($diffAmt) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight:700;background:#f3f6fb;">
                                <td colspan="3">Total</td>
                                <td class="num"><?= formatMoney($comparisonDrTotal) ?></td>
                                <td class="num"><?= formatMoney($comparisonCrTotal) ?></td>
                                <td>Total</td>
                                <td class="num"><?= formatMoney($notesDrTotal) ?></td>
                                <td class="num"><?= formatMoney($notesCrTotal) ?></td>
                                <td class="num"><?= formatMoney(($comparisonDrTotal - $comparisonCrTotal) - ($notesDrTotal - $notesCrTotal)) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="rc-card"><div style="padding:12px;color:var(--rc-muted);">No reconciliation bridge data available.</div></div>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             TAB: LEDGER DETAILS (Exception-First + Pagination)
             ============================================================ -->
        <div class="rc-tab-content" id="tab-ledger">
            <div class="rc-card">
                <div class="rc-card-header">
                    <h3>Ledger Details</h3>
                    <span class="rc-badge ok"><?= count($bridgeBlock['items'] ?? []) ?> total</span>
                </div>

                <!-- Filter Bar -->
                <div class="rc-filter-bar">
                    <input type="text" id="ledgerSearch" placeholder="Search ledger name&hellip;">
                    <select id="ledgerNoteFilter">
                        <option value="">All Notes</option>
                        <option value="unmapped">Unmapped</option>
                        <option value="conflict">Conflicts</option>
                        <option value="mismatch">Group-Schedule Mismatch</option>
                        <option value="risk">Risky Mappings</option>
                    </select>
                    <select id="ledgerGroupFilter">
                        <option value="">All Tally Groups</option>
                        <?php
                        $pgSet = [];
                        foreach ($bridgeBlock['items'] ?? [] as $br) {
                            $pg = $br['parent_group'] ?? '';
                            if ($pg !== '' && !isset($pgSet[$pg])) { $pgSet[$pg] = true; echo '<option value="' . htmlspecialchars($pg) . '">' . htmlspecialchars($pg) . '</option>'; }
                        }
                        ?>
                    </select>
                    <button onclick="rcExportLedgerTable()" style="padding:6px 12px;border:1px solid var(--rc-border);border-radius:6px;background:#fff;cursor:pointer;font-size:0.8rem;">Export</button>
                    <div style="margin-left:auto;">
                        <button class="rc-filter-chip active" id="chipException" onclick="rcSetLedgerFilter('exception')">Exceptions Only</button>
                        <button class="rc-filter-chip" id="chipAll" onclick="rcSetLedgerFilter('all')">Show All</button>
                    </div>
                </div>

                <!-- Ledger Table -->
                <div class="rc-table-wrap" style="max-height:600px;overflow-y:auto;">
                    <table class="rc-table" id="ledgerTable">
                        <thead>
                        <tr>
                            <th>#</th><th>Ledger Name</th><th>Tally Group</th><th>Dr/Cr</th>
                            <th class="num">Amount</th><th>Note / Schedule</th><th>Status</th>
                            <th>Difference</th><th>Group&ndash;Schedule</th><th>Action</th>
                        </tr>
                        </thead>
                        <tbody id="ledgerTableBody">
                        <?php
                        $ledgerIdx = 0;
                        $mismatchNames = array_flip(array_column($mismatchItems, 'ledger_name'));
                        $riskNames = array_flip(array_column($riskItems, 'ledger_name'));
                        foreach ($bridgeBlock['items'] ?? [] as $row):
                            $sa = (float) ($row['signed_amount'] ?? 0);
                            $tbDr = $sa >= 0 ? abs($sa) : 0.0;
                            $tbCr = $sa < 0 ? abs($sa) : 0.0;
                            $mc = (string) ($row['mapped_code'] ?? '');
                            $noteDisp = $mc !== '' ? ($noteDisplayMap[$mc] ?? ($mappingEngine->getLabel($mc) ?? $mc)) : '';
                            $isInc = str_starts_with((string) ($row['status'] ?? ''), 'included_');
                            $nDr = $isInc && $sa >= 0 ? abs($sa) : 0.0;
                            $nCr = $isInc && $sa < 0 ? abs($sa) : 0.0;
                            $diffAmt = ($tbDr - $tbCr) - ($nDr - $nCr);
                            $status = $row['status'] ?? 'unmapped';
                            $pg = $row['parent_group'] ?? '';
                            $ln = $row['ledger_name'] ?? '';
                            $pgNature = $row['parent_group_nature'] ?? '';
                            $mapNature = $row['mapped_nature'] ?? '';
                            $hasMismatch = isset($mismatchNames[$ln]);
                            $hasRisk = isset($riskNames[$ln]);
                            $isException = abs($diffAmt) > 0.01 || $status === 'unmapped' || str_starts_with($status, 'parent_group_conflict') || $hasMismatch || $hasRisk;
                            $ledgerIdx++;
                        ?>
                        <tr class="rc-ledger-row <?= $isException ? 'rc-row-exception' : '' ?>"
                            data-exception="<?= $isException ? '1' : '0' ?>"
                            data-search="<?= htmlspecialchars(strtolower($ln . ' ' . $pg . ' ' . $noteDisp)) ?>"
                            data-note="<?= $status ?>"
                            data-group="<?= htmlspecialchars($pg) ?>"
                            data-mismatch="<?= $hasMismatch ? '1' : '0' ?>"
                            data-risk="<?= $hasRisk ? '1' : '0' ?>">
                            <td><?= $ledgerIdx ?></td>
                            <td><?= htmlspecialchars($ln) ?></td>
                            <td><?= htmlspecialchars($pg) ?></td>
                            <td><?= htmlspecialchars($row['dr_cr'] ?? '') ?></td>
                            <td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td>
                            <td><?= htmlspecialchars($noteDisp !== '' ? $noteDisp : '<em>Unmapped</em>') ?></td>
                            <td>
                                <?php if ($status === 'unmapped'): ?><span class="rc-risk-badge risky">Unmapped</span>
                                <?php elseif (str_starts_with($status, 'parent_group_conflict')): ?><span class="rc-risk-badge risky">Conflict</span>
                                <?php elseif ($status === 'excluded'): ?><span class="rc-risk-badge mismatch">Excluded</span>
                                <?php elseif ($status === 'duplicate_mapping'): ?><span class="rc-risk-badge mismatch">Duplicate</span>
                                <?php else: ?><span style="color:var(--rc-success);font-size:0.78rem;">&#10003; <?= htmlspecialchars($row['statement_bucket'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num" style="font-weight:600;<?= abs($diffAmt) > 0.01 ? 'color:var(--rc-danger)' : '' ?>"><?= formatMoney($diffAmt) ?></td>
                            <td>
                                <?php if ($hasMismatch): ?><span class="rc-risk-badge mismatch">Mismatch</span>
                                <?php elseif ($hasRisk): ?><span class="rc-risk-badge risky">Risk</span>
                                <?php else: ?><span style="color:var(--rc-success);">&#10003;</span>
                                <?php endif; ?>
                            </td>
                            <td><a class="recon-link" href="<?= htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php?mode=ledger&search=' . urlencode($ln)) ?>">Open ReconHub</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="rc-pagination" id="ledgerPagination">
                    <select id="rcPageSize" onchange="rcUpdateLedgerPage()">
                        <option value="50">50 rows</option>
                        <option value="100">100 rows</option>
                        <option value="250">250 rows</option>
                        <option value="0">All</option>
                    </select>
                    <button id="rcPrevBtn" onclick="rcPrevPage()">&#8592; Prev</button>
                    <span class="rc-page-info" id="rcPageInfo">1 &ndash; 50 of 200</span>
                    <button id="rcNextBtn" onclick="rcNextPage()">Next &#8594;</button>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <!-- ============================================================
         VALIDATION SIDEBAR
         ============================================================ -->
    <?php if ($response !== null): ?>
    <div class="validation-sidebar">
        <div class="vs-header">
            <h3>Validation</h3>
            <span class="action" onclick="window.location.reload()">Run All</span>
        </div>
        <div class="vs-summary">
            <div class="vs-summary-item error <?= $errorCount > 0 ? 'active' : '' ?>">
                <div class="count"><?= $errorCount ?></div><div class="label">Errors</div>
            </div>
            <div class="vs-summary-item warning <?= $warningCount > 0 && $errorCount === 0 ? 'active' : '' ?>">
                <div class="count"><?= $warningCount ?></div><div class="label">Warnings</div>
            </div>
            <div class="vs-summary-item success <?= $errorCount === 0 && $warningCount === 0 ? 'active' : '' ?>">
                <div class="count"><?= $passCount ?></div><div class="label">Passed</div>
            </div>
            <div class="vs-summary-item info">
                <div class="count"><?= $infoCount ?></div><div class="label">Info</div>
            </div>
        </div>
        <div class="vs-body">
            <!-- Trial Balance -->
            <div class="v-cat open">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#9878;</span> Trial Balance
                    <span style="font-size:0.75rem;font-weight:600;margin-left:auto;color:<?= $tbOk ? 'var(--rc-success)' : 'var(--rc-danger)' ?>;"><?= $tbOk ? 'Passed' : 'Failed' ?></span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <div class="issue-item">
                        <span class="dot <?= $tbOk ? 'success' : 'error' ?>"></span>
                        <div class="issue-text">
                            <?= $tbOk ? 'DR = CR - Balanced' : 'Trial Balance does not tally' ?>
                            <span class="issue-path"><?= $tbOk ? 'No action needed' : 'Review TB import' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mapping Completeness -->
            <div class="v-cat open">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#128451;</span> Mapping
                    <span style="font-size:0.75rem;font-weight:600;margin-left:auto;color:<?= $unmappedCount > 0 ? 'var(--rc-warning)' : 'var(--rc-success)' ?>;">
                        <?= $unmappedCount > 0 ? $unmappedCount . ' Unmapped' : 'Complete' ?>
                    </span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <div class="issue-item">
                        <span class="dot <?= $unmappedCount > 0 ? 'warning' : 'success' ?>"></span>
                        <div class="issue-text">
                            <?= $unmappedCount > 0 ? $unmappedCount . ' ledgers pending mapping' : 'All ledgers mapped' ?>
                            <span class="issue-path"><?= $unmappedCount > 0 ? '<a href="' . htmlspecialchars(BASE_URL . 'data_console/mapping_workbench.php') . '" style="color:var(--rc-brand);">Go to ReconHub</a>' : '' ?></span>
                        </div>
                    </div>
                    <?php if ($conflictCount > 0): ?>
                    <div class="issue-item">
                        <span class="dot error"></span>
                        <div class="issue-text"><?= $conflictCount ?> parent-group conflicts<span class="issue-path">Resolve in ReconHub</span></div>
                    </div>
                    <?php endif; ?>
                    <?php if (count($riskItems) > 0): ?>
                    <div class="issue-item">
                        <span class="dot warning"></span>
                        <div class="issue-text"><?= count($riskItems) ?> risky mappings detected<span class="issue-path">See Mapping Health tab</span></div>
                    </div>
                    <?php endif; ?>
                    <?php if (count($mismatchItems) > 0): ?>
                    <div class="issue-item">
                        <span class="dot warning"></span>
                        <div class="issue-text"><?= count($mismatchItems) ?> group-schedule mismatches<span class="issue-path">See Mapping Health tab</span></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- BS Identity -->
            <div class="v-cat open">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#128202;</span> BS Identity
                    <span style="font-size:0.75rem;font-weight:600;margin-left:auto;color:<?= $isReconciled ? 'var(--rc-success)' : 'var(--rc-danger)' ?>;">
                        <?= $isReconciled ? 'Passed' : 'Failed' ?>
                    </span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <div class="issue-item">
                        <span class="dot <?= $isReconciled ? 'success' : 'error' ?>"></span>
                        <div class="issue-text">
                            <?= $isReconciled ? 'Assets = Liabilities + Equity' : 'Balance sheet identity not matched' ?>
                            <span class="issue-path"><?= $isReconciled ? 'Balanced' : 'Difference: ' . formatMoney($diff) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mapping Risks -->
            <?php if (count($riskItems) > 0): ?>
            <div class="v-cat open">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#9888;&#65039;</span> Mapping Risks
                    <span style="font-size:0.75rem;color:var(--rc-warning);font-weight:600;margin-left:auto;"><?= count($riskItems) ?></span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <?php foreach (array_slice($riskItems, 0, 5) as $ri): ?>
                    <div class="issue-item">
                        <span class="dot warning"></span>
                        <div class="issue-text">
                            <?= htmlspecialchars($ri['ledger_name']) ?> &mdash; <?= htmlspecialchars($ri['risk_reason']) ?>
                            <span class="issue-path"><?= htmlspecialchars($ri['parent_group']) ?> &rarr; <?= htmlspecialchars($ri['mapped_head']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($riskItems) > 5): ?>
                    <div style="font-size:0.72rem;color:var(--rc-muted);text-align:center;padding:6px;">+<?= count($riskItems) - 5 ?> more</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Group-Schedule Mismatches -->
            <?php if (count($mismatchItems) > 0): ?>
            <div class="v-cat open">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#128260;</span> Group-Schedule Mismatch
                    <span style="font-size:0.75rem;color:var(--rc-warning);font-weight:600;margin-left:auto;"><?= count($mismatchItems) ?></span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <?php foreach (array_slice($mismatchItems, 0, 5) as $mi): ?>
                    <div class="issue-item">
                        <span class="dot warning"></span>
                        <div class="issue-text">
                            <?= htmlspecialchars($mi['ledger_name']) ?>: <?= htmlspecialchars($mi['mismatch_reason']) ?>
                            <span class="issue-path"><?= htmlspecialchars($mi['parent_group']) ?> &rarr; <?= htmlspecialchars($mi['mapped_code']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($mismatchItems) > 5): ?>
                    <div style="font-size:0.72rem;color:var(--rc-muted);text-align:center;padding:6px;">+<?= count($mismatchItems) - 5 ?> more</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Conflicts -->
            <?php if ($conflictCount > 0): ?>
            <div class="v-cat open">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#128681;</span> Conflicts
                    <span style="font-size:0.75rem;color:var(--rc-danger);font-weight:600;margin-left:auto;"><?= $conflictCount ?></span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <?php $sc = 0; foreach ($bridgeBlock['items'] ?? [] as $br): if (str_starts_with((string) ($br['status'] ?? ''), 'parent_group_conflict') && $sc < 5): $sc++; ?>
                    <div class="issue-item">
                        <span class="dot error"></span>
                        <div class="issue-text">
                            <?= htmlspecialchars($br['ledger_name'] ?? '') ?> mapped to <?= htmlspecialchars($br['mapped_head'] ?? '') ?>
                            <span class="issue-path">Parent group: <?= htmlspecialchars($br['parent_group'] ?? '') ?></span>
                        </div>
                    </div>
                    <?php endif; endforeach; ?>
                    <?php if ($conflictCount > 5): ?>
                    <div style="font-size:0.72rem;color:var(--rc-muted);text-align:center;padding:6px;">+<?= $conflictCount - 5 ?> more</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notes Completeness -->
            <div class="v-cat">
                <div class="v-cat-header" onclick="this.parentElement.classList.toggle('open')">
                    <span class="cat-icon">&#128221;</span> Notes Completeness
                    <span style="font-size:0.75rem;color:var(--rc-muted);font-weight:600;margin-left:auto;">Info</span>
                    <span class="arrow">&#9654;</span>
                </div>
                <div class="v-cat-body">
                    <div class="issue-item">
                        <span class="dot info"></span>
                        <div class="issue-text">Review note-wise mapping in the bridge section<span class="issue-path">See Reconciliation Bridge tab</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================
     BOTTOM BAR
     ============================================================ -->
<?php if ($response !== null): ?>
<div class="rc-bottom-bar">
    <span class="b-label">Validation</span>
    <span class="b-item"><span class="b-dot error"></span> <?= $errorCount ?> Errors</span>
    <span class="b-item"><span class="b-dot warning"></span> <?= $warningCount ?> Warnings</span>
    <span class="b-item"><span class="b-dot success"></span> <?= $passCount ?> Passed</span>
    <span style="margin-left:auto;color:var(--rc-muted);font-size:0.75rem;">Last validated: now</span>
</div>
<?php endif; ?>

<!-- ============================================================
     JAVASCRIPT — Tabs, Pagination, Filtering
     ============================================================ -->
<script>
(function() {
    'use strict';

    /* ---- Tab Switching ---- */
    var tabs = document.querySelectorAll('.rc-tab');
    var contents = document.querySelectorAll('.rc-tab-content');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            tabs.forEach(function(t) { t.classList.remove('active'); });
            contents.forEach(function(c) { c.classList.remove('active'); });
            this.classList.add('active');
            var el = document.getElementById('tab-' + target);
            if (el) el.classList.add('active');
            if (target === 'ledger') { rcUpdateLedgerPage(); }
        });
    });

    /* ---- Ledger Table: Pagination + Filter ---- */
    var currentPage = 1;
    var pageSize = 50;
    var currentFilter = 'exception';
    var allRows = [];
    var filteredRows = [];

    function initLedgerTable() {
        var tbody = document.getElementById('ledgerTableBody');
        if (!tbody) return;
        allRows = Array.from(tbody.querySelectorAll('tr.rc-ledger-row'));
        applyLedgerFilter();
    }

    function applyLedgerFilter() {
        var search = (document.getElementById('ledgerSearch').value || '').toLowerCase().trim();
        var noteFilter = document.getElementById('ledgerNoteFilter').value;
        var groupFilter = document.getElementById('ledgerGroupFilter').value;

        filteredRows = allRows.filter(function(row) {
            /* Exception filter */
            if (currentFilter === 'exception') {
                if (row.getAttribute('data-exception') !== '1') return false;
            }
            /* Search */
            if (search) {
                var hay = row.getAttribute('data-search') || '';
                if (hay.indexOf(search) === -1) return false;
            }
            /* Note filter */
            if (noteFilter === 'unmapped' && row.getAttribute('data-note') !== 'unmapped') return false;
            if (noteFilter === 'conflict' && row.getAttribute('data-note').indexOf('parent_group_conflict') === -1) return false;
            if (noteFilter === 'mismatch' && row.getAttribute('data-mismatch') !== '1') return false;
            if (noteFilter === 'risk' && row.getAttribute('data-risk') !== '1') return false;
            /* Group filter */
            if (groupFilter && row.getAttribute('data-group') !== groupFilter) return false;

            return true;
        });

        currentPage = 1;
        rcUpdateLedgerPage();
    }

    function rcUpdateLedgerPage() {
        var ps = document.getElementById('rcPageSize');
        pageSize = ps ? parseInt(ps.value) || 50 : 50;
        var total = filteredRows.length;
        var totalPages = pageSize > 0 ? Math.ceil(total / pageSize) : 1;
        if (currentPage > totalPages) currentPage = totalPages || 1;
        var start = pageSize > 0 ? (currentPage - 1) * pageSize : 0;
        var end = pageSize > 0 ? Math.min(start + pageSize, total) : total;

        allRows.forEach(function(r) { r.style.display = 'none'; });
        filteredRows.forEach(function(r, i) {
            r.style.display = (i >= start && i < end) ? '' : 'none';
        });

        var info = document.getElementById('rcPageInfo');
        if (info) info.textContent = total > 0 ? (start + 1) + ' \u2013 ' + end + ' of ' + total : '0 results';
        var prev = document.getElementById('rcPrevBtn');
        var next = document.getElementById('rcNextBtn');
        if (prev) prev.disabled = (currentPage <= 1);
        if (next) next.disabled = (currentPage >= totalPages);
    }

    window.rcPrevPage = function() { if (currentPage > 1) { currentPage--; rcUpdateLedgerPage(); } };
    window.rcNextPage = function() { currentPage++; rcUpdateLedgerPage(); };

    window.rcSetLedgerFilter = function(mode) {
        currentFilter = mode;
        document.getElementById('chipException').classList.toggle('active', mode === 'exception');
        document.getElementById('chipAll').classList.toggle('active', mode === 'all');
        applyLedgerFilter();
    };

    window.rcExportLedgerTable = function() {
        var csv = '#,Ledger Name,Tally Group,Dr/Cr,Amount,Note/Schedule,Status,Difference\n';
        filteredRows.forEach(function(row) {
            var cells = row.querySelectorAll('td');
            var vals = [];
            cells.forEach(function(c, i) {
                if (i < 8) vals.push('"' + (c.textContent || '').replace(/"/g, '""').trim() + '"');
            });
            if (vals.length) csv += vals.join(',') + '\n';
        });
        var blob = new Blob([csv], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ledger_details_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
    };

    var searchInput = document.getElementById('ledgerSearch');
    if (searchInput) {
        var timer;
        searchInput.addEventListener('input', function() {
            clearTimeout(timer);
            timer = setTimeout(applyLedgerFilter, 250);
        });
    }
    var noteFilter = document.getElementById('ledgerNoteFilter');
    if (noteFilter) noteFilter.addEventListener('change', applyLedgerFilter);
    var groupFilter = document.getElementById('ledgerGroupFilter');
    if (groupFilter) groupFilter.addEventListener('change', applyLedgerFilter);

    /* Init on load */
    initLedgerTable();
    rcUpdateLedgerPage();

})();
</script>

<?php require_once __DIR__ . '/layouts/footer_v2.php'; ?>
