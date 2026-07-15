-- ============================================================
-- e-BAL Migration 012: Share Capital Shareholders (>5% holding)
-- Date: 2026-07-15
-- Purpose: Schedule III Note 1 (Share Capital) requires disclosure of
--          shareholders holding more than 5% of shares. This is a
--          repeatable list per company/FY, which the existing
--          report_manual_inputs key-value store isn't a good fit for
--          (no precedent for JSON/indexed-key lists in this codebase --
--          the closest, review_timeline_*, packs rows into pipe-delimited
--          strings, which isn't queryable per-field). A dedicated table
--          matches the structural style already used for other per-FY
--          multi-row data (e.g. fy_opening_balance_sources).
-- Safe: Idempotent. No DDL on page load.
-- ============================================================

CREATE TABLE IF NOT EXISTS share_capital_shareholders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    row_order INT NOT NULL DEFAULT 0,
    shareholder_name VARCHAR(255) NOT NULL,
    shares_held DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
