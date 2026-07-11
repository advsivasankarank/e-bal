<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6/dist/css/tabulator_midnight.min.css">
<style>
/* Tabulator theme overrides to match e-BAL design */
.tabulator { border: none !important; font-family: inherit !important; }
.tabulator .tabulator-header { background: var(--panel-strong) !important; border-bottom: 1px solid var(--border) !important; }
.tabulator .tabulator-header .tabulator-col { background: var(--panel-strong) !important; border-color: var(--border) !important; }
.tabulator .tabulator-header .tabulator-col .tabulator-col-content { padding: 6px 8px !important; }
.tabulator .tabulator-header .tabulator-col .tabulator-col-content .tabulator-col-title { font-size: 0.78rem !important; font-weight: 600 !important; color: var(--muted) !important; text-transform: uppercase !important; }
.tabulator .tabulator-tableholder { background: var(--panel-strong) !important; }
.tabulator .tabulator-row { background: var(--panel-strong) !important; border-color: var(--border) !important; min-height: 34px !important; }
.tabulator .tabulator-row .tabulator-cell { border-color: var(--border) !important; padding: 4px 8px !important; font-size: 0.82rem !important; }
.tabulator .tabulator-row:hover { background: #f1f5f9 !important; }
.tabulator .tabulator-row.tabulator-selected { background: #e8f0fe !important; }
.tabulator .tabulator-row .tabulator-cell.tabulator-frozen { background: var(--panel-strong) !important; z-index: 1 !important; }
.tabulator-row.row-unmapped { background: #fff8e1 !important; }
.tabulator-row.row-lowconf { background: #fff3e0 !important; }
.tabulator .tabulator-footer { background: var(--panel-strong) !important; border-top: 1px solid var(--border) !important; }
.tabulator .tabulator-footer .tabulator-page { color: var(--text) !important; border-color: var(--border) !important; }
.tabulator .tabulator-footer .tabulator-page.active { background: var(--brand) !important; color: #fff !important; }
.tabulator-row .tabulator-cell .tabulator-select-editor select { width: 100% !important; border: 1px solid var(--border-strong) !important; border-radius: 4px !important; padding: 2px 4px !important; font-size: 0.8rem !important; background: var(--panel-strong) !important; color: var(--text) !important; }
</style>
<style>
.wb-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.wb-card {
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    text-align: center;
    box-shadow: var(--shadow-sm);
    cursor: default;
    transition: border-color 0.15s, background 0.15s;
}
.wb-card:hover { border-color: var(--brand); }
.wb-card[data-filter] { cursor: pointer; }
.wb-card[data-filter]:hover { background: #e8f0fe; }
.wb-card.active-tile { background: var(--brand); border-color: var(--brand); }
.wb-card.active-tile .num { color: #fff !important; }
.wb-card.active-tile .lbl { color: rgba(255,255,255,0.85); }
.wb-card.active-tile[data-filter="unmapped"] .num { color: #fff !important; }
.wb-card.active-tile[data-filter="risky"] .num { color: #fff !important; }
.wb-card.active-tile[data-filter="critical"] .num { color: #fff !important; }
.wb-card .num { font-size: 1.6rem; font-weight: 700; line-height: 1.2; }
.wb-card .lbl { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
.wb-card.total .num { color: var(--text); }
.wb-card.mapped .num { color: var(--success); }
.wb-card.unmapped .num { color: var(--danger); }
.wb-card.suggested .num { color: var(--brand); }
.wb-card.high .num { color: #2e7d32; }
.wb-card.review .num { color: var(--warning); }
.wb-card.unsaved .num { color: #c62828; }

.wb-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    flex-wrap: wrap;
    background: var(--panel-strong);
    padding: 8px 0;
    border-bottom: 1px solid var(--border);
}
.wb-toolbar .search-box {
    position: relative;
    flex: 1;
    min-width: 220px;
}
.wb-toolbar .search-box input {
    width: 100%;
    padding: 8px 12px 8px 32px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.85rem;
    background: var(--panel-strong);
}
.wb-toolbar .search-box .s-icon {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--muted); font-size: 0.85rem; pointer-events: none;
}
.wb-toolbar .search-mode {
    padding: 8px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.82rem;
    background: var(--panel-strong);
    color: var(--text);
    min-width: 150px;
}
.wb-toolbar .pg-filter {
    padding: 8px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 8px;
    font-size: 0.82rem;
    background: var(--panel-strong);
    color: var(--text);
    min-width: 180px;
}

.filter-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}
.filter-chip {
    padding: 5px 10px;
    border: 1px solid var(--border-strong);
    border-radius: 999px;
    font-size: 0.75rem;
    background: var(--panel-strong);
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
    color: var(--text);
}
.filter-chip:hover { border-color: var(--brand); color: var(--brand); }
.filter-chip.active { background: var(--brand); color: #fff; border-color: var(--brand); }

.wb-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 10px;
    margin-top: 8px;
    flex-wrap: wrap;
    box-shadow: var(--shadow-sm);
    border-bottom: 2px solid var(--brand);
}
.wb-actions .btn { min-height: 34px; padding: 0 14px; font-size: 0.8rem; }
.wb-actions .sep { width: 1px; height: 24px; background: var(--border); margin: 0 4px; }
.wb-actions .status-text { font-size: 0.8rem; color: var(--muted); margin-left: auto; }

.hot-container {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow-x: auto;
    overflow-y: visible;
    box-shadow: var(--shadow-sm);
    min-height: 400px;
    position: relative;
    max-width: 100%;
}

.recon-grid-wrap {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

#hotSearch {
    display: inline-block;
    min-width: 140px;
}

.recon-context-strip {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 14px;
    background: var(--panel-strong);
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 10px;
    font-size: 0.82rem;
    color: var(--text);
    flex-wrap: wrap;
}
.recon-context-strip .rcs-sep { color: var(--muted); }
.recon-context-strip .rcs-label { color: var(--muted); font-weight: 400; }
.recon-context-strip .rcs-value { font-weight: 600; }

/* ReconHub page heading */
.rh-page-heading {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}
.rh-page-heading h2 {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text);
    margin: 0;
}
.rh-page-heading .rh-subtitle {
    font-size: 0.82rem;
    color: var(--muted);
    font-weight: 400;
}

/* Grid containment — prevent body-level horizontal overflow */
.hot-container {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow-x: auto;
    overflow-y: visible;
    box-shadow: var(--shadow-sm);
    min-height: 400px;
    position: relative;
    max-width: 100%;
}

.recon-grid-wrap {
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}

/* Tabulator cell text clamping — reduce row height */
.tabulator-row .tabulator-cell {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: 280px;
}
.tabulator-row .tabulator-cell[tabulator-field="final_mapping"],
.tabulator-row .tabulator-cell[tabulator-field="suggested_label"],
.tabulator-row .tabulator-cell[tabulator-field="current_label"] {
    max-width: 220px;
}
.tabulator-row .tabulator-cell[tabulator-field="remarks"] {
    max-width: 180px;
}
.tabulator-row .tabulator-cell[tabulator-field="ledger_name"] {
    max-width: 260px;
    font-weight: 500;
}

/* Action buttons — consistent sizing */
.wb-actions .btn {
    min-height: 32px;
    padding: 4px 12px;
    font-size: 0.78rem;
    white-space: nowrap;
    flex-shrink: 0;
}
.wb-actions .btn-success { min-width: 80px; }
.wb-actions .btn-outline { min-width: 70px; }
.wb-actions select {
    min-height: 32px;
    flex-shrink: 0;
}

.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: 8px;
    color: #fff;
    font-size: 0.85rem;
    z-index: 9999;
    animation: fadeInUp 0.3s ease;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.toast.success { background: var(--success); }
.toast.error { background: var(--danger); }
.toast.info { background: var(--brand); }

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.hidden-input { display: none; }
</style>
