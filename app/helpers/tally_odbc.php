<?php

function getTallyOdbcDsn(): string
{
    $driver = getenv('TALLY_ODBC_DRIVER') ?: 'Tally ODBC Driver';
    $server = getenv('TALLY_ODBC_SERVER') ?: 'localhost';
    $port = getenv('TALLY_ODBC_PORT') ?: '9000';

    return "Driver={$driver};Server={$server};Port={$port}";
}

function connectTallyOdbc(): ?PDO
{
    if (!extension_loaded('pdo_odbc')) {
        appLog('WARN', 'Tally ODBC: pdo_odbc extension not loaded');
        return null;
    }

    try {
        $dsn = getTallyOdbcDsn();
        $pdo = new PDO("odbc:{$dsn}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException $e) {
        appLog('WARN', 'Tally ODBC connection failed', ['message' => $e->getMessage()]);
        return null;
    }
}

function tallyOdbcIsAvailable(): bool
{
    $pdo = connectTallyOdbc();
    if ($pdo === null) {
        return false;
    }
    try {
        $pdo->query('SELECT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function fetchVouchersViaOdbc(string $fromDate, string $toDate, ?string $lastAltered = null): ?array
{
    $pdo = connectTallyOdbc();
    if ($pdo === null) {
        return null;
    }

    try {
        $tables = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $voucherTable = null;
        foreach ($tables as $t) {
            if (stripos($t, 'voucher') !== false) {
                $voucherTable = $t;
                break;
            }
        }
        if ($voucherTable === null) {
            $voucherTable = 'Voucher';
        }

        $sql = "SELECT * FROM \"{$voucherTable}\" WHERE Date >= ? AND Date <= ?";
        $params = [$fromDate, $toDate];

        if ($lastAltered !== null) {
            $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='{$voucherTable}'")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('AlteredDate', $cols, true)) {
                $sql .= " AND AlteredDate >= ?";
                $params[] = $lastAltered;
            } elseif (in_array('$AlteredDate', $cols, true)) {
                $sql .= " AND \"\$AlteredDate\" >= ?";
                $params[] = $lastAltered;
            }
        }

        $sql .= " ORDER BY Date ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $cols = array_keys($rows[0]);
        $guidCol = null;
        foreach (['$GUID', 'GUID', 'Guid', 'guid', '$MasterId'] as $c) {
            if (in_array($c, $cols, true)) {
                $guidCol = $c;
                break;
            }
        }

        $vouchers = [];
        foreach ($rows as $row) {
            $guid = $guidCol ? (string) ($row[$guidCol] ?? '') : '';
            if ($guid === '') {
                continue;
            }

            $voucher = [
                'tally_guid' => $guid,
                'master_id' => (int) ($row['$MasterId'] ?? 0),
                'voucher_type' => trim((string) ($row['VoucherTypeName'] ?? $row['Voucher Type Name'] ?? '')),
                'voucher_number' => trim((string) ($row['VoucherNumber'] ?? $row['Voucher Number'] ?? '')),
                'date' => normalizeOdbcDateValue($row['Date'] ?? ''),
                'effective_date' => normalizeOdbcDateValue($row['EffectiveDate'] ?? ''),
                'narration' => trim((string) ($row['Narration'] ?? '')),
                'party_ledger_name' => trim((string) ($row['PartyLedgerName'] ?? $row['Party Name'] ?? '')),
                'is_optional' => (int) ($row['IsOptional'] ?? 0),
                'is_cancelled' => (int) ($row['IsCancelled'] ?? 0),
                'altered_date' => normalizeOdbcDateTimeValue($row['AlteredDate'] ?? $row['$AlteredDate'] ?? null),
                'created_date' => normalizeOdbcDateTimeValue($row['CreatedDate'] ?? $row['$CreatedDate'] ?? null),
                'source' => 'odbc',
            ];

            $entries = extractOdbcVoucherEntries($row);
            if (!empty($entries)) {
                $voucher['entries'] = $entries;
            }

            $vouchers[$guid] = $voucher;
        }

        return array_values($vouchers);
    } catch (PDOException $e) {
        appLog('WARN', 'Tally ODBC query failed', ['message' => $e->getMessage()]);
        return null;
    } catch (Throwable $e) {
        appLog('WARN', 'Tally ODBC unexpected error', ['message' => $e->getMessage()]);
        return null;
    }
}

function extractOdbcVoucherEntries(array $row): array
{
    $entries = [];
    $prefixes = ['LedgerName', 'Ledger', 'Account'];

    $ledgerCol = null;
    $amountCol = null;
    $drCrCol = null;

    foreach ($row as $col => $val) {
        $colLower = strtolower($col);
        foreach ($prefixes as $p) {
            if (strpos($colLower, strtolower($p)) !== false && $val !== null && $val !== '') {
                $ledgerCol = $col;
                break;
            }
        }
    }

    foreach ($row as $col => $val) {
        $colLower = strtolower($col);
        if (in_array($colLower, ['dramount', 'cramount', 'amount'], true) || strpos($colLower, 'amount') !== false) {
            if ($val !== null && (float) $val !== 0.0) {
                $amountCol = $col;
                if (strpos($colLower, 'dr') !== false) {
                    $drCrCol = 'DR';
                } elseif (strpos($colLower, 'cr') !== false) {
                    $drCrCol = 'CR';
                }
                break;
            }
        }
    }

    if ($ledgerCol !== null && $amountCol !== null) {
        $entries[] = [
            'ledger_name' => trim((string) ($row[$ledgerCol] ?? '')),
            'amount' => abs((float) ($row[$amountCol] ?? 0)),
            'dr_cr' => $drCrCol ?? ((float) ($row[$amountCol] ?? 0) >= 0 ? 'DR' : 'CR'),
        ];
    }

    return $entries;
}

function normalizeOdbcDateValue($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $m)) {
        return $m[0];
    }
    $ts = strtotime((string) $value);
    return $ts ? date('Y-m-d', $ts) : (string) $value;
}

function normalizeOdbcDateTimeValue($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', (string) $value, $m)) {
        return $m[0];
    }
    $ts = strtotime((string) $value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
