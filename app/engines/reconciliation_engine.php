<?php

require_once __DIR__ . '/../helpers/parent_group_validation_helper.php';

function getCompanyLedgers(PDO $pdo, int $companyId, int $fyId = 0): array
{
    $sql = "
        SELECT
            0 AS id,
            tl.company_id,
            tl.fy_id,
            tl.ledger_name,
            COALESCE(tl.parent_group, '') AS parent_group,
            CAST(tl.amount AS DECIMAL(18,2)) AS amount,
            UPPER(TRIM(tl.dr_cr)) AS dr_cr,
            c.name AS company_name
        FROM tally_ledgers tl
        INNER JOIN companies c ON c.id = tl.company_id
        WHERE tl.company_id = ?
    ";

    $params = [$companyId];
    if ($fyId > 0) {
        $sql .= " AND tl.fy_id = ? ";
        $params[] = $fyId;
    }

    $sql .= " ORDER BY tl.ledger_name ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getLedgerMappings(PDO $pdo, int $companyId, int $fyId = 0): array
{
    ensureLedgerMappingOverrideColumn($pdo);
    $stmt = $pdo->prepare("
        SELECT
            tl.ledger_name,
            TRIM(COALESCE(lm.schedule_code, '')) AS fs_head,
            MAX(COALESCE(lm.override_parent_group, 0)) AS override_parent_group
        FROM tally_ledgers tl
        LEFT JOIN ledger_mapping lm
            ON lm.company_id = tl.company_id
            AND lm.ledger_name = tl.ledger_name
        WHERE tl.company_id = ?
        " . ($fyId > 0 ? " AND tl.fy_id = ? " : "") . "
        GROUP BY tl.ledger_name, lm.schedule_code
        ORDER BY tl.ledger_name
    ");
    $stmt->execute($fyId > 0 ? [$companyId, $fyId] : [$companyId]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ledgerKey = strtolower(trim((string) $row['ledger_name']));
        if (!isset($map[$ledgerKey])) {
            $map[$ledgerKey] = [
                'ledger_id' => 0,
                'ledger_name' => (string) $row['ledger_name'],
                'fs_heads' => [],
                'override_parent_group' => (int) ($row['override_parent_group'] ?? 0),
            ];
        } else {
            $map[$ledgerKey]['override_parent_group'] = max(
                (int) ($map[$ledgerKey]['override_parent_group'] ?? 0),
                (int) ($row['override_parent_group'] ?? 0)
            );
        }

        if ($row['fs_head'] !== '') {
            $map[$ledgerKey]['fs_heads'][] = (string) $row['fs_head'];
        }
    }

    return $map;
}

function validateTrialBalance(array $ledgers): array
{
    $totalDebit = 0.0;
    $totalCredit = 0.0;
    $invalidRows = [];

    foreach ($ledgers as $ledger) {
        $amount = abs((float) ($ledger['amount'] ?? 0));
        $side = strtoupper(trim((string) ($ledger['dr_cr'] ?? '')));

        if (!in_array($side, ['DR', 'CR'], true)) {
            $invalidRows[] = [
                'ledger_id' => (int) ($ledger['id'] ?? 0),
                'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
                'issue' => 'Invalid DR/CR flag',
            ];
            continue;
        }

        if ($side === 'DR') {
            $totalDebit += $amount;
        } else {
            $totalCredit += $amount;
        }
    }

    $difference = round($totalDebit - $totalCredit, 2);

    return [
        'ok' => empty($invalidRows) && abs($difference) < 0.01,
        'total_debit' => round($totalDebit, 2),
        'total_credit' => round($totalCredit, 2),
        'difference' => $difference,
        'errors' => $invalidRows,
    ];
}

function normalizeAmounts(array $ledgers): array
{
    $normalized = [];

    foreach ($ledgers as $ledger) {
        $amount = abs((float) ($ledger['amount'] ?? 0));
        $side = strtoupper(trim((string) ($ledger['dr_cr'] ?? '')));

        $ledger['normalized_amount'] = $side === 'CR' ? -1 * $amount : $amount;
        $normalized[] = $ledger;
    }

    return $normalized;
}

function checkUnmappedLedgers(array $ledgers, array $mappingByLedgerId): array
{
    $unmapped = [];

    foreach ($ledgers as $ledger) {
        $ledgerId = (int) ($ledger['id'] ?? 0);
        $ledgerKey = strtolower(trim((string) ($ledger['ledger_name'] ?? '')));
        $mappedHeads = $mappingByLedgerId[$ledgerKey]['fs_heads'] ?? [];
        $validHeads = array_values(array_filter($mappedHeads, static function ($head): bool {
            $normalized = normalizeFsHead((string) $head);
            return $normalized !== '' && !in_array($normalized, ['exclude', 'excluded'], true);
        }));

        if ($validHeads === []) {
            $unmapped[] = [
                'ledger_id' => $ledgerId,
                'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
                'amount' => round((float) ($ledger['normalized_amount'] ?? 0), 2),
            ];
        }
    }

    return $unmapped;
}

function calculateProfit(array $ledgers, array $mappingByLedgerId): array
{
    $income = 0.0;
    $expense = 0.0;
    $incomeRows = [];
    $expenseRows = [];

    foreach ($ledgers as $ledger) {
        $ledgerKey = strtolower(trim((string) ($ledger['ledger_name'] ?? '')));
        $mappedHead = primaryMappedHead($mappingByLedgerId[$ledgerKey]['fs_heads'] ?? []);
        $amount = round((float) ($ledger['normalized_amount'] ?? 0), 2);
        $ledgerId = (int) ($ledger['id'] ?? 0);

        if ($mappedHead === null) {
            continue;
        }

        $override = (int) ($mappingByLedgerId[$ledgerKey]['override_parent_group'] ?? 0) === 1;
        if (!$override && !isScheduleCodeAllowedForParentGroup((string) ($ledger['parent_group'] ?? ''), $mappedHead)) {
            continue;
        }

        if (isIncomeHead($mappedHead)) {
            // Income heads are credit-natured, so debit-side rows such as sales returns
            // must reduce the total instead of being forced positive.
            $value = round(-1 * $amount, 2);
            $income += $value;
            $incomeRows[] = [
                'ledger_id' => $ledgerId,
                'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
                'fs_head' => $mappedHead,
                'amount' => round($value, 2),
            ];
            continue;
        }

        if (isExpenseHead($mappedHead)) {
            // Expense heads are debit-natured, so credit-side reversals must stay negative.
            $value = round($amount, 2);
            $expense += $value;
            $expenseRows[] = [
                'ledger_id' => $ledgerId,
                'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
                'fs_head' => $mappedHead,
                'amount' => round($value, 2),
            ];
        }
    }

    return [
        'income' => round($income, 2),
        'expense' => round($expense, 2),
        'profit' => round($income - $expense, 2),
        'income_rows' => $incomeRows,
        'expense_rows' => $expenseRows,
    ];
}

function buildBalanceSheet(array $ledgers, array $mappingByLedgerId, float $profit): array
{
    $assets = 0.0;
    $liabilities = 0.0;
    $capital = 0.0;
    $assetRows = [];
    $liabilityRows = [];
    $capitalRows = [];
    $profitTransferred = false;

    foreach ($ledgers as $ledger) {
        $ledgerKey = strtolower(trim((string) ($ledger['ledger_name'] ?? '')));
        $mappedHead = primaryMappedHead($mappingByLedgerId[$ledgerKey]['fs_heads'] ?? []);
        $amount = round((float) ($ledger['normalized_amount'] ?? 0), 2);
        $ledgerId = (int) ($ledger['id'] ?? 0);

        if ($mappedHead === null) {
            continue;
        }

        $override = (int) ($mappingByLedgerId[$ledgerKey]['override_parent_group'] ?? 0) === 1;
        if (!$override && !isScheduleCodeAllowedForParentGroup((string) ($ledger['parent_group'] ?? ''), $mappedHead)) {
            continue;
        }

        if (isAssetHead($mappedHead)) {
            // Assets are debit-natured; credit items should reduce the asset balance.
            $value = round($amount, 2);
            $assets += $value;
            $assetRows[] = buildBreakdownRow($ledger, $mappedHead, $value);
            continue;
        }

        if (isLiabilityHead($mappedHead)) {
            // Liabilities are credit-natured; debit items should reduce the liability balance.
            $value = round(-1 * $amount, 2);
            $liabilities += $value;
            $liabilityRows[] = buildBreakdownRow($ledger, $mappedHead, $value);
            continue;
        }

        if (isCapitalHead($mappedHead)) {
            // Capital/equity is credit-natured; debit items should reduce the capital balance.
            $value = round(-1 * $amount, 2);
            $capital += $value;
            $capitalRows[] = buildBreakdownRow($ledger, $mappedHead, $value);
            $profitTransferred = true;
        }
    }

    $capital += $profit;
    $capitalRows[] = [
        'ledger_id' => null,
        'ledger_name' => 'Profit / Loss transferred to Capital / Partners Fund',
        'fs_head' => 'profit_transfer',
        'amount' => round($profit, 2),
        'temporary' => false,
    ];

    return [
        'assets' => round($assets, 2),
        'liabilities' => round($liabilities, 2),
        'capital' => round($capital, 2),
        'asset_rows' => $assetRows,
        'liability_rows' => $liabilityRows,
        'capital_rows' => $capitalRows,
        'profit_transferred' => $profitTransferred,
    ];
}

function calculateDifference(float $assets, float $liabilities, float $capital): float
{
    return round($assets - ($liabilities + $capital), 2);
}

function traceDifference(array $ledgers, array $mappingByLedgerId, float $difference): array
{
    $breakdown = [];

    $unmapped = checkUnmappedLedgers($ledgers, $mappingByLedgerId);
    if ($unmapped !== []) {
        $breakdown[] = [
            'type' => 'unmapped_ledgers',
            'count' => count($unmapped),
            'items' => $unmapped,
        ];
    }

    $duplicateMappings = [];
    foreach ($mappingByLedgerId as $map) {
        $heads = array_values(array_unique(array_map('normalizeFsHead', $map['fs_heads'])));
        $heads = array_values(array_filter($heads, static fn ($head) => $head !== ''));
        if (count($heads) > 1) {
            $duplicateMappings[] = [
                'ledger_id' => (int) $map['ledger_id'],
                'ledger_name' => (string) $map['ledger_name'],
                'fs_heads' => $heads,
            ];
        }
    }
    if ($duplicateMappings !== []) {
        $breakdown[] = [
            'type' => 'duplicate_mappings',
            'count' => count($duplicateMappings),
            'items' => $duplicateMappings,
        ];
    }

    $parentGroupConflicts = [];
    foreach ($ledgers as $ledger) {
        $ledgerKey = strtolower(trim((string) ($ledger['ledger_name'] ?? '')));
        $mappedHead = primaryMappedHead($mappingByLedgerId[$ledgerKey]['fs_heads'] ?? []);
        $override = (int) ($mappingByLedgerId[$ledgerKey]['override_parent_group'] ?? 0) === 1;
        if ($mappedHead === null) {
            continue;
        }

        if (!$override && !isScheduleCodeAllowedForParentGroup((string) ($ledger['parent_group'] ?? ''), $mappedHead)) {
            $parentGroupConflicts[] = buildParentGroupConflict(
                (string) ($ledger['ledger_name'] ?? ''),
                (string) ($ledger['parent_group'] ?? ''),
                $mappedHead
            );
        }
    }
    if ($parentGroupConflicts !== []) {
        $breakdown[] = [
            'type' => 'parent_group_conflicts',
            'count' => count($parentGroupConflicts),
            'items' => $parentGroupConflicts,
        ];
    }

    $excludedLedgers = [];
    foreach ($ledgers as $ledger) {
        $ledgerKey = strtolower(trim((string) ($ledger['ledger_name'] ?? '')));
        $mappedHead = primaryMappedHead($mappingByLedgerId[$ledgerKey]['fs_heads'] ?? []);
        $ledgerId = (int) ($ledger['id'] ?? 0);
        if ($mappedHead === 'exclude' || $mappedHead === 'excluded') {
            $excludedLedgers[] = [
                'ledger_id' => $ledgerId,
                'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
                'amount' => round((float) ($ledger['normalized_amount'] ?? 0), 2),
            ];
        }
    }
    if ($excludedLedgers !== []) {
        $breakdown[] = [
            'type' => 'excluded_ledgers',
            'count' => count($excludedLedgers),
            'items' => $excludedLedgers,
        ];
    }

    if (abs($difference) >= 0.01) {
        $breakdown[] = [
            'type' => 'temporary_suspense',
            'ledger_name' => 'Balance Sheet Difference A/c',
            'amount' => round($difference, 2),
            'temporary' => true,
            'note' => 'Temporary suspense created until reconciliation is resolved.',
        ];
    }

    return $breakdown;
}

function buildReconciliationBridge(array $ledgers, array $mappingByLedgerId): array
{
    $rows = [];
    $statusCounts = [
        'included_asset' => 0,
        'included_liability' => 0,
        'included_capital' => 0,
        'included_income' => 0,
        'included_expense' => 0,
        'unmapped' => 0,
        'duplicate_mapping' => 0,
        'excluded' => 0,
        'parent_group_conflict' => 0,
    ];
    $statusImpact = [
        'unmapped' => 0.0,
        'duplicate_mapping' => 0.0,
        'excluded' => 0.0,
        'parent_group_conflict' => 0.0,
    ];

    foreach ($ledgers as $ledger) {
        $ledgerKey = strtolower(trim((string) ($ledger['ledger_name'] ?? '')));
        $mappedHeads = array_values(array_filter(array_map(
            static fn ($head) => trim((string) $head),
            $mappingByLedgerId[$ledgerKey]['fs_heads'] ?? []
        )));
        $normalizedHeads = array_values(array_unique(array_filter(array_map(
            static fn ($head) => normalizeFsHead((string) $head),
            $mappedHeads
        ))));
        $override = (int) ($mappingByLedgerId[$ledgerKey]['override_parent_group'] ?? 0) === 1;
        $primaryCode = $mappedHeads[0] ?? null;
        $primaryHead = $primaryCode !== null ? normalizeFsHead($primaryCode) : null;
        $parentGroup = (string) ($ledger['parent_group'] ?? '');
        $amount = round((float) ($ledger['normalized_amount'] ?? 0), 2);
        $absAmount = round(abs($amount), 2);
        $status = 'unmapped';
        $statementBucket = '';
        $estimatedEffect = estimateDifferenceImpact($amount, $parentGroup, $primaryHead);

        if ($primaryHead === null) {
            $status = 'unmapped';
        } elseif (count(array_unique($mappedHeads)) > 1) {
            $status = 'duplicate_mapping';
        } elseif (in_array($primaryHead, ['exclude', 'excluded'], true)) {
            $status = 'excluded';
        } elseif (!$override && !isScheduleCodeAllowedForParentGroup($parentGroup, $primaryHead)) {
            $status = 'parent_group_conflict';
        } elseif (isAssetHead($primaryHead)) {
            $status = 'included_asset';
            $statementBucket = 'Asset';
        } elseif (isLiabilityHead($primaryHead)) {
            $status = 'included_liability';
            $statementBucket = 'Liability';
        } elseif (isCapitalHead($primaryHead)) {
            $status = 'included_capital';
            $statementBucket = 'Capital';
        } elseif (isIncomeHead($primaryHead)) {
            $status = 'included_income';
            $statementBucket = 'Income';
        } elseif (isExpenseHead($primaryHead)) {
            $status = 'included_expense';
            $statementBucket = 'Expense';
        }

        $statusCounts[$status]++;
        if (isset($statusImpact[$status])) {
            $statusImpact[$status] += $estimatedEffect;
        }

        $rows[] = [
            'ledger_id' => (int) ($ledger['id'] ?? 0),
            'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
            'parent_group' => $parentGroup,
            'parent_group_nature' => normalizeParentGroupNature($parentGroup),
            'dr_cr' => strtoupper(trim((string) ($ledger['dr_cr'] ?? ''))),
            'amount' => $absAmount,
            'signed_amount' => $amount,
            'mapped_code' => $primaryCode ?? '',
            'mapped_head' => $primaryHead ?? '',
            'mapped_codes' => $mappedHeads,
            'mapped_heads' => $normalizedHeads,
            'mapped_nature' => $primaryHead !== null ? normalizeScheduleCodeNature($primaryHead) : null,
            'status' => $status,
            'statement_bucket' => $statementBucket,
            'estimated_effect' => round($estimatedEffect, 2),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $statusCompare = strcmp((string) ($a['status'] ?? ''), (string) ($b['status'] ?? ''));
        if ($statusCompare !== 0) {
            return $statusCompare;
        }

        $impactCompare = abs((float) ($b['estimated_effect'] ?? 0)) <=> abs((float) ($a['estimated_effect'] ?? 0));
        if ($impactCompare !== 0) {
            return $impactCompare;
        }

        return strcasecmp((string) ($a['ledger_name'] ?? ''), (string) ($b['ledger_name'] ?? ''));
    });

    $topContributors = array_values(array_filter($rows, static function (array $row): bool {
        return in_array((string) ($row['status'] ?? ''), ['unmapped', 'duplicate_mapping', 'excluded', 'parent_group_conflict'], true);
    }));
    usort($topContributors, static function (array $a, array $b): int {
        return abs((float) ($b['estimated_effect'] ?? 0)) <=> abs((float) ($a['estimated_effect'] ?? 0));
    });
    $topContributors = array_slice($topContributors, 0, 15);

    return [
        'type' => 'reconciliation_bridge',
        'count' => count($rows),
        'status_counts' => $statusCounts,
        'status_impact' => array_map(static fn ($value) => round((float) $value, 2), $statusImpact),
        'top_contributors' => $topContributors,
        'items' => $rows,
    ];
}

function runBalanceSheetValidation(PDO $pdo, int $companyId, int $fyId = 0): array
{
    $result = [
        'trial_balance_status' => 'ERROR',
        'unmapped_ledgers' => [],
        'profit' => 0.0,
        'assets' => 0.0,
        'liabilities' => 0.0,
        'capital' => 0.0,
        'difference' => 0.0,
        'difference_breakdown' => [],
        'status' => 'NOT TALLY',
    ];

    $ledgers = getCompanyLedgers($pdo, $companyId, $fyId);
    if ($ledgers === []) {
        $result['difference_breakdown'][] = [
            'type' => 'data',
            'message' => 'No ledgers found for the selected company.',
        ];
        return $result;
    }

    $tb = validateTrialBalance($ledgers);
    if (!$tb['ok']) {
        $result['difference'] = $tb['difference'];
        $result['difference_breakdown'][] = [
            'type' => 'trial_balance_mismatch',
            'total_debit' => $tb['total_debit'],
            'total_credit' => $tb['total_credit'],
            'difference' => $tb['difference'],
            'errors' => $tb['errors'],
        ];
        return $result;
    }

    $result['trial_balance_status'] = 'OK';

    $normalizedLedgers = normalizeAmounts($ledgers);
    $mappingByLedgerId = getLedgerMappings($pdo, $companyId, $fyId);
    $unmapped = checkUnmappedLedgers($normalizedLedgers, $mappingByLedgerId);

    if ($unmapped !== []) {
        $result['unmapped_ledgers'] = $unmapped;
        $result['difference_breakdown'][] = [
            'type' => 'unmapped_ledgers',
            'count' => count($unmapped),
            'items' => $unmapped,
        ];
        return $result;
    }

    $profitData = calculateProfit($normalizedLedgers, $mappingByLedgerId);
    $balanceSheet = buildBalanceSheet($normalizedLedgers, $mappingByLedgerId, $profitData['profit']);
    $difference = calculateDifference($balanceSheet['assets'], $balanceSheet['liabilities'], $balanceSheet['capital']);
    $bridge = buildReconciliationBridge($normalizedLedgers, $mappingByLedgerId);
    $differenceBreakdown = traceDifference($normalizedLedgers, $mappingByLedgerId, $difference);

    $result['profit'] = $profitData['profit'];
    $result['assets'] = $balanceSheet['assets'];
    $result['liabilities'] = $balanceSheet['liabilities'];
    $result['capital'] = $balanceSheet['capital'];
    $result['difference'] = $difference;
    $result['difference_breakdown'] = array_merge([
        [
            'type' => 'profit_computation',
            'income' => $profitData['income'],
            'expense' => $profitData['expense'],
            'profit' => $profitData['profit'],
            'income_rows' => $profitData['income_rows'],
            'expense_rows' => $profitData['expense_rows'],
        ],
        [
            'type' => 'balance_sheet_build',
            'assets' => $balanceSheet['assets'],
            'liabilities' => $balanceSheet['liabilities'],
            'capital' => $balanceSheet['capital'],
            'profit_transferred' => $balanceSheet['profit_transferred'],
            'asset_rows' => $balanceSheet['asset_rows'],
            'liability_rows' => $balanceSheet['liability_rows'],
            'capital_rows' => $balanceSheet['capital_rows'],
        ],
        $bridge,
    ], $differenceBreakdown);
    $result['status'] = abs($difference) < 0.01 ? 'TALLY' : 'NOT TALLY';

    return $result;
}

function buildBreakdownRow(array $ledger, string $head, float $amount): array
{
    return [
        'ledger_id' => (int) ($ledger['id'] ?? 0),
        'ledger_name' => (string) ($ledger['ledger_name'] ?? ''),
        'fs_head' => $head,
        'amount' => round($amount, 2),
        'temporary' => false,
    ];
}

function primaryMappedHead(array $heads): ?string
{
    foreach ($heads as $head) {
        $normalized = normalizeFsHead((string) $head);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return null;
}

function normalizeFsHead(string $head): string
{
    $head = strtolower(trim($head));
    $head = str_replace(['&', '/', '-', '(', ')', '_'], ' ', $head);
    $head = preg_replace('/\s+/', ' ', $head);

    $aliases = [
        'asset' => 'asset',
        'assets' => 'asset',
        'current asset' => 'asset',
        'current assets' => 'asset',
        'non current asset' => 'asset',
        'non current assets' => 'asset',
        'fixed asset' => 'asset',
        'fixed assets' => 'asset',
        'inventory' => 'asset',
        'inventories' => 'asset',
        'property plant and equipment' => 'asset',
        'capital work in progress' => 'asset',
        'intangible assets' => 'asset',
        'non current investments' => 'asset',
        'current investments' => 'asset',
        'non current loans and advances' => 'asset',
        'current loans and advances' => 'asset',
        'deferred tax asset' => 'asset',
        'other non current assets' => 'asset',
        'other current assets' => 'asset',
        'other bank balances' => 'asset',
        'trade receivable' => 'asset',
        'trade receivables' => 'asset',
        'cash bank' => 'asset',
        'cash and bank' => 'asset',
        'cash' => 'asset',
        'bank' => 'asset',
        'receivables' => 'asset',
        'ppe' => 'asset',
        'cwip' => 'asset',
        'intangible assets' => 'asset',
        'investments non current' => 'asset',
        'investments current' => 'asset',
        'loans non current' => 'asset',
        'loans current' => 'asset',
        'deferred tax asset' => 'asset',
        'other non current assets' => 'asset',
        'other current assets' => 'asset',
        'bank balances other' => 'asset',
        'liability' => 'liability',
        'liabilities' => 'liability',
        'current liability' => 'liability',
        'current liabilities' => 'liability',
        'non current liability' => 'liability',
        'non current liabilities' => 'liability',
        'trade payable' => 'liability',
        'trade payables' => 'liability',
        'borrowings' => 'liability',
        'long term borrowings' => 'liability',
        'short term borrowings' => 'liability',
        'deferred tax liability' => 'liability',
        'other non current liabilities' => 'liability',
        'long term provisions' => 'liability',
        'trade payables msme' => 'liability',
        'other financial liabilities' => 'liability',
        'other current liabilities' => 'liability',
        'short term provisions' => 'liability',
        'loan' => 'liability',
        'loans' => 'liability',
        'lt borrowings' => 'liability',
        'st borrowings' => 'liability',
        'trade payables msme' => 'liability',
        'other financial liabilities' => 'liability',
        'other current liabilities' => 'liability',
        'short term provisions' => 'liability',
        'capital' => 'capital',
        'capital account' => 'capital',
        'owners fund' => 'capital',
        'owners funds' => 'capital',
        'partners fund' => 'capital',
        'partners funds' => 'capital',
        'equity' => 'capital',
        'share capital' => 'capital',
        'reserve' => 'capital',
        'reserves' => 'capital',
        'surplus' => 'capital',
        'other equity' => 'capital',
        'income' => 'income',
        'revenue' => 'income',
        'sales' => 'income',
        'other income' => 'income',
        'revenue from operations' => 'income',
        'expense' => 'expense',
        'expenses' => 'expense',
        'employee cost' => 'expense',
        'employee benefit expense' => 'expense',
        'finance cost' => 'expense',
        'depreciation' => 'expense',
        'depreciation and amortisation' => 'expense',
        'purchase' => 'expense',
        'purchases' => 'expense',
        'material consumed' => 'expense',
        'cost of materials consumed' => 'expense',
        'purchase of stock in trade' => 'expense',
        'changes in inventories' => 'expense',
        'other expenses' => 'expense',
        'materials' => 'expense',
        'purchase stock' => 'expense',
        'inventory change' => 'expense',
        'employee cost' => 'expense',
        'finance cost' => 'expense',
        'other expenses' => 'expense',
        'exclude' => 'exclude',
        'excluded' => 'excluded',
    ];

    return $aliases[$head] ?? $head;
}

function isIncomeHead(string $head): bool
{
    return normalizeFsHead($head) === 'income';
}

function isExpenseHead(string $head): bool
{
    return normalizeFsHead($head) === 'expense';
}

function isAssetHead(string $head): bool
{
    return normalizeFsHead($head) === 'asset';
}

function isLiabilityHead(string $head): bool
{
    return normalizeFsHead($head) === 'liability';
}

function isCapitalHead(string $head): bool
{
    return normalizeFsHead($head) === 'capital';
}

function estimateDifferenceImpact(float $signedAmount, string $parentGroup, ?string $mappedHead): float
{
    if ($mappedHead !== null) {
        if (isAssetHead($mappedHead)) {
            return round(abs($signedAmount), 2);
        }

        if (isLiabilityHead($mappedHead) || isCapitalHead($mappedHead)) {
            return round(-1 * abs($signedAmount), 2);
        }

        if (isIncomeHead($mappedHead)) {
            return round(abs($signedAmount), 2);
        }

        if (isExpenseHead($mappedHead)) {
            return round(-1 * abs($signedAmount), 2);
        }
    }

    $parentNature = normalizeParentGroupNature($parentGroup);
    if ($parentNature === 'asset') {
        return round(abs($signedAmount), 2);
    }
    if ($parentNature === 'liability') {
        return round(-1 * abs($signedAmount), 2);
    }
    if ($parentNature === 'income') {
        return round(abs($signedAmount), 2);
    }
    if ($parentNature === 'expense') {
        return round(-1 * abs($signedAmount), 2);
    }

    return 0.0;
}
