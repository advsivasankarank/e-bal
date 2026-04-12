<?php

function getDefaultFinancialYearRange()
{
    $years = [];

    for ($startYear = 2015; $startYear <= 2025; $startYear++) {
        $years[] = [
            'id' => $startYear,
            'fy_label' => $startYear . '-' . ($startYear + 1),
        ];
    }

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

function getFinancialYears(PDO $pdo)
{
    $table = detectFinancialYearTable($pdo);
    if ($table === null) {
        return getDefaultFinancialYearRange();
    }

    $hasId = fyColumnExists($pdo, $table, 'id');
    $hasFyName = fyColumnExists($pdo, $table, 'fy_name');
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

    if ($hasFyName) {
        $sql = "SELECT id, fy_name AS raw_label FROM `$table` ORDER BY id DESC";
    } elseif ($hasName) {
        $sql = "SELECT id, name AS raw_label FROM `$table` ORDER BY id DESC";
    } elseif ($hasLabel) {
        $sql = "SELECT id, label AS raw_label FROM `$table` ORDER BY id DESC";
    } elseif ($hasStartDate && $hasEndDate) {
        $sql = "SELECT id, start_date, end_date FROM `$table` ORDER BY start_date DESC, end_date DESC";
    } elseif ($hasFromDate && $hasToDate) {
        $sql = "SELECT id, from_date, to_date FROM `$table` ORDER BY from_date DESC, to_date DESC";
    } elseif ($hasStart && $hasEnd) {
        $sql = "SELECT id, start_year, end_year FROM `$table` ORDER BY start_year DESC, end_year DESC";
    } else {
        $sql = "SELECT id, CONCAT('FY ', id) AS raw_label FROM `$table` ORDER BY id DESC";
    }

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

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

function findFinancialYearById(PDO $pdo, $fyId)
{
    $years = getFinancialYears($pdo);
    foreach ($years as $year) {
        if ((int) $year['id'] === (int) $fyId) {
            return $year;
        }
    }

    return null;
}

function findFinancialYearByLabel(PDO $pdo, $fyLabel)
{
    $normalized = normalizeFinancialYearLabel($fyLabel);
    if ($normalized === '') {
        return null;
    }

    $years = getFinancialYears($pdo);
    foreach ($years as $year) {
        if (($year['fy_label'] ?? '') === $normalized) {
            return $year;
        }
    }

    return null;
}
