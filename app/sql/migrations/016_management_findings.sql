-- Migration 016: Management Findings & Recommendations
--
-- Purpose: A client-facing "Management Letter" deliverable, distinct from
-- Review Centre (internal CA working notes) -- surfaces accounting
-- inconsistencies/gaps found while preparing the statements (e.g. the
-- Asset Register's computed net block not reconciling with Tally's TB)
-- as a formal disclosure to the client, with the CA's recommendation.
--
-- Findings are auto-detected from the existing validation/diagnostics
-- engines (buildBsDiagnostics(), computeOpeningBalanceDiagnostics()) --
-- no duplicated detection logic -- staged as 'pending_review' and only
-- reach the client-facing report once a CA explicitly includes them.
-- finding_key groups the same underlying issue across financial years
-- (numbers normalised out of the detected message) so recurrence can be
-- shown: "also raised in FY23-24, still unresolved".
--
-- Safe: Idempotent. No data loss. No DDL on page load -- invoked
-- defensively via ensureManagementFindingsSchema(PDO $pdo) in
-- app/helpers/management_findings_helper.php.

CREATE TABLE IF NOT EXISTS management_findings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    finding_key VARCHAR(80) NOT NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'auto',
    severity VARCHAR(20) NOT NULL DEFAULT 'observation',
    check_code VARCHAR(60) NOT NULL DEFAULT '',
    title VARCHAR(255) NOT NULL DEFAULT '',
    detected_message TEXT NOT NULL,
    ca_recommendation TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
    exclusion_reason VARCHAR(255) NULL,
    first_raised_fy_id INT NULL,
    resolved_fy_id INT NULL,
    created_by INT NULL,
    decided_by INT NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id),
    KEY idx_company_finding_key (company_id, finding_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
