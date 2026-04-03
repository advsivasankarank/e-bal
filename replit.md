# e-BAL (Balance Sheet Builder)

## Overview
A PHP web application that automates generation of financial reports (Balance Sheets and Profit & Loss statements) by fetching data from Tally Prime accounting software via its XML interface.

## Tech Stack
- **Language:** PHP 8.2
- **Database:** PostgreSQL (Replit built-in, via PDO pgsql driver)
- **PDF Generation:** dompdf/dompdf (^3.1, via Composer)
- **Frontend:** HTML/CSS (no JavaScript framework)
- **Server:** PHP built-in dev server on port 5000

## Project Structure
```
public/          - Web root (PHP entry points served here)
  index.php      - Dashboard
  company_create.php / company_list.php - Company management
  fy_create.php  - Financial year creation
  tally_fetch.php - Fetch data from Tally XML server
  mapping_console.php / save_mapping.php - Ledger mapping UI
  generate_pdf.php / generate_bs.php / generate_pl.php - Report generation
  asset/         - Static assets (CSS) - copied from root asset/

app/             - Core PHP logic
  bootstrap.php  - DB + engine bootstrap
  core/          - Classification, mapping, notes, PDF, tally engines
  helpers/       - Common helper functions

config/
  database.php   - PostgreSQL PDO connection (uses PGHOST/PGPORT/PGUSER/PGPASSWORD/PGDATABASE env vars)

layouts/         - Shared header/footer/navigation PHP templates
mapping_engine/  - Group mapping and mapper logic
document_engine/ - Balance sheet document engine
xml_engine/      - Tally XML connector
templates/       - HTML/XML report templates
vendor/          - Composer dependencies (dompdf)
```

## Database Schema (PostgreSQL)
- `companies` - Company records (id, company_name, cin, created_at)
- `financial_years` - Financial years per company (id, company_id, fy_start, fy_end)
- `workflow_status` - Tracks progress per company/FY (tally_fetched, mapping_completed, verified, reports_generated)
- `ledger_mapping` - Maps ledger names to schedule codes (company_id, ledger_name, schedule_code)
- `schedule_heads` - Schedule head definitions (code, main_head, sub_head) — seeded with 27 standard codes

## Running the App
- Workflow: `Start application` runs `php -S 0.0.0.0:5000 -t public/`
- Visit port 5000 to see the Dashboard

## Key Notes
- Originally designed for MySQL; adapted to PostgreSQL for Replit
- `lastInsertId()` replaced with `RETURNING id` for PostgreSQL compatibility
- CSS asset path fixed from `/e-bal/asset/css/app.css` to `/asset/css/app.css`
- Asset folder copied into `public/asset/` for PHP dev server serving
- Tally XML integration requires a running Tally Prime instance on port 9000
