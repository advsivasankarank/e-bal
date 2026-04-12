<?php

require_once __DIR__ . '/../helpers/parent_group_validation_helper.php';

function normalizeScheduleBucket($scheduleCode)
{
    $scheduleCode = strtolower(trim((string) $scheduleCode));
    $scheduleCode = str_replace(['&', '-', ' '], ['and', '_', '_'], $scheduleCode);
    $scheduleCode = preg_replace('/_+/', '_', $scheduleCode);

    if ($scheduleCode === '') {
        return null;
    }

    $explicitBuckets = [
        'share_capital' => 'equity',
        'reserves' => 'equity',
        'lt_borrowings' => 'borrowings',
        'st_borrowings' => 'borrowings',
        'deferred_tax_liability' => 'current_liabilities',
        'other_non_current_liabilities' => 'current_liabilities',
        'long_term_provisions' => 'current_liabilities',
        'trade_payables' => 'current_liabilities',
        'trade_payables_msme' => 'current_liabilities',
        'other_financial_liabilities' => 'current_liabilities',
        'other_current_liabilities' => 'current_liabilities',
        'short_term_provisions' => 'current_liabilities',
        'ppe' => 'non_current_assets',
        'cwip' => 'non_current_assets',
        'intangible_assets' => 'non_current_assets',
        'investments_non_current' => 'non_current_assets',
        'loans_non_current' => 'non_current_assets',
        'deferred_tax_asset' => 'non_current_assets',
        'other_non_current_assets' => 'non_current_assets',
        'inventory' => 'current_assets',
        'investments_current' => 'current_assets',
        'receivables' => 'current_assets',
        'cash' => 'current_assets',
        'bank_balances_other' => 'current_assets',
        'loans_current' => 'current_assets',
        'other_current_assets' => 'current_assets',
        'revenue' => 'revenue',
        'other_income' => 'revenue',
        'materials' => 'expenses',
        'purchase_stock' => 'expenses',
        'inventory_change' => 'expenses',
        'employee_cost' => 'expenses',
        'finance_cost' => 'expenses',
        'depreciation' => 'expenses',
        'other_expenses' => 'expenses',
    ];

    if (isset($explicitBuckets[$scheduleCode])) {
        return $explicitBuckets[$scheduleCode];
    }

    if (str_contains($scheduleCode, 'equity') || str_contains($scheduleCode, 'capital') || str_contains($scheduleCode, 'partner')) {
        return 'equity';
    }

    if (str_contains($scheduleCode, 'borrowing') || str_contains($scheduleCode, 'loan') || str_contains($scheduleCode, 'debt')) {
        return 'borrowings';
    }

    if (str_contains($scheduleCode, 'liabilit') || str_contains($scheduleCode, 'creditor') || str_contains($scheduleCode, 'payable') || str_contains($scheduleCode, 'provision')) {
        return 'current_liabilities';
    }

    if (str_contains($scheduleCode, 'asset') || str_contains($scheduleCode, 'fixed') || str_contains($scheduleCode, 'property') || str_contains($scheduleCode, 'inventory') || str_contains($scheduleCode, 'receivable') || str_contains($scheduleCode, 'cash') || str_contains($scheduleCode, 'bank')) {
        return str_contains($scheduleCode, 'non_current') || str_contains($scheduleCode, 'fixed') || str_contains($scheduleCode, 'property') || str_contains($scheduleCode, 'intangible')
            ? 'non_current_assets'
            : 'current_assets';
    }

    if (str_contains($scheduleCode, 'revenue') || str_contains($scheduleCode, 'income') || str_contains($scheduleCode, 'sales')) {
        return 'revenue';
    }

    if (str_contains($scheduleCode, 'expense') || str_contains($scheduleCode, 'cost') || str_contains($scheduleCode, 'purchase')) {
        return 'expenses';
    }

    return null;
}

function scheduleCodeLabel($scheduleCode)
{
    static $labels = [
        'share_capital' => 'Share Capital',
        'reserves' => 'Reserves and Surplus',
        'lt_borrowings' => 'Long-term Borrowings',
        'deferred_tax_liability' => 'Deferred Tax Liability',
        'other_non_current_liabilities' => 'Other Non-current Liabilities',
        'long_term_provisions' => 'Long-term Provisions',
        'st_borrowings' => 'Short-term Borrowings',
        'trade_payables' => 'Trade Payables',
        'trade_payables_msme' => 'Trade Payables - MSME',
        'other_financial_liabilities' => 'Other Financial Liabilities',
        'other_current_liabilities' => 'Other Current Liabilities',
        'short_term_provisions' => 'Short-term Provisions',
        'ppe' => 'Property, Plant and Equipment',
        'cwip' => 'Capital Work-in-Progress',
        'intangible_assets' => 'Intangible Assets',
        'investments_non_current' => 'Non-current Investments',
        'loans_non_current' => 'Non-current Loans and Advances',
        'deferred_tax_asset' => 'Deferred Tax Asset',
        'other_non_current_assets' => 'Other Non-current Assets',
        'inventory' => 'Inventories',
        'investments_current' => 'Current Investments',
        'receivables' => 'Trade Receivables',
        'cash' => 'Cash and Cash Equivalents',
        'bank_balances_other' => 'Other Bank Balances',
        'loans_current' => 'Current Loans and Advances',
        'other_current_assets' => 'Other Current Assets',
        'revenue' => 'Revenue from Operations',
        'other_income' => 'Other Income',
        'materials' => 'Cost of Materials Consumed',
        'purchase_stock' => 'Purchase of Stock-in-Trade',
        'inventory_change' => 'Changes in Inventories',
        'employee_cost' => 'Employee Benefit Expense',
        'finance_cost' => 'Finance Cost',
        'depreciation' => 'Depreciation and Amortisation',
        'other_expenses' => 'Other Expenses',
    ];

    if (isset($labels[$scheduleCode])) {
        return $labels[$scheduleCode];
    }

    return ucwords(str_replace('_', ' ', (string) $scheduleCode));
}

function getClassifiedData(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureLedgerMappingOverrideColumn($pdo);
    $columns = $pdo->query("SHOW COLUMNS FROM tally_ledgers")->fetchAll(PDO::FETCH_COLUMN);
    $hasOpeningColumns = in_array('opening_amount', $columns, true) && in_array('opening_dr_cr', $columns, true);

    $stmt = $pdo->prepare("
        SELECT
            lm.schedule_code,
            COALESCE(lm.override_parent_group, 0) AS override_parent_group,
            tb.ledger_name,
            COALESCE(tlm.parent_group, '') AS parent_group,
            SUM(CASE WHEN tb.dr_cr = 'DR' THEN tb.amount ELSE 0 END) AS dr_total,
            SUM(CASE WHEN tb.dr_cr = 'CR' THEN tb.amount ELSE 0 END) AS cr_total" .
            ($hasOpeningColumns ? ",
            SUM(CASE WHEN tb.opening_dr_cr = 'DR' THEN tb.opening_amount ELSE 0 END) AS opening_dr_total,
            SUM(CASE WHEN tb.opening_dr_cr = 'CR' THEN tb.opening_amount ELSE 0 END) AS opening_cr_total" : ",
            0 AS opening_dr_total,
            0 AS opening_cr_total") . "
        FROM ledger_mapping lm
        JOIN tally_ledgers tb
            ON tb.company_id = lm.company_id
            AND tb.ledger_name = lm.ledger_name
        LEFT JOIN tally_ledger_master tlm
            ON tlm.company_id = tb.company_id
            AND tlm.ledger_name = tb.ledger_name
        WHERE lm.company_id = ? AND tb.fy_id = ?
        GROUP BY lm.schedule_code, tb.ledger_name, tlm.parent_group
        ORDER BY lm.schedule_code, tb.ledger_name
    ");
    $stmt->execute([$company_id, $fy_id]);

    $summary = [
        'equity' => 0.0,
        'borrowings' => 0.0,
        'current_liabilities' => 0.0,
        'current_assets' => 0.0,
        'non_current_assets' => 0.0,
        'revenue' => 0.0,
        'expenses' => 0.0,
    ];
    $previousSummary = $summary;
    $scheduleItems = [];
    $parentGroupConflicts = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $scheduleCode = trim((string) ($row['schedule_code'] ?? ''));
        if ($scheduleCode === '') {
            continue;
        }

        $parentGroup = (string) ($row['parent_group'] ?? '');
        $overrideParentGroup = (int) ($row['override_parent_group'] ?? 0) === 1;
        if (!$overrideParentGroup && !isScheduleCodeAllowedForParentGroup($parentGroup, $scheduleCode)) {
            $parentGroupConflicts[] = buildParentGroupConflict(
                (string) ($row['ledger_name'] ?? ''),
                $parentGroup,
                $scheduleCode
            );
            continue;
        }

        $bucket = normalizeScheduleBucket($scheduleCode);
        if ($bucket === null) {
            continue;
        }

        $drTotal = (float) ($row['dr_total'] ?? 0);
        $crTotal = (float) ($row['cr_total'] ?? 0);
        $openingDrTotal = (float) ($row['opening_dr_total'] ?? 0);
        $openingCrTotal = (float) ($row['opening_cr_total'] ?? 0);
        $isCreditDriven = in_array($bucket, ['equity', 'borrowings', 'current_liabilities', 'revenue'], true);
        $amount = $isCreditDriven ? ($crTotal - $drTotal) : ($drTotal - $crTotal);
        $previousAmount = $isCreditDriven ? ($openingCrTotal - $openingDrTotal) : ($openingDrTotal - $openingCrTotal);

        if (!isset($scheduleItems[$scheduleCode])) {
            $scheduleItems[$scheduleCode] = [
                'code' => $scheduleCode,
                'label' => scheduleCodeLabel($scheduleCode),
                'bucket' => $bucket,
                'amount' => 0.0,
                'previous_amount' => 0.0,
                'rows' => [],
            ];
        }

        $scheduleItems[$scheduleCode]['amount'] += $amount;
        $scheduleItems[$scheduleCode]['previous_amount'] += $previousAmount;
        if (abs($amount) > 0.00001 || abs($previousAmount) > 0.00001) {
            $scheduleItems[$scheduleCode]['rows'][] = [
                'ledger_name' => (string) $row['ledger_name'],
                'amount' => $amount,
                'previous_amount' => $previousAmount,
            ];
        }

        $summary[$bucket] += $amount;
        $previousSummary[$bucket] += $previousAmount;
    }

    $summary['assets_total'] = $summary['current_assets'] + $summary['non_current_assets'];
    $summary['liabilities_total'] = $summary['equity'] + $summary['borrowings'] + $summary['current_liabilities'];
    $summary['profit'] = $summary['revenue'] - $summary['expenses'];
    $previousSummary['assets_total'] = $previousSummary['current_assets'] + $previousSummary['non_current_assets'];
    $previousSummary['liabilities_total'] = $previousSummary['equity'] + $previousSummary['borrowings'] + $previousSummary['current_liabilities'];
    $previousSummary['profit'] = $previousSummary['revenue'] - $previousSummary['expenses'];

    return [
        'summary' => $summary,
        'previous_summary' => $previousSummary,
        'schedule_items' => $scheduleItems,
        'validation' => [
            'parent_group_conflicts' => $parentGroupConflicts,
        ],
    ];
}
