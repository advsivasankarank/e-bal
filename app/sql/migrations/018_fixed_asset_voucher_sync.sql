-- Migration 018: Fixed Asset voucher-level sync
--
-- Purpose: syncFixedAssetsFromTallyClassification() (the old "Sync from
-- Tally" button) inferred additions/disposals from a ledger's opening-vs-
-- closing Trial Balance movement for the year -- not the actual purchase/
-- sale transactions, and used the SAME movement's "previous" figure as an
-- opening balance, which conflicts with this app's Excel-only opening
-- balance policy. This migration adds the columns needed to instead sync
-- individual Tally VOUCHERS (Purchase/Journal/Payment additions, Sales/
-- Journal/Receipt disposals) against Fixed-Asset-classified ledgers, one
-- fixed_assets row per voucher, so each addition/disposal is pro-rated
-- from its own real Tally voucher date rather than a single ledger-level
-- date approximation.
--
-- Safe: idempotent (checked defensively in PHP via information_schema
-- before each ALTER, same pattern as report_manual_helper.php), no data
-- loss, no runtime DDL on page load.

ALTER TABLE fixed_assets ADD COLUMN tally_voucher_entry_id BIGINT NULL AFTER source_ledger_name;
ALTER TABLE fixed_assets ADD COLUMN voucher_type VARCHAR(100) NULL AFTER tally_voucher_entry_id;
ALTER TABLE fixed_assets ADD COLUMN voucher_classification VARCHAR(20) NULL AFTER voucher_type;
ALTER TABLE fixed_assets ADD COLUMN voucher_narration VARCHAR(500) NULL AFTER voucher_classification;
ALTER TABLE fixed_assets ADD UNIQUE KEY uk_fa_voucher_entry (company_id, fy_id, tally_voucher_entry_id);
