<?php

require_once __DIR__ . '/runtime_helper.php';
require_once __DIR__ . '/tally_odbc.php';
require_once __DIR__ . '/voucher_xml.php';

function ensureVoucherTables(PDO $pdo): void
{
    if (!appAllowsRuntimeSchema()) {
        assertTableExists($pdo, 'voucher_types');
        assertTableExists($pdo, 'vouchers');
        assertTableExists($pdo, 'voucher_entries');
        assertTableExists($pdo, 'voucher_sync_state');
        return;
    }

    $schema = file_get_contents(__DIR__ . '/../sql/voucher_schema.sql');
    if ($schema !== false) {
        $statements = explode(';', $schema);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    appLog('WARN', 'Voucher schema statement failed', [
                        'error' => $e->getMessage(),
                        'sql' => substr($stmt, 0, 100),
                    ]);
                }
            }
        }
    }

    try {
        $pdo->exec("ALTER TABLE workflow_status ADD COLUMN IF NOT EXISTS voucher_synced TINYINT(1) NOT NULL DEFAULT 0 AFTER ledger_fetched");
    } catch (PDOException $e) {
        appLog('WARN', 'Failed to add voucher_synced column', ['message' => $e->getMessage()]);
    }
}

function getLastVoucherSync(PDO $pdo, int $companyId, int $fyId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM voucher_sync_state WHERE company_id = ? AND fy_id = ?");
    $stmt->execute([$companyId, $fyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function updateVoucherSyncState(PDO $pdo, int $companyId, int $fyId, array $data): void
{
    $allowedFields = ['last_synced_at', 'last_altered_synced', 'odbc_available', 'total_vouchers', 'synced_vouchers', 'error_message'];
    $updates = [];
    $updateParams = [];
    $insertCols = [];
    $insertVals = [];
    $insertParams = [];

    $insertCols[] = 'company_id';
    $insertVals[] = '?';
    $insertParams[] = $companyId;

    $insertCols[] = 'fy_id';
    $insertVals[] = '?';
    $insertParams[] = $fyId;

    foreach ($allowedFields as $f) {
        if (array_key_exists($f, $data)) {
            $updates[] = "$f = ?";
            $updateParams[] = $data[$f];
            $insertCols[] = $f;
            $insertVals[] = '?';
            $insertParams[] = $data[$f];
        }
    }

    if (empty($updates)) {
        return;
    }

    $sql = "INSERT INTO voucher_sync_state (" . implode(', ', $insertCols) . ")
            VALUES (" . implode(', ', $insertVals) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

    $pdo->prepare($sql)->execute(array_merge($insertParams, $updateParams));
}

function syncVouchersIncremental(PDO $pdo, int $companyId, int $fyId, string $fromDate, string $toDate, ?string $voucherType = null): array
{
    ensureVoucherTables($pdo);

    $state = getLastVoucherSync($pdo, $companyId, $fyId);
    $lastAltered = $state['last_altered_synced'] ?? null;

    $odbcOk = false;
    $vouchers = null;
    $sourceUsed = 'xml';

    $odbcOk = tallyOdbcIsAvailable();
    if ($odbcOk) {
        $vouchers = fetchVouchersViaOdbc($fromDate, $toDate, $lastAltered);
        if ($vouchers !== null) {
            $sourceUsed = 'odbc';
        } else {
            $odbcOk = false;
        }
    }

    if ($vouchers === null) {
        $vouchers = fetchVouchersViaXml($fromDate, $toDate, $voucherType);
        $sourceUsed = 'xml';
    }

    if ($vouchers === null) {
        $result = [
            'ok' => false,
            'message' => 'Failed to fetch vouchers from Tally (ODBC unavailable, XML returned no data).',
            'source' => null,
            'synced' => 0,
            'total' => 0,
        ];
        updateVoucherSyncState($pdo, $companyId, $fyId, [
            'last_synced_at' => date('Y-m-d H:i:s'),
            'odbc_available' => 0,
            'error_message' => $result['message'],
        ]);
        return $result;
    }

    $storeResult = storeFetchedVouchers($pdo, $companyId, $fyId, $vouchers, $sourceUsed, $lastAltered);
    if (!$storeResult['ok']) {
        return [
            'ok' => false,
            'message' => $storeResult['message'],
            'source' => $sourceUsed,
            'synced' => $storeResult['synced'],
            'total' => count($vouchers),
        ];
    }

    updateVoucherSyncState($pdo, $companyId, $fyId, [
        'last_synced_at' => date('Y-m-d H:i:s'),
        'last_altered_synced' => $storeResult['max_altered'],
        'odbc_available' => $odbcOk ? 1 : 0,
        'total_vouchers' => count($vouchers),
        'synced_vouchers' => $storeResult['synced'],
        'error_message' => null,
    ]);

    return [
        'ok' => true,
        'message' => "Synced {$storeResult['synced']} vouchers via {$sourceUsed}.",
        'source' => $sourceUsed,
        'synced' => $storeResult['synced'],
        'total' => count($vouchers),
        'odbc_available' => $odbcOk,
    ];
}

/**
 * Persists an already-fetched array of voucher dicts (the shape produced by
 * fetchVouchersViaXml()/fetchVouchersViaOdbc(), and identically by the
 * Smart Bridge's own fetch_vouchers_via_xml()/fetch_vouchers_via_odbc() in
 * tally_bridge_exe/ui_app.py) into vouchers/voucher_entries. Shared by
 * syncVouchersIncremental() (server-initiated fetch) and
 * public/bridge_voucher.php (bridge-pushed vouchers already fetched
 * client-side, where the server has no direct route to Tally at all) so
 * both paths store data identically.
 */
function storeFetchedVouchers(PDO $pdo, int $companyId, int $fyId, array $vouchers, string $sourceUsed, ?string $lastAltered = null): array
{
    ensureVoucherTables($pdo);

    $maxAltered = $lastAltered;
    $synced = 0;
    $pdo->beginTransaction();

    try {
        $stmtV = $pdo->prepare("
            INSERT INTO vouchers
                (company_id, fy_id, tally_guid, voucher_type, voucher_number, date, effective_date,
                 narration, party_ledger_name, is_optional, is_cancelled, master_id,
                 altered_date, created_date, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                voucher_number = COALESCE(NULLIF(VALUES(voucher_number), ''), voucher_number),
                date = VALUES(date),
                effective_date = VALUES(effective_date),
                narration = VALUES(narration),
                party_ledger_name = VALUES(party_ledger_name),
                is_optional = VALUES(is_optional),
                is_cancelled = VALUES(is_cancelled),
                altered_date = VALUES(altered_date),
                source = VALUES(source),
                updated_at = NOW()
        ");

        $stmtEntry = $pdo->prepare("
            INSERT INTO voucher_entries
                (voucher_id, company_id, ledger_name, parent_group, amount, dr_cr,
                 bill_type, bill_name, cost_centre)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmtDelEntries = $pdo->prepare("DELETE FROM voucher_entries WHERE voucher_id = ?");

        foreach ($vouchers as $v) {
            if (empty($v['tally_guid'])) {
                continue;
            }
            $vDate = $v['date'] ?? null;
            $effDate = $v['effective_date'] ?? null;
            $alterDate = $v['altered_date'] ?? null;
            $createDate = $v['created_date'] ?? null;

            $stmtV->execute([
                $companyId,
                $fyId,
                $v['tally_guid'],
                $v['voucher_type'] ?? '',
                $v['voucher_number'] ?? '',
                $vDate,
                $effDate,
                $v['narration'] ?? null,
                $v['party_ledger_name'] ?? null,
                $v['is_optional'] ?? 0,
                $v['is_cancelled'] ?? 0,
                $v['master_id'] ?? 0,
                $alterDate,
                $createDate,
                $sourceUsed,
            ]);

            $voucherId = (int) $pdo->lastInsertId();
            if ($voucherId <= 0) {
                $fetchStmt = $pdo->prepare("SELECT id FROM vouchers WHERE tally_guid = ?");
                $fetchStmt->execute([$v['tally_guid']]);
                $voucherId = (int) $fetchStmt->fetchColumn();
            }

            if ($voucherId > 0 && !empty($v['entries'])) {
                $stmtDelEntries->execute([$voucherId]);
                foreach ($v['entries'] as $entry) {
                    $stmtEntry->execute([
                        $voucherId,
                        $companyId,
                        $entry['ledger_name'],
                        $entry['parent_group'] ?? '',
                        $entry['amount'] ?? 0,
                        $entry['dr_cr'] ?? 'DR',
                        $entry['bill_type'] ?? null,
                        $entry['bill_name'] ?? null,
                        $entry['cost_centre'] ?? null,
                    ]);
                }
            }

            if ($alterDate !== null && ($maxAltered === null || $alterDate > $maxAltered)) {
                $maxAltered = $alterDate;
            }

            $synced++;
        }

        $pdo->prepare("
            INSERT INTO workflow_status (company_id, fy_id, voucher_synced, updated_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                voucher_synced = 1,
                updated_at = NOW()
        ")->execute([$companyId, $fyId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        appLog('ERROR', 'Voucher store failed', [
            'company_id' => $companyId,
            'fy_id' => $fyId,
            'message' => $e->getMessage(),
        ]);
        return ['ok' => false, 'message' => 'Database error: ' . $e->getMessage(), 'synced' => $synced, 'max_altered' => $maxAltered];
    }

    return ['ok' => true, 'message' => "Stored {$synced} vouchers.", 'synced' => $synced, 'max_altered' => $maxAltered];
}

function getVoucherSyncSummary(PDO $pdo, int $companyId, int $fyId): array
{
    $state = getLastVoucherSync($pdo, $companyId, $fyId);

    $countStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COUNT(DISTINCT voucher_type) as types,
            MIN(date) as first_date,
            MAX(date) as last_date
        FROM vouchers 
        WHERE company_id = ? AND fy_id = ?
    ");
    $countStmt->execute([$companyId, $fyId]);
    $stats = $countStmt->fetch(PDO::FETCH_ASSOC);

    $typeStmt = $pdo->prepare("
        SELECT voucher_type, COUNT(*) as cnt 
        FROM vouchers 
        WHERE company_id = ? AND fy_id = ? 
        GROUP BY voucher_type 
        ORDER BY cnt DESC
    ");
    $typeStmt->execute([$companyId, $fyId]);
    $typeBreakdown = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'state' => $state,
        'stats' => $stats,
        'type_breakdown' => $typeBreakdown,
    ];
}

function getVoucherDetail(PDO $pdo, int $companyId, int $fyId, array $filters = []): array
{
    $where = 'WHERE v.company_id = ? AND v.fy_id = ?';
    $params = [$companyId, $fyId];

    if (!empty($filters['voucher_type'])) {
        $where .= ' AND v.voucher_type = ?';
        $params[] = $filters['voucher_type'];
    }
    if (!empty($filters['date_from'])) {
        $where .= ' AND v.date >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where .= ' AND v.date <= ?';
        $params[] = $filters['date_to'];
    }
    if (!empty($filters['search'])) {
        $where .= ' AND (v.voucher_number LIKE ? OR v.narration LIKE ? OR v.party_ledger_name LIKE ?)';
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $limit = min((int) ($filters['limit'] ?? 50), 500);
    $offset = max((int) ($filters['offset'] ?? 0), 0);

    $sql = "SELECT v.* FROM vouchers v {$where} ORDER BY v.date DESC, v.id DESC LIMIT {$limit} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($vouchers)) {
        $ids = array_column($vouchers, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $entryStmt = $pdo->prepare("SELECT * FROM voucher_entries WHERE voucher_id IN ({$placeholders}) ORDER BY id ASC");
        $entryStmt->execute($ids);
        $entries = $entryStmt->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);

        foreach ($vouchers as &$v) {
            $v['entries'] = $entries[$v['id']] ?? [];
        }
    }

    return $vouchers;
}
