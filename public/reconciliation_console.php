<?php
require_once __DIR__ . '/../app/session_bootstrap.php';
require_once __DIR__ . '/../app/context_check.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/engines/reconciliation_engine.php';
require_once __DIR__ . '/../app/engines/ai_mapping_engine.php';
require_once __DIR__ . '/../app/helpers/schedule3_master_helper.php';
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
    return number_format((float) $value, 2, '.', ',');
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

$companyId = isset($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($_SESSION['company_id'] ?? 0);
$fyId = isset($_GET['fy_id']) ? (int) $_GET['fy_id'] : (int) ($_SESSION['fy_id'] ?? 0);
$detailView = trim((string) ($_GET['detail'] ?? ''));
$response = null;
$companyCategory = '';
$noteDisplayMap = [];
$mappingEngine = new AIMappingEngine('corporate');
$nextProcessLabel = 'Go to Report Dashboard';
$nextProcessUrl = BASE_URL . 'dashboard_report.php';
$nextProcessHelp = 'Open the reporting dashboard to continue the report preparation workflow.';

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
requireFullContext();

$page_title = 'Balance Sheet Reconciliation Console';
require_once __DIR__ . '/layouts/header.php';
?>

<style>
    .recon-toolbar, .recon-section {
        background: #ffffff;
        border: 1px solid #dbe3ef;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 18px;
    }
    .recon-toolbar form {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
        align-items: center;
    }
    .recon-toolbar select,
    .recon-toolbar button {
        padding: 10px 14px;
        border-radius: 8px;
        border: 1px solid #c8d3e1;
        background: #fff;
        font-size: 14px;
    }
    .recon-toolbar button {
        background: #0f5cc0;
        color: #fff;
        border-color: #0f5cc0;
        cursor: pointer;
    }
    .recon-status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
        margin-top: 16px;
    }
    .recon-status-card {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 16px;
        background: #f9fbff;
    }
    .recon-status-card strong {
        display: block;
        margin-top: 8px;
        font-size: 20px;
    }
    .recon-section table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
    }
    .recon-section th,
    .recon-section td {
        border: 1px solid #dde5f0;
        padding: 10px 12px;
        vertical-align: top;
    }
    .recon-section th {
        background: #f3f6fb;
        text-align: left;
        color: #17324d;
    }
    .recon-section td.num,
    .recon-section th.num {
        text-align: right;
        white-space: nowrap;
    }
    .recon-ok {
        color: #157347;
        font-weight: 700;
    }
    .recon-error {
        color: #b42318;
        font-weight: 700;
    }
    .recon-link {
        color: #0f5cc0;
        text-decoration: none;
        font-weight: 700;
    }
    .recon-link:hover {
        text-decoration: underline;
    }
    .recon-muted {
        color: #667085;
    }
    .recon-section pre {
        background: #0f172a;
        color: #e2e8f0;
        padding: 16px;
        border-radius: 12px;
        overflow: auto;
    }
    .recon-next {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
    }
</style>

<div class="page-title">Balance Sheet Reconciliation Console</div>

<div class="active-info">
    Company: <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'Not Selected') ?></strong><br>
    FY: <strong><?= htmlspecialchars($_SESSION['fy_name'] ?? 'Not Selected') ?></strong>
</div>

<div class="card" style="margin-bottom:18px;">
    Validate trial balance integrity, unmapped ledgers, profit transfer, balance sheet build, and reconciliation difference.
</div>

<div class="recon-toolbar">
        <form method="get">
            <label for="company_id"><strong>Company</strong></label>
            <select name="company_id" id="company_id" required>
                <option value="">Select company</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= (int) $company['id'] ?>" <?= $companyId === (int) $company['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($company['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" name="fy_id" value="<?= (int) $fyId ?>">
            <button type="submit">Run Validation</button>
        </form>
</div>

<?php if ($response !== null): ?>
    <div class="recon-section recon-next">
        <div>
            <strong>Next Process</strong><br>
            <?= htmlspecialchars($nextProcessHelp) ?>
        </div>
        <div>
            <a class="btn" href="<?= htmlspecialchars($nextProcessUrl) ?>"><?= htmlspecialchars($nextProcessLabel) ?></a>
        </div>
    </div>
<?php endif; ?>

    <?php if ($response !== null): ?>
        <div class="recon-section">
            <h2>1. Trial Balance Summary</h2>
            <div class="<?= $response['trial_balance_status'] === 'OK' ? 'recon-ok' : 'recon-error' ?>">
                Trial Balance Status: <?= htmlspecialchars($response['trial_balance_status']) ?>
            </div>
            <div class="recon-status-grid">
                <div class="recon-status-card">
                    Trial Balance
                    <strong><?= htmlspecialchars($response['trial_balance_status']) ?></strong>
                </div>
                <div class="recon-status-card">
                    Reconciliation Difference
                    <strong><?= formatMoney($response['difference']) ?></strong>
                </div>
                <div class="recon-status-card">
                    Final Status
                    <strong><?= htmlspecialchars($response['status']) ?></strong>
                </div>
            </div>
        </div>

        <div class="recon-section">
            <h2>2. Unmapped Ledgers</h2>
            <?php if (empty($response['unmapped_ledgers'])): ?>
                <div class="recon-ok">No unmapped ledgers found.</div>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Ledger ID</th>
                        <th>Ledger Name</th>
                        <th class="num">Amount</th>
                    </tr>
                    <?php foreach ($response['unmapped_ledgers'] as $row): ?>
                        <tr>
                            <td><?= (int) ($row['ledger_id'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string) ($row['ledger_name'] ?? '')) ?></td>
                            <td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="recon-section">
            <h2>3. Profit Computation</h2>
            <?php $profitBlock = findBreakdownBlock($response, 'profit_computation'); ?>
            <table>
                <tr>
                    <th>Metric</th>
                    <th class="num">Value</th>
                </tr>
                <tr>
                    <td>Income</td>
                    <td class="num">
                        <a class="recon-link" href="<?= htmlspecialchars(buildReconLink($companyId, $fyId, 'income')) ?>"><?= formatMoney($profitBlock['income'] ?? 0) ?></a>
                    </td>
                </tr>
                <tr>
                    <td>Expense</td>
                    <td class="num">
                        <a class="recon-link" href="<?= htmlspecialchars(buildReconLink($companyId, $fyId, 'expense')) ?>"><?= formatMoney($profitBlock['expense'] ?? 0) ?></a>
                    </td>
                </tr>
                <tr>
                    <td><strong>Profit / Loss</strong></td>
                    <td class="num"><strong><?= formatMoney($response['profit']) ?></strong></td>
                </tr>
            </table>
        </div>

        <div class="recon-section">
            <h2>4. Balance Sheet Summary</h2>
            <table>
                <tr>
                    <th>Head</th>
                    <th class="num">Amount</th>
                </tr>
                <tr>
                    <td>Assets</td>
                    <td class="num">
                        <a class="recon-link" href="<?= htmlspecialchars(buildReconLink($companyId, $fyId, 'assets')) ?>"><?= formatMoney($response['assets']) ?></a>
                    </td>
                </tr>
                <tr>
                    <td>Liabilities</td>
                    <td class="num">
                        <a class="recon-link" href="<?= htmlspecialchars(buildReconLink($companyId, $fyId, 'liabilities')) ?>"><?= formatMoney($response['liabilities']) ?></a>
                    </td>
                </tr>
                <tr>
                    <td>Capital</td>
                    <td class="num">
                        <a class="recon-link" href="<?= htmlspecialchars(buildReconLink($companyId, $fyId, 'capital')) ?>"><?= formatMoney($response['capital']) ?></a>
                    </td>
                </tr>
                <tr>
                    <td><strong>Difference</strong></td>
                    <td class="num"><strong><?= formatMoney($response['difference']) ?></strong></td>
                </tr>
            </table>
        </div>

        <?php
        $balanceBlock = findBreakdownBlock($response, 'balance_sheet_build');
        $drilldownTitle = '';
        $drilldownRows = [];
        if ($detailView === 'income') {
            $drilldownTitle = 'Income Bifurcation';
            $drilldownRows = $profitBlock['income_rows'] ?? [];
        } elseif ($detailView === 'expense') {
            $drilldownTitle = 'Expense Bifurcation';
            $drilldownRows = $profitBlock['expense_rows'] ?? [];
        } elseif ($detailView === 'assets') {
            $drilldownTitle = 'Assets Bifurcation';
            $drilldownRows = $balanceBlock['asset_rows'] ?? [];
        } elseif ($detailView === 'liabilities') {
            $drilldownTitle = 'Liabilities Bifurcation';
            $drilldownRows = $balanceBlock['liability_rows'] ?? [];
        } elseif ($detailView === 'capital') {
            $drilldownTitle = 'Capital Bifurcation';
            $drilldownRows = $balanceBlock['capital_rows'] ?? [];
        }
        ?>
        <?php if ($drilldownTitle !== ''): ?>
            <div class="recon-section">
                <h2><?= htmlspecialchars('Detail: ' . $drilldownTitle) ?></h2>
                <?php if (empty($drilldownRows)): ?>
                    <div class="recon-muted">No ledger rows available for this section.</div>
                <?php else: ?>
                    <table>
                        <tr>
                            <th>Ledger Name</th>
                            <th>Mapped Head</th>
                            <th class="num">Amount</th>
                        </tr>
                        <?php foreach ($drilldownRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($row['ledger_name'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string) ($row['fs_head'] ?? '')) ?></td>
                                <td class="num"><?= formatMoney($row['amount'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="recon-section">
            <h2>5. Reconciliation Bridge</h2>
            <?php $bridgeBlock = findBreakdownBlock($response, 'reconciliation_bridge'); ?>
            <?php if (empty($bridgeBlock['items'])): ?>
                <div class="recon-muted">No ledger-level bridge rows available.</div>
            <?php else: ?>
                <?php
                $comparisonDrTotal = 0.0;
                $comparisonCrTotal = 0.0;
                $notesDrTotal = 0.0;
                $notesCrTotal = 0.0;
                $differenceTotal = 0.0;
                foreach ($bridgeBlock['items'] as $bridgeRow) {
                    $signedAmount = (float) ($bridgeRow['signed_amount'] ?? 0);
                    if ($signedAmount >= 0) {
                        $comparisonDrTotal += abs($signedAmount);
                    } else {
                        $comparisonCrTotal += abs($signedAmount);
                    }

                    $isIncludedInNotes = str_starts_with((string) ($bridgeRow['status'] ?? ''), 'included_');
                    if ($isIncludedInNotes) {
                        if ($signedAmount >= 0) {
                            $notesDrTotal += abs($signedAmount);
                        } else {
                            $notesCrTotal += abs($signedAmount);
                        }
                    }
                }
                $differenceTotal = ($comparisonDrTotal - $comparisonCrTotal) - ($notesDrTotal - $notesCrTotal);
                ?>
                <div class="recon-status-grid" style="margin-bottom:16px;">
                    <div class="recon-status-card">
                        Included Asset
                        <strong><?= (int) ($bridgeBlock['status_counts']['included_asset'] ?? 0) ?></strong>
                    </div>
                    <div class="recon-status-card">
                        Included Liability
                        <strong><?= (int) ($bridgeBlock['status_counts']['included_liability'] ?? 0) ?></strong>
                    </div>
                    <div class="recon-status-card">
                        Included Capital
                        <strong><?= (int) ($bridgeBlock['status_counts']['included_capital'] ?? 0) ?></strong>
                    </div>
                    <div class="recon-status-card">
                        Included P&amp;L
                        <strong><?= (int) (($bridgeBlock['status_counts']['included_income'] ?? 0) + ($bridgeBlock['status_counts']['included_expense'] ?? 0)) ?></strong>
                    </div>
                    <div class="recon-status-card">
                        Conflicts
                        <strong><?= (int) ($bridgeBlock['status_counts']['parent_group_conflict'] ?? 0) ?></strong>
                    </div>
                    <div class="recon-status-card">
                        Unmapped / Excluded
                        <strong><?= (int) (($bridgeBlock['status_counts']['unmapped'] ?? 0) + ($bridgeBlock['status_counts']['excluded'] ?? 0)) ?></strong>
                    </div>
                </div>

                <h3 style="margin:0 0 8px;">Trial Balance vs Notes Comparison</h3>
                <table style="margin-bottom:16px;">
                    <tr>
                        <th colspan="4">As per Trial Balance</th>
                        <th colspan="3">As per Notes</th>
                        <th class="num">Difference (TB - Notes)</th>
                    </tr>
                    <tr>
                        <th>Sl No</th>
                        <th>Ledger Name</th>
                        <th>Tally Group</th>
                        <th class="num">Dr Amt</th>
                        <th class="num">Cr Amt</th>
                        <th>Note No with Heading</th>
                        <th class="num">Dr Amt</th>
                        <th class="num">Cr Amt</th>
                        <th class="num">Difference</th>
                    </tr>
                    <?php foreach ($bridgeBlock['items'] as $index => $row): ?>
                        <?php
                        $signedAmount = (float) ($row['signed_amount'] ?? 0);
                        $tbDr = $signedAmount >= 0 ? abs($signedAmount) : 0.0;
                        $tbCr = $signedAmount < 0 ? abs($signedAmount) : 0.0;
                        $mappedCode = (string) ($row['mapped_code'] ?? '');
                        $mappedHead = (string) ($row['mapped_head'] ?? '');
                        $noteDisplay = $mappedCode !== ''
                            ? ($noteDisplayMap[$mappedCode] ?? ($mappingEngine->getLabel($mappedCode) ?? $mappedCode))
                            : '';
                        $isIncludedInNotes = str_starts_with((string) ($row['status'] ?? ''), 'included_');
                        $notesDr = $isIncludedInNotes && $signedAmount >= 0 ? abs($signedAmount) : 0.0;
                        $notesCr = $isIncludedInNotes && $signedAmount < 0 ? abs($signedAmount) : 0.0;
                        $differenceAmount = ($tbDr - $tbCr) - ($notesDr - $notesCr);
                        ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><?= htmlspecialchars((string) ($row['ledger_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['parent_group'] ?? '')) ?></td>
                            <td class="num"><?= $tbDr > 0 ? formatMoney($tbDr) : '' ?></td>
                            <td class="num"><?= $tbCr > 0 ? formatMoney($tbCr) : '' ?></td>
                            <td><?= htmlspecialchars($noteDisplay !== '' ? $noteDisplay : 'Unmapped') ?></td>
                            <td class="num"><?= $notesDr > 0 ? formatMoney($notesDr) : '' ?></td>
                            <td class="num"><?= $notesCr > 0 ? formatMoney($notesCr) : '' ?></td>
                            <td class="num"><?= formatMoney($differenceAmount) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th colspan="3" class="num">Total</th>
                        <th class="num"><?= formatMoney($comparisonDrTotal) ?></th>
                        <th class="num"><?= formatMoney($comparisonCrTotal) ?></th>
                        <th class="num">Total</th>
                        <th class="num"><?= formatMoney($notesDrTotal) ?></th>
                        <th class="num"><?= formatMoney($notesCrTotal) ?></th>
                        <th class="num"><?= formatMoney($differenceTotal) ?></th>
                    </tr>
                </table>

                <table style="margin-bottom:16px;">
                    <tr>
                        <th>Difference Driver</th>
                        <th class="num">Estimated Impact</th>
                    </tr>
                    <tr>
                        <td>Parent Group Conflicts</td>
                        <td class="num"><?= formatMoney($bridgeBlock['status_impact']['parent_group_conflict'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Duplicate Mappings</td>
                        <td class="num"><?= formatMoney($bridgeBlock['status_impact']['duplicate_mapping'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Unmapped Ledgers</td>
                        <td class="num"><?= formatMoney($bridgeBlock['status_impact']['unmapped'] ?? 0) ?></td>
                    </tr>
                    <tr>
                        <td>Excluded Ledgers</td>
                        <td class="num"><?= formatMoney($bridgeBlock['status_impact']['excluded'] ?? 0) ?></td>
                    </tr>
                </table>

            <?php endif; ?>
        </div>

        <div class="recon-section">
            <h2>6. Difference Analysis</h2>
            <?php if (empty($response['difference_breakdown'])): ?>
                <div class="recon-ok">No difference breakdown items found.</div>
            <?php else: ?>
                <?php foreach ($response['difference_breakdown'] as $item): ?>
                    <?php if (($item['type'] ?? '') === 'reconciliation_bridge'): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <div style="margin-bottom:18px; padding:14px; border:1px solid #dde5f0; border-radius:10px;">
                        <strong><?= htmlspecialchars(strtoupper((string) ($item['type'] ?? 'item'))) ?></strong>
                        <?php if (isset($item['message'])): ?>
                            <div style="margin-top:8px;"><?= htmlspecialchars((string) $item['message']) ?></div>
                        <?php endif; ?>

                        <?php if (isset($item['note'])): ?>
                            <div style="margin-top:8px;"><?= htmlspecialchars((string) $item['note']) ?></div>
                        <?php endif; ?>

                        <?php if (isset($item['amount'])): ?>
                            <div style="margin-top:8px;"><strong>Amount:</strong> <?= formatMoney($item['amount']) ?></div>
                        <?php endif; ?>

                        <?php if (isset($item['count'])): ?>
                            <div style="margin-top:8px;"><strong>Count:</strong> <?= (int) $item['count'] ?></div>
                        <?php endif; ?>

                        <?php if (!empty($item['items']) && is_array($item['items'])): ?>
                            <table>
                                <tr>
                                    <th>Ledger ID</th>
                                    <th>Ledger Name</th>
                                    <th>FS Head / Issue</th>
                                    <th class="num">Amount</th>
                                </tr>
                                <?php foreach ($item['items'] as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($row['ledger_id'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['ledger_name'] ?? '')) ?></td>
                                        <td>
                                            <?=
                                            htmlspecialchars(
                                                (string) (
                                                    $row['fs_head']
                                                    ?? $row['issue']
                                                    ?? (isset($row['fs_heads']) ? implode(', ', (array) $row['fs_heads']) : '')
                                                )
                                            )
                                            ?>
                                        </td>
                                        <td class="num"><?= isset($row['amount']) ? formatMoney($row['amount']) : '' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
