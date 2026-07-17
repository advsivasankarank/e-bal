-- ============================================================
-- e-BAL Migration 014: Opening Balance Diagnostics
-- Date: 2026-07-17
-- Purpose: Tally's own Trial Balance XML export reports both an
--          opening AND a closing balance per ledger (DSPOPDRAMT/
--          DSPOPCRAMT vs DSPCLDRAMT/DSPCLCRAMT), but the import
--          parsers only ever captured the closing figure. The
--          existing tally_ledgers.opening_amount/opening_dr_cr
--          columns are NOT Tally's own figure -- they are e-BAL's
--          own carry-forward, populated by copying the PREVIOUS
--          FY's closing balance already stored in e-BAL (see
--          loadOpeningRowsFromStoredYear() in tb_import_helper.php),
--          which is why they're always 0 for a company's first
--          imported year even when the ledger has real history in
--          Tally.
--
--          tally_reported_opening_amount/_dr_cr captures Tally's own
--          native opening figure directly from the import, so the two
--          can be reconciled: if e-BAL's carried-forward opening and
--          Tally's own reported opening disagree, that's a genuine
--          data-integrity signal worth surfacing to the CA, not
--          silently overwriting either side.
-- Safe: Idempotent. No data loss. No DDL on page load (also created
--       defensively at runtime by
--       ensureOpeningBalanceDiagnosticsSchema() in
--       app/helpers/opening_balance_diagnostics_helper.php).
-- ============================================================

ALTER TABLE tally_ledgers
    ADD COLUMN IF NOT EXISTS tally_reported_opening_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER opening_dr_cr,
    ADD COLUMN IF NOT EXISTS tally_reported_opening_dr_cr VARCHAR(2) NULL AFTER tally_reported_opening_amount;

CREATE TABLE IF NOT EXISTS opening_balance_diagnostics_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    ledger_name VARCHAR(255) NOT NULL,
    tally_opening DECIMAL(18,2) NOT NULL DEFAULT 0,
    app_expected_opening DECIMAL(18,2) NOT NULL DEFAULT 0,
    difference DECIMAL(18,2) NOT NULL DEFAULT 0,
    resolution VARCHAR(20) NOT NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
