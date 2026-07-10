-- ============================================================
-- e-BAL Migration 007: Mapping Workbench Schema
-- Date: 2026-07-10
-- Purpose: Add metadata columns to ledger_mapping and create
--          mapping_learning table for AI-assisted mapping.
-- Safe: Idempotent. No data loss. No DDL on page load.
-- ============================================================

-- 1. Add mapping_source column
SET @col1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'mapping_source');
SET @sql1 = IF(@col1 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN mapping_source VARCHAR(50) NOT NULL DEFAULT \'manual\' AFTER schedule_code',
    'SELECT 1');
PREPARE stmt FROM @sql1; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add confidence_score column
SET @col2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'confidence_score');
SET @sql2 = IF(@col2 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER mapping_source',
    'SELECT 1');
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add mapping_reason column
SET @col3 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'mapping_reason');
SET @sql3 = IF(@col3 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN mapping_reason TEXT NULL AFTER confidence_score',
    'SELECT 1');
PREPARE stmt FROM @sql3; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Add remember_scope column
SET @col4 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'remember_scope');
SET @sql4 = IF(@col4 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN remember_scope VARCHAR(20) NULL AFTER mapping_reason',
    'SELECT 1');
PREPARE stmt FROM @sql4; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Add approved_by_user_id column
SET @col5 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'approved_by_user_id');
SET @sql5 = IF(@col5 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN approved_by_user_id INT NULL AFTER remember_scope',
    'SELECT 1');
PREPARE stmt FROM @sql5; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6. Add approved_at column
SET @col6 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'approved_at');
SET @sql6 = IF(@col6 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN approved_at DATETIME NULL AFTER approved_by_user_id',
    'SELECT 1');
PREPARE stmt FROM @sql6; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7. Add override_parent_group column (if not already present from 004)
SET @col7 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'override_parent_group');
SET @sql7 = IF(@col7 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN override_parent_group TINYINT(1) NOT NULL DEFAULT 0 AFTER approved_at',
    'SELECT 1');
PREPARE stmt FROM @sql7; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8. Create mapping_learning table (if not exists)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
