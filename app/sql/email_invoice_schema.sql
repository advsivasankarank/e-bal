-- ============================================================================
-- e-BAL Email & Invoice Infrastructure Migration
-- Created: 2024-06-18
-- Description: Creates tables for email logging and invoice management
-- ============================================================================

-- Email Logging Table
-- Tracks all email communications with customers
CREATE TABLE IF NOT EXISTS `email_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `email_to` VARCHAR(255) NOT NULL,
    `email_from` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_html` LONGTEXT NOT NULL,
    `template_type` VARCHAR(50) NOT NULL,
    `template_data` JSON NULL,
    `status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
    `error_message` VARCHAR(500) NULL,
    `sent_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email_user` (`user_id`),
    INDEX `idx_email_status` (`status`),
    INDEX `idx_email_created` (`created_at`),
    INDEX `idx_email_to` (`email_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice Management Table
-- Stores all generated tax invoices with GST details
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `plan_id` INT NOT NULL,
    `license_transaction_id` INT NULL,
    `invoice_number` VARCHAR(30) NOT NULL UNIQUE,
    `invoice_date` DATE NOT NULL,
    `customer_name` VARCHAR(255) NOT NULL,
    `customer_email` VARCHAR(255) NOT NULL,
    `gstin` VARCHAR(15) NULL,
    `pan` VARCHAR(10) NULL,
    `taxable_value` INT NOT NULL COMMENT 'Amount in paise',
    `cgst_amount` INT NOT NULL COMMENT 'Central GST in paise',
    `sgst_amount` INT NOT NULL COMMENT 'State GST in paise',
    `igst_amount` INT NOT NULL DEFAULT 0 COMMENT 'Integrated GST in paise',
    `total_value` INT NOT NULL COMMENT 'Total including GST in paise',
    `pdf_path` VARCHAR(500) NULL,
    `status` ENUM('draft','issued','paid','cancelled') NOT NULL DEFAULT 'draft',
    `notes` VARCHAR(500) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_invoice_user` (`user_id`),
    INDEX `idx_invoice_number` (`invoice_number`),
    INDEX `idx_invoice_date` (`invoice_date`),
    INDEX `idx_invoice_status` (`status`),
    INDEX `idx_invoice_license_tx` (`license_transaction_id`),
    CONSTRAINT `fk_invoice_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_invoice_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add invoice_id column to license_transactions if it doesn't exist
ALTER TABLE `license_transactions` ADD COLUMN `invoice_id` INT NULL AFTER `id`;
ALTER TABLE `license_transactions` ADD INDEX `idx_license_tx_invoice` (`invoice_id`);

-- ============================================================================
-- End of Migration
-- ============================================================================
