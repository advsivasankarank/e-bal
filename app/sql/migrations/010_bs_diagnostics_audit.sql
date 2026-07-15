-- ============================================================
-- e-BAL Migration 010: BS Diagnostics Audit Log
-- Date: 2026-07-15
-- Purpose: Record who applied which fix (auto-suggested or manual)
--          from the Balance Sheet diff drill-down panel, with
--          before/after note mapping, for statutory audit trail.
-- Safe: Idempotent. No data loss. No DDL on page load
--       (also created defensively at runtime by
--       ensureBsDiagnosticsAuditSchema() in
--       app/helpers/bs_diagnostics_helper.php).
-- ============================================================

CREATE TABLE IF NOT EXISTS bs_diagnostics_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    issue_id VARCHAR(191) NOT NULL,
    action VARCHAR(20) NOT NULL,
    ledger_name VARCHAR(255) NOT NULL,
    before_note VARCHAR(100) NOT NULL DEFAULT '',
    after_note VARCHAR(100) NOT NULL DEFAULT '',
    user_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
