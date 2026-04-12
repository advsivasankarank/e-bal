<?php

require_once __DIR__ . '/financial_year_helper.php';

function ensureReportManualInputsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS report_manual_inputs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            fy_id INT NOT NULL,
            input_key VARCHAR(120) NOT NULL,
            input_value TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_report_manual_input (company_id, fy_id, input_key)
        )
    ");
}

function loadManualInputsForYear(PDO $pdo, int $company_id, int $fy_id): array
{
    ensureReportManualInputsTable($pdo);

    $stmt = $pdo->prepare("
        SELECT input_key, input_value
        FROM report_manual_inputs
        WHERE company_id = ? AND fy_id = ?
    ");
    $stmt->execute([$company_id, $fy_id]);

    $inputs = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $inputs[$row['input_key']] = (string) ($row['input_value'] ?? '');
    }

    return $inputs;
}

function loadManualInputsWithCarryForward(PDO $pdo, int $company_id, int $fy_id, string $fyLabel): array
{
    $current = loadManualInputsForYear($pdo, $company_id, $fy_id);
    $previous = [];

    $previousLabel = getPreviousFinancialYearLabel($fyLabel);
    if ($previousLabel !== '') {
        $previousFy = findFinancialYearByLabel($pdo, $previousLabel);
        if ($previousFy !== null) {
            $previous = loadManualInputsForYear($pdo, $company_id, (int) ($previousFy['id'] ?? 0));
        }
    }

    return [
        'current' => array_replace($previous, $current),
        'saved_current' => $current,
        'previous' => $previous,
    ];
}

function saveManualInputs(PDO $pdo, int $company_id, int $fy_id, array $inputs): void
{
    ensureReportManualInputsTable($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO report_manual_inputs (company_id, fy_id, input_key, input_value)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            input_value = VALUES(input_value),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($inputs as $key => $value) {
        $stmt->execute([$company_id, $fy_id, $key, (string) $value]);
    }
}
