-- ============================================================
-- e-BAL Partner Capital Schedule Migration
-- Version: 009
-- Purpose: Partnership/LLP entities had no data model for partners at all
--   (confirmed against production: no table, only a free-text "Designated
--   Partner" signatory-designation option reused from the director UI).
--   Mirrors the existing auditor_master/director_master +
--   company_directors pattern from migration 002.
-- Safe: New tables only (CREATE TABLE IF NOT EXISTS). No existing data
--   touched.
-- ============================================================

-- 1. partner_master — the partner roster, reusable across a user's companies
CREATE TABLE IF NOT EXISTS partner_master (
    partner_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NULL,
    partner_name VARCHAR(255) NOT NULL DEFAULT '',
    pan VARCHAR(20) NOT NULL DEFAULT '',
    email VARCHAR(255) NOT NULL DEFAULT '',
    mobile VARCHAR(20) NOT NULL DEFAULT '',
    address TEXT NULL,
    active_status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_partner_owner (owner_user_id),
    INDEX idx_partner_name (partner_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. company_partners — which partners belong to which company
CREATE TABLE IF NOT EXISTS company_partners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    partner_id INT NOT NULL,
    designation VARCHAR(100) NOT NULL DEFAULT 'Partner',
    appointment_date DATE NULL,
    cessation_date DATE NULL,
    active_status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_partner (company_id, partner_id),
    INDEX idx_cp_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. partner_capital_movements — the per-FY schedule data (Note 3a/3b of the
--    ICAI illustrative format: opening, contributed, remuneration, interest,
--    withdrawals). Deliberately does NOT store share_of_profit or
--    closing_balance -- those are computed at read time from the classified
--    P&L profit and this table's own columns, so they can never drift out of
--    sync with the statement they're meant to reconcile to.
CREATE TABLE IF NOT EXISTS partner_capital_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    partner_id INT NOT NULL,
    share_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    opening_balance DECIMAL(18,2) NOT NULL DEFAULT 0,
    capital_introduced DECIMAL(18,2) NOT NULL DEFAULT 0,
    remuneration DECIMAL(18,2) NOT NULL DEFAULT 0,
    interest_on_capital DECIMAL(18,2) NOT NULL DEFAULT 0,
    withdrawals DECIMAL(18,2) NOT NULL DEFAULT 0,
    created_by_user_id INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_partner_fy (company_id, fy_id, partner_id),
    INDEX idx_pcm_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
