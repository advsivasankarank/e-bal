<?php
/**
 * e-BAL — ReconHub Context Resolver
 *
 * Centralised request-context identification, validation, and normalisation
 * for the ReconHub (Mapping Workbench) page.
 *
 * Resolves:
 *   - Authentication
 *   - Company/entity existence, archive status, and user access
 *   - Financial-year existence, company ownership, and status
 *   - Request parameters (mode, pagination)
 *   - Schema readiness
 *
 * Performs only small, targeted validation queries.
 * Does NOT load ledgers, aggregations, mappings, suggestions, or hierarchy data.
 *
 * Security model:
 *   - Requires authenticated user (user_id > 0)
 *   - Validates company access via canViewEntity() from entity_access_helper
 *   - Rejects archived companies (archived_at IS NOT NULL)
 *   - Validates FY exists and belongs to selected company
 *   - Checks FY status (closed/open) where schema supports it
 *   - Session values are persisted only after validation succeeds
 *   - Malformed IDs are distinguished from missing IDs
 */

require_once __DIR__ . '/entity_access_helper.php';

class ReconHubContextResolver
{
    private PDO $pdo;

    /** @var array<string, string> Allowed view modes */
    private const ALLOWED_MODES = ['group', 'ledger'];

    /** @var int Hard maximum page size */
    private const MAX_PER_PAGE = 100;

    /** @var int Minimum page size */
    private const MIN_PER_PAGE = 25;

    /** @var int Default page size */
    private const DEFAULT_PER_PAGE = 50;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Resolve the complete operating context for ReconHub.
     *
     * @param array<string, mixed> $get     Superglobal $_GET
     * @param array<string, mixed> $session Superglobal $_SESSION (reference-mutated for validated company_id/fy_id)
     *
     * @return array{
     *     user: array{id: int, role: string},
     *     company: array{id: int, name: string, category: string},
     *     financial_year: array{id: int, label: string, start_date: string, end_date: string, status: string, is_closed: bool},
     *     screen: array{mode: string, is_group_mode: bool, is_ledger_mode: bool},
     *     pagination: array{per_page: int, page: int},
     *     schema: array{mapping_ready: bool},
     *     timing_ms: float,
     *     query_count: int,
     *     error: string|null,
     *     error_page_title: string|null,
     *     error_message: string|null,
     * }
     *
     * When 'error' is set, the caller should render the error page and exit.
     */
    public function resolve(array $get, array &$session): array
    {
        $timeStart = microtime(true);
        $queryCount = 0;

        // =====================================================================
        // 1. Authentication
        // =====================================================================
        $userId = (int) ($session['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->buildErrorResult($timeStart, $queryCount, 'authentication_required',
                'Login Required', 'Please log in to continue.');
        }

        // =====================================================================
        // 2. Resolve candidate company ID (GET → session)
        //    Distinguish: absent, malformed, zero/negative
        //    Do NOT persist to session yet — validate first.
        // =====================================================================
        $rawCompanyId = $get['company_id'] ?? $get['entity_id'] ?? null;
        $candidateCompanyId = $this->resolveIntId($rawCompanyId, 'company_id');

        // Fall back to session if no GET value
        if ($candidateCompanyId === null) {
            $candidateCompanyId = $this->resolveIntId($session['company_id'] ?? null, 'company_id');
        }

        if ($candidateCompanyId === null) {
            return $this->buildErrorResult($timeStart, $queryCount, 'missing_company_context',
                "ReconHub \u2014 Select Context", 'Please select an entity and financial year to continue.');
        }

        if ($candidateCompanyId <= 0) {
            return $this->buildErrorResult($timeStart, $queryCount, 'invalid_company_id',
                'Invalid Entity', 'The entity ID is invalid. Please select a valid entity.');
        }

        // =====================================================================
        // 3. Resolve candidate FY ID (GET → session)
        //    Distinguish: absent, malformed, zero/negative
        //    Do NOT persist to session yet — validate first.
        // =====================================================================
        $rawFyId = $get['fy_id'] ?? null;
        $candidateFyId = $this->resolveIntId($rawFyId, 'fy_id');

        if ($candidateFyId === null) {
            $candidateFyId = $this->resolveIntId($session['fy_id'] ?? null, 'fy_id');
        }

        if ($candidateFyId === null) {
            return $this->buildErrorResult($timeStart, $queryCount, 'missing_fy_context',
                "ReconHub \u2014 Select Context", 'Please select a financial year to continue.');
        }

        if ($candidateFyId <= 0) {
            return $this->buildErrorResult($timeStart, $queryCount, 'invalid_fy_id',
                'Invalid Financial Year', 'The financial year ID is invalid. Please select a valid financial year.');
        }

        // =====================================================================
        // 4. Company existence + user access validation
        //    Uses canViewEntity() from entity_access_helper.php
        // =====================================================================
        $canView = canViewEntity($this->pdo, $candidateCompanyId);
        $queryCount++; // canViewEntity executes 1 query

        if (!$canView) {
            // Distinguish: company not found vs access denied vs archived
            $companyRow = $this->getCompanyRow($candidateCompanyId);
            $queryCount++;

            if (!$companyRow) {
                return $this->buildErrorResult($timeStart, $queryCount, 'company_not_found',
                    'Entity Not Found', 'The selected entity does not exist. Please select a valid entity.');
            }

            // Check if company is archived
            if (!empty($companyRow['archived_at'])) {
                // Clear any stale session context for this company
                unset($session['company_id'], $session['company_name'], $session['fy_id'], $session['fy_name']);
                return $this->buildErrorResult($timeStart, $queryCount, 'company_archived',
                    'Entity Archived', 'This entity has been archived and is no longer accessible. Please contact support to restore it.');
            }

            return $this->buildErrorResult($timeStart, $queryCount, 'company_access_denied',
                'Access Denied', 'You do not have permission to access this entity.');
        }

        // =====================================================================
        // 5. Company/FY context query (consolidated)
        //    Validates: FY exists, FY belongs to company
        //    Resolves: company name, category, FY label, FY status
        //    Note: company existence and access already validated above
        // =====================================================================
        $fyRow = null;

        try {
            // Check if financial_years has a status column
            $hasStatusCol = $this->columnExists('financial_years', 'status');
            $queryCount++;

            $statusSelect = $hasStatusCol ? ', fy.status AS fy_status' : ", '' AS fy_status";

            $stmt = $this->pdo->prepare("
                SELECT
                    c.name AS company_name,
                    c.category AS company_category,
                    fy.id AS fy_id,
                    fy.fy_label AS fy_label,
                    fy.fy_start AS fy_start,
                    fy.fy_end AS fy_end
                    {$statusSelect}
                FROM companies c
                INNER JOIN financial_years fy ON fy.company_id = c.id AND fy.id = ?
                WHERE c.id = ?
                LIMIT 1
            ");
            $stmt->execute([$candidateFyId, $candidateCompanyId]);
            $fyRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $queryCount++;
        } catch (Throwable $e) {
            appLog('ERROR', 'ReconHub context: Company/FY query failed', ['message' => $e->getMessage()]);
        }

        // ---- FY not found or FY belongs to another company ----
        if (!$fyRow) {
            $fyExists = $this->fyExists($candidateFyId);
            $queryCount++;

            if ($fyExists) {
                // FY exists but belongs to another company — clear stale FY from session
                unset($session['fy_id'], $session['fy_name']);
                return $this->buildErrorResult($timeStart, $queryCount, 'fy_company_mismatch',
                    "ReconHub \u2014 Invalid Financial Year",
                    'The selected financial year does not belong to this entity. Please select a valid financial year.');
            }

            // FY does not exist at all — clear stale FY from session
            unset($session['fy_id'], $session['fy_name']);
            return $this->buildErrorResult($timeStart, $queryCount, 'fy_not_found',
                "ReconHub \u2014 Invalid Financial Year",
                'The selected financial year does not exist. Please select a valid financial year.');
        }

        $companyName = (string) ($fyRow['company_name'] ?? 'Unknown');
        $companyCategory = strtolower((string) ($fyRow['company_category'] ?? ''));
        $fyLabel = (string) ($fyRow['fy_label'] ?? '');
        $fyStart = (string) ($fyRow['fy_start'] ?? '');
        $fyEnd = (string) ($fyRow['fy_end'] ?? '');
        $fyStatus = strtolower(trim((string) ($fyRow['fy_status'] ?? '')));
        $isClosed = in_array($fyStatus, ['closed', 'locked'], true);

        // ---- FY closed/locked status ----
        // Preserving existing behaviour: Mapping Workbench currently does not block closed FY.
        // The closed status is resolved and returned for downstream use if needed.

        // =====================================================================
        // 6. Persist validated context to session
        //    Only now that ALL validation has passed.
        // =====================================================================
        $session['company_id'] = $candidateCompanyId;
        $session['company_name'] = $companyName;
        $session['fy_id'] = $candidateFyId;
        $session['fy_name'] = $fyLabel;

        // =====================================================================
        // 7. Screen context (mode)
        // =====================================================================
        $mode = isset($get['mode']) && in_array($get['mode'], self::ALLOWED_MODES, true) ? $get['mode'] : 'group';

        // =====================================================================
        // 8. Pagination
        // =====================================================================
        $perPage = max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) ($get['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $page = max(1, (int) ($get['page'] ?? 1));

        // =====================================================================
        // 9. Schema readiness
        // =====================================================================
        $schemaReady = $this->checkSchemaReady();
        $queryCount += 2; // SHOW COLUMNS FROM ledger_mapping + SHOW TABLES LIKE mapping_learning

        $timingMs = round((microtime(true) - $timeStart) * 1000);

        return [
            'user' => ['id' => $userId, 'role' => getCurrentUserRole($this->pdo)],
            'company' => ['id' => $candidateCompanyId, 'name' => $companyName, 'category' => $companyCategory],
            'financial_year' => [
                'id' => $candidateFyId,
                'label' => $fyLabel,
                'start_date' => $fyStart,
                'end_date' => $fyEnd,
                'status' => $fyStatus,
                'is_closed' => $isClosed,
            ],
            'screen' => ['mode' => $mode, 'is_group_mode' => $mode === 'group', 'is_ledger_mode' => $mode === 'ledger'],
            'pagination' => ['per_page' => $perPage, 'page' => $page],
            'schema' => ['mapping_ready' => $schemaReady],
            'timing_ms' => $timingMs,
            'query_count' => $queryCount,
            'error' => null,
            'error_page_title' => null,
            'error_message' => null,
        ];
    }

    /**
     * Safely resolve an integer ID from a raw value.
     *
     * Returns null if the value is absent/empty.
     * Returns 0 if the value is malformed (non-integer string, array, etc).
     * Returns the positive integer if valid.
     * Returns the negative/zero integer if that's what was provided.
     *
     * @param mixed $rawValue  The raw value from GET or session
     * @param string $paramName  Parameter name for logging
     * @return int|null  null = absent, 0 = malformed, int = resolved value
     */
    private function resolveIntId($rawValue, string $paramName): ?int
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        // Reject arrays
        if (is_array($rawValue)) {
            appLog('WARNING', 'ReconHub context: param received array value, treating as malformed', ['param' => $paramName]);
            return 0;
        }

        // Reject booleans
        if (is_bool($rawValue)) {
            return 0;
        }

        // If it's already an integer, use it directly
        if (is_int($rawValue)) {
            return $rawValue;
        }

        // For strings, validate with filter_var
        if (is_string($rawValue)) {
            $filtered = filter_var(trim($rawValue), FILTER_VALIDATE_INT);
            if ($filtered === false) {
                // Malformed: non-integer string like "abc", "1.5", "1e2"
                return 0;
            }
            return $filtered;
        }

        // For floats, reject if not a whole number
        if (is_float($rawValue)) {
            if ($rawValue != (int) $rawValue) {
                return 0;
            }
            return (int) $rawValue;
        }

        return 0;
    }

    /**
     * Get a company row including archive status.
     * Returns null if company not found.
     */
    private function getCompanyRow(int $companyId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, archived_at FROM companies WHERE id = ? LIMIT 1");
            $stmt->execute([$companyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Check whether an FY exists (regardless of company).
     */
    private function fyExists(int $fyId): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM financial_years WHERE id = ? LIMIT 1");
            $stmt->execute([$fyId]);
            return (bool) $stmt->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check whether a column exists on a table.
     */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
            $stmt->execute([$column]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Check whether required mapping schema columns and tables exist.
     * Read-only — no DDL executed.
     */
    private function checkSchemaReady(): bool
    {
        try {
            $requiredCols = ['mapping_source', 'confidence_score', 'mapping_reason', 'override_parent_group'];
            $existingCols = $this->pdo->query("SHOW COLUMNS FROM ledger_mapping")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($requiredCols as $col) {
                if (!in_array($col, $existingCols, true)) {
                    return false;
                }
            }
            $tblCheck = $this->pdo->query("SHOW TABLES LIKE 'mapping_learning'");
            return $tblCheck->rowCount() > 0;
        } catch (Throwable $e) {
            appLog('ERROR', 'ReconHub context: Schema check failed', ['message' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Build a standardised error result.
     */
    private function buildErrorResult(float $timeStart, int $queryCount, string $errorType, string $pageTitle, string $message): array
    {
        return [
            'user' => ['id' => 0, 'role' => ''],
            'company' => ['id' => 0, 'name' => '', 'category' => ''],
            'financial_year' => ['id' => 0, 'label' => '', 'start_date' => '', 'end_date' => '', 'status' => '', 'is_closed' => false],
            'screen' => ['mode' => 'group', 'is_group_mode' => true, 'is_ledger_mode' => false],
            'pagination' => ['per_page' => self::DEFAULT_PER_PAGE, 'page' => 1],
            'schema' => ['mapping_ready' => false],
            'timing_ms' => round((microtime(true) - $timeStart) * 1000),
            'query_count' => $queryCount,
            'error' => $errorType,
            'error_page_title' => $pageTitle,
            'error_message' => $message,
        ];
    }
}
