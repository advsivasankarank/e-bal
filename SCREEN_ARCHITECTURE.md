# e-BAL Screen Architecture — Post-Pilot Transition

## Layout Architecture

```
┌──────────────────────────────────────────────────────────┐
│  TOPBAR (brand, global search, notifications, user menu)  │
├──────────┬───────────────────────────────────────────────┤
│          │                                                │
│  LEFT    │              MAIN CONTENT                      │
│  SIDEBAR │                                                │
│          │                                                │
│  Context │                                                │
│  Nav     │                                                │
│          │                                                │
│  Workflow│                                                │
│  Status  │                                                │
│          │                                                │
│  Quick   │                                                │
│  Actions │                                                │
│          │                                                │
├──────────┴───────────────────────────────────────────────┤
│  VALIDATION BAR (collapsible bottom bar — errors/warnings)│
└──────────────────────────────────────────────────────────┘
```

### Navigation Topology

```
Topbar: [e-BAL Logo] [Global Search] [🔔 Notifications] [👤 User ▼]

Sidebar (contextual by workspace):
  ── Data Operations ──
    ■ TB Import & Sync         ← Active step indicator
    ■ Mapping Workbench        ← Step 2 of 6
    ■ Trial Balance Review
    ■ Financial Statements
    ■ Validation & Reconcile
    ■ Export Centre
  ── Company ──
    ■ Dashboard
    ■ Company Settings
  ── Admin (superadmin only) ──
    ■ Admin Dashboard
    ■ Licensing
    ■ Audit Log
```

---

## Screen Inventory & Priority

### A. TB Import Dashboard
**Route:** `/import`
**Purpose:** Single entry point for all data import operations.
**States:** Empty (no imports yet) / In Progress / Completed / Error
**Components:**
- Import method cards (Online Tally / XML Upload / CSV Upload)
- Drag-and-drop file upload zone
- Import progress stepper (Upload → Validate → Process → Complete)
- Import history table (last 10 imports, status, timestamp, record count)
- Quick stats bar (total imports, ledgers, TB entries)

### B. Mapping Workbench
**Route:** `/mapping`
**Purpose:** Map Tally ledgers to Schedule III codes with AI assistance.
**Components:**
- Search + filter bar (by ledger name, parent group, status, schedule code)
- Split-pane layout: ledger list (left) + detail panel (right)
- Mapping status progress bar
- Inline dropdown editor with search
- AI suggestion badge (confidence score + reasoning tooltip)
- Parent group conflict indicator (inline, with resolution)
- Batch actions toolbar (select-all, bulk assign, remember scope)
- Undo/Redo stack indicator

### C. Financial Statement Workspace
**Route:** `/reports`
**Purpose:** View, edit, and finalize financial statements.
**Components:**
- Tab bar: Balance Sheet | P&L | Notes to Accounts | Directors Report
- Section sidebar (within each tab — expand/collapse individual items)
- Report canvas (formatted FINANCIAL STATEMENT display)
- Manual input panel (slide-out right drawer for adjustments)
- Year comparison toggle (Current / Previous / Side-by-side)
- Validation indicator strip (errors that affect this specific statement)
- Notes-to-accounts with inline editing

### D. Validation Sidebar + Bottom Bar
**Route:** Persistent across all workspace pages
**Purpose:** Always-visible validation status and issue navigation.
**Components:**
- Collapsible bottom bar showing error/warning/info counts
- Validation categories: TB Balance | Mapping Completeness | Parent Group Conflicts | BS Identity | Note Completeness
- Each category expandable to show individual issues
- Click issue → navigate to relevant page/section
- Real-time status indicator (pulse animation when validation is stale)

### E. Export Centre
**Route:** `/export`
**Purpose:** Configure, preview, and download report exports.
**Components:**
- Export wizard stepper (Select Format → Configure → Preview → Download)
- Format cards (PDF, Excel, Word, HTML) with feature comparison
- Configuration panel (orientation, paper size, margins, watermark, logo, entity stamp)
- Live preview pane (renders sample page)
- Batch export queue (multi-format, multi-company)
- Export history with re-download links

### F. Admin/Monitoring Dashboard
**Route:** `/admin`
**Purpose:** System-wide oversight for superadmin.
**Components:**
- KPI row (total companies, active licenses, monthly imports, storage used)
- Revenue chart (interactive bar/line, toggle monthly/quarterly/yearly)
- Recent activity feed (real-time updates)
- License management table (with search, filter by status, expiry alerts)
- Company usage table (last login, imports count, report generation count)
- Quick actions (create company, assign license, system health check)

---

## Component Library (New)

| Component | Description | Priority |
|---|---|---|
| Sidebar | Collapsible left nav with step indicator | P0 |
| TabBar | Horizontal tab navigation | P0 |
| ValidationBar | Bottom bar, collapsible, with issue list | P0 |
| ProgressBar | Linear progress with label + percentage | P0 |
| SearchBar | Input with icon, clear button, optional filters | P0 |
| Badge | Small label (success/warning/error/info) | P0 |
| Dropdown | Select with search, grouped options | P0 |
| Modal | Overlay dialog with title, body, actions | P1 |
| Toast | Auto-dismiss notification (top-right) | P1 |
| DataTable | Table with sort, pagination, row selection | P1 |
| FileDropZone | Drag-and-drop area with visual feedback | P1 |
| SplitPane | Resizable left/right panel layout | P1 |
| SlidePanel | Right drawer for contextual input | P1 |
| Stepper | Multi-step wizard indicator | P2 |
| Tooltip | Hover-reveal information popup | P2 |

---

## Migration Path

1. **Phase 1** (this sprint): New layout (sidebar + topbar), TB Import Dashboard, Mapping Workbench UX improvements
2. **Phase 2**: Financial Statement Workspace (tabs + slide panel), Validation Bar
3. **Phase 3**: Export Centre, Admin Dashboard enhancements
4. **Phase 4**: Polish, responsive, performance, accessibility

---

## Design Tokens (extending current CSS vars)

```css
:root {
  /* Existing tokens preserved */
  --sidebar-width: 260px;
  --sidebar-collapsed-width: 64px;
  --topbar-height: 64px;
  --validation-bar-height: 48px;
  --sidebar-bg: #f8fafc;
  --sidebar-active: #0f4c81;
  --sidebar-hover: #e9f4fb;
  --sidebar-text: #475569;
  --sidebar-width-active: #0f4c81;
  --validation-error: #dc2626;
  --validation-warning: #d97706;
  --validation-info: #2563eb;
  --validation-success: #16a34a;
  --badge-error-bg: #fef2f2;
  --badge-warning-bg: #fffbeb;
  --badge-success-bg: #f0fdf4;
  --badge-info-bg: #eff6ff;
}
```
