-- Migration 015: Fixed Asset Register + Depreciation Schedule (Schedule II)
--
-- Purpose: Support a per-asset register so Depreciation (SLM/WDV, Schedule II
-- useful lives, pro-rata for mid-year additions/disposals) can be computed
-- properly instead of being a flat pass-through of whatever "Depreciation"
-- TB ledger balance exists (see accounting_policies.php's prior claim of
-- Schedule II compliance that the engine didn't actually compute).
--
-- Each row is one asset (or one Tally PPE/CWIP ledger, for companies that
-- maintain per-asset ledgers in Tally, which is common) for one company+FY.
-- Rows can originate from a prior-year Excel upload (opening balances only),
-- from syncing Tally's classified PPE/CWIP ledgers (additions/disposals for
-- the year), or be entered manually.
--
-- Safe: Idempotent. No data loss. No DDL on page load -- invoked defensively
-- via ensureFixedAssetRegisterSchema(PDO $pdo) in
-- app/helpers/fixed_asset_helper.php, matching the pattern used by
-- ensureOpeningBalanceDiagnosticsSchema()/ensureShareCapitalShareholdersSchema().

CREATE TABLE IF NOT EXISTS fixed_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    asset_category VARCHAR(100) NOT NULL DEFAULT '',
    asset_description VARCHAR(255) NOT NULL DEFAULT '',
    source_ledger_name VARCHAR(255) NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    opening_gross_block DECIMAL(18,2) NOT NULL DEFAULT 0,
    opening_accumulated_depreciation DECIMAL(18,2) NOT NULL DEFAULT 0,
    additions_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
    addition_date DATE NULL,
    disposals_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
    disposal_date DATE NULL,
    is_disposed TINYINT(1) NOT NULL DEFAULT 0,
    useful_life_years DECIMAL(6,2) NULL,
    residual_value_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    depreciation_method VARCHAR(10) NOT NULL DEFAULT 'SLM',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id),
    KEY idx_source_ledger (company_id, fy_id, source_ledger_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Audit trail for prior-year Excel uploads (what was imported, by whom, when)
-- -- separate from the asset rows themselves so a re-upload doesn't erase
-- the history of what was previously imported.
CREATE TABLE IF NOT EXISTS fixed_asset_excel_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL DEFAULT '',
    row_count INT NOT NULL DEFAULT 0,
    imported_by INT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
