-- ============================================================
-- e-BAL Enterprise Release Hardening Migration Script
-- Version: 003
-- Date: 2026-06-27
-- Purpose: Align production schema with Enterprise V1.0 workflow
-- Safe: Non-destructive. Copies legacy meta_* values into canonical input_* columns.
-- ============================================================

-- Canonical manual input schema: input_key/input_value.
CREATE TABLE IF NOT EXISTS report_manual_inputs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    input_key VARCHAR(120) NOT NULL,
    input_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_report_manual_input (company_id, fy_id, input_key),
    INDEX idx_report_manual_inputs_lookup (company_id, fy_id, input_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @has_input_key = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND COLUMN_NAME = 'input_key');
SET @sql = IF(@has_input_key = 0,
    'ALTER TABLE report_manual_inputs ADD COLUMN input_key VARCHAR(120) NULL AFTER fy_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_input_value = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND COLUMN_NAME = 'input_value');
SET @sql = IF(@has_input_value = 0,
    'ALTER TABLE report_manual_inputs ADD COLUMN input_value TEXT NULL AFTER input_key',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Copy legacy review/signoff values if an earlier build used meta_key/meta_value.
SET @has_meta_key = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND COLUMN_NAME = 'meta_key');
SET @has_meta_value = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND COLUMN_NAME = 'meta_value');
SET @sql = IF(@has_meta_key > 0 AND @has_meta_value > 0,
    'UPDATE report_manual_inputs SET input_key = COALESCE(NULLIF(input_key, ''''), meta_key), input_value = COALESCE(input_value, meta_value) WHERE (input_key IS NULL OR input_key = '''') AND meta_key IS NOT NULL AND meta_key <> ''''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove duplicate canonical rows before enforcing uniqueness.
DELETE r1 FROM report_manual_inputs r1
INNER JOIN report_manual_inputs r2
WHERE r1.company_id = r2.company_id
  AND r1.fy_id = r2.fy_id
  AND r1.input_key = r2.input_key
  AND r1.id > r2.id
  AND r1.input_key IS NOT NULL
  AND r1.input_key <> '';

SET @uniq_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND INDEX_NAME = 'uniq_report_manual_input');
SET @sql = IF(@uniq_exists = 0,
    'ALTER TABLE report_manual_inputs ADD UNIQUE KEY uniq_report_manual_input (company_id, fy_id, input_key)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND INDEX_NAME = 'idx_report_manual_inputs_lookup');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_report_manual_inputs_lookup ON report_manual_inputs (company_id, fy_id, input_key)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Workflow indexes used by assignment, review, and deliverables workspaces.
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    reset_token VARCHAR(255) NULL,
    reset_token_expires_at TIMESTAMP NULL,
    role ENUM('superadmin','admin','staff','manager','partner','viewer') NOT NULL DEFAULT 'admin',
    company_owner_id INT NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE users MODIFY role ENUM('superadmin','admin','staff','manager','partner','viewer') NOT NULL DEFAULT 'admin';

SET @owner_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'owner_user_id');
SET @sql = IF(@owner_col = 0,
    'ALTER TABLE companies ADD COLUMN owner_user_id INT NULL AFTER id, ADD INDEX idx_companies_owner (owner_user_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @profile_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'profile_completeness');
SET @sql = IF(@profile_col = 0,
    'ALTER TABLE companies ADD COLUMN profile_completeness TINYINT(3) NOT NULL DEFAULT 0 AFTER updated_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @wf_idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_status' AND INDEX_NAME = 'idx_workflow_assignment');
SET @sql = IF(@wf_idx = 0,
    'CREATE INDEX idx_workflow_assignment ON workflow_status (company_id, fy_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fy_idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'financial_years' AND INDEX_NAME = 'idx_fy_company_dates');
SET @sql = IF(@fy_idx = 0,
    'CREATE INDEX idx_fy_company_dates ON financial_years (company_id, fy_start, fy_end)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove orphan workflow rows without touching valid production data.
DELETE ws FROM workflow_status ws
LEFT JOIN companies c ON c.id = ws.company_id
LEFT JOIN financial_years fy ON fy.id = ws.fy_id AND fy.company_id = ws.company_id
WHERE c.id IS NULL OR fy.id IS NULL;
