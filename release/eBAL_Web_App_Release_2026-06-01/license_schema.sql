-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'admin',
    company_owner_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Plans table
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    price_inr INT NOT NULL DEFAULT 0,
    company_limit INT NOT NULL DEFAULT 0,
    user_limit INT NOT NULL DEFAULT 0,
    ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Licenses table
CREATE TABLE IF NOT EXISTS licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    plan VARCHAR(40) NOT NULL,
    company_limit INT NOT NULL DEFAULT 0,
    user_limit INT NOT NULL DEFAULT 0,
    ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
    expires_at DATE NOT NULL,
    status ENUM('active','expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license_user (user_id),
    INDEX idx_license_status (status)
);

-- Companies ownership (optional for per-owner limits)
ALTER TABLE companies
    ADD COLUMN IF NOT EXISTS owner_user_id INT NULL AFTER name;

-- Seed plans
INSERT INTO plans (code, name, price_inr, company_limit, user_limit, ai_enabled)
VALUES
('starter', 'Starter', 2999, 5, 1, 0),
('professional', 'Professional', 4999, 10, 3, 1),
('pro_plus', 'Pro Plus', 9999, 999, 5, 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    price_inr = VALUES(price_inr),
    company_limit = VALUES(company_limit),
    user_limit = VALUES(user_limit),
    ai_enabled = VALUES(ai_enabled);
