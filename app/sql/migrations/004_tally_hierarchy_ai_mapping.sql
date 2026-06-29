-- ============================================================
-- e-BAL Tally Group Hierarchy Migration
-- Version: 004
-- Date: 2026-06-30
-- Purpose: Add Tally group hierarchy fields for ledger mapping
-- Safe: Idempotent. No data loss.
-- ============================================================

-- 1. Add hierarchy fields to tally_ledger_master (if not exists)
SET @col1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledger_master' AND COLUMN_NAME = 'primary_group');
SET @sql1 = IF(@col1 = 0,
    'ALTER TABLE tally_ledger_master ADD COLUMN primary_group VARCHAR(255) NOT NULL DEFAULT \'\' AFTER parent_group',
    'SELECT 1');
PREPARE stmt FROM @sql1; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledger_master' AND COLUMN_NAME = 'tally_group_path');
SET @sql2 = IF(@col2 = 0,
    'ALTER TABLE tally_ledger_master ADD COLUMN tally_group_path TEXT NULL AFTER primary_group',
    'SELECT 1');
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col3 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledger_master' AND COLUMN_NAME = 'tally_group_depth');
SET @sql3 = IF(@col3 = 0,
    'ALTER TABLE tally_ledger_master ADD COLUMN tally_group_depth INT NOT NULL DEFAULT 0 AFTER tally_group_path',
    'SELECT 1');
PREPARE stmt FROM @sql3; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col4 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledger_master' AND COLUMN_NAME = 'tally_root_type');
SET @sql4 = IF(@col4 = 0,
    'ALTER TABLE tally_ledger_master ADD COLUMN tally_root_type VARCHAR(50) NOT NULL DEFAULT \'\' AFTER tally_group_depth',
    'SELECT 1');
PREPARE stmt FROM @sql4; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add hierarchy fields to tally_ledgers (if not exists)
SET @col5 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledgers' AND COLUMN_NAME = 'tally_group_path');
SET @sql5 = IF(@col5 = 0,
    'ALTER TABLE tally_ledgers ADD COLUMN tally_group_path TEXT NULL AFTER parent_group',
    'SELECT 1');
PREPARE stmt FROM @sql5; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col6 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledgers' AND COLUMN_NAME = 'tally_group_depth');
SET @sql6 = IF(@col6 = 0,
    'ALTER TABLE tally_ledgers ADD COLUMN tally_group_depth INT NOT NULL DEFAULT 0 AFTER tally_group_path',
    'SELECT 1');
PREPARE stmt FROM @sql6; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col7 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledgers' AND COLUMN_NAME = 'tally_root_type');
SET @sql7 = IF(@col7 = 0,
    'ALTER TABLE tally_ledgers ADD COLUMN tally_root_type VARCHAR(50) NOT NULL DEFAULT \'\' AFTER tally_group_depth',
    'SELECT 1');
PREPARE stmt FROM @sql7; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add AI suggestion fields to ledger_mapping (if not exists)
SET @col8 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'ai_suggested_head');
SET @sql8 = IF(@col8 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN ai_suggested_head VARCHAR(100) NULL AFTER mapping_reason',
    'SELECT 1');
PREPARE stmt FROM @sql8; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col9 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'ai_confidence');
SET @sql9 = IF(@col9 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN ai_confidence DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER ai_suggested_head',
    'SELECT 1');
PREPARE stmt FROM @sql9; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col10 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'ai_reason');
SET @sql10 = IF(@col10 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN ai_reason TEXT NULL AFTER ai_confidence',
    'SELECT 1');
PREPARE stmt FROM @sql10; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col11 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'ai_risk');
SET @sql11 = IF(@col11 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN ai_risk VARCHAR(20) NOT NULL DEFAULT \'Low\' AFTER ai_reason',
    'SELECT 1');
PREPARE stmt FROM @sql11; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col12 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'ai_source');
SET @sql12 = IF(@col12 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN ai_source VARCHAR(50) NOT NULL DEFAULT \'rule\' AFTER ai_risk',
    'SELECT 1');
PREPARE stmt FROM @sql12; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Add hierarchy columns to ledger_mapping for group-based filtering
SET @col13 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ledger_mapping' AND COLUMN_NAME = 'tally_parent_group');
SET @sql13 = IF(@col13 = 0,
    'ALTER TABLE ledger_mapping ADD COLUMN tally_parent_group VARCHAR(255) NULL AFTER ai_source',
    'SELECT 1');
PREPARE stmt FROM @sql13; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Create tally_group_master table for storing Tally group hierarchy
CREATE TABLE IF NOT EXISTS tally_group_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    group_name VARCHAR(255) NOT NULL,
    parent_group VARCHAR(255) NOT NULL DEFAULT '',
    primary_group VARCHAR(255) NOT NULL DEFAULT '',
    group_path TEXT NULL,
    group_depth INT NOT NULL DEFAULT 0,
    root_type VARCHAR(50) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group (company_id, group_name),
    INDEX idx_group_company (company_id),
    INDEX idx_group_parent (company_id, parent_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Create ai_mapping_audit table for tracking AI suggestions
CREATE TABLE IF NOT EXISTS ai_mapping_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ledger_id INT NULL,
    ledger_name VARCHAR(255) NOT NULL,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    suggested_head VARCHAR(100) NOT NULL,
    accepted_head VARCHAR(100) NULL,
    confidence DECIMAL(5,2) NOT NULL DEFAULT 0,
    reason TEXT NULL,
    risk VARCHAR(20) NOT NULL DEFAULT 'Low',
    source VARCHAR(50) NOT NULL DEFAULT 'rule',
    accepted_by_user_id INT NULL,
    accepted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_company (company_id, fy_id),
    INDEX idx_audit_ledger (ledger_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
