<?php
/**
 * e-BAL — Bulk Mapping Helper
 *
 * Provides:
 * - Previous FY mapping reuse (suggestion only)
 * - Global mapping master lookup (via mapping_learning)
 * - Enhanced keyword-based auto mapping
 * - Unified suggestion pipeline with confidence scoring
 *
 * DOES NOT modify existing mapping logic. Pure read-only suggestion engine.
 */

require_once __DIR__ . '/mapping_ai_helper.php';

/**
 * Get the previous FY for the same company.
 * Uses fy_start for ordering (not id) to handle non-sequential FY IDs.
 * Returns ['id' => int, 'fy_label' => string] or null.
 */
function getPreviousFyForCompany(PDO $pdo, int $companyId, int $currentFyId): ?array
{
    // Get the start date of the current FY
    $currentStmt = $pdo->prepare("SELECT fy_start FROM financial_years WHERE id = ? AND company_id = ?");
    $currentStmt->execute([$currentFyId, $companyId]);
    $currentStart = $currentStmt->fetchColumn();

    if (!$currentStart) {
        return null;
    }

    // Find the most recent FY that starts before the current FY
    $stmt = $pdo->prepare("
        SELECT id, fy_label
        FROM financial_years
        WHERE company_id = ?
          AND fy_start < ?
        ORDER BY fy_start DESC
        LIMIT 1
    ");
    $stmt->execute([$companyId, $currentStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Load previous FY mappings for the same company.
 * Since ledger_mapping has no FY dimension, we use mapping_master (which has fy_id)
 * for the previous FY. Falls back to current ledger_mapping if mapping_master is empty.
 * Returns [normalized_ledger_name => ['schedule_code' => ..., 'original_ledger' => ...]]
 */
function loadPreviousFyMappings(PDO $pdo, int $companyId, int $previousFyId): array
{
    $mappings = [];

    // Try mapping_master first (has FY dimension)
    try {
        $stmt = $pdo->prepare("
            SELECT ledger_name, schedule_head AS schedule_code
            FROM mapping_master
            WHERE company_id = ? AND fy_id = ?
            ORDER BY ledger_name
        ");
        $stmt->execute([$companyId, $previousFyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $normalized = normalizeMappingText($row['ledger_name']);
            if ($normalized !== '' && $row['schedule_code'] !== '') {
                $mappings[$normalized] = [
                    'schedule_code' => $row['schedule_code'],
                    'original_ledger' => $row['ledger_name'],
                ];
            }
        }
    } catch (Throwable $e) {
        // mapping_master table may not exist or may not have the column
    }

    // If mapping_master had no results, fall back to ledger_mapping
    if (empty($mappings)) {
        $stmt = $pdo->prepare("
            SELECT lm.ledger_name, lm.schedule_code
            FROM ledger_mapping lm
            WHERE lm.company_id = ?
            ORDER BY lm.ledger_name
        ");
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $normalized = normalizeMappingText($row['ledger_name']);
            if ($normalized !== '' && $row['schedule_code'] !== '') {
                $mappings[$normalized] = [
                    'schedule_code' => $row['schedule_code'],
                    'original_ledger' => $row['ledger_name'],
                ];
            }
        }
    }

    return $mappings;
}

/**
 * Load global mapping master from mapping_learning (scope='global').
 * Returns [normalized_ledger_name => ['schedule_code' => ..., 'original_ledger' => ..., 'usage_count' => int]]
 */
function loadGlobalMappingMaster(PDO $pdo): array
{
    ensureMappingLearningTable($pdo);

    $stmt = $pdo->prepare("
        SELECT normalized_ledger_name, original_ledger_name, schedule_code, usage_count
        FROM mapping_learning
        WHERE scope = 'global' AND company_id = 0
        ORDER BY usage_count DESC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mappings = [];
    foreach ($rows as $row) {
        $key = $row['normalized_ledger_name'];
        if ($key !== '' && $row['schedule_code'] !== '') {
            if (!isset($mappings[$key]) || $row['usage_count'] > $mappings[$key]['usage_count']) {
                $mappings[$key] = [
                    'schedule_code' => $row['schedule_code'],
                    'original_ledger' => $row['original_ledger_name'],
                    'usage_count' => (int) $row['usage_count'],
                ];
            }
        }
    }

    return $mappings;
}

/**
 * Enhanced keyword rules for common Indian accounting ledger names.
 * Returns [normalized_keyword => schedule_code]
 */
function getEnhancedKeywordRules(): array
{
    return [
        'sales' => 'revenue',
        'revenue' => 'revenue',
        'service income' => 'revenue',
        'service revenue' => 'revenue',
        'turnover' => 'revenue',
        'export sales' => 'revenue',
        'domestic sales' => 'revenue',

        'purchase' => 'materials',
        'purchases' => 'materials',
        'raw material' => 'materials',
        'raw material consumption' => 'materials',
        'material consumed' => 'materials',
        'consumption' => 'materials',
        'stock consumption' => 'materials',

        'salary' => 'employee_cost',
        'salaries' => 'employee_cost',
        'wages' => 'employee_cost',
        'bonus' => 'employee_cost',
        'pf contribution' => 'employee_cost',
        'esi contribution' => 'employee_cost',
        'employee benefit' => 'employee_cost',
        'staff welfare' => 'employee_cost',
        'gratuity' => 'employee_cost',
        'leave encashment' => 'employee_cost',
        'labour charges' => 'employee_cost',

        'rent' => 'other_expenses',
        'rent expense' => 'other_expenses',
        'office rent' => 'other_expenses',
        'electricity' => 'other_expenses',
        'power and fuel' => 'other_expenses',
        'power' => 'other_expenses',
        'fuel' => 'other_expenses',
        'printing and stationery' => 'other_expenses',
        'stationery' => 'other_expenses',
        'telephone' => 'other_expenses',
        'telephone expenses' => 'other_expenses',
        'internet' => 'other_expenses',
        'postage' => 'other_expenses',
        'courier' => 'other_expenses',
        'travelling' => 'other_expenses',
        'travelling expense' => 'other_expenses',
        'travel expense' => 'other_expenses',
        'conveyance' => 'other_expenses',
        'vehicle expense' => 'other_expenses',
        'fuel expense' => 'other_expenses',
        'petrol' => 'other_expenses',
        'diesel' => 'other_expenses',
        'repair' => 'other_expenses',
        'repairs' => 'other_expenses',
        'repair and maintenance' => 'other_expenses',
        'maintenance' => 'other_expenses',
        'audit fees' => 'other_expenses',
        'audit fee' => 'other_expenses',
        'legal charges' => 'other_expenses',
        'legal expense' => 'other_expenses',
        'professional fees' => 'other_expenses',
        'professional charges' => 'other_expenses',
        'consultation' => 'other_expenses',
        'consulting' => 'other_expenses',
        'insurance' => 'other_expenses',
        'insurance premium' => 'other_expenses',
        'advertisement' => 'other_expenses',
        'advertising' => 'other_expenses',
        'marketing' => 'other_expenses',
        'commission' => 'other_expenses',
        'commission paid' => 'other_expenses',
        'brokerage' => 'other_expenses',
        'office expense' => 'other_expenses',
        'office expenses' => 'other_expenses',
        'general expense' => 'other_expenses',
        'miscellaneous expense' => 'other_expenses',
        'sundry expense' => 'other_expenses',
        'sundry expenses' => 'other_expenses',
        'packing' => 'other_expenses',
        'packing charges' => 'other_expenses',
        'freight' => 'other_expenses',
        'freight outward' => 'other_expenses',
        'courier charges' => 'other_expenses',
        'subscription' => 'other_expenses',
        'donation' => 'other_expenses',
        'bad debts' => 'other_expenses',
        'loss on sale' => 'other_expenses',
        'penalty' => 'other_expenses',
        'late fee' => 'other_expenses',

        'interest paid' => 'finance_cost',
        'interest expense' => 'finance_cost',
        'interest on loan' => 'finance_cost',
        'interest on overdraft' => 'finance_cost',
        'bank charges' => 'finance_cost',
        'bank commission' => 'finance_cost',
        'finance cost' => 'finance_cost',
        'finance charges' => 'finance_cost',
        'processing charges' => 'finance_cost',
        'loan processing' => 'finance_cost',
        'stamp duty on loan' => 'finance_cost',
        'less bank charges' => 'finance_cost',

        'interest received' => 'other_income',
        'interest income' => 'other_income',
        'interest on deposit' => 'other_income',
        'interest on fd' => 'other_income',
        'discount received' => 'other_income',
        'commission received' => 'other_income',
        'rental income' => 'other_income',
        'dividend income' => 'other_income',
        'profit on sale' => 'other_income',
        'misc income' => 'other_income',
        'miscellaneous income' => 'other_income',
        'other income' => 'other_income',
        'exchange gain' => 'other_income',
        'interest earned' => 'other_income',

        'cgst' => 'other_current_liabilities',
        'sgst' => 'other_current_liabilities',
        'igst' => 'other_current_liabilities',
        'gst payable' => 'other_current_liabilities',
        'input gst' => 'other_current_assets',
        'input cgst' => 'other_current_assets',
        'input sgst' => 'other_current_assets',
        'input igst' => 'other_current_assets',
        'output gst' => 'other_current_liabilities',
        'output cgst' => 'other_current_liabilities',
        'output sgst' => 'other_current_liabilities',
        'output igst' => 'other_current_liabilities',
        'gst input' => 'other_current_assets',
        'gst output' => 'other_current_liabilities',
        'input tax credit' => 'other_current_assets',
        'tds receivable' => 'other_current_assets',
        'tds deducted' => 'other_current_liabilities',
        'tds payable' => 'other_current_liabilities',
        'tds liability' => 'other_current_liabilities',
        'tds deposit' => 'other_current_liabilities',
        'professional tax' => 'other_current_liabilities',
        'pt payable' => 'other_current_liabilities',
        'income tax payable' => 'other_current_liabilities',
        'advance tax' => 'other_current_assets',
        'self assessment tax' => 'other_current_assets',
        'equalisation levy' => 'other_current_liabilities',
        'esi payable' => 'other_current_liabilities',
        'pf payable' => 'other_current_liabilities',

        'sundry debtors' => 'receivables',
        'trade receivables' => 'receivables',
        'book debts' => 'receivables',
        'accounts receivable' => 'receivables',
        'debtor' => 'receivables',

        'sundry creditors' => 'trade_payables',
        'trade creditors' => 'trade_payables',
        'accounts payable' => 'trade_payables',
        'payables' => 'trade_payables',
        'creditor' => 'trade_payables',

        'cash in hand' => 'cash',
        'cash account' => 'cash',
        'cash' => 'cash',
        'petty cash' => 'cash',
        'cash at bank' => 'cash',

        'bank account' => 'cash',
        'bank od' => 'st_borrowings',
        'bank overdraft' => 'st_borrowings',
        'current account' => 'cash',
        'saving account' => 'cash',
        'hdfc' => 'cash',
        'sbi' => 'cash',
        'icici' => 'cash',
        'axis' => 'cash',
        'kotak' => 'cash',
        'yes bank' => 'cash',

        'capital account' => 'share_capital',
        'capital' => 'share_capital',
        'equity share capital' => 'share_capital',
        'partners capital' => 'share_capital',
        'partner capital' => 'share_capital',
        'owner funds' => 'share_capital',

        'term loan' => 'lt_borrowings',
        'secured loan' => 'lt_borrowings',
        'vehicle loan' => 'lt_borrowings',
        'housing loan' => 'lt_borrowings',
        'loan from bank' => 'lt_borrowings',
        'unsecured loan' => 'lt_borrowings',
        'loan liability' => 'lt_borrowings',
        'borrowings' => 'lt_borrowings',
        'loan' => 'lt_borrowings',
        'cash credit' => 'st_borrowings',
        'od account' => 'st_borrowings',
        'short term loan' => 'st_borrowings',
        'overdraft' => 'st_borrowings',

        'fixed asset' => 'ppe',
        'plant' => 'ppe',
        'machinery' => 'ppe',
        'furniture' => 'ppe',
        'fixture' => 'ppe',
        'vehicle' => 'ppe',
        'motor car' => 'ppe',
        'computer' => 'ppe',
        'laptop' => 'ppe',
        'office equipment' => 'ppe',
        'electrical' => 'ppe',
        'land and building' => 'ppe',
        'building' => 'ppe',
        'land' => 'ppe',
        'freehold property' => 'ppe',
        'leasehold property' => 'ppe',
        'factory building' => 'ppe',
        'plant and machinery' => 'ppe',

        'depreciation' => 'depreciation',
        'depreciation expense' => 'depreciation',
        'accumulated depreciation' => 'depreciation',
        'amortisation' => 'depreciation',
        'amortization' => 'depreciation',

        'goodwill' => 'intangible_assets',
        'software' => 'intangible_assets',
        'trademark' => 'intangible_assets',
        'patent' => 'intangible_assets',
        'copyright' => 'intangible_assets',
        'licence fee' => 'intangible_assets',

        'stock in hand' => 'inventory',
        'closing stock' => 'inventory',
        'opening stock' => 'inventory',
        'raw material stock' => 'inventory',
        'finished goods' => 'inventory',
        'work in progress' => 'inventory',
        'inventory' => 'inventory',
        'stores and spares' => 'inventory',

        'fixed deposit' => 'bank_balances_other',
        'fd account' => 'bank_balances_other',
        'margin money' => 'bank_balances_other',
        'deposit with bank' => 'bank_balances_other',
        'lien marked' => 'bank_balances_other',
        'unpaid dividend' => 'other_current_liabilities',
        'esic payable' => 'other_current_liabilities',

        'security deposit paid' => 'loans_non_current',
        'advance supplier' => 'loans_current',
        'advance tax paid' => 'other_current_assets',
        'prepaid expense' => 'other_current_assets',
        'prepaid expenses' => 'other_current_assets',
        'prepaid insurance' => 'other_current_assets',
        'advance income tax' => 'other_current_assets',
        'mat credit' => 'other_current_assets',

        'provision for tax' => 'short_term_provisions',
        'provision for expenses' => 'short_term_provisions',
        'provision' => 'short_term_provisions',

        'reserves' => 'reserves',
        'general reserve' => 'reserves',
        'capital reserve' => 'reserves',
        'securities premium' => 'reserves',
        'retained earnings' => 'reserves',
        'surplus' => 'reserves',
        'profit and loss' => 'reserves',
        'p and l' => 'reserves',
    ];
}

/**
 * Group-to-schedule mapping rules for standard Tally parent groups.
 * Returns [normalized_parent_group => ['schedule_code' => ..., 'confidence' => int, 'reason' => ...]]
 *
 * Standard groups get 90% confidence (reliable).
 * Custom/healthcare groups get 75-85% (needs review).
 */
function getParentGroupMappingRules(): array
{
    return [
        // ===== STANDARD TALLY GROUPS (90% confidence) =====
        'bank accounts' => [
            'schedule_code' => 'cash',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Bank Accounts → Cash and Cash Equivalents.',
        ],
        'cash in hand' => [
            'schedule_code' => 'cash',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Cash-in-Hand → Cash and Cash Equivalents.',
        ],
        'sundry debtors' => [
            'schedule_code' => 'receivables',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Sundry Debtors → Trade Receivables.',
        ],
        'sundry creditors' => [
            'schedule_code' => 'trade_payables',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Sundry Creditors → Trade Payables.',
        ],
        'sales accounts' => [
            'schedule_code' => 'revenue',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Sales Accounts → Revenue from Operations.',
        ],
        'purchase accounts' => [
            'schedule_code' => 'materials',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Purchase Accounts → Cost of Materials Consumed.',
        ],
        'duties and taxes' => [
            'schedule_code' => 'other_current_liabilities',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Duties & Taxes → Statutory Liabilities.',
        ],
        'fixed assets' => [
            'schedule_code' => 'ppe',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Fixed Assets → Property, Plant and Equipment.',
        ],
        'capital account' => [
            'schedule_code' => 'share_capital',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Capital Account → Share Capital.',
        ],
        'secured loans' => [
            'schedule_code' => 'lt_borrowings',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Secured Loans → Long-Term Borrowings.',
        ],
        'unsecured loans' => [
            'schedule_code' => 'lt_borrowings',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Unsecured Loans → Long-Term Borrowings.',
        ],
        'loans advances asset' => [
            'schedule_code' => 'loans_current',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Loans & Advances (Asset) → Short-Term Loans and Advances.',
        ],
        'loans and advances (liability)' => [
            'schedule_code' => 'st_borrowings',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Loans & Advances (Liability) → Short-Term Borrowings.',
        ],
        'deposits (asset)' => [
            'schedule_code' => 'other_current_assets',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Deposits (Asset) → Other Current Assets.',
        ],
        'direct expenses' => [
            'schedule_code' => 'materials',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Direct Expenses → Cost of Materials Consumed.',
        ],
        'indirect expenses' => [
            'schedule_code' => 'other_expenses',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Indirect Expenses → Other Expenses.',
        ],
        'direct incomes' => [
            'schedule_code' => 'revenue',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Direct Incomes → Revenue from Operations.',
        ],
        'indirect incomes' => [
            'schedule_code' => 'other_income',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Indirect Incomes → Other Income.',
        ],
        'current liabilities' => [
            'schedule_code' => 'other_current_liabilities',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Current Liabilities → Other Current Liabilities.',
        ],
        'current assets' => [
            'schedule_code' => 'other_current_assets',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Current Assets → Other Current Assets.',
        ],
        'provisions' => [
            'schedule_code' => 'short_term_provisions',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Provisions → Short-Term Provisions.',
        ],
        'stock in hand' => [
            'schedule_code' => 'inventory',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Stock in Hand → Inventories.',
        ],
        'branch divisions' => [
            'schedule_code' => 'other_current_assets',
            'confidence' => 85,
            'reason' => 'Standard Tally group: Branch Divisions → Other Current Assets.',
        ],
        'miscellaneous expenses' => [
            'schedule_code' => 'other_expenses',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Miscellaneous Expenses → Other Expenses.',
        ],
        'service accounts' => [
            'schedule_code' => 'revenue',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Service Accounts → Revenue from Operations.',
        ],
        'income (direct)' => [
            'schedule_code' => 'revenue',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Income (Direct) → Revenue from Operations.',
        ],
        'income (indirect)' => [
            'schedule_code' => 'other_income',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Income (Indirect) → Other Income.',
        ],
        'expense (direct)' => [
            'schedule_code' => 'materials',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Expense (Direct) → Cost of Materials Consumed.',
        ],
        'expense (indirect)' => [
            'schedule_code' => 'other_expenses',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Expense (Indirect) → Other Expenses.',
        ],
        'statutory duties' => [
            'schedule_code' => 'other_current_liabilities',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Statutory Duties → Statutory Liabilities.',
        ],
        'statutory payments' => [
            'schedule_code' => 'other_current_liabilities',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Statutory Payments → Statutory Liabilities.',
        ],
        'bank od accounts' => [
            'schedule_code' => 'st_borrowings',
            'confidence' => 90,
            'reason' => 'Standard Tally group: Bank OD Accounts → Short-Term Borrowings.',
        ],

        // ===== CUSTOM / HEALTHCARE GROUPS (75-85% confidence) =====
        'plant machinery and equipment' => [
            'schedule_code' => 'ppe',
            'confidence' => 85,
            'reason' => 'Custom group: Plant & Machinery → Property, Plant and Equipment.',
        ],
        'furniture fittings' => [
            'schedule_code' => 'ppe',
            'confidence' => 85,
            'reason' => 'Custom group: Furniture & Fittings → Property, Plant and Equipment.',
        ],
        'computer computer accessories' => [
            'schedule_code' => 'ppe',
            'confidence' => 85,
            'reason' => 'Custom group: Computer & Accessories → Property, Plant and Equipment.',
        ],
        'lab equipment' => [
            'schedule_code' => 'ppe',
            'confidence' => 85,
            'reason' => 'Custom group: Lab Equipment → Property, Plant and Equipment.',
        ],
        'fixed deposit' => [
            'schedule_code' => 'bank_balances_other',
            'confidence' => 85,
            'reason' => 'Custom group: Fixed Deposit → Other Bank Balances.',
        ],
        'staff loan a\c' => [
            'schedule_code' => 'loans_current',
            'confidence' => 80,
            'reason' => 'Custom group: Staff Loan → Short-Term Loans and Advances.',
        ],
        'tds professionals' => [
            'schedule_code' => 'other_current_assets',
            'confidence' => 85,
            'reason' => 'Custom group: TDS-Professionals → Other Current Assets (TDS Receivable).',
        ],
        'tds contractor' => [
            'schedule_code' => 'other_current_assets',
            'confidence' => 85,
            'reason' => 'Custom group: TDS-Contractor → Other Current Assets (TDS Receivable).',
        ],
        'doctors fees payable' => [
            'schedule_code' => 'trade_payables',
            'confidence' => 80,
            'reason' => 'Custom group: Doctors Fees Payable → Trade Payables.',
        ],
        'professional fees' => [
            'schedule_code' => 'other_expenses',
            'confidence' => 85,
            'reason' => 'Custom group: Professional Fees → Other Expenses.',
        ],
        'doctors fees' => [
            'schedule_code' => 'other_expenses',
            'confidence' => 80,
            'reason' => 'Custom group: Doctors Fees → Other Expenses (Professional Fees).',
        ],
        'salary nursing' => [
            'schedule_code' => 'employee_cost',
            'confidence' => 85,
            'reason' => 'Custom group: Salary Nursing → Employee Benefit Expenses.',
        ],
        'telephone charges' => [
            'schedule_code' => 'other_expenses',
            'confidence' => 85,
            'reason' => 'Custom group: Telephone Charges → Other Expenses.',
        ],
        'insurance patient' => [
            'schedule_code' => 'receivables',
            'confidence' => 75,
            'reason' => 'Custom healthcare group: Insurance Patient → Trade Receivables (insurance claims).',
        ],
        'advance in patient' => [
            'schedule_code' => 'receivables',
            'confidence' => 75,
            'reason' => 'Custom healthcare group: Advance in Patient → Trade Receivables (patient advances).',
        ],
        'credit bill patient' => [
            'schedule_code' => 'receivables',
            'confidence' => 75,
            'reason' => 'Custom healthcare group: Credit Bill Patient → Trade Receivables.',
        ],
        'mission smile' => [
            'schedule_code' => 'other_current_assets',
            'confidence' => 75,
            'reason' => 'Custom healthcare group: Mission Smile → Other Current Assets (charitable programme).',
        ],
        'inpatient receipts' => [
            'schedule_code' => 'revenue',
            'confidence' => 80,
            'reason' => 'Custom healthcare group: Inpatient Receipts → Revenue from Operations.',
        ],
        'out patient receipts' => [
            'schedule_code' => 'revenue',
            'confidence' => 80,
            'reason' => 'Custom healthcare group: Out Patient Receipts → Revenue from Operations.',
        ],
        'patient care income' => [
            'schedule_code' => 'revenue',
            'confidence' => 80,
            'reason' => 'Custom healthcare group: Patient Care Income → Revenue from Operations.',
        ],
        'tests procedures other receipts op' => [
            'schedule_code' => 'revenue',
            'confidence' => 80,
            'reason' => 'Custom healthcare group: Tests/Procedures OP → Revenue from Operations.',
        ],
        'tests procedures other receipts ip' => [
            'schedule_code' => 'revenue',
            'confidence' => 80,
            'reason' => 'Custom healthcare group: Tests/Procedures IP → Revenue from Operations.',
        ],
        "creditor's pharmacy purchase" => [
            'schedule_code' => 'trade_payables',
            'confidence' => 85,
            'reason' => 'Custom group: Pharmacy Purchase Creditors → Trade Payables.',
        ],
        "creditor's repair maintenance" => [
            'schedule_code' => 'trade_payables',
            'confidence' => 85,
            'reason' => 'Custom group: Repair & Maintenance Creditors → Trade Payables.',
        ],
        "creditor's lab purchase" => [
            'schedule_code' => 'trade_payables',
            'confidence' => 85,
            'reason' => 'Custom group: Lab Purchase Creditors → Trade Payables.',
        ],
        "creditor's others" => [
            'schedule_code' => 'trade_payables',
            'confidence' => 80,
            'reason' => 'Custom group: Other Creditors → Trade Payables.',
        ],
    ];
}

/**
 * Check if a parent group matches any group mapping rule.
 * Returns suggestion array or null.
 */
function matchParentGroupRule(string $parentGroup): ?array
{
    $rules = getParentGroupMappingRules();
    $normalized = normalizeMappingText($parentGroup);

    if (isset($rules[$normalized])) {
        $rule = $rules[$normalized];
        return [
            'schedule_code' => $rule['schedule_code'],
            'confidence' => $rule['confidence'],
            'source' => 'parent_group_rule',
            'reason' => $rule['reason'],
        ];
    }

    return null;
}

/**
 * Run the unified suggestion pipeline for a single ledger.
 * Returns ['schedule_code' => ..., 'confidence' => int, 'source' => string, 'reason' => string]
 */
function suggestBulkMapping(
    string $ledgerName,
    string $parentGroup,
    array $currentMapping,
    array $previousFyMappings,
    array $globalMaster,
    array $keywordRules,
    ?object $hierarchyEngine = null,
    ?object $mappingEngine = null
): array {
    $normalized = normalizeMappingText($ledgerName);
    $normalizedGroup = normalizeMappingText($parentGroup);

    // 1. Already mapped in current year → 100%
    if (!empty($currentMapping[$ledgerName])) {
        return [
            'schedule_code' => $currentMapping[$ledgerName]['schedule_code'],
            'confidence' => 100,
            'source' => 'current_year',
            'reason' => 'Already mapped in current financial year.',
        ];
    }

    // 2. Previous FY exact match → 95%
    if (isset($previousFyMappings[$normalized])) {
        return [
            'schedule_code' => $previousFyMappings[$normalized]['schedule_code'],
            'confidence' => 95,
            'source' => 'previous_fy',
            'reason' => 'Exact match from previous financial year: "' . $previousFyMappings[$normalized]['original_ledger'] . '".',
        ];
    }

    // 3. Global mapping master exact match → 90%
    if (isset($globalMaster[$normalized])) {
        return [
            'schedule_code' => $globalMaster[$normalized]['schedule_code'],
            'confidence' => 90,
            'source' => 'global_master',
            'reason' => 'Matched global mapping master (used ' . $globalMaster[$normalized]['usage_count'] . ' times across companies).',
        ];
    }

    // 3b. Parent group rule match → 85-90%
    $groupRule = matchParentGroupRule($parentGroup);
    if ($groupRule) {
        return $groupRule;
    }

    // 4. Hierarchy AI engine → variable confidence
    $hierarchyResult = null;
    if ($hierarchyEngine) {
        try {
            $hierarchy = $hierarchyEngine->getLedgerHierarchy($ledgerName);
            $hierarchyResult = $hierarchyEngine->mapLedger($ledgerName, $parentGroup, $hierarchy);
        } catch (Throwable $e) {
            $hierarchyResult = null;
        }
    }

    // 5. Legacy AI engine → variable confidence
    $legacyResult = null;
    if ($mappingEngine) {
        try {
            $legacyResult = $mappingEngine->mapLedger($ledgerName, $parentGroup);
        } catch (Throwable $e) {
            $legacyResult = null;
        }
    }

    // Pick the best AI result
    $aiResult = null;
    if ($hierarchyResult && $legacyResult) {
        $aiResult = ($hierarchyResult['confidence'] ?? 0) >= ($legacyResult['confidence'] ?? 0)
            ? $hierarchyResult : $legacyResult;
    } elseif ($hierarchyResult) {
        $aiResult = $hierarchyResult;
    } elseif ($legacyResult) {
        $aiResult = $legacyResult;
    }

    if ($aiResult && ($aiResult['confidence'] ?? 0) >= 70) {
        $suggestedCode = $aiResult['suggested_head'] ?? $aiResult['head'] ?? '';
        if ($suggestedCode !== '' && $suggestedCode !== 'unmapped') {
            return [
                'schedule_code' => $suggestedCode,
                'confidence' => (int) ($aiResult['confidence'] ?? 70),
                'source' => $aiResult['source_basis'][0] ?? $aiResult['method'] ?? 'ai_engine',
                'reason' => $aiResult['reason'] ?? 'AI engine suggestion.',
            ];
        }
    }

    // 6. Previous FY normalized match → 80% (only if no AI match found)
    if (!$aiResult || ($aiResult['confidence'] ?? 0) < 70) {
        foreach ($previousFyMappings as $prevNorm => $prevData) {
            if ($prevNorm !== '' && $normalized !== '') {
                $pct = 0;
                similar_text($normalized, $prevNorm, $pct);
                if ($pct >= 75) {
                    return [
                        'schedule_code' => $prevData['schedule_code'],
                        'confidence' => 80,
                        'source' => 'previous_fy_normalized',
                        'reason' => 'Similar to previous FY ledger "' . $prevData['original_ledger'] . '" (' . round($pct) . '% similar).',
                    ];
                }
            }
        }
    }

    // 7. Enhanced keyword rules → 70% (forward match only, prevents short ledger names matching longer keywords)
    foreach ($keywordRules as $keyword => $scheduleCode) {
        if ($normalized !== '' && strpos($normalized, $keyword) !== false) {
            return [
                'schedule_code' => $scheduleCode,
                'confidence' => 70,
                'source' => 'keyword_rule',
                'reason' => 'Keyword match: "' . $keyword . '" → ' . $scheduleCode,
            ];
        }
    }

    // 8. No confident match → manual review
    return [
        'schedule_code' => '',
        'confidence' => 0,
        'source' => 'none',
        'reason' => 'No confident mapping found. Manual review required.',
    ];
}

/**
 * Generate suggestions for all unmapped ledgers in a company.
 * Returns array of [ledger_name => suggestion_data]
 */
function generateBulkSuggestions(
    PDO $pdo,
    int $companyId,
    int $fyId,
    ?object $hierarchyEngine = null,
    ?object $mappingEngine = null
): array {
    $previousFy = getPreviousFyForCompany($pdo, $companyId, $fyId);
    $previousFyMappings = $previousFy ? loadPreviousFyMappings($pdo, $companyId, $previousFy['id']) : [];
    $globalMaster = loadGlobalMappingMaster($pdo);
    $keywordRules = getEnhancedKeywordRules();

    // Load current mappings
    $currentMapping = [];
    $stmt = $pdo->prepare("SELECT ledger_name, schedule_code FROM ledger_mapping WHERE company_id = ?");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['schedule_code'] !== '') {
            $currentMapping[$row['ledger_name']] = ['schedule_code' => $row['schedule_code']];
        }
    }

    /* Check hierarchy columns exist before selecting them — same guard
       reconhub_data_loading_service.php uses, for environments where
       migration 004 (tally_hierarchy_ai_mapping) hasn't run yet. */
    $hasHierarchyCols = false;
    try {
        $chkStmt = $pdo->query("SHOW COLUMNS FROM tally_ledger_master LIKE 'tally_group_path'");
        $hasHierarchyCols = $chkStmt->rowCount() > 0;
    } catch (Throwable $e) { /* ignore */ }

    // Load all ledgers for this company
    $hierarchyCols = $hasHierarchyCols
        ? "COALESCE(t.primary_group, '') AS primary_group,
           COALESCE(t.tally_group_path, '') AS tally_group_path,
           COALESCE(t.tally_root_type, '') AS tally_root_type"
        : "'' AS primary_group,
           '' AS tally_group_path,
           '' AS tally_root_type";
    $ledgerStmt = $pdo->prepare("
        SELECT t.ledger_name, COALESCE(t.parent_group, '') AS parent_group, {$hierarchyCols}
        FROM tally_ledger_master t
        WHERE t.company_id = ?
        ORDER BY t.ledger_name
    ");
    $ledgerStmt->execute([$companyId]);
    $ledgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

    /* Prime the hierarchy cache from this same query instead of letting
       suggestBulkMapping() -> getLedgerHierarchy() fall through to a
       per-ledger SHOW COLUMNS + SELECT for every row (the "slow group
       panel loop" anti-pattern already fixed in the mapping workbench via
       reconhub_data_loading_service.php, mirrored here). */
    if ($hierarchyEngine !== null) {
        $hierarchyEngine->setHierarchyCache($ledgers);
    }

    $suggestions = [];
    foreach ($ledgers as $ledger) {
        $name = $ledger['ledger_name'];
        $group = $ledger['parent_group'];

        $suggestion = suggestBulkMapping(
            $name,
            $group,
            $currentMapping,
            $previousFyMappings,
            $globalMaster,
            $keywordRules,
            $hierarchyEngine,
            $mappingEngine
        );

        $suggestion['ledger_name'] = $name;
        $suggestion['parent_group'] = $group;
        $suggestion['is_mapped'] = isset($currentMapping[$name]);
        $suggestions[$name] = $suggestion;
    }

    return $suggestions;
}
