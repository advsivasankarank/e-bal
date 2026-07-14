# e-BAL Screen Architecture

> Refreshed to describe what's actually shipped (the V2 shell), not the
> original pre-build proposal. The V2 shell has been live for some time —
> this document had drifted out of sync with it.

## Layout Architecture

```
┌──────────────────────────────────────────────────────────┐
│  TOPBAR (brand, company/FY context, notifications, bridge │
│  connectivity status, user menu)                          │
├──────────┬───────────────────────────────────────────────┤
│          │                                                │
│  LEFT    │              MAIN CONTENT                      │
│  SIDEBAR │                                                │
│          │                                                │
│  Workflow│                                                │
│  Steps   │                                                │
│  (Gateway│                                                │
│  → FY →  │                                                │
│  Data →  │                                                │
│  Stmts → │                                                │
│  Review →│                                                │
│  Deliver)│                                                │
│          │                                                │
│  Footer: │                                                │
│  Reports,│                                                │
│  Settings│                                                │
│  Logout  │                                                │
├──────────┴───────────────────────────────────────────────┤
│  VALIDATION STRIP (per-page, click-through to detail modal)│
└──────────────────────────────────────────────────────────┘
```

Implemented in `public/layouts/header_v2.php` (topbar + sidebar) and
`public/layouts/footer_v2.php`. There is an older, unrelated
`header.php`/`footer.php`/`navigation.php` still in the repo from before
this shell was built — those are dead code, not an alternate skin.

### Navigation Topology (as shipped)

```
Topbar: [eB logo] [Company / FY context] [🔔] [Bridge + Tally status] [User ▼]

Sidebar ($v2NavItems in header_v2.php):
  ■ e-BAL Gateway              → dashboard_company.php
  ■ Financial Year Console     → fy_workspace.php?entity_id=…
  ■ Data Console               → data/index.php?entity_id=…&fy_id=…
  ■ Financial Statement Console → statements/financials.php?entity_id=…&fy_id=…
  ■ Validation & Reconcile     → review/index.php?company_id=…&fy_id=…
  ■ Deliverables                → deliverables/index.php?entity_id=…&fy_id=…

Sidebar footer ($v2FooterItems):
  ■ Management Reports  → reports.php
  ■ Settings            → settings.php
  ■ Logout              → logout.php

Admin (separate surface, not in this sidebar):
  ■ superadmin/index.php ("Platform Control Centre") — its own standalone
    shell, not wrapped in header_v2.php/footer_v2.php.
```

Data/FY-scoped items disable themselves (`'disabled' => true`) until an
entity/FY is selected — see `$v2HasEntity`/`$v2HasFy` in header_v2.php.

---

## Screen Inventory (as shipped)

### A. e-BAL Gateway
**Route:** `public/dashboard_company.php`, `entity_select.php`, `entity_create.php`, `entity_edit.php`
**Purpose:** Entity selection/creation — the entry point before anything else is reachable.

### B. Financial Year Console
**Route:** `public/fy_workspace.php?entity_id=…`
**Purpose:** Select/create/close/reopen a financial year for the active entity.

### C. Data Console
**Route:** `public/data/index.php?entity_id=…&fy_id=…`, plus `data_console/mapping_workbench.php`, `tb_grid.php`, `view_synced_ledgers.php`, `trial_balance_preview.php`
**Purpose:** TB import, ledger sync, and mapping. The Mapping Workbench is the highest-traffic screen in the app (see the perf work in `reconhub_data_loading_service.php`).

### D. Financial Statement Console
**Route:** `public/statements/financials.php?entity_id=…&fy_id=…`
**Purpose:** View/edit/finalize financial statements. Tab bar (Balance Sheet / Trading A/c / P&L / Notes, or Income & Expenditure for Trust/Society), manual-input side panel (`_manual_inputs.php`), Validation Strip with a detail modal, PDF/Word/Excel download buttons, and entity-specific compliance cards (Directors Report for corporates, Partners' Capital Schedule for LLP/Partnership).

### E. Validation & Reconcile
**Route:** `public/review/index.php?company_id=…&fy_id=…`
**Purpose:** Balance-difference analysis and per-note review/sign-off tracking. Was orphaned from the sidebar for a period (computed an active-nav state with no link pointing to it) — now linked.

### F. Deliverables (Export Centre)
**Route:** `public/deliverables/index.php?entity_id=…&fy_id=…`, downloads via `public/report_download.php?format={pdf,word,excel}`
**Purpose:** Export financial statements. PDF export reuses the same HTML note templates as the on-screen view; DOCX/XLSX use PhpWord/PhpSpreadsheet and build their notes sections generically from `$notes['sections']` (custom blocks like the Partners' Capital Schedule note currently only reach HTML+PDF, not DOCX/XLSX — a known gap, not a regression).

### G. Superadmin / Platform Control Centre
**Route:** `public/superadmin/index.php`, `superadmin_dashboard.php`, `workspace_admin.php`
**Purpose:** System-wide oversight — licensing, company usage, revenue. Deliberately its own standalone shell (own `<head>`, own `platform_control_centre.css`), not wrapped in the shared topbar/sidebar.

---

## Component Library (as shipped)

`public/components/ui.php` — the real, in-use shared component set (not
a wishlist). Functions, not classes:

| Function | Purpose |
|---|---|
| `uiBreadcrumb()` | Page breadcrumb trail |
| `uiPageHero()` | Page title + subtitle block |
| `uiSectionTag()` | Small labeled section marker |
| `uiContextCard()` | Company/FY context display |
| `uiKpiCards()` | Row of KPI tiles |
| `uiActionCards()` | Clickable action tile grid |
| `uiStatCard()` | Single stat tile |
| `uiStatusBadge()` | Colored status label |
| `uiTableStart()` / `uiTableEnd()` | Table wrapper with column headers |
| `uiEmptyState()` | Empty-state placeholder with CTA |
| `uiAlert()` | Inline alert banner |
| `uiProgressSteps()` | Step-progress indicator |
| `uiActivityFeed()` / `uiRecentList()` | Recent-activity lists |
| `uiSectionCard()` | Titled content card |
| `uiFormField()` | Labeled form input |
| `uiButton()` | Styled button/link |
| `uiWorkspaceStart()` / `uiWorkspaceEnd()`, `uiGrid()`/`uiGridEnd()`, `uiTwoCol()`/`uiTwoColEnd()` | Layout wrappers |

**Known gap:** no `Modal` or `Toast` in this library. At least one screen
(`statements/financials.php`'s validation-issues dialog, and an inline
modal in the same file) hand-rolls its own `position:fixed` overlay
instead. Badges are also fragmented — beyond `uiStatusBadge()`, several
module stylesheets (`review_workspace.css`, `deliverables_workspace.css`,
`financials_workspace.css`, `workspace_launcher.css`) define their own
divergent badge classes with hardcoded colors instead of using the
shared one. Both are tracked as Phase 3 cleanup, not yet done.

---

## Design Tokens (as shipped, `public/asset/css/app_v2.css`)

```css
:root {
  --font-sans: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
  --font-mono: "Cascadia Code", "Fira Code", Consolas, monospace;
  --bg: #f4f6f8;
  --panel: #ffffff;
  --border: #e0e4e8;
  --text: #1a1d21;
  --muted: #6b7280;
  --muted-light: #9ca3af;
  --brand: #0f4c81;
  --brand-hover: #0d3f6d;
  --brand-light: #e8f0f8;
  --brand-text: #ffffff;
  --success: #16a34a;
  --success-light: #dcfce7;
  --warning: #d97706;
  --warning-light: #fef3c7;
  --danger: #dc2626;
  --danger-light: #fee2e2;
  --info: #2563eb;
  --info-light: #dbeafe;
  --radius-sm: 4px;
  --radius: 6px;
  --radius-lg: 8px;
  --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
  --shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
  --topbar-h: clamp(48px, 5vh, 56px);
  --sidebar-w: 240px;
  --sidebar-collapsed-w: 68px;
  --transition-fast: 0.15s ease;
  --transition: 0.25s ease;
}
```

These are a different, self-consistent set from an earlier draft of this
document — use `--brand` (not `--sidebar-active`), `--danger` (not
`--validation-error`), `--sidebar-w`/`--topbar-h` (not
`--sidebar-width`/`--topbar-height`). Style new screens through these
tokens; several module stylesheets currently hardcode hex values
instead (same Phase 3 cleanup as the badge fragmentation above).

**Responsiveness:** `app_v2.css` carries broad `@media` coverage for the
shell itself. Per-module stylesheets are uneven — `review_workspace.css`
and `deliverables_workspace.css` have only 1-2 breakpoints each, versus
the shell's much wider coverage. Worth an audit at 375px/768px before
treating any single module as mobile-verified.
