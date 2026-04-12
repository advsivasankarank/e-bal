<?php

class AIMappingEngine
{
    private $category;
    private $mapping;
    private $labels;
    private $aliases;

    public function __construct($category = 'corporate')
    {
        $this->category = strtolower(trim((string) $category));
        $this->mapping = $this->loadMapping();
        $this->labels = $this->buildLabels();
        $this->aliases = $this->loadAliases();
    }

    /* =========================
       MAIN FUNCTION
    ========================= */

    public function mapLedger($ledgerName, $groupName = '')
    {
        $ledger = $this->normalize($ledgerName);
        $group  = $this->normalize($groupName);
        $allowedHeads = $this->allowedHeadsForGroup($group);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($this->mapping as $head => $keywords) {
            if ($allowedHeads !== [] && !in_array($head, $allowedHeads, true)) {
                continue;
            }

            $candidates = $keywords;

            if (isset($this->aliases[$head])) {
                $candidates = array_merge($candidates, $this->aliases[$head]);
            }

            foreach ($candidates as $keyword) {
                $keyword = $this->normalize($keyword);
                if ($keyword === '') {
                    continue;
                }

                // 1. Direct match
                if ($ledger === $keyword || strpos($ledger, $keyword) !== false || strpos($keyword, $ledger) !== false) {
                    return [
                        'head' => $head,
                        'confidence' => 95,
                        'method' => 'direct'
                    ];
                }

                // 2. Fuzzy similarity
                similar_text($ledger, $keyword, $percent);

                if ($group !== '' && (strpos($group, $keyword) !== false || strpos($keyword, $group) !== false)) {
                    $percent = max($percent, 75);
                }

                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $bestMatch = $head;
                }
            }
        }

        // 3. Group-based intelligence (Tally groups)
        $groupHead = $this->mapByGroup($group);

        if (
            $groupHead
            && ($allowedHeads === [] || in_array($groupHead, $allowedHeads, true))
            && ($bestScore < 75 || ($allowedHeads !== [] && !in_array($bestMatch, $allowedHeads, true)))
        ) {
            return [
                'head' => $groupHead,
                'confidence' => 88,
                'method' => 'group'
            ];
        }

        return [
            'head' => $bestScore >= 40 ? ($bestMatch ?? 'unmapped') : 'unmapped',
            'confidence' => round($bestScore),
            'method' => 'fuzzy'
        ];
    }

    public function getMappingOptions()
    {
        return $this->labels;
    }

    public function getLabel($code)
    {
        return $this->labels[$code] ?? ucwords(str_replace('_', ' ', (string) $code));
    }

    /* =========================
       GROUP BASED LOGIC
    ========================= */

    private function mapByGroup($group)
    {
        if (strpos($group, 'capital') !== false) return 'share_capital';
        if (strpos($group, 'reserve') !== false) return 'reserves';
        if (strpos($group, 'secured loan') !== false) return 'lt_borrowings';
        if (strpos($group, 'current liabilities') !== false) return 'other_current_liabilities';
        if (strpos($group, 'sundry creditors') !== false) return 'trade_payables';
        if (strpos($group, 'sundry debtors') !== false) return 'receivables';
        if (strpos($group, 'cash') !== false) return 'cash';
        if (strpos($group, 'bank') !== false) return 'cash';
        if (strpos($group, 'fixed assets') !== false) return 'ppe';
        if (strpos($group, 'expenses') !== false) return 'other_expenses';
        if (strpos($group, 'income') !== false) return 'revenue';

        return null;
    }

    private function allowedHeadsForGroup($group)
    {
        if ($group === '') {
            return [];
        }

        $profitAndLossHeads = [
            'revenue',
            'other_income',
            'materials',
            'purchase_stock',
            'inventory_change',
            'employee_cost',
            'finance_cost',
            'depreciation',
            'other_expenses',
        ];

        $balanceSheetHeads = [
            'share_capital',
            'reserves',
            'lt_borrowings',
            'deferred_tax_liability',
            'other_non_current_liabilities',
            'long_term_provisions',
            'st_borrowings',
            'trade_payables',
            'trade_payables_msme',
            'other_financial_liabilities',
            'other_current_liabilities',
            'short_term_provisions',
            'ppe',
            'cwip',
            'intangible_assets',
            'investments_non_current',
            'loans_non_current',
            'deferred_tax_asset',
            'other_non_current_assets',
            'inventory',
            'investments_current',
            'receivables',
            'cash',
            'bank_balances_other',
            'loans_current',
            'other_current_assets',
        ];

        $assetPriorityMarkers = [
            'sundry debtors',
            'trade debtors',
            'debtors',
            'current assets',
            'fixed assets',
            'bank accounts',
            'cash in hand',
            'cash at bank',
            'stock in hand',
            'inventory',
            'investments',
            'asset',
            'assets',
        ];

        foreach ($assetPriorityMarkers as $marker) {
            if (strpos($group, $marker) !== false) {
                return $balanceSheetHeads;
            }
        }

        $liabilityPriorityMarkers = [
            'sundry creditors',
            'trade creditors',
            'creditors',
            'current liabilities',
            'capital',
            'loan',
            'liabilit',
            'provisions',
            'duties',
            'tax',
            'deposit received',
        ];

        foreach ($liabilityPriorityMarkers as $marker) {
            if (strpos($group, $marker) !== false) {
                return $balanceSheetHeads;
            }
        }

        $expenseMarkers = [
            'indirect expenses',
            'direct expenses',
            'purchase accounts',
            'purchases',
            'consumption',
            'employee',
            'salary',
            'wages',
            'finance cost',
            'bank charges',
            'depreciation',
            'expense',
            'expenses',
        ];

        foreach ($expenseMarkers as $marker) {
            if (strpos($group, $marker) !== false) {
                return $profitAndLossHeads;
            }
        }

        $incomeMarkers = [
            'direct incomes',
            'indirect incomes',
            'sales',
            'income',
            'revenue',
        ];

        foreach ($incomeMarkers as $marker) {
            if (strpos($group, $marker) !== false) {
                return ['revenue', 'other_income'];
            }
        }

        return [];
    }

    private function loadMapping()
    {
        if ($this->category === 'corporate') {
            return require __DIR__ . '/../../public/data_console/schedule3_mapping_list.php';
        }

        return [
            'non_current_assets' => ['fixed assets', 'plant', 'machinery', 'furniture', 'building'],
            'current_assets' => ['cash', 'bank', 'debtors', 'stock', 'inventory', 'receivable'],
            'equity' => ['capital', 'partner capital', 'owner funds'],
            'borrowings' => ['loan', 'borrowing', 'od', 'cash credit'],
            'current_liabilities' => ['creditors', 'payable', 'expenses payable', 'duties and taxes'],
            'revenue' => ['sales', 'income', 'service income'],
            'expenses' => ['salary', 'rent', 'purchase', 'expense'],
        ];
    }

    private function buildLabels()
    {
        $customLabels = [
            'share_capital' => 'Share Capital',
            'reserves' => 'Reserves and Surplus',
            'lt_borrowings' => 'Long-Term Borrowings',
            'deferred_tax_liability' => 'Deferred Tax Liability',
            'other_non_current_liabilities' => 'Other Non-Current Liabilities',
            'long_term_provisions' => 'Long-Term Provisions',
            'st_borrowings' => 'Short-Term Borrowings',
            'trade_payables' => 'Trade Payables',
            'trade_payables_msme' => 'Trade Payables - MSME',
            'other_financial_liabilities' => 'Other Financial Liabilities',
            'other_current_liabilities' => 'Other Current Liabilities',
            'short_term_provisions' => 'Short-Term Provisions',
            'ppe' => 'Property, Plant and Equipment',
            'cwip' => 'Capital Work in Progress',
            'intangible_assets' => 'Intangible Assets',
            'investments_non_current' => 'Non-Current Investments',
            'loans_non_current' => 'Non-Current Loans and Advances',
            'deferred_tax_asset' => 'Deferred Tax Asset',
            'other_non_current_assets' => 'Other Non-Current Assets',
            'inventory' => 'Inventories',
            'investments_current' => 'Current Investments',
            'receivables' => 'Trade Receivables',
            'cash' => 'Cash and Cash Equivalents',
            'bank_balances_other' => 'Other Bank Balances',
            'loans_current' => 'Short-Term Loans and Advances',
            'other_current_assets' => 'Other Current Assets',
            'revenue' => 'Revenue from Operations',
            'other_income' => 'Other Income',
            'materials' => 'Cost of Materials Consumed',
            'purchase_stock' => 'Purchase of Stock-in-Trade',
            'inventory_change' => 'Changes in Inventories',
            'employee_cost' => 'Employee Benefits Expense',
            'finance_cost' => 'Finance Costs',
            'depreciation' => 'Depreciation and Amortisation',
            'other_expenses' => 'Other Expenses',
            'non_current_assets' => 'Non-Current Assets',
            'current_assets' => 'Current Assets',
            'equity' => 'Equity / Capital',
            'borrowings' => 'Borrowings',
            'current_liabilities' => 'Current Liabilities',
            'expenses' => 'Expenses',
        ];

        $labels = [];
        foreach (array_keys($this->mapping) as $code) {
            $labels[$code] = $customLabels[$code] ?? ucwords(str_replace('_', ' ', $code));
        }

        return $labels;
    }

    private function loadAliases()
    {
        if ($this->category === 'corporate') {
            return [
                'receivables' => ['debtors', 'sundry debtors', 'trade debtors', 'book debts', 'accounts receivable', 'receivables'],
                'trade_payables' => ['creditors', 'sundry creditors', 'trade creditors', 'accounts payable', 'payables'],
                'cash' => ['cash in hand', 'cash at bank', 'bank account', 'cash balance'],
                'bank_balances_other' => ['fixed deposit', 'fd account', 'margin money', 'deposit with bank'],
                'inventory' => ['stock', 'closing stock', 'opening stock', 'raw material', 'finished goods', 'wip'],
                'share_capital' => ['share capital', 'equity share capital', 'preference share capital'],
                'reserves' => ['capital reserve', 'general reserve', 'surplus', 'retained earnings', 'profit and loss account'],
                'lt_borrowings' => ['term loan', 'secured loan', 'vehicle loan', 'housing loan'],
                'st_borrowings' => ['cash credit', 'od account', 'short term loan', 'overdraft'],
                'other_financial_liabilities' => ['outstanding expenses', 'salary payable', 'interest payable'],
                'other_current_liabilities' => ['gst payable', 'tds payable', 'statutory dues', 'advance from customers'],
                'short_term_provisions' => ['provision for tax', 'provision for expenses'],
                'ppe' => ['land', 'building', 'plant', 'machinery', 'furniture', 'office equipment', 'computer'],
                'intangible_assets' => ['goodwill', 'software', 'trademark'],
                'loans_non_current' => ['long term loans', 'loans and advances'],
                'other_current_assets' => ['prepaid expenses', 'input gst', 'other receivables'],
                'revenue' => ['sales', 'service income', 'turnover'],
                'other_income' => ['interest income', 'discount received', 'misc income', 'other income'],
                'materials' => ['purchases', 'raw material consumption'],
                'purchase_stock' => ['purchase of stock', 'stock purchase'],
                'inventory_change' => ['closing stock adjustment', 'changes in inventory'],
                'employee_cost' => ['salary', 'wages', 'bonus', 'pf', 'esi'],
                'finance_cost' => ['interest expense', 'bank charges', 'finance charges'],
                'depreciation' => ['depreciation', 'amortisation'],
                'other_expenses' => ['rent', 'electricity', 'office expenses', 'repairs', 'audit fees', 'legal charges'],
            ];
        }

        return [];
    }

    private function normalize($value)
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['&', '-', '_', '/', '.', ','], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return $value;
    }
}
