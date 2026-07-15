-- ============================================================
-- e-BAL Migration 011: De-duplicate tally_ledger_master
-- Date: 2026-07-15
-- Purpose: tally_ledger_master had no unique constraint on
--          (company_id, ledger_name), so a re-run Tally sync could
--          insert a duplicate row per ledger. classification_engine.php's
--          getClassifiedData() joins against this table, and a
--          duplicate row caused its GROUP BY to fan out and double-count
--          that ledger's trial balance amount -- confirmed against a
--          production data copy, where a single duplicated ledger
--          produced a matching Balance Sheet residual. Only a small
--          number of duplicated (company_id, ledger_name) pairs existed
--          as of this migration.
--
--          app/engines/classification_engine.php's query was also
--          hardened to dedupe defensively at query time regardless of
--          this migration, so this is belt-and-braces: clean data +
--          a constraint to stop it recurring.
-- Safe: Idempotent. Keeps the earliest (lowest id) row per
--       (company_id, ledger_name); deletes the rest. No DDL on page load.
-- ============================================================

-- 1. Delete duplicate rows, keeping the lowest id per (company_id, ledger_name)
DELETE t1 FROM tally_ledger_master t1
INNER JOIN tally_ledger_master t2
    ON t1.company_id = t2.company_id
    AND t1.ledger_name = t2.ledger_name
    AND t1.id > t2.id;

-- 2. Add a unique constraint so this can't silently recur
SET @idx1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tally_ledger_master' AND INDEX_NAME = 'uq_tally_ledger_master_company_ledger');
SET @sql1 = IF(@idx1 = 0,
    'ALTER TABLE tally_ledger_master ADD UNIQUE KEY uq_tally_ledger_master_company_ledger (company_id, ledger_name)',
    'SELECT 1');
PREPARE stmt FROM @sql1; EXECUTE stmt; DEALLOCATE PREPARE stmt;
