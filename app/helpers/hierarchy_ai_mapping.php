<?php
/**
 * e-BAL — Hierarchy-Aware AI Mapping Engine
 *
 * Provides rule-based and optionally AI-powered Schedule III mapping
 * using Tally parent group hierarchy, ledger name analysis, and
 * Schedule III classification rules.
 */

class HierarchyAIMappingEngine
{
    private PDO $pdo;
    private int $companyId;
    private string $category;
    private array $hierarchyRules = [];
    private array $learnedMappings = [];

    public function __construct(PDO $pdo, int $companyId, string $category = 'corporate')
    {
        $this->pdo = $pdo;
        $this->companyId = $companyId;
        $this->category = strtolower($category);
        $this->buildHierarchyRules();
        $this->loadLearnedMappings();
    }

    /**
     * Build hierarchy-based classification rules.
     */
    private function buildHierarchyRules(): void
    {
        $this->hierarchyRules = [
            // Fixed Assets
            [
                'keywords' => ['fixed assets', 'plant', 'machinery', 'computer', 'furniture', 'fixture', 'vehicle', 'office equipment', 'electrical'],
                'schedule' => 'PPE',
                'confidence' => 94,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Fixed Assets in Tally hierarchy',
                'alternatives' => ['NC_OTHER_ASSETS'],
                'root_type' => 'Balance Sheet',
            ],
            // Bank / Cash
            [
                'keywords' => ['bank accounts', 'cash-in-hand', 'cash', 'bank', 'sbi', 'hdfc', 'icici'],
                'schedule' => 'CCE',
                'confidence' => 92,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Bank Accounts or Cash-in-Hand in Tally hierarchy',
                'alternatives' => ['BANK_BALANCES'],
                'root_type' => 'Balance Sheet',
            ],
            // Sundry Debtors
            [
                'keywords' => ['sundry debtors', 'trade debtors', 'receivables', 'customers', 'debtors'],
                'schedule' => 'TRADE_RECEIVABLES',
                'confidence' => 95,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Sundry Debtors in Tally hierarchy',
                'alternatives' => ['OTHER_FINANCIAL_ASSETS'],
                'root_type' => 'Balance Sheet',
            ],
            // Sundry Creditors
            [
                'keywords' => ['sundry creditors', 'trade creditors', 'suppliers', 'payables', 'creditors'],
                'schedule' => 'TRADE_PAYABLES',
                'confidence' => 95,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Sundry Creditors in Tally hierarchy',
                'alternatives' => ['OTHER_FINANCIAL_LIABILITIES'],
                'root_type' => 'Balance Sheet',
            ],
            // Duties and Taxes
            [
                'keywords' => ['duties', 'taxes', 'gst', 'tds', 'pf', 'esi', 'professional tax', 'income tax payable', 'statutory'],
                'schedule' => 'OTHER_CURRENT_LIABILITIES',
                'confidence' => 90,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Duties & Taxes in Tally hierarchy',
                'alternatives' => ['SHORT_TERM_PROVISIONS'],
                'root_type' => 'Balance Sheet',
            ],
            // Loans and Borrowings
            [
                'keywords' => ['loans', 'secured', 'unsecured', 'bank od', 'term loan', 'vehicle loan', 'borrowings', 'loan'],
                'schedule' => 'BORROWINGS',
                'confidence' => 85,
                'risk' => 'Medium',
                'reason' => 'Ledger is grouped under Loans in Tally hierarchy. Needs maturity classification.',
                'alternatives' => ['SHORT_TERM_BORROWINGS', 'LONG_TERM_BORROWINGS'],
                'root_type' => 'Balance Sheet',
            ],
            // Capital Account
            [
                'keywords' => ['capital account', 'partner capital', 'proprietor capital', 'share capital', 'reserves'],
                'schedule' => 'SHARE_CAPITAL',
                'confidence' => 93,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Capital Account in Tally hierarchy',
                'alternatives' => ['RESERVES_AND_SURPLUS'],
                'root_type' => 'Balance Sheet',
            ],
            // Sales / Revenue
            [
                'keywords' => ['sales accounts', 'direct incomes', 'revenue', 'service income', 'professional receipts', 'sales'],
                'schedule' => 'REVENUE_FROM_OPERATIONS',
                'confidence' => 92,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Sales Accounts in Tally hierarchy',
                'alternatives' => ['OTHER_INCOME'],
                'root_type' => 'Profit & Loss',
            ],
            // Purchases / Direct Expenses
            [
                'keywords' => ['purchase accounts', 'direct expenses', 'cost of goods', 'materials', 'freight', 'wages', 'manufacturing'],
                'schedule' => 'COST_OF_MATERIALS_CONSUMED',
                'confidence' => 88,
                'risk' => 'Medium',
                'reason' => 'Ledger is grouped under Purchase Accounts / Direct Expenses in Tally hierarchy',
                'alternatives' => ['EMPLOYEE_BENEFIT_EXPENSE', 'OTHER_EXPENSES'],
                'root_type' => 'Profit & Loss',
            ],
            // Indirect Expenses
            [
                'keywords' => ['indirect expenses', 'administrative', 'office', 'rent', 'electricity', 'audit', 'legal', 'repairs', 'travelling', 'printing', 'stationery', 'bank charges'],
                'schedule' => 'OTHER_EXPENSES',
                'confidence' => 85,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Indirect Expenses in Tally hierarchy',
                'alternatives' => ['EMPLOYEE_BENEFIT_EXPENSE'],
                'root_type' => 'Profit & Loss',
            ],
            // Indirect Income
            [
                'keywords' => ['indirect incomes', 'interest income', 'discount received', 'other income', 'profit on sale'],
                'schedule' => 'OTHER_INCOME',
                'confidence' => 90,
                'risk' => 'Low',
                'reason' => 'Ledger is grouped under Indirect Incomes in Tally hierarchy',
                'alternatives' => ['REVENUE_FROM_OPERATIONS'],
                'root_type' => 'Profit & Loss',
            ],
            // Suspense / Round Off
            [
                'keywords' => ['suspense', 'round off', 'temp', 'clearing', 'sundry balance'],
                'schedule' => 'OTHER_EXPENSES',
                'confidence' => 40,
                'risk' => 'High',
                'reason' => 'Suspense or round-off account requires manual review',
                'alternatives' => [],
                'root_type' => 'Profit & Loss',
            ],
        ];
    }

    /**
     * Load learned mappings from mapping_learning table.
     */
    private function loadLearnedMappings(): void
    {
        try {
            // Company-specific
            $stmt = $this->pdo->prepare("
                SELECT normalized_ledger_name, normalized_parent_group, schedule_code, usage_count
                FROM mapping_learning
                WHERE scope = 'company' AND company_id = ?
                ORDER BY usage_count DESC
            ");
            $stmt->execute([$this->companyId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->learnedMappings['company'][$row['normalized_ledger_name'] . '|' . $row['normalized_parent_group']] = [
                    'schedule' => $row['schedule_code'],
                    'confidence' => min(99, 90 + $row['usage_count']),
                ];
            }

            // Global
            $stmt = $this->pdo->prepare("
                SELECT normalized_ledger_name, normalized_parent_group, schedule_code, usage_count
                FROM mapping_learning
                WHERE scope = 'global' AND company_id = 0
                ORDER BY usage_count DESC
            ");
            $stmt->execute([]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->learnedMappings['global'][$row['normalized_ledger_name'] . '|' . $row['normalized_parent_group']] = [
                    'schedule' => $row['schedule_code'],
                    'confidence' => min(96, 90 + $row['usage_count']),
                ];
            }
        } catch (Throwable $e) {
            // Silently ignore - mapping_learning may not exist yet
        }
    }

    /**
     * Get the Tally group hierarchy path for a ledger.
     */
    public function getLedgerHierarchy(string $ledgerName): array
    {
        $empty = [
            'parent_group' => '',
            'primary_group' => '',
            'group_path' => '',
            'group_depth' => 0,
            'root_type' => '',
            'has_hierarchy' => false,
        ];

        try {
            /* Check if hierarchy columns exist */
            $chk = $this->pdo->query("SHOW COLUMNS FROM tally_ledger_master LIKE 'tally_group_path'");
            if ($chk->rowCount() === 0) {
                $row = $this->pdo->prepare("SELECT parent_group FROM tally_ledger_master WHERE company_id = ? AND ledger_name = ? LIMIT 1");
                $row->execute([$this->companyId, $ledgerName]);
                $data = $row->fetch(PDO::FETCH_ASSOC);
                if (!$data) return $empty;
                $empty['parent_group'] = $data['parent_group'] ?? '';
                return $empty;
            }

            $stmt = $this->pdo->prepare("
                SELECT parent_group, primary_group, tally_group_path, tally_group_depth, tally_root_type
                FROM tally_ledger_master
                WHERE company_id = ? AND ledger_name = ?
                LIMIT 1
            ");
            $stmt->execute([$this->companyId, $ledgerName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return $empty;

            return [
                'parent_group' => $row['parent_group'] ?? '',
                'primary_group' => $row['primary_group'] ?? '',
                'group_path' => $row['tally_group_path'] ?? '',
                'group_depth' => (int) ($row['tally_group_depth'] ?? 0),
                'root_type' => $row['tally_root_type'] ?? '',
                'has_hierarchy' => !empty($row['tally_group_path']),
            ];
        } catch (Throwable $e) {
            return [
                'parent_group' => '',
                'primary_group' => '',
                'group_path' => '',
                'group_depth' => 0,
                'root_type' => '',
                'has_hierarchy' => false,
            ];
        }
    }

    /**
     * Normalize a string for matching.
     */
    private function normalize(string $value): string
    {
        $v = strtolower(trim($value));
        $v = preg_replace('/[^a-z0-9\s]/', '', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        return trim($v);
    }

    /**
     * Check if a string contains any of the keywords.
     */
    private function containsKeyword(string $text, array $keywords): bool
    {
        $norm = $this->normalize($text);
        foreach ($keywords as $kw) {
            if (strpos($norm, $this->normalize($kw)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Main mapping function. Returns structured suggestion with confidence and reasoning.
     * Priority: Learned > Hierarchy > Primary Group > Parent Group > Ledger Name
     */
    public function mapLedger(string $ledgerName, string $parentGroup = '', ?array $hierarchy = null): array
    {
        $result = [
            'suggested_head' => '',
            'confidence' => 0,
            'risk' => 'Low',
            'reason' => '',
            'alternative_heads' => [],
            'requires_manual_review' => false,
            'source_basis' => [],
        ];

        $groupPath = $hierarchy['group_path'] ?? '';
        $primaryGroup = $hierarchy['primary_group'] ?? '';
        $rootType = $hierarchy['root_type'] ?? '';

        // 1. Check learned company mappings (confidence 90-99)
        $normLedger = $this->normalize($ledgerName);
        $normGroup = $this->normalize($parentGroup);
        $lookupKey = $normLedger . '|' . $normGroup;

        if (isset($this->learnedMappings['company'][$lookupKey])) {
            $learned = $this->learnedMappings['company'][$lookupKey];
            return [
                'suggested_head' => $learned['schedule'],
                'confidence' => $learned['confidence'],
                'risk' => 'Low',
                'reason' => 'Previously approved mapping for this exact ledger+group combination in this company.',
                'alternative_heads' => [],
                'requires_manual_review' => false,
                'source_basis' => ['Learned Mapping (Company)'],
            ];
        }

        // 2. Check learned global mappings (confidence 90-96)
        if (isset($this->learnedMappings['global'][$lookupKey])) {
            $learned = $this->learnedMappings['global'][$lookupKey];
            return [
                'suggested_head' => $learned['schedule'],
                'confidence' => $learned['confidence'],
                'risk' => 'Low',
                'reason' => 'Previously approved mapping from another company (global learned).',
                'alternative_heads' => [],
                'requires_manual_review' => false,
                'source_basis' => ['Learned Mapping (Global)'],
            ];
        }

        // 3. Hierarchy-first matching
        //    Priority: group_path > primary_group > parent_group > ledger name
        $bestRule = null;
        $bestScore = 0;
        $bestReason = '';

        foreach ($this->hierarchyRules as $rule) {
            $score = 0;
            $matchedParts = [];

            // Hierarchy path is the strongest signal
            if (!empty($groupPath) && $this->containsKeyword($groupPath, $rule['keywords'])) {
                $score += 50;
                $matchedParts[] = 'Tally hierarchy path';
            }

            // Primary group is strong
            if (!empty($primaryGroup) && $this->containsKeyword($primaryGroup, $rule['keywords'])) {
                $score += 25;
                $matchedParts[] = 'Ultimate parent group';
            }

            // Parent group is moderate
            if (!empty($parentGroup) && $this->containsKeyword($parentGroup, $rule['keywords'])) {
                $score += 15;
                $matchedParts[] = 'Parent group';
            }

            // Ledger name is weak (only when nothing else matches)
            if ($this->containsKeyword($ledgerName, $rule['keywords'])) {
                $score += 5;
                $matchedParts[] = 'Ledger name similarity';
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRule = $rule;
                $bestReason = $this->buildReason($rule, $parentGroup, $primaryGroup, $groupPath, $ledgerName, $matchedParts);
            }
        }

        if ($bestRule && $bestScore >= 40) {
            // Scale confidence: hierarchy match = 90-95%, parent group = 80-89%, name only = 60-75%
            if ($bestScore >= 70) {
                $confidence = min(99, $bestRule['confidence']);
            } elseif ($bestScore >= 40) {
                $confidence = min(89, max(70, $bestRule['confidence'] - 10));
            } else {
                $confidence = min(74, max(50, $bestRule['confidence'] - 20));
            }

            $sourceBasis = [];
            if (!empty($groupPath) && $this->containsKeyword($groupPath, $bestRule['keywords'])) {
                $sourceBasis[] = 'Tally Hierarchy';
            }
            if (!empty($primaryGroup) && $this->containsKeyword($primaryGroup, $bestRule['keywords'])) {
                $sourceBasis[] = 'Ultimate Group';
            }
            if (!empty($parentGroup) && $this->containsKeyword($parentGroup, $bestRule['keywords'])) {
                $sourceBasis[] = 'Parent Group';
            }
            if (empty($sourceBasis)) {
                $sourceBasis[] = 'Schedule III Rule';
            }

            $requiresReview = $confidence < 80 || in_array($bestRule['risk'], ['High', 'Medium']);

            return [
                'suggested_head' => $bestRule['schedule'],
                'confidence' => $confidence,
                'risk' => $bestRule['risk'],
                'reason' => $bestReason,
                'alternative_heads' => $bestRule['alternatives'],
                'requires_manual_review' => $requiresReview,
                'source_basis' => $sourceBasis,
            ];
        }

        // 4. No match found
        return [
            'suggested_head' => '',
            'confidence' => 0,
            'risk' => 'High',
            'reason' => 'No matching Tally hierarchy or group rule found. Manual classification required.',
            'alternative_heads' => [],
            'requires_manual_review' => true,
            'source_basis' => ['No Match'],
        ];
    }

    /**
     * Build hierarchy-based reasoning string.
     */
    private function buildReason(array $rule, string $parentGroup, string $primaryGroup, string $groupPath, string $ledgerName, array $matchedParts): string
    {
        $scheduleLabel = $this->buildLabels()[$rule['schedule']] ?? $rule['schedule'];

        if (!empty($groupPath) && $this->containsKeyword($groupPath, $rule['keywords'])) {
            return "Ledger is under Tally hierarchy {$groupPath}. Therefore Schedule III classification is {$scheduleLabel}.";
        }
        if (!empty($primaryGroup) && $this->containsKeyword($primaryGroup, $rule['keywords'])) {
            return "Ledger's ultimate parent group is {$primaryGroup} in Tally. Therefore Schedule III classification is {$scheduleLabel}.";
        }
        if (!empty($parentGroup) && $this->containsKeyword($parentGroup, $rule['keywords'])) {
            return "Ledger is under {$parentGroup} in Tally hierarchy. Therefore Schedule III classification is {$scheduleLabel}.";
        }
        if ($this->containsKeyword($ledgerName, $rule['keywords'])) {
            return "Ledger name suggests classification as {$scheduleLabel}. Manual review recommended as hierarchy is not available.";
        }
        return $rule['reason'];
    }

    /**
     * Get all unique parent groups for a company (for filtering).
     */
    public function getParentGroups(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT parent_group
                FROM tally_ledger_master
                WHERE company_id = ? AND parent_group != ''
                ORDER BY parent_group
            ");
            $stmt->execute([$this->companyId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Get all unique primary groups for a company (for filtering).
     */
    public function getPrimaryGroups(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT primary_group
                FROM tally_ledger_master
                WHERE company_id = ? AND primary_group != ''
                ORDER BY primary_group
            ");
            $stmt->execute([$this->companyId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Get all unique root types for a company (for filtering).
     */
    public function getRootTypes(): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT tally_root_type
                FROM tally_ledger_master
                WHERE company_id = ? AND tally_root_type != ''
                ORDER BY tally_root_type
            ");
            $stmt->execute([$this->companyId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Build schedule code to label mapping for display purposes.
     */
    private function buildLabels(): array
    {
        return [
            'PPE' => 'Property, Plant and Equipment',
            'CCE' => 'Cash and Cash Equivalents',
            'TRADE_RECEIVABLES' => 'Trade Receivables',
            'TRADE_PAYABLES' => 'Trade Payables',
            'OTHER_CURRENT_LIABILITIES' => 'Other Current Liabilities',
            'SHORT_TERM_PROVISIONS' => 'Short-Term Provisions',
            'SHARE_CAPITAL' => 'Share Capital',
            'RESERVES' => 'Reserves and Surplus',
            'LT_BORROWINGS' => 'Long-Term Borrowings',
            'ST_BORROWINGS' => 'Short-Term Borrowings',
            'INVENTORY' => 'Inventories',
            'OTHER_CURRENT_ASSETS' => 'Other Current Assets',
            'REVENUE' => 'Revenue from Operations',
            'OTHER_INCOME' => 'Other Income',
            'EMPLOYEE_COST' => 'Employee Benefits Expense',
            'FINANCE_COST' => 'Finance Costs',
            'DEPRECIATION' => 'Depreciation and Amortisation',
            'OTHER_EXPENSES' => 'Other Expenses',
            'MATERIALS' => 'Cost of Materials Consumed',
            'CWIP' => 'Capital Work in Progress',
            'INTANGIBLE_ASSETS' => 'Intangible Assets',
            'NC_INVESTMENTS' => 'Non-Current Investments',
            'NC_LOANS' => 'Non-Current Loans and Advances',
            'DEFERRED_TAX' => 'Deferred Tax Asset/Liability',
            'NC_OTHER_ASSETS' => 'Other Non-Current Assets',
            'BANK_BALANCES' => 'Other Bank Balances',
            'CURRENT_INVESTMENTS' => 'Current Investments',
            'LOANS_CURRENT' => 'Short-Term Loans and Advances',
        ];
    }

    /**
     * Record an accepted AI suggestion in the audit trail.
     */
    public function recordAcceptance(
        string $ledgerName,
        int $fyId,
        string $suggestedHead,
        string $acceptedHead,
        float $confidence,
        string $reason,
        string $risk,
        string $source,
        int $userId
    ): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_mapping_audit
                (ledger_name, company_id, fy_id, suggested_head, accepted_head, confidence, reason, risk, source, accepted_by_user_id, accepted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $ledgerName,
                $this->companyId,
                $fyId,
                $suggestedHead,
                $acceptedHead,
                $confidence,
                $reason,
                $risk,
                $source,
                $userId,
            ]);
        } catch (Throwable $e) {
            // Silently ignore audit failures
        }
    }
}
