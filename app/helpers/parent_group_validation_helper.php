<?php

function normalizeParentGroupNature(string $parentGroup): ?string
{
    $group = strtolower(trim($parentGroup));
    $group = str_replace(['&', '-', '_', '/', '.', ','], ' ', $group);
    $group = preg_replace('/\s+/', ' ', $group);

    if ($group === '') {
        return null;
    }

    $assetPriorityMarkers = [
        'sundry debtors',
        'trade debtors',
        'debtors',
        'current assets',
        'fixed assets',
        'bank accounts',
        'bank od accounts',
        'cash in hand',
        'cash at bank',
        'stock in hand',
        'inventory',
        'loans and advances asset',
        'investments',
    ];
    foreach ($assetPriorityMarkers as $marker) {
        if (str_contains($group, $marker)) {
            return 'asset';
        }
    }

    $liabilityPriorityMarkers = [
        'sundry creditors',
        'trade creditors',
        'creditors',
        'current liabilities',
        'capital account',
        'capital',
        'reserves',
        'surplus',
        'secured loans',
        'unsecured loans',
        'loans liability',
        'duties and taxes',
        'provisions',
        'branch divisions',
    ];
    foreach ($liabilityPriorityMarkers as $marker) {
        if (str_contains($group, $marker)) {
            return 'liability';
        }
    }

    $expenseMarkers = [
        'direct expenses',
        'indirect expenses',
        'expenses',
        'expense',
        'purchase accounts',
        'purchases',
        'employee',
        'salary',
        'wages',
        'cost of sales',
        'finance cost',
        'bank charges',
        'depreciation',
    ];
    foreach ($expenseMarkers as $marker) {
        if (str_contains($group, $marker)) {
            return 'expense';
        }
    }

    $incomeMarkers = [
        'direct incomes',
        'indirect incomes',
        'income',
        'sales accounts',
        'sales',
        'revenue',
    ];
    foreach ($incomeMarkers as $marker) {
        if (str_contains($group, $marker)) {
            return 'income';
        }
    }

    $assetMarkers = [
        'deposit',
        'stock',
        'assets',
        'asset',
    ];
    foreach ($assetMarkers as $marker) {
        if (str_contains($group, $marker)) {
            return 'asset';
        }
    }

    $liabilityMarkers = [
        'liabilities',
        'liability',
    ];
    foreach ($liabilityMarkers as $marker) {
        if (str_contains($group, $marker)) {
            return 'liability';
        }
    }

    return null;
}

function normalizeScheduleCodeNature(string $scheduleCode): ?string
{
    $code = strtolower(trim($scheduleCode));
    $code = str_replace(['&', '-', ' '], ['and', '_', '_'], $code);
    $code = preg_replace('/_+/', '_', $code);

    if ($code === '') {
        return null;
    }

    $map = [
        'share_capital' => 'liability',
        'reserves' => 'liability',
        'lt_borrowings' => 'liability',
        'st_borrowings' => 'liability',
        'deferred_tax_liability' => 'liability',
        'other_non_current_liabilities' => 'liability',
        'long_term_provisions' => 'liability',
        'trade_payables' => 'liability',
        'trade_payables_msme' => 'liability',
        'other_financial_liabilities' => 'liability',
        'other_current_liabilities' => 'liability',
        'short_term_provisions' => 'liability',
        'ppe' => 'asset',
        'cwip' => 'asset',
        'intangible_assets' => 'asset',
        'investments_non_current' => 'asset',
        'loans_non_current' => 'asset',
        'deferred_tax_asset' => 'asset',
        'other_non_current_assets' => 'asset',
        'inventory' => 'asset',
        'investments_current' => 'asset',
        'receivables' => 'asset',
        'cash' => 'asset',
        'bank_balances_other' => 'asset',
        'loans_current' => 'asset',
        'other_current_assets' => 'asset',
        'revenue' => 'income',
        'other_income' => 'income',
        'materials' => 'expense',
        'purchase_stock' => 'expense',
        'inventory_change' => 'expense',
        'employee_cost' => 'expense',
        'finance_cost' => 'expense',
        'depreciation' => 'expense',
        'other_expenses' => 'expense',
    ];

    return $map[$code] ?? null;
}

function isScheduleCodeAllowedForParentGroup(string $parentGroup, string $scheduleCode): bool
{
    $groupNature = normalizeParentGroupNature($parentGroup);
    $codeNature = normalizeScheduleCodeNature($scheduleCode);

    if ($groupNature === null || $codeNature === null) {
        return true;
    }

    return $groupNature === $codeNature;
}

function buildParentGroupConflict(string $ledgerName, string $parentGroup, string $scheduleCode): array
{
    return [
        'ledger_name' => $ledgerName,
        'parent_group' => $parentGroup,
        'schedule_code' => $scheduleCode,
        'parent_group_nature' => normalizeParentGroupNature($parentGroup),
        'schedule_code_nature' => normalizeScheduleCodeNature($scheduleCode),
    ];
}

function ensureLedgerMappingOverrideColumn(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM ledger_mapping")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('override_parent_group', $columns, true)) {
        $pdo->exec("ALTER TABLE ledger_mapping ADD COLUMN override_parent_group TINYINT(1) NOT NULL DEFAULT 0");
    }
}
