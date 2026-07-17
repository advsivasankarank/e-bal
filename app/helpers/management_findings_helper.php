<?php
/**
 * e-BAL — Management Findings & Recommendations
 *
 * A client-facing "Management Letter" deliverable, distinct from Review
 * Centre (internal CA working notes): accounting inconsistencies/gaps
 * found while preparing the statements, disclosed to the client with the
 * CA's recommendation -- e.g. the Asset Register's computed net block not
 * reconciling with Tally's TB (a real, genuine finding, not a bug to hide).
 *
 * Findings are auto-detected from the existing validation/diagnostics
 * engines (buildBsDiagnostics(), computeOpeningBalanceDiagnostics()) --
 * no duplicated detection logic. They land as 'pending_review' and only
 * reach the client-facing report once a CA explicitly includes them with
 * a recommendation, per the "CA must review & promote each one" decision.
 */

require_once __DIR__ . '/bs_diagnostics_helper.php';
require_once __DIR__ . '/opening_balance_diagnostics_helper.php';

function ensureManagementFindingsSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS management_findings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            finding_key VARCHAR(80) NOT NULL,
            source VARCHAR(10) NOT NULL DEFAULT 'auto',
            severity VARCHAR(20) NOT NULL DEFAULT 'observation',
            check_code VARCHAR(60) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            detected_message TEXT NOT NULL,
            ca_recommendation TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
            exclusion_reason VARCHAR(255) NULL,
            first_raised_fy_id INT NULL,
            resolved_fy_id INT NULL,
            created_by INT NULL,
            decided_by INT NULL,
            decided_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id),
            KEY idx_company_finding_key (company_id, finding_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Groups the same underlying issue across financial years so recurrence
 * can be shown ("also raised in FY23-24, still unresolved") even though
 * the exact figures in the detected message differ year to year (a
 * residual-value percentage, a mismatch amount, etc.) -- numbers are
 * normalised out before hashing so only the descriptive wording (which
 * names the specific ledger/asset) drives the match.
 */
function managementFindingKey(string $checkCode, string $message): string
{
    $normalized = preg_replace('/[\d,]+\.?\d*/', '#', $message) ?? $message;
    return substr($checkCode, 0, 40) . ':' . substr(md5($normalized), 0, 32);
}

function managementFindingSeverity(string $checkCode): string
{
    $critical = ['assets_equals_liabilities', 'mapping_completeness'];
    $significant = [
        'parent_group_conflicts', 'borrowing_maturity_mismatch', 'opening_closing_pair_gap',
        'fixed_asset_missing_category', 'fixed_asset_disposed_missing_date', 'fixed_asset_excel_reconciliation',
        'opening_balance_mismatch', 'opening_balance_first_year_gap',
    ];

    if (in_array($checkCode, $critical, true)) {
        return 'critical';
    }
    if (in_array($checkCode, $significant, true)) {
        return 'significant';
    }
    return 'observation';
}

function managementFindingTitle(string $checkCode): string
{
    $titles = [
        'assets_equals_liabilities' => 'Balance Sheet does not balance',
        'mapping_completeness' => 'Unmapped Trial Balance ledgers',
        'parent_group_conflicts' => 'Ledger mapped to a note inconsistent with its Tally group',
        'branch_divisions_standalone' => 'Branch/Division balance in standalone Balance Sheet',
        'company_statutory_details' => 'Company statutory details incomplete',
        'borrowing_maturity_mismatch' => 'Possible Current/Non-Current borrowing misclassification',
        'opening_closing_pair_gap' => 'Opening balance entered without a matching Closing figure',
        'fixed_asset_residual_value_high' => 'Asset residual value exceeds Schedule II guidance',
        'fixed_asset_missing_category' => 'Fixed asset not yet classified',
        'fixed_asset_disposed_missing_date' => 'Disposed asset missing disposal date',
        'fixed_asset_excel_reconciliation' => 'Asset Register opening balance does not reconcile with Tally',
        'manual_overrides_included' => 'Ledger(s) included by manual override',
        'previous_year_not_closed' => 'Previous financial year not closed',
        'opening_balance_mismatch' => 'Opening balance does not match Tally\'s own records',
        'opening_balance_first_year_gap' => 'No carried-forward opening balance for a ledger Tally reports one for',
    ];

    return $titles[$checkCode] ?? ucfirst(str_replace('_', ' ', $checkCode));
}

/**
 * Pulls current auto-detected issues from the existing diagnostics engines
 * and stages any not already present (matched by finding_key) as new
 * 'pending_review' rows. Idempotent -- safe to call on every page load.
 * Also auto-resolves prior-year 'included' findings whose finding_key is
 * no longer detected this year (the underlying issue appears fixed).
 */
function syncAutoDetectedFindings(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureManagementFindingsSchema($pdo);

    $detected = [];

    $bsDiag = buildBsDiagnostics($pdo, $company_id, $fy_id);
    foreach ($bsDiag['issues'] as $issue) {
        $check = (string) ($issue['check'] ?? $issue['type'] ?? 'issue');
        $message = (string) ($issue['message'] ?? '');
        if ($message === '') {
            continue;
        }
        $detected[] = ['check' => $check, 'message' => $message];
    }

    $obDiag = computeOpeningBalanceDiagnostics($pdo, $company_id, $fy_id);
    foreach ($obDiag['mismatches'] as $m) {
        $detected[] = [
            'check' => 'opening_balance_mismatch',
            'message' => 'Ledger "' . $m['ledger_name'] . '": e-BAL\'s carried-forward opening (₹' . number_format($m['app_expected_opening'], 2)
                . ') does not match Tally\'s own reported opening (₹' . number_format($m['tally_opening'], 2) . '), difference ₹' . number_format($m['difference'], 2) . '.',
        ];
    }
    foreach ($obDiag['first_year_gaps'] as $g) {
        $detected[] = [
            'check' => 'opening_balance_first_year_gap',
            'message' => 'Ledger "' . $g['ledger_name'] . '": Tally reports an opening balance of ₹' . number_format($g['tally_opening'], 2)
                . ' that e-BAL has no carried-forward figure for.',
        ];
    }

    $detectedKeys = [];
    foreach ($detected as $d) {
        $detectedKeys[managementFindingKey($d['check'], $d['message'])] = $d;
    }

    // What's already staged/decided for this company (any FY, for recurrence + resolution).
    $existingStmt = $pdo->prepare('SELECT id, fy_id, finding_key, status, source FROM management_findings WHERE company_id = ?');
    $existingStmt->execute([$company_id]);
    $allExisting = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

    $existingThisFy = [];
    $priorFirstRaised = [];
    foreach ($allExisting as $row) {
        if ((int) $row['fy_id'] === $fy_id) {
            $existingThisFy[$row['finding_key']] = true;
        } else {
            $priorFirstRaised[$row['finding_key']][] = $row;
        }
    }

    $insert = $pdo->prepare('
        INSERT INTO management_findings (company_id, fy_id, finding_key, source, severity, check_code, title, detected_message, status, first_raised_fy_id)
        VALUES (?, ?, ?, \'auto\', ?, ?, ?, ?, \'pending_review\', ?)
    ');
    $newCount = 0;
    foreach ($detectedKeys as $key => $d) {
        if (isset($existingThisFy[$key])) {
            continue;
        }
        $firstRaisedFyId = isset($priorFirstRaised[$key][0]) ? (int) $priorFirstRaised[$key][0]['fy_id'] : $fy_id;
        $insert->execute([
            $company_id, $fy_id, $key,
            managementFindingSeverity($d['check']), $d['check'], managementFindingTitle($d['check']), $d['message'],
            $firstRaisedFyId,
        ]);
        $newCount++;
    }

    // Auto-resolve: a prior-year 'included' auto finding whose key isn't detected this year anymore.
    $resolveStmt = $pdo->prepare("
        UPDATE management_findings SET resolved_fy_id = ?
        WHERE company_id = ? AND fy_id != ? AND source = 'auto' AND status = 'included' AND resolved_fy_id IS NULL AND finding_key = ?
    ");
    $resolvedCount = 0;
    $priorIncludedKeys = [];
    foreach ($allExisting as $row) {
        if ((int) $row['fy_id'] !== $fy_id && $row['status'] === 'included' && $row['source'] === 'auto') {
            $priorIncludedKeys[$row['finding_key']] = true;
        }
    }
    foreach (array_keys($priorIncludedKeys) as $key) {
        if (!isset($detectedKeys[$key])) {
            $resolveStmt->execute([$fy_id, $company_id, $fy_id, $key]);
            $resolvedCount++;
        }
    }

    return ['new_findings' => $newCount, 'auto_resolved' => $resolvedCount];
}

function getManagementFindings(PDO $pdo, int $company_id, int $fy_id, ?string $status = null): array
{
    ensureManagementFindingsSchema($pdo);
    $sql = 'SELECT * FROM management_findings WHERE company_id = ? AND fy_id = ?';
    $params = [$company_id, $fy_id];
    if ($status !== null) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY FIELD(severity, \'critical\',\'significant\',\'observation\'), created_at';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function decideFinding(PDO $pdo, int $company_id, int $fy_id, int $findingId, string $status, string $recommendation, string $exclusionReason, ?int $userId): void
{
    if (!in_array($status, ['included', 'excluded', 'pending_review'], true)) {
        return;
    }
    $pdo->prepare('
        UPDATE management_findings
        SET status = ?, ca_recommendation = ?, exclusion_reason = ?, decided_by = ?, decided_at = NOW()
        WHERE company_id = ? AND fy_id = ? AND id = ?
    ')->execute([$status, $recommendation, $exclusionReason, $userId, $company_id, $fy_id, $findingId]);
}

function addManualFinding(PDO $pdo, int $company_id, int $fy_id, string $title, string $message, string $severity, string $recommendation, ?int $userId): void
{
    ensureManagementFindingsSchema($pdo);
    if (!in_array($severity, ['critical', 'significant', 'observation'], true)) {
        $severity = 'observation';
    }
    $key = managementFindingKey('manual', $title . ':' . $message);
    $pdo->prepare("
        INSERT INTO management_findings (company_id, fy_id, finding_key, source, severity, check_code, title, detected_message, ca_recommendation, status, first_raised_fy_id, created_by, decided_by, decided_at)
        VALUES (?, ?, ?, 'manual', ?, 'manual', ?, ?, ?, 'included', ?, ?, ?, NOW())
    ")->execute([$company_id, $fy_id, $key, $severity, $title, $message, $recommendation, $fy_id, $userId, $userId]);
}

function deleteFinding(PDO $pdo, int $company_id, int $fy_id, int $findingId): void
{
    $pdo->prepare('DELETE FROM management_findings WHERE company_id = ? AND fy_id = ? AND id = ?')->execute([$company_id, $fy_id, $findingId]);
}

/**
 * Prior-year 'included' findings sharing a finding_key with this FY that
 * are still unresolved -- the "also raised in FY23-24, still unresolved"
 * recurrence panel.
 */
function getRecurringUnresolvedFindings(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureManagementFindingsSchema($pdo);
    $stmt = $pdo->prepare("
        SELECT mf.*, fy.fy_label
        FROM management_findings mf
        JOIN financial_years fy ON fy.id = mf.fy_id
        WHERE mf.company_id = ? AND mf.fy_id != ? AND mf.status = 'included' AND mf.resolved_fy_id IS NULL
          AND mf.finding_key IN (
              SELECT finding_key FROM management_findings WHERE company_id = ? AND fy_id = ?
          )
        ORDER BY mf.fy_id DESC
    ");
    $stmt->execute([$company_id, $fy_id, $company_id, $fy_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
