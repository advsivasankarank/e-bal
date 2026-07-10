<?php
/**
 * e-BAL — ReconHub Data Loading Service
 *
 * Centralised database retrieval and dataset preparation for the
 * ReconHub (Mapping Workbench) page.
 *
 * Owns:
 *   - Ledger master loading
 *   - Trial balance loading
 *   - Voucher entries loading
 *   - Mapping and suggestion enrichment
 *   - Risk detection
 *   - Parent-group derivation from current-page data only
 *   - Group-mapping panel data
 *   - Pagination calculations
 *   - Timing instrumentation
 *
 * Does NOT own:
 *   - Context resolution (ReconHubContextResolver)
 *   - View rendering
 *   - JavaScript
 *   - Save/export/import endpoints
 */

require_once __DIR__ . '/../helpers/mapping_ai_helper.php';
require_once __DIR__ . '/../helpers/parent_group_validation_helper.php';
require_once __DIR__ . '/../helpers/hierarchy_ai_mapping.php';
require_once __DIR__ . '/../helpers/bulk_mapping_helper.php';
require_once __DIR__ . '/../engines/ai_mapping_engine.php';

class ReconHubDataLoadingService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load all ReconHub data for the given validated context.
     *
     * @param array $context  Output from ReconHubContextResolver::resolve()
     * @return array          Structured result consumed by the view
     */
    public function load(array $context): array
    {
        $timeStart = microtime(true);
        $companyId = (int) $context['company']['id'];
        $fyId      = (int) $context['financial_year']['id'];
        $mode      = $context['screen']['mode'];
        $perPage   = (int) $context['pagination']['per_page'];
        $page      = (int) $context['pagination']['page'];
        $isGroupMode = $context['screen']['is_group_mode'];

        $result = [
            'grid_data'       => [],
            'stats'           => ['total' => 0, 'mapped' => 0, 'unmapped' => 0, 'auto_suggested' => 0, 'high_confidence' => 0, 'manual_review' => 0, 'risk' => 0],
            'pagination'      => ['page' => $page, 'per_page' => $perPage, 'total_rows' => 0, 'total_pages' => 1, 'offset' => 0],
            'parent_groups'   => ['counts' => [], 'data' => [], 'top' => [], 'mapping_data' => []],
            'mapping_options' => [],
            'mapping_options_json' => [],
            'timing'          => ['query_ms' => 0, 'suggestions_ms' => 0, 'parent_groups_ms' => 0, 'total_ms' => 0],
            'meta'            => [
                'mode'                => $mode,
                'is_group_mode'       => $isGroupMode,
                'is_ledger_mode'      => $context['screen']['is_ledger_mode'],
                'processed_count'     => 0,
                'total_ledger_count'  => 0,
                'tb_impact_count'     => 0,
                'default_view_tb_impact' => false,
                'has_hierarchy_cols'  => false,
                'page_warning'        => '',
                'processing_error'    => null,
                'pct_complete'        => 0,
            ],
            'error' => null,
        ];

        try {
            $this->loadData($result, $companyId, $fyId, $perPage, $page, $timeStart);
        } catch (Throwable $e) {
            error_log('ReconHub data loading failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $result['error'] = [
                'code'    => 'data_loading_failed',
                'message' => 'Unable to load ReconHub data. Please try again or contact support.',
            ];
        }

        $result['timing']['total_ms'] = round((microtime(true) - $timeStart) * 1000);
        return $result;
    }

    /**
     * Core data-loading logic. Populates $result by reference.
     */
    private function loadData(array &$result, int $companyId, int $fyId, int $perPage, int $page, float $timeStart): void
    {
        /* ---- Initialise engines ---- */
        $companyCategory = strtolower((string) $result['_company_category'] ?? '');
        $mappingEngine = new AIMappingEngine($companyCategory, $this->pdo, $companyId);
        $mappingOptions = $mappingEngine->getMappingOptions();
        asort($mappingOptions, SORT_NATURAL | SORT_FLAG_CASE);

        $hierarchyEngine = null;
        try {
            $hierarchyEngine = new HierarchyAIMappingEngine($this->pdo, $companyId, $companyCategory);
        } catch (Throwable $e) {
            error_log('ReconHub: hierarchy engine init failed: ' . $e->getMessage());
            $result['meta']['page_warning'] = 'Hierarchy AI mapping unavailable. Basic mapping mode active.';
        }

        /* ---- Previous FY info ---- */
        $prevFy = getPreviousFyForCompany($this->pdo, $companyId, $fyId);

        /* ---- Check hierarchy columns ---- */
        $hasHierarchyCols = false;
        try {
            $chkStmt = $this->pdo->query("SHOW COLUMNS FROM tally_ledger_master LIKE 'tally_group_path'");
            $hasHierarchyCols = $chkStmt->rowCount() > 0;
        } catch (Throwable $e) { /* ignore */ }
        $result['meta']['has_hierarchy_cols'] = $hasHierarchyCols;

        /* ---- TB-impact ledgers ---- */
        $tbImpactLedgerNames = [];
        try {
            $tbImpactStmt = $this->pdo->prepare("SELECT DISTINCT ledger_name FROM tally_ledgers WHERE company_id = ? AND fy_id = ?");
            $tbImpactStmt->execute([$companyId, $fyId]);
            $tbImpactLedgerNames = $tbImpactStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { /* ignore */ }

        $tbImpactCount = count($tbImpactLedgerNames);
        $result['meta']['tb_impact_count'] = $tbImpactCount;

        $totalLedgerCount = 0;
        try {
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM tally_ledger_master WHERE company_id = ?");
            $countStmt->execute([$companyId]);
            $totalLedgerCount = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) { /* ignore */ }
        $result['meta']['total_ledger_count'] = $totalLedgerCount;

        $defaultViewTbImpact = ($tbImpactCount > 0 && $tbImpactCount < $totalLedgerCount);
        $result['meta']['default_view_tb_impact'] = $defaultViewTbImpact;

        /* ---- Load ledgers ---- */
        $ledgerStmt = $this->pdo->prepare("
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
        $ledgerStmt->execute([$companyId]);
        $allLedgers = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);

        /* ---- Preload hierarchy cache from already-loaded ledger data ---- */
        // Runtime protection: hierarchy data is derived from the same allLedgers query,
        // avoiding per-row SQL in getLedgerHierarchy().
        if ($hierarchyEngine !== null) {
            $hierarchyEngine->setHierarchyCache($allLedgers);
        }

        /* ---- Trial balance data ---- */
        $tbData = $this->loadTrialBalance($companyId, $fyId);

        /* ---- Voucher entries data ---- */
        $veData = $this->loadVoucherEntries($companyId, $fyId);

        /* ---- Mapping helpers ---- */
        $previousFyMappings = $prevFy ? loadPreviousFyMappings($this->pdo, $companyId, $prevFy['id']) : [];
        $globalMaster = loadGlobalMappingMaster($this->pdo);
        $keywordRules = getEnhancedKeywordRules();

        /* ---- Current mappings lookup ---- */
        $currentMappings = [];
        foreach ($allLedgers as $row) {
            if (!empty($row['mapped_code'])) {
                $currentMappings[$row['ledger_name']] = ['schedule_code' => $row['mapped_code']];
            }
        }

        /* ---- Filter to TB-impact ledgers ---- */
        $processingLedgers = $allLedgers;
        $processingError = null;
        if ($defaultViewTbImpact && !empty($tbImpactLedgerNames)) {
            $tbImpactSet = array_flip($tbImpactLedgerNames);
            $processingLedgers = array_values(array_filter($allLedgers, function ($row) use ($tbImpactSet) {
                return isset($tbImpactSet[$row['ledger_name']]);
            }));
            $processingError = 'Showing TB-impact ledgers only (' . count($processingLedgers) . ' of ' . count($allLedgers) . '). Switch to "All Ledger Master" to view all.';
        }

        $maxLedgers = 20000;
        if (count($processingLedgers) > $maxLedgers) {
            $processingError = 'Dataset too large (' . count($processingLedgers) . ' ledgers). Processing first ' . number_format($maxLedgers) . ' only.';
            $processingLedgers = array_slice($processingLedgers, 0, $maxLedgers);
        }
        $result['meta']['processing_error'] = $processingError;

        /* ---- Pagination ---- */
        $totalLedgerRows = count($processingLedgers);
        $totalPages = max(1, (int) ceil($totalLedgerRows / $perPage));
        $page = min($page, $totalPages);
        $gridOffset = ($page - 1) * $perPage;
        $currentPageLedgers = array_slice($processingLedgers, $gridOffset, $perPage);

        $result['pagination'] = [
            'page'        => $page,
            'per_page'    => $perPage,
            'total_rows'  => $totalLedgerRows,
            'total_pages' => $totalPages,
            'offset'      => $gridOffset,
        ];

        $timeQuery = round((microtime(true) - $timeStart) * 1000);

        /* ---- Process current-page rows ---- */
        // Runtime protection: suggestion enrichment is limited to current-page rows only.
        $gridData = [];
        $stats = ['total' => 0, 'mapped' => 0, 'unmapped' => 0, 'auto_suggested' => 0, 'high_confidence' => 0, 'manual_review' => 0, 'risk' => 0];

        try {
        foreach ($currentPageLedgers as $row) {
            $name = $row['ledger_name'];
            $group = $row['parent_group'] ?? '';
            $mappedCode = $row['mapped_code'] ?? '';
            $isMapped = $mappedCode !== '';

            $tb = $tbData[$name] ?? null;
            $ve = $veData[$name] ?? null;

            $openingDr = 0; $openingCr = 0;
            $closingDr = 0; $closingCr = 0;
            $drcr = '';

            if ($tb) {
                if (isset($tb['opening_debit'])) {
                    $openingDr = (float) $tb['opening_debit'];
                    $openingCr = (float) $tb['opening_credit'];
                    $closingDr = (float) $tb['closing_debit'];
                    $closingCr = (float) $tb['closing_credit'];
                } else {
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

            $suggestion = ['schedule_code' => '', 'confidence' => 0, 'source' => 'none', 'reason' => 'Suggestion generation failed.'];
            try {
                $suggestion = suggestBulkMapping(
                    $name, $group, $currentMappings,
                    $previousFyMappings, $globalMaster, $keywordRules,
                    $hierarchyEngine, $mappingEngine
                );
            } catch (\Throwable $e) { /* Fallback: no suggestion */ }

            $scheduleLabel = $mappedCode !== '' ? $mappingEngine->getLabel($mappedCode) : '';
            $suggestedLabel = $suggestion['schedule_code'] !== '' ? $mappingEngine->getLabel($suggestion['schedule_code']) : '';
            $status = $isMapped ? 'Mapped' : 'Unmapped';

            /* DR/CR Risk Detection */
            $riskLevel = 'none';
            $riskReason = '';
            $scheduleCode = $suggestion['schedule_code'] ?? '';
            $assetSchedules = ['ppe', 'inventory', 'receivables', 'cash', 'bank_balances_other', 'other_current_assets', 'investments_non_current', 'loans_non_current', 'intangible_assets', 'cwip', 'deferred_tax_asset', 'other_non_current_assets', 'investments_current', 'loans_current'];
            $liabilitySchedules = ['trade_payables', 'lt_borrowings', 'st_borrowings', 'other_current_liabilities', 'short_term_provisions', 'share_capital', 'reserves', 'deferred_tax_liability', 'other_non_current_liabilities', 'long_term_provisions'];

            $plVariants = ['profit & loss a/c', 'profit and loss a/c', 'profit & loss account', 'profit and loss account', 'p&l a/c', 'p and l a/c', 'surplus in statement of profit and loss'];
            if (in_array(strtolower($group), $plVariants) && $scheduleCode !== 'reserves') {
                $riskLevel = 'critical';
                $riskReason = 'Profit & Loss A/c should map to Reserves (Equity). Current mapping: ' . ($scheduleCode ?: 'Unmapped');
            }

            $bankOdVariants = ['bank od', 'od account', 'overdraft', 'cash credit', 'bank overdraft', 'current account'];
            if (in_array(strtolower($group), $bankOdVariants) && $closingCr > 0 && $scheduleCode === 'cash') {
                $riskLevel = 'critical';
                $riskReason = 'Bank OD/CC with credit balance should map to st_borrowings, not cash.';
            }

            $patientAdvanceVariants = ['advance in patient', 'patient advance'];
            if (in_array(strtolower($group), $patientAdvanceVariants) && $closingCr > 0 && $scheduleCode === 'receivables') {
                $riskLevel = 'critical';
                $riskReason = 'Patient advance with credit balance is a liability, not a receivable.';
            }

            if (strtolower($group) === 'insurance patient' && $closingCr > 0 && $scheduleCode === 'receivables') {
                $riskLevel = 'critical';
                $riskReason = 'Insurance patient with credit balance is a liability, not a receivable.';
            }

            if ($riskLevel === 'none' && $closingCr > 0 && in_array($scheduleCode, $assetSchedules)) {
                $riskLevel = 'warning';
                $riskReason = 'Credit balance in asset schedule.';
            }

            if ($riskLevel === 'none' && $closingDr > 0 && in_array($scheduleCode, $liabilitySchedules)) {
                $riskLevel = 'warning';
                $riskReason = 'Debit balance in liability/equity schedule.';
            }

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
            $result['meta']['processing_error'] = $e->getMessage();
            error_log('ReconHub processing error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }

        $timeSuggestions = round((microtime(true) - $timeStart - ($timeQuery / 1000)) * 1000);

        /* ---- Parent groups from current-page gridData only ---- */
        // Runtime protection: derive parent groups only from the current paginated dataset.
        $timePgStart = microtime(true);
        $parentGroupCounts = [];
        $parentGroupData = [];
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
        arsort($parentGroupCounts);
        $topParentGroups = array_slice($parentGroupCounts, 0, 25, true);

        /* ---- Group mapping panel data ---- */
        $groupMappingData = [];
        try {
        $assetSchedulesForRisk = ['ppe', 'inventory', 'receivables', 'cash', 'bank_balances_other', 'other_current_assets', 'investments_non_current', 'loans_non_current', 'intangible_assets', 'cwip', 'deferred_tax_asset', 'other_non_current_assets', 'investments_current', 'loans_current'];
        $liabilitySchedulesForRisk = ['trade_payables', 'lt_borrowings', 'st_borrowings', 'other_current_liabilities', 'short_term_provisions', 'share_capital', 'reserves', 'deferred_tax_liability', 'other_non_current_liabilities', 'long_term_provisions'];
        $mappingCountsByGroup = [];
        $crPositiveCountByGroup = [];
        $drPositiveCountByGroup = [];
        foreach ($gridData as $row) {
            $pg = $row['parent_group'] ?: '(none)';
            if (!empty($row['current_mapping'])) {
                $mc = $row['current_mapping'];
                $mappingCountsByGroup[$pg][$mc] = ($mappingCountsByGroup[$pg][$mc] ?? 0) + 1;
            }
            if ($row['closing_cr'] > 0) {
                $crPositiveCountByGroup[$pg] = ($crPositiveCountByGroup[$pg] ?? 0) + 1;
            }
            if ($row['closing_dr'] > 0) {
                $drPositiveCountByGroup[$pg] = ($drPositiveCountByGroup[$pg] ?? 0) + 1;
            }
        }

        foreach ($parentGroupCounts as $pg => $cnt) {
            $pgData = $parentGroupData[$pg];
            $netBal = $pgData['net_balance'];
            $drcr = $pgData['closing_dr'] > $pgData['closing_cr'] ? 'DR' : ($pgData['closing_cr'] > $pgData['closing_dr'] ? 'CR' : '');

            $dominantMapping = '';
            $mappingCounts = $mappingCountsByGroup[$pg] ?? [];
            if (!empty($mappingCounts)) {
                arsort($mappingCounts);
                $mappingKeys = array_keys($mappingCounts);
                $dominantMapping = reset($mappingKeys);
            }

            $suggested = matchParentGroupRule($pg);
            $suggestedCode = $suggested ? $suggested['schedule_code'] : '';
            $confidence = $suggested ? $suggested['confidence'] : 0;

            $riskCount = 0;
            if (in_array($suggestedCode, $assetSchedulesForRisk, true)) {
                $riskCount += $crPositiveCountByGroup[$pg] ?? 0;
            }
            if (in_array($suggestedCode, $liabilitySchedulesForRisk, true)) {
                $riskCount += $drPositiveCountByGroup[$pg] ?? 0;
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
        } catch (Throwable $e) {
            error_log('ReconHub: Group mapping panel error: ' . $e->getMessage());
            $groupMappingData = [];
        }

        $timePg = round((microtime(true) - $timePgStart) * 1000);

        /* ---- Mapping options JSON ---- */
        $mappingOptionsJson = [];
        foreach ($mappingOptions as $code => $label) {
            $mappingOptionsJson[] = ['id' => $code, 'label' => $label . ' (' . $code . ')', 'code' => $code, 'fullLabel' => $label];
        }

        $pctComplete = $stats['total'] > 0 ? round(($stats['mapped'] / $stats['total']) * 100) : 0;

        /* ---- Populate result ---- */
        $result['grid_data'] = $gridData;
        $result['stats'] = $stats;
        $result['parent_groups'] = [
            'counts'       => $parentGroupCounts,
            'data'         => $parentGroupData,
            'top'          => $topParentGroups,
            'mapping_data' => $groupMappingData,
        ];
        $result['mapping_options'] = $mappingOptions;
        $result['mapping_options_json'] = $mappingOptionsJson;
        $result['timing'] = [
            'query_ms'          => $timeQuery,
            'suggestions_ms'    => $timeSuggestions,
            'parent_groups_ms'  => $timePg,
            'total_ms'          => 0, // filled by caller
        ];
        $result['meta']['processed_count'] = count($currentPageLedgers);
        $result['meta']['pct_complete'] = $pctComplete;

        error_log("ReconHub timing: query={$timeQuery}ms, suggestions={$timeSuggestions}ms, parent_groups={$timePg}ms, processed=" . count($currentPageLedgers) . " of " . count($processingLedgers));
    }

    /**
     * Load trial balance amounts. Handles missing columns gracefully.
     *
     * Loads one row per ledger for the given company/FY.
     * This is the same query that existed in the original mapping_workbench.php.
     * The full FY dataset is loaded because DR/CR computation requires
     * looking up arbitrary ledger names from the current page, and the
     * ledger names are not known until after pagination.
     */
    private function loadTrialBalance(int $companyId, int $fyId): array
    {
        $tbData = [];
        try {
            $tbColumns = $this->pdo->query("SHOW COLUMNS FROM tally_ledgers")->fetchAll(PDO::FETCH_COLUMN);
            $hasOpeningDebit = in_array('opening_debit', $tbColumns);
            $hasOpeningCredit = in_array('opening_credit', $tbColumns);
            $hasClosingDebit = in_array('closing_debit', $tbColumns);
            $hasClosingCredit = in_array('closing_credit', $tbColumns);

            if ($hasOpeningDebit && $hasOpeningCredit && $hasClosingDebit && $hasClosingCredit) {
                $tbStmt = $this->pdo->prepare("
                    SELECT ledger_name,
                           opening_debit, opening_credit,
                           closing_debit, closing_credit,
                           dr_cr, parent_group
                    FROM tally_ledgers
                    WHERE company_id = ? AND fy_id = ?
                ");
                $tbStmt->execute([$companyId, $fyId]);
                while ($row = $tbStmt->fetch(PDO::FETCH_ASSOC)) {
                    $tbData[$row['ledger_name']] = $row;
                }
            } else {
                $tbStmt = $this->pdo->prepare("
                    SELECT ledger_name,
                           opening_amount,
                           amount AS closing_amount,
                           dr_cr, parent_group
                    FROM tally_ledgers
                    WHERE company_id = ? AND fy_id = ?
                ");
                $tbStmt->execute([$companyId, $fyId]);
                while ($row = $tbStmt->fetch(PDO::FETCH_ASSOC)) {
                    $tbData[$row['ledger_name']] = $row;
                }
            }
        } catch (Throwable $e) {
            error_log('ReconHub: TB load failed: ' . $e->getMessage());
        }
        return $tbData;
    }

    /**
     * Load voucher entries data (graceful fallback if table missing).
     *
     * Already aggregated by ledger_name in SQL (SUM + GROUP BY).
     * Returns one row per ledger with total_dr and total_cr.
     * Unchanged from the original mapping_workbench.php implementation.
     */
    private function loadVoucherEntries(int $companyId, int $fyId): array
    {
        $veData = [];
        try {
            $veStmt = $this->pdo->prepare("
                SELECT ledger_name,
                       SUM(CASE WHEN dr_cr = 'DR' THEN amount ELSE 0 END) AS total_dr,
                       SUM(CASE WHEN dr_cr = 'CR' THEN amount ELSE 0 END) AS total_cr
                FROM voucher_entries
                WHERE company_id = ? AND fy_id = ?
                GROUP BY ledger_name
            ");
            $veStmt->execute([$companyId, $fyId]);
            while ($row = $veStmt->fetch(PDO::FETCH_ASSOC)) {
                $veData[$row['ledger_name']] = $row;
            }
        } catch (Throwable $e) {
            /* voucher_entries may not exist */
        }
        return $veData;
    }
}
