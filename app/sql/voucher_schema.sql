-- Voucher master types
CREATE TABLE IF NOT EXISTS voucher_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    tally_guid VARCHAR(64) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vtype_company (company_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Voucher headers
CREATE TABLE IF NOT EXISTS vouchers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    tally_guid VARCHAR(64) NOT NULL,
    voucher_type VARCHAR(100) NOT NULL,
    voucher_number VARCHAR(100) NOT NULL DEFAULT '',
    date DATE NOT NULL,
    effective_date DATE DEFAULT NULL,
    narration TEXT DEFAULT NULL,
    party_ledger_name VARCHAR(255) DEFAULT NULL,
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    master_id INT NOT NULL DEFAULT 0,
    altered_date DATETIME DEFAULT NULL,
    created_date DATETIME DEFAULT NULL,
    raw_xml LONGTEXT DEFAULT NULL,
    source ENUM('odbc','xml') NOT NULL DEFAULT 'xml',
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_v_company_fy (company_id, fy_id),
    INDEX idx_v_date (date),
    INDEX idx_v_type (voucher_type),
    INDEX idx_v_guid (tally_guid),
    INDEX idx_v_altered (altered_date),
    UNIQUE KEY uk_v_guid (tally_guid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Voucher line entries (ledger allocations)
CREATE TABLE IF NOT EXISTS voucher_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    voucher_id BIGINT NOT NULL,
    company_id INT NOT NULL,
    ledger_name VARCHAR(255) NOT NULL,
    parent_group VARCHAR(255) DEFAULT NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    dr_cr ENUM('DR','CR') NOT NULL,
    bill_type VARCHAR(50) DEFAULT NULL,
    bill_name VARCHAR(100) DEFAULT NULL,
    cost_centre VARCHAR(100) DEFAULT NULL,
    stock_item_name VARCHAR(255) DEFAULT NULL,
    quantity DECIMAL(15,4) DEFAULT NULL,
    rate DECIMAL(15,4) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ve_voucher (voucher_id),
    INDEX idx_ve_company (company_id),
    INDEX idx_ve_ledger (ledger_name),
    CONSTRAINT fk_ve_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Incremental sync tracking per company+FY
CREATE TABLE IF NOT EXISTS voucher_sync_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    last_synced_at DATETIME DEFAULT NULL,
    last_altered_synced DATETIME DEFAULT NULL,
    odbc_available TINYINT(1) NOT NULL DEFAULT 0,
    total_vouchers INT NOT NULL DEFAULT 0,
    synced_vouchers INT NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vss_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add voucher_synced column to workflow_status if not exists
ALTER TABLE workflow_status
    ADD COLUMN IF NOT EXISTS voucher_synced TINYINT(1) NOT NULL DEFAULT 0 AFTER ledger_fetched;
