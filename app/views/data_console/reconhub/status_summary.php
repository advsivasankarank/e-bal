<?= uiWorkspaceStart() ?>

<!-- Summary Cards -->
<div class="wb-summary" id="wbSummary">
    <div class="wb-card total active-tile" data-filter="all"><div class="num" id="statTotal"><?= $stats['total'] ?></div><div class="lbl">Showing</div></div>
    <div class="wb-card" style="border-color:var(--info);" data-filter="tb_impact"><div class="num" id="statTbImpact" style="color:var(--info);"><?= number_format($tbImpactCount) ?></div><div class="lbl">TB Impact</div></div>
    <div class="wb-card mapped" data-filter="mapped"><div class="num" id="statMapped"><?= $stats['mapped'] ?></div><div class="lbl">Mapped</div></div>
    <div class="wb-card unmapped" data-filter="unmapped"><div class="num" id="statUnmapped"><?= $stats['unmapped'] ?></div><div class="lbl">Unmapped</div></div>
    <div class="wb-card suggested" data-filter="suggested"><div class="num" id="statSuggested"><?= $stats['auto_suggested'] ?></div><div class="lbl">Auto-Suggested</div></div>
    <div class="wb-card high" data-filter="high_conf"><div class="num" id="statHigh"><?= $stats['high_confidence'] ?></div><div class="lbl">High Confidence</div></div>
    <div class="wb-card review" data-filter="manual_review"><div class="num" id="statReview"><?= $stats['manual_review'] ?></div><div class="lbl">Manual Review</div></div>
    <div class="wb-card unsaved" data-filter="unsaved"><div class="num" id="statUnsaved">0</div><div class="lbl">Unsaved Changes</div></div>
    <div class="wb-card" style="border-color:var(--danger);" data-filter="risky"><div class="num" id="statRisk" style="color:var(--danger);">0</div><div class="lbl">Risk Issues</div></div>
</div>

<!-- Toolbar: Search + Filter Chips -->
<div class="wb-toolbar">
    <select class="search-mode" id="searchMode" title="Search mode">
        <option value="all">Search All</option>
        <option value="ledger_name">Search Ledger Name</option>
        <option value="parent_group">Search Parent Group</option>
        <option value="schedule">Search Schedule</option>
        <option value="remarks">Search Remarks</option>
    </select>
    <select class="pg-filter" id="pgFilter" title="Filter by parent group">
        <option value="">All Parent Groups</option>
    </select>
    <div class="search-box">
        <span class="s-icon">&#128269;</span>
        <input type="text" id="hotSearch" placeholder="Search ledger, parent group, schedule, remarks&hellip;">
    </div>
    <div class="filter-chips" id="viewChips" style="margin-bottom:4px;">
        <span class="filter-chip active" data-view="tb_impact" title="Only ledgers with Trial Balance data (fastest load)">TB Impact (<?= number_format($tbImpactCount) ?>)</span>
        <span class="filter-chip" data-view="all" title="All ledgers in master (may be slow for large datasets)">All Master (<?= number_format($totalLedgerCount) ?>)</span>
    </div>
    <div class="filter-chips" id="filterChips">
        <span class="filter-chip active" data-filter="all">All</span>
        <span class="filter-chip" data-filter="unmapped">Unmapped</span>
        <span class="filter-chip" data-filter="mapped">Mapped</span>
        <span class="filter-chip" data-filter="suggested">Auto-Suggested</span>
        <span class="filter-chip" data-filter="high_conf">High Confidence</span>
        <span class="filter-chip" data-filter="low_conf">Low Confidence</span>
        <span class="filter-chip" data-filter="review">Manual Review</span>
        <span class="filter-chip" data-filter="bs">Balance Sheet</span>
        <span class="filter-chip" data-filter="pl">Profit & Loss</span>
        <span class="filter-chip" data-filter="asset">Assets</span>
        <span class="filter-chip" data-filter="liability">Liabilities</span>
        <span class="filter-chip" data-filter="income">Income</span>
        <span class="filter-chip" data-filter="expense">Expenses</span>
        <span class="filter-chip" data-filter="risky">⚠️ Risky</span>
        <span class="filter-chip" data-filter="critical">🔴 Critical</span>
        <span class="filter-chip" data-filter="credit_in_asset">Credit in Asset</span>
        <span class="filter-chip" data-filter="debit_in_liability">Debit in Liability</span>
        <span class="filter-chip" data-filter="manual_review">Manual Review</span>
    </div>
</div>

<!-- Pagination Controls -->
<?php if ($totalGridRows > $perPage): ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin:12px 0;padding:10px 16px;background:var(--panel);border:1px solid var(--border);border-radius:8px;font-size:0.82rem;">
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="color:var(--muted);">Showing <?= number_format($gridOffset + 1) ?>–<?= number_format(min($gridOffset + $perPage, $totalGridRows)) ?> of <?= number_format($totalGridRows) ?> ledgers</span>
        <label style="color:var(--muted);">Per page:</label>
        <select onchange="var u=new URL(window.location);u.searchParams.set('per_page',this.value);u.searchParams.set('page','1');window.location=u;" style="padding:2px 6px;font-size:0.8rem;">
            <option value="25" <?= $perPage === 25 ? 'selected' : '' ?>>25</option>
            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
        </select>
    </div>
    <div style="display:flex;gap:6px;align-items:center;">
        <?php if ($currentPage > 1): ?>
        <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?<?= http_build_query(array_merge($currentGetParams, ['page' => $currentPage - 1, 'per_page' => $perPage])) ?>" style="padding:4px 10px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:var(--text);">&#8592; Prev</a>
        <?php endif; ?>
        <span style="color:var(--muted);"><?= $currentPage ?> / <?= $totalPages ?></span>
        <?php if ($currentPage < $totalPages): ?>
        <a href="<?= BASE_URL ?>data_console/mapping_workbench.php?<?= http_build_query(array_merge($currentGetParams, ['page' => $currentPage + 1, 'per_page' => $perPage])) ?>" style="padding:4px 10px;border:1px solid var(--border);border-radius:4px;text-decoration:none;color:var(--text);">Next &#8594;</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isGroupMode): ?>
<!-- Schedule III Group Mapping Panel -->
<div id="groupMappingPanel" style="background:var(--panel-strong);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:12px;box-shadow:var(--shadow-sm);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <strong style="font-size:0.9rem;">Schedule III Group Mapping</strong>
        <span id="groupPanelInfo" style="font-size:0.75rem;color:var(--muted);">Tag parent groups to Schedule III heads</span>
    </div>
    <div id="groupMappingBody" style="overflow-x:auto;"></div>
</div>
<?php endif; ?>
