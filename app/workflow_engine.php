<?php
require_once __DIR__ . '/../config/database.php';

function ensureWorkflowColumns(): void
{
    global $pdo;

    $columns = $pdo->query("SHOW COLUMNS FROM workflow_status")->fetchAll(PDO::FETCH_COLUMN);
    $required = [
        'notes_prepared' => "ALTER TABLE workflow_status ADD COLUMN notes_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER tally_fetched",
        'profit_loss_prepared' => "ALTER TABLE workflow_status ADD COLUMN profit_loss_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER notes_prepared",
        'balance_sheet_prepared' => "ALTER TABLE workflow_status ADD COLUMN balance_sheet_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER profit_loss_prepared",
        'directors_report_prepared' => "ALTER TABLE workflow_status ADD COLUMN directors_report_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER balance_sheet_prepared",
    ];

    foreach ($required as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec($sql);
        }
    }
}

function getWorkflow($company_id, $fy_id) {
    global $pdo;
    ensureWorkflowColumns();

    $stmt = $pdo->prepare("
        SELECT * FROM workflow_status 
        WHERE company_id=? AND fy_id=?
    ");
    $stmt->execute([$company_id, $fy_id]);

    return $stmt->fetch() ?: [
        'data_imported' => 0,
        'mapping_done' => 0,
        'reports_generated' => 0
    ];
}

function updateWorkflow($company_id, $fy_id, $field) {
    global $pdo;
    ensureWorkflowColumns();

    $fieldMap = [
        'data_imported' => 'tally_fetched',
        'mapping_done' => 'mapping_completed',
        'mapping_completed' => 'mapping_completed',
        'tally_fetched' => 'tally_fetched',
        'ledger_fetched' => 'ledger_fetched',
        'notes_prepared' => 'notes_prepared',
        'profit_loss_prepared' => 'profit_loss_prepared',
        'balance_sheet_prepared' => 'balance_sheet_prepared',
        'directors_report_prepared' => 'directors_report_prepared',
        'verified' => 'verified',
        'reports_generated' => 'reports_generated',
    ];

    $column = $fieldMap[$field] ?? null;
    if ($column === null) {
        throw new InvalidArgumentException("Invalid workflow field: {$field}");
    }

    $pdo->prepare("
        INSERT INTO workflow_status (company_id, fy_id, {$column}, updated_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE {$column}=1, updated_at=NOW()
    ")->execute([$company_id, $fy_id]);
}
