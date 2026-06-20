-- ============================================================
-- e-BAL Entity Management Migration
-- Version: 002 — Auditor Details Tab Refactor
-- ============================================================

-- 1. auditor_master
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
    INDEX idx_auditor_frn (frn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. director_master
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
    INDEX idx_director_name (director_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. company_fy_auditors (renamed from company_fy_governance)
CREATE TABLE IF NOT EXISTS company_fy_auditors (
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
    remarks TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_auditor_fy (company_id, fy_id),
    INDEX idx_auditor_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. company_directors
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
    INDEX idx_cd_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. company_fy_signatories
CREATE TABLE IF NOT EXISTS company_fy_signatories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    director_id INT NOT NULL,
    is_signing_person TINYINT(1) NOT NULL DEFAULT 1,
    signing_order INT NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fy_signatory (company_id, fy_id, director_id),
    INDEX idx_fys_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 6. Migrate data from old company_fy_governance if it exists
SET @tbl_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_fy_governance');
SET @sql = IF(@tbl_exists > 0,
    'INSERT IGNORE INTO company_fy_auditors (company_id, fy_id, auditor_id, appointment_date, report_date, audit_type, signed_partner, signed_membership_no, udin, created_at) SELECT company_id, fy_id, auditor_id, appointment_date, report_date, audit_type, signed_partner, signed_membership_no, udin, created_at FROM company_fy_governance',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
