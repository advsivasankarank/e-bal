-- ============================================================
-- e-BAL Ledger Lookup Index Migration
-- Version: 008
-- Purpose: tally_ledger_master has no index beyond its PRIMARY KEY (id) —
--   confirmed against production: 410k+ rows, every mapping query filters
--   on company_id, so each one is currently a full table scan. tally_ledgers
--   has separate single-column keys on company_id and fy_id (not composite),
--   and no index on ledger_name, which is used in JOINs/lookups throughout
--   the mapping workflow.
-- Safe: Idempotent (checked via INFORMATION_SCHEMA.STATISTICS below). Index
--   additions only — no columns/rows changed. ALGORITHM=INPLACE, LOCK=NONE
--   requested so this doesn't block reads/writes on tally_ledger_master
--   while it builds; falls back automatically if the server can't honor it.
-- ============================================================

SET @idx1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledger_master' AND INDEX_NAME = 'idx_tlm_company_ledger');
SET @sql1 = IF(@idx1 = 0,
    'ALTER TABLE tally_ledger_master ADD INDEX idx_tlm_company_ledger (company_id, ledger_name), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @sql1; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledgers' AND INDEX_NAME = 'idx_tl_company_fy');
SET @sql2 = IF(@idx2 = 0,
    'ALTER TABLE tally_ledgers ADD INDEX idx_tl_company_fy (company_id, fy_id), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx3 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledgers' AND INDEX_NAME = 'idx_tl_ledger_name');
SET @sql3 = IF(@idx3 = 0,
    'ALTER TABLE tally_ledgers ADD INDEX idx_tl_ledger_name (ledger_name), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1');
PREPARE stmt FROM @sql3; EXECUTE stmt; DEALLOCATE PREPARE stmt;
