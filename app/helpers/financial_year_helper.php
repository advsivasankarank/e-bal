<?php

function getCurrentFinancialYearLabel($date = 'now')
{
    $timestamp = strtotime((string) $date);
    if ($timestamp === false) {
        $timestamp = time();
    }

    $month = (int) date('n', $timestamp);
    $year = (int) date('Y', $timestamp);
    $startYear = $month >= 4 ? $year : $year - 1;

    return $startYear . '-' . ($startYear + 1);
}

function getDefaultFinancialYearRange($pastYears = 15, $futureYears = 2)
{
    $years = [];
    $currentLabel = getCurrentFinancialYearLabel();
    [$currentStartYear] = array_map('intval', explode('-', $currentLabel, 2));

    for ($startYear = $currentStartYear - $pastYears; $startYear <= $currentStartYear + $futureYears; $startYear++) {
        $years[] = [
            'id' => $startYear,
            'fy_label' => $startYear . '-' . ($startYear + 1),
        ];
    }

    usort($years, static function ($left, $right) {
        return strcmp((string) $right['fy_label'], (string) $left['fy_label']);
    });

    return $years;
}

function normalizeFinancialYearLabel($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/\b(\d{4})\D+(\d{4})\b/', $value, $matches)) {
        return $matches[1] . '-' . $matches[2];
    }

    if (preg_match('/\b(\d{4})\D+(\d{2})\b/', $value, $matches)) {
        $endYear = (int) substr($matches[1], 0, 2) . (int) $matches[2];
        return $matches[1] . '-' . $endYear;
    }

    return $value;
}

function getPreviousFinancialYearLabel($fyLabel)
{
    $normalized = normalizeFinancialYearLabel($fyLabel);
    if (!preg_match('/^(\d{4})-(\d{4})$/', $normalized, $matches)) {
        return '';
    }

    $startYear = (int) $matches[1] - 1;
    $endYear = (int) $matches[2] - 1;

    return $startYear . '-' . $endYear;
}

function formatFinancialYearFromDates($startValue, $endValue = null)
{
    $startTs = strtotime((string) $startValue);
    $endTs = $endValue ? strtotime((string) $endValue) : false;

    if ($startTs === false && $endTs === false) {
        return '';
    }

    if ($startTs !== false) {
        $startMonth = (int) date('n', $startTs);
        $startYear = (int) date('Y', $startTs);
    } elseif ($endTs !== false) {
        $endMonth = (int) date('n', $endTs);
        $endYear = (int) date('Y', $endTs);
        $startYear = $endMonth <= 3 ? $endYear - 1 : $endYear;
        return $startYear . '-' . ($startYear + 1);
    }

    if ($endTs !== false) {
        $endYear = (int) date('Y', $endTs);
        return $startYear . '-' . $endYear;
    }

    if ($startMonth >= 4) {
        return $startYear . '-' . ($startYear + 1);
    }

    return ($startYear - 1) . '-' . $startYear;
}

function fyTableExists(PDO $pdo, $tableName)
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ");
    $stmt->execute([$tableName]);
    return (bool) $stmt->fetchColumn();
}

function fyColumnExists(PDO $pdo, $tableName, $columnName)
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
        LIMIT 1
    ");
    $stmt->execute([$tableName, $columnName]);
    return (bool) $stmt->fetchColumn();
}

function detectFinancialYearTable(PDO $pdo)
{
    $candidates = [
        'financial_years',
        'financial_year',
        'fy_master',
        'assessment_years'
    ];

    foreach ($candidates as $candidate) {
        if (fyTableExists($pdo, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function buildFinancialYearLabelFromStartYear($startYear)
{
    $startYear = (int) $startYear;
    return $startYear . '-' . ($startYear + 1);
}

function parseFinancialYearBounds($fyLabel)
{
    $normalized = normalizeFinancialYearLabel($fyLabel);
    if (!preg_match('/^(\d{4})-(\d{4})$/', $normalized, $matches)) {
        return null;
    }

    $startYear = (int) $matches[1];
    $endYear = (int) $matches[2];
    if ($endYear !== ($startYear + 1)) {
        return null;
    }

    return [
        'fy_label' => $normalized,
        'fy_start' => sprintf('%04d-04-01', $startYear),
        'fy_end' => sprintf('%04d-03-31', $endYear),
        'start_year' => $startYear,
        'end_year' => $endYear,
    ];
}

function getFinancialYears(PDO $pdo, $companyId = null)
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null) {
        return getDefaultFinancialYearRange();
    }

    $hasId = fyColumnExists($pdo, $table, 'id');
    $hasFyName = fyColumnExists($pdo, $table, 'fy_name');
    $hasFyLabel = fyColumnExists($pdo, $table, 'fy_label');
    $hasName = fyColumnExists($pdo, $table, 'name');
    $hasLabel = fyColumnExists($pdo, $table, 'label');
    $hasStart = fyColumnExists($pdo, $table, 'start_year');
    $hasEnd = fyColumnExists($pdo, $table, 'end_year');
    $hasStartDate = fyColumnExists($pdo, $table, 'start_date');
    $hasEndDate = fyColumnExists($pdo, $table, 'end_date');
    $hasFromDate = fyColumnExists($pdo, $table, 'from_date');
    $hasToDate = fyColumnExists($pdo, $table, 'to_date');

    if (!$hasId) {
        return [];
    }

    $hasCompanyId = fyColumnExists($pdo, $table, 'company_id');
    $where = '';
    $params = [];
    if ($hasCompanyId && (int) $companyId > 0) {
        $where = ' WHERE company_id = ?';
        $params[] = (int) $companyId;
    }

    if ($hasFyName) {
        $sql = "SELECT id, fy_name AS raw_label FROM `$table`{$where} ORDER BY id DESC";
    } elseif ($hasFyLabel) {
        $sql = "SELECT id, fy_label AS raw_label FROM `$table`{$where} ORDER BY id DESC";
    } elseif ($hasName) {
        $sql = "SELECT id, name AS raw_label FROM `$table`{$where} ORDER BY id DESC";
    } elseif ($hasLabel) {
        $sql = "SELECT id, label AS raw_label FROM `$table`{$where} ORDER BY id DESC";
    } elseif ($hasStartDate && $hasEndDate) {
        $sql = "SELECT id, start_date, end_date FROM `$table`{$where} ORDER BY start_date DESC, end_date DESC";
    } elseif ($hasFromDate && $hasToDate) {
        $sql = "SELECT id, from_date, to_date FROM `$table`{$where} ORDER BY from_date DESC, to_date DESC";
    } elseif ($hasStart && $hasEnd) {
        $sql = "SELECT id, start_year, end_year FROM `$table`{$where} ORDER BY start_year DESC, end_year DESC";
    } else {
        $sql = "SELECT id, CONCAT('FY ', id) AS raw_label FROM `$table`{$where} ORDER BY id DESC";
    }

    if ($params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($rows as &$row) {
        if (isset($row['raw_label'])) {
            $row['fy_label'] = normalizeFinancialYearLabel($row['raw_label']);
        } elseif (isset($row['start_date'], $row['end_date'])) {
            $row['fy_label'] = formatFinancialYearFromDates($row['start_date'], $row['end_date']);
        } elseif (isset($row['from_date'], $row['to_date'])) {
            $row['fy_label'] = formatFinancialYearFromDates($row['from_date'], $row['to_date']);
        } elseif (isset($row['start_year'], $row['end_year'])) {
            $row['fy_label'] = (int) $row['start_year'] . '-' . (int) $row['end_year'];
        } else {
            $row['fy_label'] = 'FY ' . (int) $row['id'];
        }
    }
    unset($row);

    if (empty($rows)) {
        return [];
    }

    $hasUsefulLabels = false;
    foreach ($rows as $row) {
        $label = trim((string) ($row['fy_label'] ?? ''));
        if ($label !== '' && !preg_match('/^FY\s+\d+$/i', $label)) {
            $hasUsefulLabels = true;
            break;
        }
    }

    if (!$hasUsefulLabels) {
        return getDefaultFinancialYearRange();
    }

    return $rows;
}

function findFinancialYearById(PDO $pdo, $fyId, $companyId = null)
{
    $years = getFinancialYears($pdo, $companyId);
    foreach ($years as $year) {
        if ((int) $year['id'] === (int) $fyId) {
            return $year;
        }
    }

    return null;
}

function findFinancialYearByLabel(PDO $pdo, $fyLabel, $companyId = null)
{
    $normalized = normalizeFinancialYearLabel($fyLabel);
    if ($normalized === '') {
        return null;
    }

    $years = getFinancialYears($pdo, $companyId);
    foreach ($years as $year) {
        if (($year['fy_label'] ?? '') === $normalized) {
            return $year;
        }
    }

    return null;
}

function getPreferredFinancialYearId(array $financialYears)
{
    if (empty($financialYears)) {
        return 0;
    }

    $currentLabel = getCurrentFinancialYearLabel();
    foreach ($financialYears as $year) {
        if (($year['fy_label'] ?? '') === $currentLabel) {
            return (int) ($year['id'] ?? 0);
        }
    }

    return (int) ($financialYears[0]['id'] ?? 0);
}

function canPersistFinancialYears(PDO $pdo)
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null) {
        return false;
    }

    return fyColumnExists($pdo, $table, 'company_id')
        && fyColumnExists($pdo, $table, 'fy_start')
        && fyColumnExists($pdo, $table, 'fy_end');
}

function ensureFinancialYearRecord(PDO $pdo, $companyId, $fyLabel)
{
    $companyId = (int) $companyId;
    if ($companyId <= 0) {
        throw new InvalidArgumentException('Company is required.');
    }

    $bounds = parseFinancialYearBounds($fyLabel);
    if ($bounds === null) {
        throw new InvalidArgumentException('Use a valid financial year like 2018-2019 or 2018-19.');
    }

    $table = detectFinancialYearTable($pdo);
    if ($table === null || !canPersistFinancialYears($pdo)) {
        return [
            'id' => $bounds['start_year'],
            'fy_label' => $bounds['fy_label'],
        ];
    }

    $stmt = $pdo->prepare("
        SELECT id, fy_label
        FROM `$table`
        WHERE company_id = ?
          AND fy_start = ?
          AND fy_end = ?
        LIMIT 1
    ");
    $stmt->execute([$companyId, $bounds['fy_start'], $bounds['fy_end']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $existing['fy_label'] = normalizeFinancialYearLabel($existing['fy_label'] ?? '') ?: $bounds['fy_label'];
        return $existing;
    }

    $insert = $pdo->prepare("
        INSERT INTO `$table` (company_id, fy_start, fy_end, created_at, fy_label)
        VALUES (?, ?, ?, NOW(), ?)
    ");
    $insert->execute([$companyId, $bounds['fy_start'], $bounds['fy_end'], $bounds['fy_label']]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'fy_label' => $bounds['fy_label'],
    ];
}
