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
        try {
            $stmt = $this->pdo->prepare("
                SELECT parent_group, primary_group, tally_group_path, tally_group_depth, tally_root_type
                FROM tally_ledger_master
                WHERE company_id = ? AND ledger_name = ?
                LIMIT 1
            ");
            $stmt->execute([$this->companyId, $ledgerName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return [
                    'parent_group' => '',
                    'primary_group' => '',
                    'group_path' => '',
                    'group_depth' => 0,
                    'root_type' => '',
                    'has_hierarchy' => false,
                ];
            }

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
                'reason' => 'Previously approved mapping for this exact ledger+group combination in this company',
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
                'reason' => 'Previously approved mapping from another company (global learned)',
                'alternative_heads' => [],
                'requires_manual_review' => false,
                'source_basis' => ['Learned Mapping (Global)'],
            ];
        }

        // 3. Check hierarchy-based rules
        $bestRule = null;
        $bestScore = 0;

        foreach ($this->hierarchyRules as $rule) {
            $score = 0;

            // Check parent group
            if (!empty($parentGroup) && $this->containsKeyword($parentGroup, $rule['keywords'])) {
                $score += 60;
            }

            // Check primary group
            if (!empty($hierarchy['primary_group']) && $this->containsKeyword($hierarchy['primary_group'], $rule['keywords'])) {
                $score += 20;
            }

            // Check ledger name
            if ($this->containsKeyword($ledgerName, $rule['keywords'])) {
                $score += 15;
            }

            // Check group path (full hierarchy)
            if (!empty($hierarchy['group_path']) && $this->containsKeyword($hierarchy['group_path'], $rule['keywords'])) {
                $score += 5;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRule = $rule;
            }
        }

        if ($bestRule && $bestScore >= 40) {
            // Scale confidence based on match strength
            $confidence = min(99, max(40, $bestRule['confidence'] - (100 - $bestScore)));
            if ($confidence < 70) {
                $confidence = 70;
            }

            $sourceBasis = ['Schedule III Rule'];
            if (!empty($parentGroup)) {
                $sourceBasis[] = 'Parent Group';
            }
            if (!empty($hierarchy['group_path'])) {
                $sourceBasis[] = 'Tally Hierarchy';
            }

            $requiresReview = in_array($bestRule['risk'], ['High', 'Medium']);

            return [
                'suggested_head' => $bestRule['schedule'],
                'confidence' => $confidence,
                'risk' => $bestRule['risk'],
                'reason' => $bestRule['reason'],
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
            'reason' => 'No matching rule found. Manual classification required.',
            'alternative_heads' => [],
            'requires_manual_review' => true,
            'source_basis' => ['No Match'],
        ];
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
