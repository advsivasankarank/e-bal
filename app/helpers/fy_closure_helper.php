<?php

require_once __DIR__ . '/financial_year_helper.php';
require_once __DIR__ . '/parent_group_validation_helper.php';
require_once __DIR__ . '/mapping_ai_helper.php';

/* =========================
   SCHEMA MANAGEMENT
========================= */

function ensureFYClosureSchema(PDO $pdo): void
{
    if (!fyColumnExists($pdo, 'financial_years', 'status')) {
        $pdo->exec("ALTER TABLE financial_years
            ADD COLUMN status ENUM('draft','finalized','closed') NOT NULL DEFAULT 'draft' AFTER fy_label,
            ADD COLUMN closed_by INT NULL AFTER status,
            ADD COLUMN closed_at DATETIME NULL AFTER closed_by,
            ADD COLUMN reopened_by INT NULL AFTER closed_at,
            ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by,
            ADD COLUMN closure_notes TEXT NULL AFTER reopened_at");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS fy_closing_snapshots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        fy_id INT NOT NULL,
        schedule_code VARCHAR(50) NOT NULL,
        ledger_name VARCHAR(255) NOT NULL DEFAULT '',
        parent_group VARCHAR(255) NOT NULL DEFAULT '',
        amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        dr_cr ENUM('DR','CR') NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fy_snapshot (company_id, fy_id, schedule_code, ledger_name),
        INDEX idx_fy_snapshot_fy (company_id, fy_id),
        INDEX idx_fy_snapshot_code (company_id, fy_id, schedule_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fy_closure_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        fy_id INT NOT NULL,
        action ENUM('closed','reopened','snapshot_regenerated') NOT NULL,
        performed_by INT NOT NULL,
        performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reason TEXT NULL,
        validation_summary TEXT NULL,
        INDEX idx_closure_audit_fy (company_id, fy_id),
        INDEX idx_closure_audit_date (performed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fy_validation_failures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        fy_id INT NOT NULL,
        check_name VARCHAR(100) NOT NULL,
        severity ENUM('error','warning') NOT NULL DEFAULT 'error',
        message TEXT NOT NULL,
        details TEXT NULL,
        checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fy_val_fy (company_id, fy_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fy_opening_balance_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        fy_id INT NOT NULL COMMENT 'The FY whose opening balances were populated',
        source_fy_id INT NOT NULL COMMENT 'The closed FY from which balances were carried',
        populated_by INT NOT NULL COMMENT 'User who triggered carry-forward',
        populated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        invalidated_by INT NULL,
        invalidated_at DATETIME NULL,
        UNIQUE KEY uq_fy_obs (company_id, fy_id),
        INDEX idx_fy_obs_source (company_id, source_fy_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/* =========================
   STATUS QUERIES
========================= */

function getFYStatus(PDO $pdo, int $company_id, int $fy_id): string
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null || !fyColumnExists($pdo, $table, 'status')) {
        return 'draft';
    }
    $stmt = $pdo->prepare("SELECT status FROM `$table` WHERE id = ? AND company_id = ?");
    $stmt->execute([$fy_id, $company_id]);
    $status = $stmt->fetchColumn();
    return in_array((string) $status, ['draft', 'finalized', 'closed'], true) ? (string) $status : 'draft';
}

function getFYClosureInfo(PDO $pdo, int $company_id, int $fy_id): array
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT
        status, closed_by, closed_at, reopened_by, reopened_at, closure_notes
        FROM `$table` WHERE id = ? AND company_id = ?");
    $stmt->execute([$fy_id, $company_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function getPreviousFYId(PDO $pdo, int $company_id, int $fy_id): ?int
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null || !fyColumnExists($pdo, $table, 'fy_start')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT fy_start FROM `$table` WHERE id = ? AND company_id = ?");
    $stmt->execute([$fy_id, $company_id]);
    $currentStart = $stmt->fetchColumn();
    if ($currentStart === false) {
        return null;
    }
    $prevEnd = date('Y-m-d', strtotime((string) $currentStart . ' -1 day'));
    $stmt = $pdo->prepare("SELECT id FROM `$table`
        WHERE company_id = ? AND fy_end = ? LIMIT 1");
    $stmt->execute([$company_id, $prevEnd]);
    $prevId = $stmt->fetchColumn();
    return $prevId !== false ? (int) $prevId : null;
}

/* =========================
   CLOSURE VALIDATION
========================= */

function validateFYClosure(PDO $pdo, int $company_id, int $fy_id): array
{
    $failures = [];

    $pdo->prepare("DELETE FROM fy_validation_failures WHERE company_id = ? AND fy_id = ?")
        ->execute([$company_id, $fy_id]);

    $classified = \getClassifiedData($pdo, $company_id, $fy_id);
    $summary = $classified['summary'] ?? [];

    $assetsTotal = (float) ($summary['assets_total'] ?? 0);
    $liabilitiesTotal = (float) ($summary['liabilities_total'] ?? 0);
    $profit = (float) ($summary['profit'] ?? 0);
    $equityIncludingProfit = $liabilitiesTotal + $profit;
    $diff = round($assetsTotal - $equityIncludingProfit, 2);

    if (abs($diff) > 0.01) {
        $msg = "Balance sheet does not balance. Assets (₹" . number_format($assetsTotal, 2)
            . ") ≠ Liabilities + Equity (₹" . number_format($liabilitiesTotal, 2)
            . ") including current year profit/loss (₹" . number_format($profit, 2)
            . "). Difference: ₹" . number_format($diff, 2);
        $failures[] = ['check_name' => 'balance_sheet_balance', 'severity' => 'error', 'message' => $msg, 'details' => json_encode(['assets' => $assetsTotal, 'liabilities' => $liabilitiesTotal, 'profit' => $profit, 'equity_including_profit' => $equityIncludingProfit, 'diff' => $diff])];
    }

    $conflicts = $classified['validation']['parent_group_conflicts'] ?? [];
    if (!empty($conflicts)) {
        $names = array_map(fn($c) => $c['ledger_name'] ?? '?', array_slice($conflicts, 0, 5));
        $msg = count($conflicts) . " parent-group conflict(s) found: " . implode(', ', $names)
            . (count($conflicts) > 5 ? ' (+' . (count($conflicts) - 5) . ' more)' : '');
        $failures[] = ['check_name' => 'parent_group_conflicts', 'severity' => 'error', 'message' => $msg, 'details' => json_encode(array_slice($conflicts, 0, 20))];
    }

    $mappedCount = 0;
    $totalLedgers = 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ledger_mapping WHERE company_id = ? AND schedule_code IS NOT NULL AND schedule_code != ''");
    $stmt->execute([$company_id]);
    $mappedCount = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tally_ledger_master WHERE company_id = ?");
    $stmt->execute([$company_id]);
    $totalLedgers = (int) $stmt->fetchColumn();
    if ($totalLedgers > 0 && $mappedCount < $totalLedgers) {
        $unmapped = $totalLedgers - $mappedCount;
        $failures[] = ['check_name' => 'unmapped_ledgers', 'severity' => 'error', 'message' => "{$unmapped} ledger(s) remain unmapped. All ledgers must be mapped before closure.", 'details' => json_encode(['mapped' => $mappedCount, 'total' => $totalLedgers])];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM workflow_status WHERE company_id = ? AND fy_id = ? AND reports_generated = 1");
    $stmt->execute([$company_id, $fy_id]);
    if ((int) $stmt->fetchColumn() === 0) {
        $failures[] = ['check_name' => 'reports_generated', 'severity' => 'warning', 'message' => 'Reports have not been generated for this financial year. Consider generating reports before closure.', 'details' => null];
    }

    foreach ($failures as $f) {
        $ins = $pdo->prepare("INSERT INTO fy_validation_failures (company_id, fy_id, check_name, severity, message, details) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$company_id, $fy_id, $f['check_name'], $f['severity'], $f['message'], $f['details']]);
    }

    return $failures;
}

function getValidationFailures(PDO $pdo, int $company_id, int $fy_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM fy_validation_failures WHERE company_id = ? AND fy_id = ? ORDER BY FIELD(severity,'error','warning'), id");
    $stmt->execute([$company_id, $fy_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   CLOSING SNAPSHOT
========================= */

function captureClosingSnapshot(PDO $pdo, int $company_id, int $fy_id): int
{
    $pdo->prepare("DELETE FROM fy_closing_snapshots WHERE company_id = ? AND fy_id = ?")
        ->execute([$company_id, $fy_id]);

    $classified = \getClassifiedData($pdo, $company_id, $fy_id);
    $scheduleItems = $classified['schedule_items'] ?? [];
    $count = 0;

    $ins = $pdo->prepare("INSERT INTO fy_closing_snapshots
        (company_id, fy_id, schedule_code, ledger_name, parent_group, amount, dr_cr)
        VALUES (?, ?, ?, ?, ?, ?, ?)");

    foreach ($scheduleItems as $code => $item) {
        foreach (($item['rows'] ?? []) as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if (abs($amount) < 0.005) {
                continue;
            }
            $ins->execute([
                $company_id,
                $fy_id,
                $code,
                (string) ($row['ledger_name'] ?? ''),
                (string) ($row['parent_group'] ?? ''),
                $amount,
                $amount >= 0 ? 'DR' : 'CR',
            ]);
            $count++;
        }
        $totalAmount = (float) ($item['amount'] ?? 0);
        if (abs($totalAmount) >= 0.005) {
            $ins->execute([
                $company_id,
                $fy_id,
                $code . '_total',
                '__TOTAL__',
                '',
                $totalAmount,
                $totalAmount >= 0 ? 'DR' : 'CR',
            ]);
            $count++;
        }
    }

    return $count;
}

function getClosingSnapshot(PDO $pdo, int $company_id, int $fy_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM fy_closing_snapshots
        WHERE company_id = ? AND fy_id = ?
        ORDER BY schedule_code, ledger_name");
    $stmt->execute([$company_id, $fy_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $row) {
        $code = $row['schedule_code'];
        if (!isset($grouped[$code])) {
            $grouped[$code] = ['amount' => 0, 'previous_amount' => 0, 'rows' => []];
        }
        $grouped[$code]['amount'] += (float) $row['amount'];
        if ($row['ledger_name'] !== '__TOTAL__') {
            $grouped[$code]['rows'][] = $row;
        }
    }
    return $grouped;
}

function getClosingSnapshotSummary(PDO $pdo, int $company_id, int $fy_id): array
{
    $stmt = $pdo->prepare("SELECT
        schedule_code,
        SUM(amount) AS total_amount
        FROM fy_closing_snapshots
        WHERE company_id = ? AND fy_id = ? AND ledger_name != '__TOTAL__'
        GROUP BY schedule_code
        ORDER BY schedule_code");
    $stmt->execute([$company_id, $fy_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $r) {
        $result[$r['schedule_code']] = (float) $r['total_amount'];
    }
    return $result;
}

/* =========================
   CLOSE FINANCIAL YEAR
========================= */

function closeFinancialYear(PDO $pdo, int $company_id, int $fy_id, int $user_id, string $reason = ''): array
{
    $pdo->beginTransaction();

    try {
        $table = detectFinancialYearTable($pdo);
        if ($table !== null && fyColumnExists($pdo, $table, 'status')) {
            $lockStmt = $pdo->prepare("SELECT status FROM `$table` WHERE id = ? AND company_id = ? FOR UPDATE");
            $lockStmt->execute([$fy_id, $company_id]);
            $currentStatus = (string) $lockStmt->fetchColumn();
        } else {
            $currentStatus = getFYStatus($pdo, $company_id, $fy_id);
        }

        if ($currentStatus === 'closed') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'This financial year is already closed.'];
        }

        $failures = validateFYClosure($pdo, $company_id, $fy_id);
        $errors = array_filter($failures, fn($f) => $f['severity'] === 'error');
        if (!empty($errors)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Closure validation failed. ' . $errors[0]['message'], 'failures' => $failures];
        }

        $snapshotCount = captureClosingSnapshot($pdo, $company_id, $fy_id);

        if ($table !== null && fyColumnExists($pdo, $table, 'status')) {
            $pdo->prepare("UPDATE `$table` SET status = 'closed', closed_by = ?, closed_at = NOW(), closure_notes = ? WHERE id = ? AND company_id = ?")
                ->execute([$user_id, $reason, $fy_id, $company_id]);
        }

        $pdo->prepare("INSERT INTO fy_closure_audit (company_id, fy_id, action, performed_by, reason, validation_summary)
            VALUES (?, ?, 'closed', ?, ?, ?)")
            ->execute([$company_id, $fy_id, $user_id, $reason, json_encode(['snapshot_entries' => $snapshotCount, 'failures' => count($failures)])]);

        $nextFYId = getNextFYId($pdo, $company_id, $fy_id);
        if ($nextFYId !== null) {
            $obsCheck = $pdo->prepare("SELECT COUNT(*) FROM fy_opening_balance_sources WHERE company_id = ? AND fy_id = ?");
            $obsCheck->execute([$company_id, $nextFYId]);
            if ((int) $obsCheck->fetchColumn() === 0) {
                createOpeningBalances($pdo, $company_id, $nextFYId, $fy_id, $user_id);
            }
        }

        $pdo->commit();

        return [
            'success' => true,
            'message' => "Financial year closed successfully. {$snapshotCount} snapshot entries captured.",
            'snapshot_count' => $snapshotCount,
            'failures' => $failures,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Closure failed: ' . $e->getMessage()];
    }
}

/* =========================
   REOPEN FINANCIAL YEAR
========================= */

function reopenFinancialYear(PDO $pdo, int $company_id, int $fy_id, int $user_id, string $reason = ''): array
{
    $pdo->beginTransaction();

    try {
        $currentStatus = getFYStatus($pdo, $company_id, $fy_id);
        if ($currentStatus !== 'closed') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Only a closed financial year can be reopened.'];
        }

        $affectedFYs = [];
        $nextFYId = getNextFYId($pdo, $company_id, $fy_id);
        if ($nextFYId !== null) {
            $nextStatus = getFYStatus($pdo, $company_id, $nextFYId);
            if ($nextStatus !== 'draft') {
                $affectedFYs[] = $nextFYId;
                $pdo->prepare("UPDATE `financial_years` SET status = 'draft', reopened_by = ?, reopened_at = NOW() WHERE id = ? AND company_id = ?")
                    ->execute([$user_id, $nextFYId, $company_id]);

                $pdo->prepare("UPDATE fy_opening_balance_sources
                    SET invalidated_by = ?, invalidated_at = NOW()
                    WHERE company_id = ? AND fy_id = ?")
                    ->execute([$user_id, $company_id, $nextFYId]);

                $pdo->prepare("DELETE FROM fy_closing_snapshots WHERE company_id = ? AND fy_id = ?")
                    ->execute([$company_id, $nextFYId]);

                $pdo->prepare("INSERT INTO fy_closure_audit (company_id, fy_id, action, performed_by, reason)
                    VALUES (?, ?, 'reopened', ?, ?)")
                    ->execute([$company_id, $nextFYId, $user_id, 'Invalidated due to reopening of previous FY: ' . $reason]);
            }
        }

        $table = detectFinancialYearTable($pdo);
        if ($table !== null && fyColumnExists($pdo, $table, 'status')) {
            $pdo->prepare("UPDATE `$table` SET status = 'draft', reopened_by = ?, reopened_at = NOW() WHERE id = ? AND company_id = ?")
                ->execute([$user_id, $fy_id, $company_id]);
        }

        $pdo->prepare("DELETE FROM fy_closing_snapshots WHERE company_id = ? AND fy_id = ?")
            ->execute([$company_id, $fy_id]);

        $pdo->prepare("INSERT INTO fy_closure_audit (company_id, fy_id, action, performed_by, reason)
            VALUES (?, ?, 'reopened', ?, ?)")
            ->execute([$company_id, $fy_id, $user_id, $reason]);

        $pdo->commit();

        $msg = 'Financial year reopened successfully. Carry-forward balances in subsequent years have been invalidated.';
        if (!empty($affectedFYs)) {
            $msg .= ' Affected FYs: #' . implode(', #', $affectedFYs) . '.';
        }

        return ['success' => true, 'message' => $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Reopen failed: ' . $e->getMessage()];
    }
}

/* =========================
   OPENING BALANCES
========================= */

function createOpeningBalances(PDO $pdo, int $company_id, int $target_fy_id, int $source_fy_id, int $user_id): array
{
    $snapshot = getClosingSnapshot($pdo, $company_id, $source_fy_id);

    $pdo->prepare("DELETE FROM fy_opening_balance_sources WHERE company_id = ? AND fy_id = ?")
        ->execute([$company_id, $target_fy_id]);

    $pdo->prepare("INSERT INTO fy_opening_balance_sources (company_id, fy_id, source_fy_id, populated_by)
        VALUES (?, ?, ?, ?)")
        ->execute([$company_id, $target_fy_id, $source_fy_id, $user_id]);

    $carryForwardCount = 0;
    $upsertStmt = $pdo->prepare("
        INSERT INTO tally_ledgers
            (company_id, fy_id, ledger_name, parent_group, amount, dr_cr, opening_amount, opening_dr_cr, created_at)
        VALUES (?, ?, ?, ?, 0, 'DR', ?, ?)
        ON DUPLICATE KEY UPDATE
            opening_amount = VALUES(opening_amount),
            opening_dr_cr = VALUES(opening_dr_cr)
    ");

    foreach ($snapshot as $code => $item) {
        foreach (($item['rows'] ?? []) as $row) {
            $ledgerName = (string) ($row['ledger_name'] ?? '');
            if ($ledgerName === '' || $ledgerName === '__TOTAL__') {
                continue;
            }
            $amount = abs((float) ($row['amount'] ?? 0));
            if ($amount < 0.005) {
                continue;
            }
            $drCr = (string) ($row['dr_cr'] ?? 'DR');
            $parentGroup = (string) ($row['parent_group'] ?? '');

            $upsertStmt->execute([
                $company_id,
                $target_fy_id,
                $ledgerName,
                $parentGroup,
                $amount,
                $drCr,
            ]);
            $carryForwardCount++;
        }
    }

    return [
        'success' => true,
        'message' => "Opening balances carried forward. {$carryForwardCount} ledger(s) populated.",
        'carry_forward_count' => $carryForwardCount,
        'source_fy' => $source_fy_id,
    ];
}

function getOpeningBalanceSource(PDO $pdo, int $company_id, int $fy_id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM fy_opening_balance_sources
        WHERE company_id = ? AND fy_id = ? AND invalidated_by IS NULL
        LIMIT 1");
    $stmt->execute([$company_id, $fy_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/* =========================
   NEXT FY LOOKUP
========================= */

function getNextFYId(PDO $pdo, int $company_id, int $fy_id): ?int
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null || !fyColumnExists($pdo, $table, 'fy_start')) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT fy_end FROM `$table` WHERE id = ? AND company_id = ?");
    $stmt->execute([$fy_id, $company_id]);
    $currentEnd = $stmt->fetchColumn();
    if ($currentEnd === false) {
        return null;
    }
    $nextStart = date('Y-m-d', strtotime((string) $currentEnd . ' +1 day'));
    $stmt = $pdo->prepare("SELECT id FROM `$table`
        WHERE company_id = ? AND fy_start = ? LIMIT 1");
    $stmt->execute([$company_id, $nextStart]);
    $nextId = $stmt->fetchColumn();
    return $nextId !== false ? (int) $nextId : null;
}

/* =========================
   COMPARATIVE DATA FOR REPORTS
========================= */

function getComparativeDataFromSnapshot(PDO $pdo, int $company_id, int $fy_id): ?array
{
    $prevFYId = getPreviousFYId($pdo, $company_id, $fy_id);
    if ($prevFYId === null) {
        return null;
    }
    $prevStatus = getFYStatus($pdo, $company_id, $prevFYId);
    if ($prevStatus !== 'closed') {
        return null;
    }
    return getClosingSnapshotSummary($pdo, $company_id, $prevFYId);
}

function hasPreviousYearData(PDO $pdo, int $company_id, int $fy_id): bool
{
    $prevFYId = getPreviousFYId($pdo, $company_id, $fy_id);
    if ($prevFYId === null) {
        return false;
    }
    $prevStatus = getFYStatus($pdo, $company_id, $prevFYId);
    if ($prevStatus !== 'closed') {
        return false;
    }
    $snapshot = getClosingSnapshotSummary($pdo, $company_id, $prevFYId);
    return !empty($snapshot);
}

function buildComparativeValidation(PDO $pdo, int $company_id, int $fy_id): array
{
    $issues = [];
    $prevFYId = getPreviousFYId($pdo, $company_id, $fy_id);
    if ($prevFYId === null) {
        return ['has_previous' => false, 'issues' => []];
    }

    $prevSnapshot = getClosingSnapshotSummary($pdo, $company_id, $prevFYId);
    if (empty($prevSnapshot)) {
        return ['has_previous' => false, 'issues' => []];
    }

    require_once __DIR__ . '/../engines/classification_engine.php';

    $assetBuckets = ['non_current_assets', 'current_assets'];
    $liabilityBuckets = ['equity', 'borrowings', 'current_liabilities'];

    $prevTotalAssets = 0;
    $prevTotalLiabilities = 0;
    $prevRevenue = 0;
    $prevExpenses = 0;

    foreach ($prevSnapshot as $code => $amount) {
        $bucket = normalizeScheduleBucket($code);
        if (in_array($bucket, $assetBuckets, true)) {
            $prevTotalAssets += $amount;
        } elseif (in_array($bucket, $liabilityBuckets, true)) {
            $prevTotalLiabilities += $amount;
        }
        if ($bucket === 'revenue') {
            $prevRevenue += $amount;
        } elseif ($bucket === 'expenses') {
            $prevExpenses += $amount;
        }
    }

    $prevPnlProfit = $prevRevenue - $prevExpenses;

    $bsCheck = round($prevTotalAssets - $prevTotalLiabilities, 2);
    if (abs($bsCheck) > 0.01) {
        $issues[] = "Previous year closing Balance Sheet does not balance (Difference: ₹" . number_format($bsCheck, 2) . ").";
    }

    if (abs($prevPnlProfit) > 0.01) {
        $stmt = $pdo->prepare("SELECT SUM(amount) FROM fy_closing_snapshots
            WHERE company_id = ? AND fy_id = ? AND schedule_code IN ('reserves','capital_introduced','drawings')");
        $stmt->execute([$company_id, $prevFYId]);
        $capitalMovement = (float) $stmt->fetchColumn();
        $capitalImpact = $prevPnlProfit + $capitalMovement;
        if (abs($capitalImpact) > 0.01) {
            $issues[] = "Previous year Profit/Loss (₹" . number_format($prevPnlProfit, 2)
                . ") may not reconcile with Capital movement.";
        }
    }

    return [
        'has_previous' => true,
        'prev_fy_id' => $prevFYId,
        'issues' => $issues,
    ];
}

/* =========================
   SNAPSHOT REGENERATION
========================= */

function regenerateSnapshot(PDO $pdo, int $company_id, int $fy_id, int $user_id): array
{
    $status = getFYStatus($pdo, $company_id, $fy_id);
    if ($status !== 'closed') {
        return ['success' => false, 'message' => 'Snapshot can only be regenerated for closed financial years.'];
    }

    $pdo->beginTransaction();
    try {
        $snapshotCount = captureClosingSnapshot($pdo, $company_id, $fy_id);

        $pdo->prepare("INSERT INTO fy_closure_audit (company_id, fy_id, action, performed_by, reason)
            VALUES (?, ?, 'snapshot_regenerated', ?, ?)")
            ->execute([$company_id, $fy_id, $user_id, 'Snapshot regenerated manually']);

        $pdo->commit();

        return [
            'success' => true,
            'message' => "Snapshot regenerated successfully. {$snapshotCount} entries captured.",
            'snapshot_count' => $snapshotCount,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Snapshot regeneration failed: ' . $e->getMessage()];
    }
}

/* =========================
   AUDIT TRAIL
========================= */

function getFYClosureAuditLog(PDO $pdo, int $company_id, int $fy_id): array
{
    $stmt = $pdo->prepare("SELECT * FROM fy_closure_audit
        WHERE company_id = ? AND fy_id = ?
        ORDER BY performed_at DESC");
    $stmt->execute([$company_id, $fy_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCompanyClosureAuditLog(PDO $pdo, int $company_id, int $limit = 50): array
{
    $stmt = $pdo->prepare("SELECT a.*, f.fy_label
        FROM fy_closure_audit a
        JOIN financial_years f ON f.id = a.fy_id AND f.company_id = a.company_id
        WHERE a.company_id = ?
        ORDER BY a.performed_at DESC LIMIT ?");
    $stmt->execute([$company_id, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   PERMISSION CHECK
========================= */

function userCanCloseFY(PDO $pdo, int $user_id): bool
{
    require_once __DIR__ . '/plan_helper.php';
    if (isSuperAdmin($pdo, $user_id)) {
        return true;
    }
    $user = getUserById($pdo, $user_id);
    $role = (string) ($user['role'] ?? '');
    return in_array($role, ['superadmin', 'admin'], true);
}
