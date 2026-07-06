-- =====================================================
-- Migration 006: SaaS Demo Mode Schema
-- Adds demo user support, demo leads, and upgrade tracking
-- =====================================================

-- 1. Add demo columns to users table (non-destructive, nullable defaults)
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_demo'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users
        ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER company_owner_id,
        ADD COLUMN demo_status VARCHAR(30) NULL AFTER is_demo,
        ADD COLUMN demo_consent_accepted_at DATETIME NULL AFTER demo_status,
        ADD COLUMN demo_started_at DATETIME NULL AFTER demo_consent_accepted_at,
        ADD COLUMN demo_expires_at DATETIME NULL AFTER demo_started_at,
        ADD COLUMN upgrade_requested_at DATETIME NULL AFTER demo_expires_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Create demo_leads table (if not exists)
CREATE TABLE IF NOT EXISTS demo_leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    name VARCHAR(120) NOT NULL,
    mobile VARCHAR(20) NOT NULL,
    email VARCHAR(190) NOT NULL,
    address_city VARCHAR(120) NULL,
    profession_type VARCHAR(60) NULL,
    firm_company_name VARCHAR(180) NULL,
    source VARCHAR(60) NOT NULL DEFAULT 'e-BAL Demo',
    status VARCHAR(40) NOT NULL DEFAULT 'Demo Access Created',
    interest_level VARCHAR(20) NULL DEFAULT 'Warm',
    consent_accepted_at DATETIME NULL,
    demo_requested_at DATETIME NULL,
    demo_started_at DATETIME NULL,
    demo_expires_at DATETIME NULL,
    last_demo_activity_at DATETIME NULL,
    upgrade_requested_at DATETIME NULL,
    retain_demo_data TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_demo_leads_email (email),
    INDEX idx_demo_leads_mobile (mobile),
    INDEX idx_demo_leads_user (user_id),
    INDEX idx_demo_leads_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create demo_upgrade_requests table (if not exists)
CREATE TABLE IF NOT EXISTS demo_upgrade_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lead_id INT NULL,
    preferred_plan VARCHAR(40) NULL,
    message TEXT NULL,
    retain_demo_data TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    requested_at DATETIME NOT NULL,
    converted_at DATETIME NULL,
    converted_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_demo_upgrade_user (user_id),
    INDEX idx_demo_upgrade_lead (lead_id),
    INDEX idx_demo_upgrade_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
