-- ============================================================
-- e-BAL Migration 013: Share Capital Classes (Equity / Preference etc.)
-- Date: 2026-07-17
-- Purpose: Schedule III Note 1 (Share Capital) requires authorised,
--          issued and paid-up capital to be broken up by share type
--          (Equity Shares, Preference Shares, ...), each with its own
--          face value and share count, not a single blended figure.
--          Same repeatable-per-FY-row pattern as share_capital_shareholders.
-- Safe: Idempotent. No DDL on page load.
-- ============================================================

CREATE TABLE IF NOT EXISTS share_capital_classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    row_order INT NOT NULL DEFAULT 0,
    share_type VARCHAR(150) NOT NULL,
    face_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    authorised_shares DECIMAL(18,2) NOT NULL DEFAULT 0,
    opening_shares DECIMAL(18,2) NOT NULL DEFAULT 0,
    issued_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
    bought_back_during_year DECIMAL(18,2) NOT NULL DEFAULT 0,
    closing_shares DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
