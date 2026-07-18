-- Migration 017: Deferred Tax Calculator (AS-22 timing differences)
--
-- Purpose: buildDeferredTaxSection() in fs_engine.php was a pass-through of
-- whatever DTA/DTL ledger balance existed in the Trial Balance -- not an
-- independent AS-22 recomputation (disclosed as a limitation earlier this
-- session). This adds real timing-difference computation:
--   1. Depreciation timing difference -- computed automatically by comparing
--      the Depreciation Calculator's book WDV (Schedule II) against a
--      parallel Income-tax Act block-of-assets WDV computed from the SAME
--      asset register (app/helpers/deferred_tax_helper.php). Not stored --
--      always computed live from fixed_assets so it can never go stale
--      relative to the asset register.
--   2. Other timing differences (Section 43B disallowances, provisions,
--      etc.) and carried-forward losses -- manual entries, since these
--      aren't derivable from Tally at all.
--
-- Safe: Idempotent. No data loss. No DDL on page load -- invoked
-- defensively via ensureDeferredTaxItemsSchema(PDO $pdo) in
-- app/helpers/deferred_tax_helper.php.

CREATE TABLE IF NOT EXISTS deferred_tax_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    fy_id INT NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'other',
    description VARCHAR(255) NOT NULL DEFAULT '',
    book_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    classification VARCHAR(3) NOT NULL DEFAULT 'DTA',
    virtual_certainty_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_company_fy (company_id, fy_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
