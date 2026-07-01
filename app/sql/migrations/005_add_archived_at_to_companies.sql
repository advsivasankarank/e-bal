-- ============================================================
-- e-BAL Add Archived At Column Migration
-- Version: 005
-- Date: 2026-07-01
-- Purpose: Add archived_at column to companies for soft archive
-- Safe: Idempotent. No data loss. Does not hard delete any records.
-- ============================================================

-- Add archived_at column to companies table if it does not exist
SET @col1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'archived_at');
SET @sql1 = IF(@col1 = 0,
    'ALTER TABLE companies ADD COLUMN archived_at DATETIME NULL DEFAULT NULL AFTER updated_at',
    'SELECT 1');
PREPARE stmt FROM @sql1; EXECUTE stmt; DEALLOCATE PREPARE stmt;
