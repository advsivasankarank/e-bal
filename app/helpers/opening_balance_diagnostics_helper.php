<?php
/**
 * e-BAL — Opening Balance Diagnostics
 *
 * Tally's own Trial Balance export reports both an opening AND a closing
 * balance per ledger. e-BAL separately derives its own "expected opening"
 * by carrying forward the previous FY's closing balance already stored in
 * e-BAL (tally_ledgers.opening_amount/opening_dr_cr, populated by
 * loadOpeningRowsFromStoredYear() in tb_import_helper.php). These two
 * figures SHOULD agree — if they don't, something changed on one side
 * without the other being told (a prior-year adjustment made in Tally
 * after e-BAL already carried forward the old figure, a ledger rename,
 * a missed import, etc.) and it's worth surfacing to the CA rather than
 * silently trusting either side.
 *
 * Per-import capture of Tally's native opening balance lives in
 * app/helpers/tb_import_helper.php (tally_reported_opening_amount/_dr_cr
 * columns) and the three live XML import paths (tally_bridge_service.php,
 * public/bridge_tb.php, api/upload_tb.php).
 */

function ensureOpeningBalanceDiagnosticsSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS opening_balance_diagnostics_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            ledger_name VARCHAR(255) NOT NULL,
            tally_opening DECIMAL(18,2) NOT NULL DEFAULT 0,
            app_expected_opening DECIMAL(18,2) NOT NULL DEFAULT 0,
            difference DECIMAL(18,2) NOT NULL DEFAULT 0,
            resolution VARCHAR(20) NOT NULL,
            notes VARCHAR(255) NOT NULL DEFAULT '',
            user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_company_fy (company_id, fy_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

/**
 * Signed balance: DR positive, CR negative. A blank/null dr_cr with a
 * non-zero amount is treated as unsigned-unknown and reported as 0 so it
 * doesn't masquerade as a real figure on either side of the comparison.
 */
function openingBalanceSignedAmount(float $amount, ?string $drCr): float
{
    $drCr = strtoupper(trim((string) $drCr));
    if ($drCr === 'DR') {
        return round($amount, 2);
    }
    if ($drCr === 'CR') {
        return round(-$amount, 2);
    }
    return 0.0;
}

/**
 * Compare every ledger's Tally-reported opening balance (this import)
 * against e-BAL's own carried-forward expected opening (previous FY's
 * closing, if any) for a company/FY. Ledgers where both sides are zero
 * are omitted entirely -- there's nothing to reconcile.
 *
 * @return array{
 *   generated_at: string, has_previous_fy: bool, total_ledgers: int,
 *   compared: int, matched: int, mismatches: array, first_year_gaps: array
 * }
 */
function computeOpeningBalanceDiagnostics(PDO $pdo, int $company_id, int $fy_id): array
{
    require_once __DIR__ . '/tb_import_helper.php';
    ensureTallyLedgersComparativeColumns($pdo);

    $prevCountStmt = $pdo->prepare("
        SELECT COUNT(*) FROM tally_ledgers
        WHERE company_id = ? AND fy_id = ? AND (opening_amount != 0 OR opening_dr_cr IS NOT NULL)
    ");
    $prevCountStmt->execute([$company_id, $fy_id]);
    $hasPreviousFy = (int) $prevCountStmt->fetchColumn() > 0;

    $stmt = $pdo->prepare("
        SELECT ledger_name, opening_amount, opening_dr_cr, tally_reported_opening_amount, tally_reported_opening_dr_cr
        FROM tally_ledgers
        WHERE company_id = ? AND fy_id = ?
        ORDER BY ledger_name
    ");
    $stmt->execute([$company_id, $fy_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalLedgers = count($rows);
    $compared = 0;
    $matched = 0;
    $mismatches = [];
    $firstYearGaps = [];
    $tolerance = 1.0;

    foreach ($rows as $row) {
        $ledgerName = (string) $row['ledger_name'];
        $appOpening = openingBalanceSignedAmount((float) $row['opening_amount'], $row['opening_dr_cr']);
        $tallyOpening = openingBalanceSignedAmount((float) $row['tally_reported_opening_amount'], $row['tally_reported_opening_dr_cr']);

        if ($tallyOpening == 0.0) {
            /* tally_reported_opening_amount defaults to 0 for every ledger
               imported before this feature existed -- indistinguishable
               from Tally genuinely reporting a zero opening. Only compare
               a ledger once a fresh import has actually captured a native
               Tally opening figure for it; otherwise every already-imported
               FY would show 100% "mismatches" that are really just "not
               re-imported yet", which would bury genuine differences. */
            continue;
        }

        $compared++;
        $difference = round($tallyOpening - $appOpening, 2);

        if ($appOpening == 0.0) {
            /* e-BAL has no carried-forward opening for this ledger (no
               previous FY on file, or the ledger is new) but Tally itself
               reports one -- a gap to fill, not a conflict. */
            $firstYearGaps[] = [
                'ledger_name' => $ledgerName,
                'tally_opening' => $tallyOpening,
                'app_expected_opening' => 0.0,
            ];
            continue;
        }

        if (abs($difference) <= $tolerance) {
            $matched++;
            continue;
        }

        $mismatches[] = [
            'ledger_name' => $ledgerName,
            'tally_opening' => $tallyOpening,
            'app_expected_opening' => $appOpening,
            'difference' => $difference,
        ];
    }

    return [
        'generated_at' => date('c'),
        'has_previous_fy' => $hasPreviousFy,
        'total_ledgers' => $totalLedgers,
        'compared' => $compared,
        'matched' => $matched,
        'mismatches' => $mismatches,
        'first_year_gaps' => $firstYearGaps,
    ];
}

/**
 * Record a CA's decision on one ledger's opening-balance diagnostic and,
 * if they chose to accept Tally's figure, apply it to e-BAL's own
 * opening_amount/opening_dr_cr columns. "Keep e-BAL's figure" only logs
 * the decision -- no data changes, matching the "flag for manual review,
 * never auto-pick" rule this feature was built to.
 */
function resolveOpeningBalanceDiagnostic(PDO $pdo, int $company_id, int $fy_id, string $ledgerName, string $resolution, ?int $userId, string $notes = ''): array
{
    ensureOpeningBalanceDiagnosticsSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT opening_amount, opening_dr_cr, tally_reported_opening_amount, tally_reported_opening_dr_cr
        FROM tally_ledgers
        WHERE company_id = ? AND fy_id = ? AND ledger_name = ?
        LIMIT 1
    ");
    $stmt->execute([$company_id, $fy_id, $ledgerName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return ['ok' => false, 'message' => 'Ledger not found for this company/FY.'];
    }

    $appOpening = openingBalanceSignedAmount((float) $row['opening_amount'], $row['opening_dr_cr']);
    $tallyOpening = openingBalanceSignedAmount((float) $row['tally_reported_opening_amount'], $row['tally_reported_opening_dr_cr']);

    if (!in_array($resolution, ['accept_tally', 'keep_app'], true)) {
        return ['ok' => false, 'message' => 'Unknown resolution action.'];
    }

    $pdo->beginTransaction();
    try {
        if ($resolution === 'accept_tally') {
            $update = $pdo->prepare("
                UPDATE tally_ledgers
                SET opening_amount = tally_reported_opening_amount, opening_dr_cr = tally_reported_opening_dr_cr
                WHERE company_id = ? AND fy_id = ? AND ledger_name = ?
            ");
            $update->execute([$company_id, $fy_id, $ledgerName]);
        }

        $audit = $pdo->prepare("
            INSERT INTO opening_balance_diagnostics_audit
                (company_id, fy_id, ledger_name, tally_opening, app_expected_opening, difference, resolution, notes, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $audit->execute([
            $company_id,
            $fy_id,
            $ledgerName,
            $tallyOpening,
            $appOpening,
            round($tallyOpening - $appOpening, 2),
            $resolution,
            $notes,
            $userId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true];
}
