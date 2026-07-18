-- Migration 019: allow 'bridge' as a vouchers.source value
--
-- Purpose: bridge_voucher.php stores vouchers the Smart Bridge already
-- fetched and pushed client-side (real fix for the fact that vouchers,
-- unlike ledgers/TB, were never actually delivered to the server -- see
-- tally_bridge_exe/ui_app.py's _run_sync_inner()). The existing
-- source ENUM('odbc','xml') only describes how the SERVER itself fetched
-- vouchers (never actually reachable for a remote server); 'bridge'
-- distinguishes bridge-pushed data for traceability.
--
-- Safe: widening an ENUM is non-destructive; existing 'odbc'/'xml' rows
-- are unaffected.

ALTER TABLE vouchers MODIFY COLUMN source ENUM('odbc','xml','bridge') NOT NULL DEFAULT 'xml';
