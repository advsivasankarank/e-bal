<?php

function ensureLedgerMappingMetadataColumns(PDO $pdo): void
{
    $columns = $pdo->query("SHOW COLUMNS FROM ledger_mapping")->fetchAll(PDO::FETCH_COLUMN);

    $requiredColumns = [
        'mapping_source' => "ALTER TABLE ledger_mapping ADD COLUMN mapping_source VARCHAR(50) NOT NULL DEFAULT 'manual' AFTER schedule_code",
        'confidence_score' => "ALTER TABLE ledger_mapping ADD COLUMN confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER mapping_source",
        'mapping_reason' => "ALTER TABLE ledger_mapping ADD COLUMN mapping_reason TEXT NULL AFTER confidence_score",
        'remember_scope' => "ALTER TABLE ledger_mapping ADD COLUMN remember_scope VARCHAR(20) NULL AFTER mapping_reason",
        'approved_by_user_id' => "ALTER TABLE ledger_mapping ADD COLUMN approved_by_user_id INT NULL AFTER remember_scope",
        'approved_at' => "ALTER TABLE ledger_mapping ADD COLUMN approved_at DATETIME NULL AFTER approved_by_user_id",
    ];

    foreach ($requiredColumns as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

function ensureMappingLearningTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mapping_learning (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(20) NOT NULL DEFAULT 'company',
            company_id INT NOT NULL DEFAULT 0,
            normalized_ledger_name VARCHAR(255) NOT NULL,
            normalized_parent_group VARCHAR(255) NOT NULL DEFAULT '',
            original_ledger_name VARCHAR(255) NOT NULL,
            original_parent_group VARCHAR(255) NOT NULL DEFAULT '',
            schedule_code VARCHAR(100) NOT NULL,
            usage_count INT NOT NULL DEFAULT 1,
            created_by_user_id INT NULL,
            updated_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mapping_learning (scope, company_id, normalized_ledger_name, normalized_parent_group)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function ensureMappingAiSchema(PDO $pdo): void
{
    ensureLedgerMappingMetadataColumns($pdo);
    ensureMappingLearningTable($pdo);
}

function normalizeMappingText(string $value): string
{
    $value = strtolower(trim($value));
    $value = str_replace(['&', '-', '_', '/', '.', ','], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string) $value);
}

function saveMappingLearning(
    PDO $pdo,
    int $companyId,
    string $ledgerName,
    string $parentGroup,
    string $scheduleCode,
    string $scope,
    ?int $userId = null
): void {
    ensureMappingLearningTable($pdo);

    $scope = strtolower(trim($scope));
    if (!in_array($scope, ['company', 'global'], true)) {
        return;
    }

    $normalizedLedger = normalizeMappingText($ledgerName);
    if ($normalizedLedger === '' || trim($scheduleCode) === '') {
        return;
    }

    $normalizedParentGroup = normalizeMappingText($parentGroup);
    $scopeCompanyId = $scope === 'global' ? 0 : $companyId;

    $stmt = $pdo->prepare("
        INSERT INTO mapping_learning (
            scope,
            company_id,
            normalized_ledger_name,
            normalized_parent_group,
            original_ledger_name,
            original_parent_group,
            schedule_code,
            usage_count,
            created_by_user_id,
            updated_by_user_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ON DUPLICATE KEY UPDATE
            schedule_code = VALUES(schedule_code),
            original_ledger_name = VALUES(original_ledger_name),
            original_parent_group = VALUES(original_parent_group),
            usage_count = usage_count + 1,
            updated_by_user_id = VALUES(updated_by_user_id),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $scope,
        $scopeCompanyId,
        $normalizedLedger,
        $normalizedParentGroup,
        $ledgerName,
        $parentGroup,
        $scheduleCode,
        $userId,
        $userId,
    ]);
}

