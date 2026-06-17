-- Financial Year Closure & Carry Forward Schema
-- Version 1.0

-- ===================================================
-- 1. Add status/closure columns to financial_years
-- ===================================================
ALTER TABLE financial_years
    ADD COLUMN IF NOT EXISTS status ENUM('draft','finalized','closed') NOT NULL DEFAULT 'draft' AFTER fy_label,
    ADD COLUMN IF NOT EXISTS closed_by INT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS closed_at DATETIME NULL AFTER closed_by,
    ADD COLUMN IF NOT EXISTS reopened_by INT NULL AFTER closed_at,
    ADD COLUMN IF NOT EXISTS reopened_at DATETIME NULL AFTER reopened_by,
    ADD COLUMN IF NOT EXISTS closure_notes TEXT NULL AFTER reopened_at;

-- ===================================================
-- 2. Closing balance snapshots
-- Stores per-schedule-code closing balances at FY end
-- ===================================================
CREATE TABLE IF NOT EXISTS fy_closing_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    schedule_code VARCHAR(50) NOT NULL,
    ledger_name VARCHAR(255) NOT NULL DEFAULT '',
    parent_group VARCHAR(255) NOT NULL DEFAULT '',
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    dr_cr ENUM('DR','CR') NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fy_snapshot (company_id, fy_id, schedule_code, ledger_name),
    INDEX idx_fy_snapshot_fy (company_id, fy_id),
    INDEX idx_fy_snapshot_code (company_id, fy_id, schedule_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================================================
-- 3. FY closure audit trail
-- ===================================================
CREATE TABLE IF NOT EXISTS fy_closure_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    action ENUM('closed','reopened','snapshot_regenerated') NOT NULL,
    performed_by INT NOT NULL,
    performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason TEXT NULL,
    validation_summary TEXT NULL,
    INDEX idx_closure_audit_fy (company_id, fy_id),
    INDEX idx_closure_audit_date (performed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================================================
-- 4. FY validation failures (pre-closure checks)
-- ===================================================
CREATE TABLE IF NOT EXISTS fy_validation_failures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    check_name VARCHAR(100) NOT NULL,
    severity ENUM('error','warning') NOT NULL DEFAULT 'error',
    message TEXT NOT NULL,
    details TEXT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fy_val_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===================================================
-- 5. Opening balance markers for next FY
-- Unlike closing snapshots, this stores which FY
-- contributed opening balances, so carry-forward
-- can be traced and invalidated on reopen.
-- ===================================================
CREATE TABLE IF NOT EXISTS fy_opening_balance_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL COMMENT 'The FY whose opening balances were populated',
    source_fy_id INT NOT NULL COMMENT 'The closed FY from which balances were carried',
    populated_by INT NOT NULL COMMENT 'User who triggered the carry-forward',
    populated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invalidated_by INT NULL,
    invalidated_at DATETIME NULL,
    UNIQUE KEY uq_fy_obs (company_id, fy_id),
    INDEX idx_fy_obs_source (company_id, source_fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
