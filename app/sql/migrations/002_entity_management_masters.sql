-- ============================================================
-- e-BAL Entity Management Master Tables Migration
-- Version: 002
-- Date: 2026-06-21
-- Purpose: Auditor Master, Director Master, FY Governance,
--          Company Directors, FY Signatories
-- ============================================================

-- 1. Auditor Master — reusable across companies and FYs
CREATE TABLE IF NOT EXISTS auditor_master (
    auditor_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NULL,
    firm_name VARCHAR(255) NOT NULL DEFAULT '',
    frn VARCHAR(50) NOT NULL DEFAULT '',
    partner_name VARCHAR(255) NOT NULL DEFAULT '',
    membership_no VARCHAR(50) NOT NULL DEFAULT '',
    address TEXT NULL,
    city VARCHAR(100) NOT NULL DEFAULT '',
    state VARCHAR(100) NOT NULL DEFAULT '',
    email VARCHAR(255) NOT NULL DEFAULT '',
    mobile VARCHAR(20) NOT NULL DEFAULT '',
    peer_review_no VARCHAR(50) NOT NULL DEFAULT '',
    peer_review_valid_upto DATE NULL,
    active_status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_auditor_owner (owner_user_id),
    INDEX idx_auditor_firm (firm_name),
    INDEX idx_auditor_frn (frn),
    INDEX idx_auditor_active (active_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Director Master — reusable across companies
CREATE TABLE IF NOT EXISTS director_master (
    director_id INT AUTO_INCREMENT PRIMARY KEY,
    owner_user_id INT NULL,
    director_name VARCHAR(255) NOT NULL DEFAULT '',
    din VARCHAR(50) NOT NULL DEFAULT '',
    pan VARCHAR(20) NOT NULL DEFAULT '',
    email VARCHAR(255) NOT NULL DEFAULT '',
    mobile VARCHAR(20) NOT NULL DEFAULT '',
    address TEXT NULL,
    active_status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_director_owner (owner_user_id),
    INDEX idx_director_name (director_name),
    INDEX idx_director_din (din),
    INDEX idx_director_active (active_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Company FY Governance — auditor appointment per FY
CREATE TABLE IF NOT EXISTS company_fy_governance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    auditor_id INT NULL,
    appointment_date DATE NULL,
    report_date DATE NULL,
    audit_type VARCHAR(50) NOT NULL DEFAULT 'statutory',
    signed_partner VARCHAR(255) NOT NULL DEFAULT '',
    signed_membership_no VARCHAR(50) NOT NULL DEFAULT '',
    udin VARCHAR(50) NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_governance_fy (company_id, fy_id),
    INDEX idx_governance_company (company_id),
    INDEX idx_governance_fy (fy_id),
    INDEX idx_governance_auditor (auditor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Company Directors — director appointments per company
CREATE TABLE IF NOT EXISTS company_directors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    director_id INT NOT NULL,
    designation VARCHAR(100) NOT NULL DEFAULT '',
    appointment_date DATE NULL,
    cessation_date DATE NULL,
    signing_authority VARCHAR(255) NOT NULL DEFAULT '',
    signing_order INT NOT NULL DEFAULT 0,
    active_status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_company_director (company_id, director_id),
    INDEX idx_cd_company (company_id),
    INDEX idx_cd_director (director_id),
    INDEX idx_cd_active (active_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. Company FY Signatories — signing persons per FY
CREATE TABLE IF NOT EXISTS company_fy_signatories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    director_id INT NOT NULL,
    is_signing_person TINYINT(1) NOT NULL DEFAULT 1,
    signing_order INT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fy_signatory (company_id, fy_id, director_id),
    INDEX idx_fys_company (company_id),
    INDEX idx_fys_fy (fy_id),
    INDEX idx_fys_director (director_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Add industry and incorporation_date to companies if not exists
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'industry');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE companies ADD COLUMN industry VARCHAR(100) NULL AFTER state_code,
     ADD COLUMN incorporation_date DATE NULL AFTER industry',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- DATA MIGRATION: Seed auditor/director from existing company data
-- Run AFTER applying the schema changes above
-- ============================================================

-- Seed auditor_master from existing statutory_auditor_name fields
INSERT IGNORE INTO auditor_master (owner_user_id, firm_name, partner_name, frn, membership_no, active_status)
SELECT DISTINCT
    c.owner_user_id,
    COALESCE(c.statutory_auditor_firm, ''),
    COALESCE(c.statutory_auditor_name, ''),
    COALESCE(c.statutory_auditor_frn, ''),
    COALESCE(c.statutory_auditor_membership_no, ''),
    1
FROM companies c
WHERE c.statutory_auditor_name IS NOT NULL AND c.statutory_auditor_name != ''
  AND NOT EXISTS (
    SELECT 1 FROM auditor_master am
    WHERE am.firm_name = COALESCE(c.statutory_auditor_firm, '')
      AND am.partner_name = c.statutory_auditor_name
      AND am.owner_user_id = c.owner_user_id
  );

-- Seed director_master from existing signatory_1_name and signatory_2_name
INSERT IGNORE INTO director_master (owner_user_id, director_name, din, active_status)
SELECT DISTINCT
    c.owner_user_id,
    c.signatory_1_name,
    COALESCE(c.signatory_1_id_no, ''),
    1
FROM companies c
WHERE c.signatory_1_name IS NOT NULL AND c.signatory_1_name != ''
  AND NOT EXISTS (
    SELECT 1 FROM director_master dm
    WHERE dm.director_name = c.signatory_1_name
      AND dm.din = COALESCE(c.signatory_1_id_no, '')
      AND dm.owner_user_id = c.owner_user_id
  );

INSERT IGNORE INTO director_master (owner_user_id, director_name, din, active_status)
SELECT DISTINCT
    c.owner_user_id,
    c.signatory_2_name,
    COALESCE(c.signatory_2_id_no, ''),
    1
FROM companies c
WHERE c.signatory_2_name IS NOT NULL AND c.signatory_2_name != ''
  AND NOT EXISTS (
    SELECT 1 FROM director_master dm
    WHERE dm.director_name = c.signatory_2_name
      AND dm.din = COALESCE(c.signatory_2_id_no, '')
      AND dm.owner_user_id = c.owner_user_id
  );
