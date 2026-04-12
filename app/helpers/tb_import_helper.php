<?php

function normalizeLedgerKey($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/\s+/', ' ', $value);
    return $value;
}

function ensureTallyLedgersComparativeColumns(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM tally_ledgers")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('opening_amount', $columns, true)) {
        $pdo->exec("ALTER TABLE tally_ledgers ADD COLUMN opening_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER dr_cr");
    }

    if (!in_array('opening_dr_cr', $columns, true)) {
        $pdo->exec("ALTER TABLE tally_ledgers ADD COLUMN opening_dr_cr VARCHAR(2) DEFAULT NULL AFTER opening_amount");
    }
}

function mapOpeningRowsByLedger(array $rows): array
{
    $mapped = [];

    foreach ($rows as $row) {
        $name = trim((string) ($row['ledger_name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $mapped[normalizeLedgerKey($name)] = [
            'amount' => abs((float) ($row['amount'] ?? 0)),
            'type' => strtoupper(trim((string) ($row['type'] ?? ''))),
        ];
    }

    return $mapped;
}

function loadOpeningRowsFromStoredYear(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureTallyLedgersComparativeColumns($pdo);

    $stmt = $pdo->prepare("
        SELECT ledger_name, amount, dr_cr
        FROM tally_ledgers
        WHERE company_id = ? AND fy_id = ?
    ");
    $stmt->execute([$company_id, $fy_id]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'ledger_name' => $row['ledger_name'],
            'amount' => (float) ($row['amount'] ?? 0),
            'type' => strtoupper(trim((string) ($row['dr_cr'] ?? ''))),
        ];
    }

    return $rows;
}

function importTrialBalanceRows(PDO $pdo, int $company_id, int $fy_id, array $rows, array $approvedUnknownMappings = [], array $openingRows = [], bool $updateWorkflowStatus = true): array
{
    ensureTallyLedgersComparativeColumns($pdo);

    $insertTbStmt = $pdo->prepare("
        INSERT INTO tally_ledgers
        (company_id, fy_id, ledger_name, parent_group, amount, dr_cr, opening_amount, opening_dr_cr, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $parentStmt = $pdo->prepare("
        SELECT parent_group FROM tally_ledger_master
        WHERE company_id=? AND ledger_name=?
        LIMIT 1
    ");

    $insertLedgerMasterStmt = $pdo->prepare("
        INSERT INTO tally_ledger_master
        (company_id, ledger_name, parent_group)
        VALUES (?, ?, ?)
    ");

    $insertMappingStmt = $pdo->prepare("
        INSERT INTO ledger_mapping
        (company_id, ledger_name, schedule_code)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            schedule_code = VALUES(schedule_code)
    ");

    $pdo->beginTransaction();

    try {
        $openingMap = mapOpeningRowsByLedger($openingRows);
        $dataInserted = 0;
        $drTotal = 0;
        $crTotal = 0;
        $unknownLedgers = [];

        $pdo->prepare("DELETE FROM tally_ledgers WHERE company_id=? AND fy_id=?")
            ->execute([$company_id, $fy_id]);

        foreach ($rows as $row) {
            $name = trim((string) ($row['ledger_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $amount = abs((float) ($row['amount'] ?? 0));
            $dr_cr = strtoupper(trim((string) ($row['type'] ?? '')));

            if ($amount == 0.0 || !in_array($dr_cr, ['DR', 'CR'], true)) {
                continue;
            }

            $parent = trim((string) ($row['parent_group'] ?? ''));
            if ($parent === '') {
                $parentStmt->execute([$company_id, $name]);
                $parent = $parentStmt->fetchColumn() ?: '';
            }

            $normalizedName = normalizeLedgerKey($name);

            if ($parent === '' && $normalizedName === 'opening stock') {
                $parent = 'Opening Stock';
                $insertLedgerMasterStmt->execute([$company_id, $name, $parent]);
                $insertMappingStmt->execute([$company_id, $name, 'inventory']);
            }

            if ($parent === '' && isset($approvedUnknownMappings[$name])) {
                $mapping = $approvedUnknownMappings[$name];
                $parent = trim((string) ($mapping['parent_group'] ?? 'TB Added Ledger'));
                $scheduleCode = trim((string) ($mapping['schedule_code'] ?? ''));

                if ($parent !== '' && $scheduleCode !== '') {
                    $insertLedgerMasterStmt->execute([$company_id, $name, $parent]);
                    $insertMappingStmt->execute([$company_id, $name, $scheduleCode]);
                }
            }

            if ($parent === '') {
                $unknownLedgers[$name] = [
                    'ledger_name' => $name,
                    'amount' => $amount,
                    'type' => $dr_cr,
                ];
                continue;
            }

            if ($dr_cr === 'DR') {
                $drTotal += $amount;
            } else {
                $crTotal += $amount;
            }

            $opening = $openingMap[$normalizedName] ?? ['amount' => 0.0, 'type' => null];

            $insertTbStmt->execute([
                $company_id,
                $fy_id,
                $name,
                $parent,
                $amount,
                $dr_cr,
                (float) ($opening['amount'] ?? 0),
                ($opening['type'] ?? null)
            ]);

            $dataInserted++;
        }

        if (!empty($unknownLedgers)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'unknowns' => array_values($unknownLedgers),
            ];
        }

        if ($updateWorkflowStatus) {
            $pdo->prepare("
                INSERT INTO workflow_status
                (company_id, fy_id, tally_fetched, updated_at)
                VALUES (?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    tally_fetched = 1,
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([$company_id, $fy_id]);
        }

        $pdo->commit();

        return [
            'ok' => true,
            'stats' => [
                'total' => $dataInserted,
                'dr_total' => $drTotal,
                'cr_total' => $crTotal,
                'type' => 'tally bridge',
                'comparative_rows' => count($openingMap),
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
