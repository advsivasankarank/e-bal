<?php
/**
 * e-BAL — Balance Sheet Diff Diagnostics
 *
 * Explains everything contributing to a non-zero BS diff as a ranked list
 * of issues, reusing the existing classification/validation engines rather
 * than re-deriving the numbers. See app/helpers/parent_group_validation_helper.php
 * for the nature-detection rules this builds on.
 */

require_once __DIR__ . '/../engines/fs_engine.php';
require_once __DIR__ . '/report_validation_helper.php';
require_once __DIR__ . '/parent_group_validation_helper.php';
require_once __DIR__ . '/schedule3_master_helper.php';
require_once __DIR__ . '/figure_helper.php';

function ensureBsDiagnosticsAuditSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bs_diagnostics_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            issue_id VARCHAR(191) NOT NULL,
            action VARCHAR(20) NOT NULL,
            ledger_name VARCHAR(255) NOT NULL,
            before_note VARCHAR(100) NOT NULL DEFAULT '',
            after_note VARCHAR(100) NOT NULL DEFAULT '',
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Map the singular nature vocabulary used internally (asset/liability/income/expense)
 * to the plural vocabulary used in the diagnostics API (assets/liabilities/...).
 */
function bsDiagNaturePlural(?string $nature): ?string
{
    if ($nature === null) {
        return null;
    }
    $map = [
        'asset' => 'assets',
        'liability' => 'liabilities',
        'income' => 'income',
        'expense' => 'expenses',
    ];
    return $map[$nature] ?? $nature;
}

function bsDiagSlug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : 'item';
}

/**
 * schedule_code -> ['number' => Schedule III note no, 'title' => note title]
 * for corporate entities. Mirrors the map built inline in trial_balance_preview.php.
 */
function bsDiagScheduleCodeNoteMap(): array
{
    $master = getSchedule3NotesMaster();
    $codeMap = schedule3MasterCodeToScheduleCodes();
    $map = [];
    foreach ($master as $noteNo => $meta) {
        $masterCode = (string) ($meta['code'] ?? '');
        foreach ($codeMap[$masterCode] ?? [] as $scheduleCode) {
            $map[$scheduleCode] = [
                'number' => (string) $noteNo,
                'title' => (string) ($meta['title'] ?? ''),
            ];
        }
    }
    return $map;
}

/**
 * Every schedule code known to the Schedule III master (BS + PL notes).
 */
function bsDiagAllScheduleCodes(): array
{
    $codes = [];
    foreach (schedule3MasterCodeToScheduleCodes() as $group) {
        foreach ($group as $code) {
            $codes[$code] = true;
        }
    }
    return array_keys($codes);
}

/**
 * Candidate notes a ledger of the given nature could legitimately be mapped to.
 * Nature is derived purely from normalizeScheduleCodeNature() — never from the
 * ledger's currently-assigned note — which is what keeps this guardrail honest.
 *
 * For corporate entities candidates are collapsed to distinct Schedule III notes
 * (two schedule codes belonging to the same note count as one candidate).
 * For LLP/non-corporate entities, which have no Schedule III numbering, each
 * matching schedule code is its own candidate.
 */
function bsDiagSuggestedNoteCandidates(string $ledgerNature, string $entityCategory): array
{
    $matchingCodes = array_values(array_filter(
        bsDiagAllScheduleCodes(),
        static fn (string $code): bool => normalizeScheduleCodeNature($code) === $ledgerNature
    ));

    if ($entityCategory !== 'corporate') {
        return array_map(static fn (string $code): array => [
            'note_id' => $code,
            'note_no' => null,
            'label' => scheduleCodeLabel($code),
        ], $matchingCodes);
    }

    $noteMap = bsDiagScheduleCodeNoteMap();
    $byNote = [];
    foreach ($matchingCodes as $code) {
        $meta = $noteMap[$code] ?? null;
        if ($meta === null) {
            continue;
        }
        $key = $meta['number'];
        if (!isset($byNote[$key])) {
            $byNote[$key] = [
                'note_id' => $code,
                'note_no' => $meta['number'],
                'label' => 'Note ' . $meta['number'] . ' - ' . $meta['title'],
            ];
        }
    }

    return array_values($byNote);
}

function bsDiagLedgerBalance(PDO $pdo, int $company_id, int $fy_id, string $ledgerName): array
{
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN dr_cr = 'DR' THEN amount ELSE 0 END) AS dr_total,
            SUM(CASE WHEN dr_cr = 'CR' THEN amount ELSE 0 END) AS cr_total
        FROM tally_ledgers
        WHERE company_id = ? AND fy_id = ? AND ledger_name = ?
    ");
    $stmt->execute([$company_id, $fy_id, $ledgerName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $drTotal = (float) ($row['dr_total'] ?? 0);
    $crTotal = (float) ($row['cr_total'] ?? 0);

    return [
        'ledger_balance' => round(abs($drTotal - $crTotal), 2),
        'dr_cr' => $drTotal >= $crTotal ? 'DR' : 'CR',
    ];
}

/**
 * Unmapped ledgers (no ledger_mapping row at all, or an empty schedule_code) —
 * the structural validation_error that must be fixed before anything else,
 * since getClassifiedData() silently drops these ledgers from every total.
 */
function bsDiagUnmappedLedgersIssue(PDO $pdo, int $company_id, int $fy_id): ?array
{
    $stmt = $pdo->prepare("
        SELECT tl.ledger_name,
               SUM(CASE WHEN tl.dr_cr = 'DR' THEN tl.amount ELSE -tl.amount END) AS net_amount
        FROM tally_ledgers tl
        LEFT JOIN ledger_mapping lm
            ON lm.company_id = tl.company_id AND lm.ledger_name = tl.ledger_name
        WHERE tl.company_id = ? AND tl.fy_id = ?
          AND (lm.schedule_code IS NULL OR lm.schedule_code = '')
        GROUP BY tl.ledger_name
        ORDER BY tl.ledger_name
    ");
    $stmt->execute([$company_id, $fy_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        return null;
    }

    $totalAmount = 0.0;
    $names = [];
    foreach ($rows as $row) {
        $totalAmount += abs((float) ($row['net_amount'] ?? 0));
        $names[] = (string) $row['ledger_name'];
    }

    return [
        'issue_id' => 'unmapped-ledgers',
        'type' => 'validation_error',
        'severity' => 'error',
        'ledgers_affected' => count($rows),
        'total_amount' => round($totalAmount, 2),
        'ledger_names' => array_slice($names, 0, 25),
        'auto_fixable' => false,
        'suggested_action' => 'Map these ledgers to a note in Note Mapping',
        'message' => count($rows) . ' ledger(s) have no note mapping at all and are fully excluded from the Balance Sheet totals.',
    ];
}

/**
 * One issue per note title that generateFinancialStatements() expects but did
 * not build a section for (buildNoteCompletenessAudit()). For corporate entities
 * we resolve the title back to its Schedule III note number and cross-reference
 * already-mapped ledgers for that note's schedule codes so the amount reflects
 * real data, not just a title mismatch.
 */
function bsDiagMissingNoteHeadingIssues(array $fs, string $entityCategory): array
{
    $missing = $fs['validation']['note_completeness']['missing'] ?? [];
    if ($missing === []) {
        return [];
    }

    $scheduleItems = $fs['schedule_items'] ?? [];
    $issues = [];

    if ($entityCategory === 'corporate') {
        $master = getSchedule3NotesMaster();
        $codeMap = schedule3MasterCodeToScheduleCodes();
        $titleToNote = [];
        foreach ($master as $noteNo => $meta) {
            $titleToNote[normalizeNoteAuditTitle((string) ($meta['title'] ?? ''))] = [
                'number' => (string) $noteNo,
                'title' => (string) ($meta['title'] ?? ''),
                'code' => (string) ($meta['code'] ?? ''),
            ];
        }

        foreach ($missing as $title) {
            $title = is_array($title) ? (string) ($title['title'] ?? $title['label'] ?? '') : (string) $title;
            $noteMeta = $titleToNote[normalizeNoteAuditTitle($title)] ?? null;

            $ledgersAffected = 0;
            $totalAmount = 0.0;
            $noteNo = null;
            if ($noteMeta !== null) {
                $noteNo = $noteMeta['number'];
                foreach ($codeMap[$noteMeta['code']] ?? [] as $code) {
                    $item = $scheduleItems[$code] ?? null;
                    if ($item === null) {
                        continue;
                    }
                    $ledgersAffected += count($item['rows'] ?? []);
                    $totalAmount += abs((float) ($item['amount'] ?? 0));
                }
            }

            $issues[] = [
                'issue_id' => $noteNo !== null ? ('missing-heading-note' . $noteNo) : ('missing-heading-' . bsDiagSlug($title)),
                'type' => 'missing_note_heading',
                'severity' => 'error',
                'note_no' => $noteNo !== null ? (int) $noteNo : null,
                'note_name' => $title,
                'ledgers_affected' => $ledgersAffected,
                'total_amount' => round($totalAmount, 2),
                'auto_fixable' => false,
                'suggested_action' => $noteNo !== null
                    ? ('Generate standard heading for Note ' . $noteNo . ' from template')
                    : 'Review this note section in Mapping Workbench',
                'message' => 'Note "' . $title . '" is expected for this entity type but no heading was generated for it.',
            ];
        }

        return $issues;
    }

    /* LLP / non-corporate: no Schedule III numbering exists at this granularity,
       so ledgers_affected/total_amount are reported as 0 (documented simplification). */
    foreach ($missing as $title) {
        $title = is_array($title) ? (string) ($title['title'] ?? $title['label'] ?? '') : (string) $title;
        $issues[] = [
            'issue_id' => 'missing-heading-' . bsDiagSlug($title),
            'type' => 'missing_note_heading',
            'severity' => 'error',
            'note_no' => null,
            'note_name' => $title,
            'ledgers_affected' => 0,
            'total_amount' => 0.0,
            'auto_fixable' => false,
            'suggested_action' => 'Review this note section in Mapping Workbench',
            'message' => 'Note "' . $title . '" is expected for this entity type but no heading was generated for it.',
        ];
    }

    return $issues;
}

/**
 * One issue per parent-group conflict already detected by classification_engine.php.
 * ledger_nature is always derived from the Tally parent group (normalizeParentGroupNature),
 * never from the ledger's current note — that is the entire bug class this feature exists
 * to catch. Protected by tests/bs_diagnostics_nature_test.php.
 */
function bsDiagParentGroupConflictIssues(PDO $pdo, int $company_id, int $fy_id, array $fs, string $entityCategory): array
{
    $conflicts = $fs['validation']['parent_group_conflicts'] ?? [];
    if ($conflicts === []) {
        return [];
    }

    $issues = [];
    $usedIds = [];

    foreach ($conflicts as $conflict) {
        $ledgerName = (string) ($conflict['ledger_name'] ?? '');
        $parentGroup = (string) ($conflict['parent_group'] ?? '');
        $currentNote = (string) ($conflict['schedule_code'] ?? '');
        $ledgerNature = $conflict['parent_group_nature'] ?? normalizeParentGroupNature($parentGroup);
        $currentNoteNature = $conflict['schedule_code_nature'] ?? normalizeScheduleCodeNature($currentNote);

        $candidates = $ledgerNature !== null
            ? bsDiagSuggestedNoteCandidates($ledgerNature, $entityCategory)
            : [];
        $autoFixable = count($candidates) === 1;
        $suggestedNote = $autoFixable ? $candidates[0]['note_id'] : null;

        $slug = 'conflict-' . bsDiagSlug($ledgerName);
        if (isset($usedIds[$slug])) {
            $usedIds[$slug]++;
            $slug .= '-' . $usedIds[$slug];
        } else {
            $usedIds[$slug] = 1;
        }

        $balance = bsDiagLedgerBalance($pdo, $company_id, $fy_id, $ledgerName);
        $ledgerNatureLabel = bsDiagNaturePlural($ledgerNature) ?? 'unknown nature';
        $currentNoteNatureLabel = bsDiagNaturePlural($currentNoteNature) ?? 'unknown nature';

        $issues[] = [
            'issue_id' => $slug,
            'type' => 'parent_group_conflict',
            'severity' => 'error',
            'ledger_name' => $ledgerName,
            'tally_group' => $parentGroup,
            'ledger_nature' => bsDiagNaturePlural($ledgerNature),
            'current_note' => $currentNote,
            'current_note_nature' => bsDiagNaturePlural($currentNoteNature),
            'suggested_note' => $suggestedNote,
            'suggested_note_nature' => $suggestedNote !== null ? bsDiagNaturePlural($ledgerNature) : null,
            'suggested_note_candidates' => $autoFixable ? [] : $candidates,
            'ledger_balance' => $balance['ledger_balance'],
            'ledger_dr_cr' => $balance['dr_cr'],
            'estimated_diff_impact' => null,
            'auto_fixable' => $autoFixable,
            'message' => 'Ledger "' . $ledgerName . '" sits under Tally group "' . $parentGroup . '" ('
                . $ledgerNatureLabel . ') but is currently mapped to a note classified as '
                . $currentNoteNatureLabel . '.',
        ];
    }

    return $issues;
}

/**
 * validation_warning issues from validateReportGeneration(), excluding checks
 * that are already represented more richly elsewhere (note_completeness is
 * superseded by missing_note_heading issues; the reconciliation warnings just
 * restate the header diff_amount).
 */
function bsDiagWarningIssues(array $validationResult): array
{
    $skip = ['note_completeness', 'current_year_reconciliation', 'previous_year_reconciliation'];
    $issues = [];
    foreach ($validationResult['warnings'] ?? [] as $warning) {
        $check = (string) ($warning['check'] ?? '');
        if (in_array($check, $skip, true)) {
            continue;
        }
        $issues[] = [
            'issue_id' => 'warning-' . ($check !== '' ? $check : bsDiagSlug((string) ($warning['message'] ?? 'warning'))),
            'type' => 'validation_warning',
            'severity' => 'warning',
            'check' => $check,
            'message' => (string) ($warning['message'] ?? ''),
            'auto_fixable' => false,
        ];
    }
    return $issues;
}

/**
 * Build the full /bs-diagnostics payload for a company + FY.
 */
function buildBsDiagnostics(PDO $pdo, int $company_id, int $fy_id): array
{
    require_once __DIR__ . '/../helpers/report_manual_helper.php';

    $fyLabelStmt = $pdo->prepare("SELECT fy_label FROM financial_years WHERE id = ? AND company_id = ?");
    $fyLabelStmt->execute([$fy_id, $company_id]);
    $fyLabel = (string) ($fyLabelStmt->fetchColumn() ?: '');

    $manualBundle = loadManualInputsWithCarryForward($pdo, $company_id, $fy_id, $fyLabel);

    $fs = generateFinancialStatements(
        $pdo,
        $company_id,
        $fy_id,
        $fyLabel,
        $manualBundle['current'] ?? [],
        $manualBundle['previous'] ?? []
    );
    $validationResult = validateReportGeneration($pdo, $company_id, $fy_id, $fs);
    $entityCategory = $fs['entity_category'] ?? 'non_corporate';

    $rawDiff = (float) ($fs['validation']['current_balance_difference'] ?? 0);

    $issues = [];

    $unmapped = bsDiagUnmappedLedgersIssue($pdo, $company_id, $fy_id);
    if ($unmapped !== null) {
        $issues[] = $unmapped;
    }

    foreach (bsDiagMissingNoteHeadingIssues($fs, $entityCategory) as $issue) {
        $issues[] = $issue;
    }

    foreach (bsDiagParentGroupConflictIssues($pdo, $company_id, $fy_id, $fs, $entityCategory) as $issue) {
        $issues[] = $issue;
    }

    foreach (bsDiagWarningIssues($validationResult) as $issue) {
        $issues[] = $issue;
    }

    return [
        'diff_amount' => round(abs($rawDiff), 2),
        'diff_direction' => $rawDiff < 0 ? 'debit_short' : 'credit_short',
        'generated_at' => date('c'),
        'issues' => $issues,
        'resolved_count' => 0,
        'unresolved_count' => count($issues),
    ];
}
