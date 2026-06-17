-- Password Reset System Schema Changes
-- Run these to add password reset functionality to existing databases

-- Add password reset columns to users table
ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL AFTER password;
ALTER TABLE users ADD COLUMN reset_token_expires_at TIMESTAMP NULL AFTER reset_token;

-- Create index on reset_token for faster lookups
CREATE INDEX idx_users_reset_token ON users(reset_token);
CREATE INDEX idx_users_reset_token_expires ON users(reset_token_expires_at);

-- Grace Period System Schema Changes
-- Add grace period columns to licenses table

ALTER TABLE licenses ADD COLUMN grace_period_active TINYINT(1) DEFAULT 0 AFTER status;
ALTER TABLE licenses ADD COLUMN grace_period_expires_at DATE NULL AFTER grace_period_active;

-- Create index on grace period columns for efficient queries
CREATE INDEX idx_licenses_grace_period_active ON licenses(grace_period_active);
CREATE INDEX idx_licenses_grace_period_expires ON licenses(grace_period_expires_at);

-- Optional: Verify the changes
-- SHOW COLUMNS FROM users;
-- SHOW COLUMNS FROM licenses;
