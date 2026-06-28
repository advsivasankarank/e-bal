-- ============================================================
-- e-BAL Production Hardening Migration Script
-- Version: 001
-- Date: 2026-06-20
-- Purpose: Consolidate all DDL changes for production deployment
-- ============================================================

-- 1. Add review_policy to companies (Phase 5: Configurable Approval Policy)
-- Safe: ADD COLUMN IF NOT EXISTS equivalent via procedure
SET @exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'review_policy');
SET @sql = IF(@exists = 0,
    'ALTER TABLE companies ADD COLUMN review_policy ENUM(\'single\',\'two_level\',\'three_level\') NOT NULL DEFAULT \'single\' AFTER category',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Ensure workflow_status columns exist (Phase 8: Runtime DDL removal)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workflow_status' AND COLUMN_NAME = 'notes_prepared');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE workflow_status ADD COLUMN notes_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER tally_fetched,
     ADD COLUMN profit_loss_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER notes_prepared,
     ADD COLUMN balance_sheet_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER profit_loss_prepared,
     ADD COLUMN directors_report_prepared TINYINT(1) NOT NULL DEFAULT 0 AFTER balance_sheet_prepared',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Create bridge_clients table if not exists (Phase 1: Security)
CREATE TABLE IF NOT EXISTS bridge_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id VARCHAR(50) NOT NULL UNIQUE,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_bridge_client_company (company_id),
    INDEX idx_bridge_client_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Add index on report_manual_inputs for faster key-value lookups
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_manual_inputs' AND INDEX_NAME = 'idx_rmi_lookup');
SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_rmi_lookup ON report_manual_inputs (company_id, fy_id, input_key)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Ensure financial_years has status column (Phase 8: fy_closure DDL removal)
SET @fy_status = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'financial_years' AND COLUMN_NAME = 'status');
SET @sql = IF(@fy_status = 0,
    'ALTER TABLE financial_years
     ADD COLUMN status ENUM(\'draft\',\'finalized\',\'closed\') NOT NULL DEFAULT \'draft\' AFTER fy_label,
     ADD COLUMN closed_by INT NULL AFTER status,
     ADD COLUMN closed_at DATETIME NULL AFTER closed_by,
     ADD COLUMN reopened_by INT NULL AFTER closed_at,
     ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by,
     ADD COLUMN closure_notes TEXT NULL AFTER reopened_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Create FY closure tables if not exist
CREATE TABLE IF NOT EXISTS fy_closing_snapshots (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS fy_closure_audit (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS fy_validation_failures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    check_name VARCHAR(100) NOT NULL,
    severity ENUM('error','warning') NOT NULL DEFAULT 'error',
    message TEXT NOT NULL,
    details TEXT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fy_val_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS fy_opening_balance_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    source_fy_id INT NOT NULL,
    populated_by INT NOT NULL,
    populated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invalidated_by INT NULL,
    invalidated_at DATETIME NULL,
    UNIQUE KEY uq_fy_obs (company_id, fy_id),
    INDEX idx_fy_obs_source (company_id, source_fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 7. Cleanup orphan workflow_status rows
DELETE ws FROM workflow_status ws
LEFT JOIN companies c ON c.id = ws.company_id
LEFT JOIN financial_years fy ON fy.id = ws.fy_id AND fy.company_id = ws.company_id
WHERE c.id IS NULL OR fy.id IS NULL;

-- 8. Cleanup duplicate financial_years (keep earliest ID per company+label)
DELETE f1 FROM financial_years f1
INNER JOIN financial_years f2
WHERE f1.company_id = f2.company_id
  AND f1.fy_label = f2.fy_label
  AND f1.id > f2.id;

-- 9. Add last_login_at column to users if missing
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

SET @login_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at');
SET @sql = IF(@login_col = 0,
    'ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER role',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
