<!-- Early Bridge Status Bootstrap — runs before heavy Tabulator init -->
<script>
(function() {
    var url = window.ebalBridgeUrl || 'http://127.0.0.1:9123';
    window.ebalBridgeUrl = url;

    function updateDot(kind, status) {
        var dots = document.querySelectorAll('.bc-dot[data-status-kind="' + kind + '"]');
        var color = (status === 'online' || status === 'connected') ? 'green' : (status === 'waiting' ? 'yellow' : 'red');
        for (var i = 0; i < dots.length; i++) {
            dots[i].className = 'bc-dot bc-dot--' + color;
        }
    }

    function updateLabel(kind, text) {
        var vals = document.querySelectorAll('.bc-status-val[data-status-kind="' + kind + '"]');
        for (var i = 0; i < vals.length; i++) {
            vals[i].textContent = text;
        }
    }

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timeoutId = null;
    if (controller) {
        timeoutId = setTimeout(function() { controller.abort(); }, 4000);
    }

    fetch(url + '/health', { mode: 'cors', cache: 'no-store', signal: controller ? controller.signal : undefined })
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(data) {
            if (timeoutId) clearTimeout(timeoutId);
            var bridgeOk = !!(data && data.ok);
            var tallyOk = !!(data && data.tally && data.tally !== 'unknown');
            updateDot('bridge', bridgeOk ? 'online' : 'offline');
            updateLabel('bridge', bridgeOk ? 'Online' : 'Offline');
            updateDot('tally', tallyOk ? 'connected' : 'offline');
            updateLabel('tally', tallyOk ? (data.tally === 'connected' ? 'Connected' : data.tally) : 'Offline');
        })
        .catch(function() {
            if (timeoutId) clearTimeout(timeoutId);
            updateDot('bridge', 'offline');
            updateLabel('bridge', 'Offline');
            updateDot('tally', 'offline');
            updateLabel('tally', 'Offline');
        });
})();
</script>

<?php if ($isLedgerMode): ?>
<script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6/dist/js/tabulator.min.js"></script>
<script>
(function() {
    'use strict';

    var ebalBaseUrl = <?= json_encode(BASE_URL) ?>;
    var csrfToken = <?= json_encode($csrfTokenValue) ?>;
    var mappingOptions = <?= json_encode($mappingOptionsJson) ?>;
    var allData = <?= json_encode($paginatedGridData) ?>;
    var totalGridRows = <?= (int) $totalGridRows ?>;
    var currentPage = <?= (int) $currentPage ?>;
    var totalPages = <?= (int) $totalPages ?>;
    var perPage = <?= (int) $perPage ?>;
    var tbImpactCount = <?= (int) $tbImpactCount ?>;
    var totalCount = <?= (int) $totalLedgerCount ?>;
    var defaultView = <?= $defaultViewTbImpact ? "'tb_impact'" : "'all'" ?>;
    var optionsMap = {};
    var optionList = [];
    mappingOptions.forEach(function(o) {
        optionsMap[o.id] = o.label;
        optionList.push({id: o.id, label: o.label});
    });

    var originalData = JSON.parse(JSON.stringify(allData));
    var dirtyRows = {};
    var currentFilter = 'all';
    var currentParentGroup = '';
    var searchMode = 'all';
    var table = null;

    /* ---- Category lookup for filter ---- */
    var bsCodes = ['share_capital','reserves','lt_borrowings','deferred_tax_liability','other_non_current_liabilities','long_term_provisions','st_borrowings','trade_payables','trade_payables_msme','other_financial_liabilities','other_current_liabilities','short_term_provisions','ppe','cwip','intangible_assets','investments_non_current','loans_non_current','deferred_tax_asset','other_non_current_assets','inventory','investments_current','receivables','cash','bank_balances_other','loans_current','other_current_assets'];
    var plCodes = ['revenue','other_income','materials','purchase_stock','inventory_change','employee_cost','finance_cost','depreciation','other_expenses'];
    var assetCodes = ['ppe','cwip','intangible_assets','investments_non_current','loans_non_current','deferred_tax_asset','other_non_current_assets','inventory','investments_current','receivables','cash','bank_balances_other','loans_current','other_current_assets'];
    var liabilityCodes = ['share_capital','reserves','lt_borrowings','deferred_tax_liability','other_non_current_liabilities','long_term_provisions','st_borrowings','trade_payables','trade_payables_msme','other_financial_liabilities','other_current_liabilities','short_term_provisions'];
    var incomeCodes = ['revenue','other_income'];
    var expenseCodes = ['materials','purchase_stock','inventory_change','employee_cost','finance_cost','depreciation','other_expenses'];

    function codeInCategory(code, list) { return list.indexOf(code) !== -1; }

    function fmtMoney(v) {
        if (v === null || v === undefined || v === '') return '';
        return parseFloat(v).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    /* ---- Filter logic ---- */
    function filterRow(row) {
        if (currentFilter === 'all') return true;
        var code = row.final_mapping || row.current_mapping || '';
        switch (currentFilter) {
            case 'unmapped': return !code || code === '';
            case 'mapped': return code && code !== '';
            case 'suggested': return row.suggested && row.suggested !== '' && (!code || code === '');
            case 'high_conf': return row.suggested && (row.confidence || 0) >= 90 && (!code || code === '');
            case 'low_conf': return row.suggested && (row.confidence || 0) > 0 && (row.confidence || 0) < 70 && (!code || code === '');
            case 'review': return (!row.suggested || row.suggested === '' || (row.confidence || 0) < 70) && (!code || code === '');
            case 'bs': return code && codeInCategory(code, bsCodes);
            case 'pl': return code && codeInCategory(code, plCodes);
            case 'asset': return code && codeInCategory(code, assetCodes);
            case 'liability': return code && codeInCategory(code, liabilityCodes);
            case 'income': return code && codeInCategory(code, incomeCodes);
            case 'expense': return code && codeInCategory(code, expenseCodes);
            case 'risky': return row.risk_level && row.risk_level !== 'none';
            case 'critical': return row.risk_level === 'critical';
            case 'credit_in_asset': return row.risk_reason && row.risk_reason.indexOf('Credit balance in asset') !== -1;
            case 'debit_in_liability': return row.risk_reason && row.risk_reason.indexOf('Debit balance in liability') !== -1;
            case 'manual_review': return row.risk_level === 'review' || (row.risk_level === 'none' && (!row.suggested || row.suggested === '') && (!code || code === ''));
            default: return true;
        }
    }

    function getFilteredData() {
        var search = (document.getElementById('hotSearch').value || '').toLowerCase().trim();
        var pgFilter = document.getElementById('pgFilter').value || '';
        return allData.filter(function(r) {
            if (!filterRow(r)) return false;
            if (pgFilter && r.parent_group !== pgFilter) return false;
            if (search) {
                var hay = '';
                switch (searchMode) {
                    case 'ledger_name': hay = (r.ledger_name||'').toLowerCase(); break;
                    case 'parent_group': hay = (r.parent_group||'').toLowerCase(); break;
                    case 'schedule': hay = ((r.current_label||'')+' '+(r.suggested_label||'')).toLowerCase(); break;
                    case 'remarks': hay = (r.remarks||'').toLowerCase(); break;
                    default: hay = ((r.ledger_name||'')+' '+(r.parent_group||'')+' '+(r.current_label||'')+' '+(r.suggested_label||'')+' '+(r.remarks||'')).toLowerCase();
                }
                if (hay.indexOf(search) === -1) return false;
            }
            return true;
        });
    }

    /* ---- Stats update ---- */
    function updateStats() {
        var total=allData.length, mapped=0, unmapped=0, suggested=0, high=0, review=0, unsaved=Object.keys(dirtyRows).length, risk=0;
        for (var i=0;i<allData.length;i++) {
            var d=allData[i], code=d.final_mapping||d.current_mapping||'';
            if (code&&code!=='') { mapped++; }
            else { unmapped++; if(d.suggested&&(d.confidence||0)>=90){suggested++;high++;}else if(d.suggested&&(d.confidence||0)>=70){suggested++;}else{review++;} }
            if (d.risk_level==='critical'||d.risk_level==='warning') { risk++; }
        }
        document.getElementById('statTotal').textContent=total;
        document.getElementById('statMapped').textContent=mapped;
        document.getElementById('statUnmapped').textContent=unmapped;
        document.getElementById('statSuggested').textContent=suggested;
        document.getElementById('statHigh').textContent=high;
        document.getElementById('statReview').textContent=review;
        document.getElementById('statUnsaved').textContent=unsaved;
        document.getElementById('statRisk').textContent=risk;
    }

    function showToast(msg,type) {
        var t=document.createElement('div');
        t.className='toast '+(type||'info');
        t.textContent=msg;
        document.body.appendChild(t);
        setTimeout(function(){t.remove();},3500);
    }

    /* ---- Tabulator dropdown editor formatter ---- */
    function finalMappingFormatter(cell) {
        var val = cell.getValue();
        if (val && optionsMap[val]) {
            var parts = optionsMap[val].split(' (');
            cell.getElement().style.color = '#1565c0';
            return parts[0];
        }
        if (val) { cell.getElement().style.color = '#1565c0'; return val; }
        cell.getElement().style.color = '#9e9e9e';
        cell.getElement().style.fontStyle = 'italic';
        return 'Select...';
    }

    function confidenceFormatter(cell) {
        var v = parseInt(cell.getValue()) || 0;
        var el = cell.getElement();
        el.style.textAlign = 'center';
        el.style.fontWeight = '600';
        if (v >= 90) el.style.color = '#2e7d32';
        else if (v >= 70) el.style.color = '#e65100';
        else if (v > 0) el.style.color = '#c62828';
        else el.style.color = '#9e9e9e';
        return v + '%';
    }

    function statusFormatter(cell) {
        var row = cell.getRow().getData();
        var code = row.final_mapping || row.current_mapping || '';
        var text = code ? 'Mapped' : 'Unmapped';
        var el = cell.getElement();
        el.style.textAlign = 'center';
        el.style.fontWeight = '600';
        el.style.fontSize = '0.78rem';
        if (text === 'Mapped') { el.style.color = '#2e7d32'; el.style.background = '#e8f5e9'; }
        else { el.style.color = '#c62828'; el.style.background = '#ffebee'; }
        return text;
    }

    function moneyFormatter(cell) {
        var v = cell.getValue();
        if (v === null || v === undefined || v === '') return '';
        return parseFloat(v).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    }

    /* ---- Row class function ---- */
    function rowClass(row) {
        var d = row.getData();
        var code = d.final_mapping || d.current_mapping || '';
        if (!code || code === '') {
            if (d.suggested && (d.confidence || 0) < 70) return 'row-lowconf';
            return 'row-unmapped';
        }
        return '';
    }

    /* ---- Initialize Tabulator ---- */
    function initTable() {
        var filtered = getFilteredData();
        var selectOptions = {};
        optionList.forEach(function(o) { selectOptions[o.label] = o.label; });

        table = new Tabulator('#hot', {
            data: filtered,
            layout: 'fitDataStretch',
            height: 'calc(100vh - 280px)',
            selectable: true,
            movableColumns: true,
            resizable: true,
            headerSortTristate: true,
            rowClass: rowClass,
            placeholder: 'No ledgers found',
            pagination: 'local',
            paginationSize: 250,
            paginationSizeSelector: [100, 250, 500, 1000, true],
            cellMouseOver: function(e, cell) {
                var el = cell.getElement();
                var field = cell.getField();
                if (field && !el.title) {
                    el.title = cell.getValue() || '';
                }
            },
            columns: [
                {title:'', formatter:'rowSelection', titleFormatter:'rowSelection', headerSort:false, width:45, hozAlign:'center', cellClick:function(e, cell){cell.getRow().toggleSelect();}},
                {title:'Ledger Name', field:'ledger_name', width:260, minWidth:200, frozen:true, headerTooltip:true},
                {title:'Parent Group', field:'parent_group', width:190, minWidth:150},
                {title:'Net Balance', field:'net_balance', width:140, minWidth:100, hozAlign:'right', formatter:moneyFormatter, accessorDownload:moneyFormatter},
                {title:'Dr/Cr', field:'drcr', width:70, minWidth:50, hozAlign:'center'},
                {title:'Current Mapping', field:'current_label', width:200, minWidth:150},
                {title:'Suggested', field:'suggested_label', width:240, minWidth:180},
                {title:'Source', field:'suggestion_source', width:130, minWidth:100},
                {title:'Conf %', field:'confidence', width:90, minWidth:70, hozAlign:'center', formatter:confidenceFormatter, accessorDownload:function(v){return (v||0)+'%';}},
                {title:'Final Mapping', field:'final_mapping', width:280, minWidth:220, editor:'select', editorParams:{values:selectOptions}, formatter:finalMappingFormatter, cellEdited:function(cell){
                    var row = cell.getRow().getData();
                    var val = cell.getValue();
                    var code = '';
                    if (val) {
                        for (var k=0;k<mappingOptions.length;k++) {
                            if (mappingOptions[k].label===val||mappingOptions[k].code===val||mappingOptions[k].id===val) { code=mappingOptions[k].id; break; }
                        }
                        if (!code) code = val;
                    }
                    row.final_mapping = code;
                    row.status = code ? 'Mapped' : 'Unmapped';
                    if (code !== row.current_mapping) { dirtyRows[row.ledger_name] = true; }
                    else { delete dirtyRows[row.ledger_name]; }
                    table.updateData([{ledger_name:row.ledger_name, final_mapping:code}]);
                    updateStats();
                }},
                {title:'Status', width:80, hozAlign:'center', formatter:statusFormatter, download:false},
                {title:'Risk', field:'risk_level', width:100, minWidth:80, hozAlign:'center', formatter:function(cell){
                    var v = cell.getValue();
                    var el = cell.getElement();
                    el.style.textAlign = 'center';
                    el.style.fontWeight = '600';
                    el.style.fontSize = '0.75rem';
                    if (v === 'critical') { el.style.color = '#dc2626'; return '🔴'; }
                    if (v === 'warning') { el.style.color = '#e65100'; return '⚠️'; }
                    if (v === 'review') { el.style.color = '#d97706'; return '👁️'; }
                    el.style.color = '#2e7d32';
                    return '✓';
                }, cellMouseOver:function(e, cell){
                    var row = cell.getRow().getData();
                    if (row.risk_reason) cell.getElement().title = row.risk_reason;
                }},
                {title:'Remarks', field:'remarks', width:240, minWidth:180, editor:'input', cellEdited:function(cell){
                    var row = cell.getRow().getData();
                    row.remarks = cell.getValue() || '';
                    dirtyRows[row.ledger_name] = true;
                    updateStats();
                }},
            ],
        });
    }

    function refreshGrid() {
        if (table) { table.destroy(); }
        initTable();
        refreshGroupPanel();
    }

    /* ---- View mode chip click ---- */
    document.getElementById('viewChips').addEventListener('click', function(e) {
        var chip = e.target.closest('.filter-chip');
        if (!chip) return;
        var view = chip.getAttribute('data-view');
        document.querySelectorAll('#viewChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');

        if (view === 'tb_impact') {
            allData = originalData.filter(function(r) {
                return r.has_tb === true;
            });
        } else {
            allData = originalData.slice();
        }
        dirtyRows = {};
        populateParentGroupDropdown();
        refreshGrid();
        highlightActiveTile();
    });

    /* ---- Filter chip click ---- */
    document.getElementById('filterChips').addEventListener('click', function(e) {
        var chip = e.target.closest('.filter-chip');
        if (!chip) return;
        var filter = chip.getAttribute('data-filter');
        document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');
        currentFilter = filter;
        refreshGrid();
        refreshGroupPanel();
        highlightActiveTile();
    });

    /* ---- Search ---- */
    var searchTimer;
    document.getElementById('hotSearch').addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { refreshGrid(); refreshGroupPanel(); }, 250);
    });

    /* ---- Search mode selector ---- */
    var searchModeMap = {
        'all': 'Search ledger, parent group, schedule, remarks\u2026',
        'ledger_name': 'Search ledger name\u2026',
        'parent_group': 'Search parent group\u2026',
        'schedule': 'Search schedule or mapping\u2026',
        'remarks': 'Search remarks\u2026',
    };
    document.getElementById('searchMode').addEventListener('change', function() {
        searchMode = this.value;
        document.getElementById('hotSearch').placeholder = searchModeMap[searchMode] || searchModeMap['all'];
        refreshGrid();
    });

    /* ---- Parent group dropdown filter ---- */
    function populateParentGroupDropdown() {
        var pgCounts = {};
        allData.forEach(function(r) {
            var pg = r.parent_group || '';
            if (!pg) return;
            pgCounts[pg] = (pgCounts[pg] || 0) + 1;
        });
        var sorted = Object.keys(pgCounts).sort(function(a, b) { return pgCounts[b] - pgCounts[a]; });
        var sel = document.getElementById('pgFilter');
        var current = sel.value;
        sel.innerHTML = '<option value="">All Parent Groups (' + allData.length + ')</option>';
        sorted.forEach(function(pg) {
            var opt = document.createElement('option');
            opt.value = pg;
            opt.textContent = pg + ' (' + pgCounts[pg] + ')';
            sel.appendChild(opt);
        });
        if (current && pgCounts[current]) sel.value = current;
    }
    document.getElementById('pgFilter').addEventListener('change', function() {
        currentParentGroup = this.value;
        refreshGrid();
        refreshGroupPanel();
    });

    /* ---- KPI tile click ---- */
    document.getElementById('wbSummary').addEventListener('click', function(e) {
        var card = e.target.closest('.wb-card[data-filter]');
        if (!card) return;
        var filter = card.getAttribute('data-filter');
        if (filter === 'unsaved') return;
        if (filter === 'tb_impact') {
            /* Switch to TB impact view */
            document.querySelectorAll('#viewChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            document.querySelector('#viewChips .filter-chip[data-view="tb_impact"]').classList.add('active');
            allData = originalData.filter(function(r) { return r.has_tb === true; });
            dirtyRows = {};
        } else if (filter === 'all') {
            document.querySelectorAll('#viewChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            document.querySelector('#viewChips .filter-chip[data-view="all"]').classList.add('active');
            allData = originalData.slice();
            dirtyRows = {};
        }
        currentFilter = (filter === 'all' || filter === 'tb_impact') ? 'all' : filter;
        /* Update filter chips */
        document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
        var targetChip = document.querySelector('#filterChips .filter-chip[data-filter="' + currentFilter + '"]');
        if (targetChip) targetChip.classList.add('active');
        else document.querySelector('#filterChips .filter-chip[data-filter="all"]').classList.add('active');
        refreshGrid();
        refreshGroupPanel();
        highlightActiveTile();
    });

    function highlightActiveTile() {
        document.querySelectorAll('#wbSummary .wb-card').forEach(function(c) { c.classList.remove('active-tile'); });
        var target = document.querySelector('#wbSummary .wb-card[data-filter="' + currentFilter + '"]');
        if (target) target.classList.add('active-tile');
        else document.querySelector('#wbSummary .wb-card[data-filter="all"]').classList.add('active-tile');
    }

    /* ---- Dynamic group panel builder ---- */
    function refreshGroupPanel() {
        var filtered = getFilteredData();
        var pgData = {};
        filtered.forEach(function(r) {
            var pg = r.parent_group || '';
            if (!pg) return;
            if (!pgData[pg]) pgData[pg] = { count: 0, mapped: 0, unmapped: 0, closing_dr: 0, closing_cr: 0, dominant: {}, risk: 0, suggested: null, confidence: 0 };
            var d = pgData[pg];
            d.count++;
            var code = r.final_mapping || r.current_mapping || '';
            if (code) { d.mapped++; } else { d.unmapped++; }
            d.closing_dr += parseFloat(r.closing_dr) || 0;
            d.closing_cr += parseFloat(r.closing_cr) || 0;
            if (r.risk_level === 'critical' || r.risk_level === 'warning') d.risk++;
            if (r.suggestion_source === 'parent_group_rule' && r.suggested) {
                d.suggested = r.suggested;
                d.confidence = r.confidence || 0;
            }
            if (code) { d.dominant[code] = (d.dominant[code] || 0) + 1; }
        });
        var sorted = Object.keys(pgData).sort(function(a, b) { return pgData[b].count - pgData[a].count; });
        var body = document.getElementById('groupMappingBody');
        var info = document.getElementById('groupPanelInfo');
        if (sorted.length === 0) {
            body.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:0.82rem;">No parent groups in current filter.</div>';
            info.textContent = '0 groups';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:0.78rem;"><thead><tr style="border-bottom:2px solid var(--border);">';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Parent Group</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Total</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Unmapped</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Dr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Cr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Net Balance</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Suggested</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Conf</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Risk</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Schedule III</th>';
        html += '<th style="text-align:center;padding:6px 8px;"></th>';
        html += '</tr></thead><tbody>';
        var showMax = Math.min(sorted.length, 30);
        for (var i = 0; i < showMax; i++) {
            var pg = sorted[i], d = pgData[pg];
            var netBal = d.closing_dr - d.closing_cr;
            var netColor = netBal < 0 ? 'color:var(--danger)' : '';
            var dominantCode = '';
            var maxCount = 0;
            Object.keys(d.dominant).forEach(function(k) { if (d.dominant[k] > maxCount) { maxCount = d.dominant[k]; dominantCode = k; } });
            var dominantLabel = dominantCode ? (optionsMap[dominantCode] || dominantCode) : '';
            var suggestedLabel = d.suggested ? (optionsMap[d.suggested] || d.suggested) : '';
            var confClass = d.confidence >= 90 ? 'color:var(--success)' : (d.confidence >= 70 ? 'color:var(--warning)' : 'color:var(--danger)');
            html += '<tr style="border-bottom:1px solid var(--border);" data-pg="' + escHtml(pg) + '">';
            html += '<td style="padding:6px 8px;font-weight:500;white-space:nowrap;">' + escHtml(pg) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + d.count + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;color:' + (d.unmapped > 0 ? 'var(--danger)' : 'var(--success)') + ';">' + d.unmapped + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(d.closing_dr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(d.closing_cr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;' + netColor + '">' + fmtMoney(netBal) + '</td>';
            html += '<td style="padding:6px 8px;">' + (dominantLabel || '<span style="color:var(--muted);">\u2014</span>') + '</td>';
            html += '<td style="padding:6px 8px;text-align:center;font-weight:600;' + confClass + '">' + d.confidence + '%</td>';
            html += '<td style="padding:6px 8px;text-align:center;">' + (d.risk > 0 ? '<span style="color:var(--danger);font-weight:600;">' + d.risk + '</span>' : '<span style="color:var(--success);">0</span>') + '</td>';
            html += '<td style="padding:6px 8px;"><select class="gm-select" data-pg="' + escHtml(pg) + '" style="padding:4px 8px;border:1px solid var(--border-strong);border-radius:4px;font-size:0.78rem;min-width:140px;"><option value="">Select...</option>';
            mappingOptions.forEach(function(o) {
                var sel = (o.id === d.suggested) ? ' selected' : '';
                html += '<option value="' + escHtml(o.id) + '"' + sel + '>' + escHtml(o.label) + '</option>';
            });
            html += '</select></td>';
            html += '<td style="padding:6px 8px;"><button class="btn btn-sm gm-apply-btn" data-pg="' + escHtml(pg) + '" style="padding:4px 10px;font-size:0.72rem;">Apply</button></td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        if (sorted.length > 30) {
            html += '<div style="margin-top:8px;font-size:0.75rem;color:var(--muted);">Showing 30 of ' + sorted.length + ' groups.</div>';
        }
        body.innerHTML = html;
        info.textContent = sorted.length + ' groups from ' + filtered.length + ' ledgers';
        bindGroupApplyButtons();
    }

    function bindGroupApplyButtons() {
        document.querySelectorAll('.gm-apply-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var pg = this.getAttribute('data-pg');
                var select = document.querySelector('.gm-select[data-pg="' + pg + '"]');
                if (!select || !select.value) {
                    showToast('Select a Schedule III head first', 'error');
                    return;
                }
                var scheduleCode = select.value;
                var count = 0;
                var filtered = getFilteredData();
                var filteredNames = {};
                filtered.forEach(function(r) { filteredNames[r.ledger_name] = true; });
                for (var i = 0; i < allData.length; i++) {
                    if (allData[i].parent_group !== pg) continue;
                    if (!filteredNames[allData[i].ledger_name]) continue;
                    var existingCode = allData[i].final_mapping || allData[i].current_mapping || '';
                    if (existingCode && existingCode !== '') continue;
                    if (allData[i].final_mapping !== scheduleCode) {
                        allData[i].final_mapping = scheduleCode;
                        allData[i].status = 'Mapped';
                        dirtyRows[allData[i].ledger_name] = true;
                        count++;
                    }
                }
                refreshGrid();
                updateStats();
                refreshGroupPanel();
                showToast(count + ' unmapped ledgers under "' + pg + '" mapped to ' + (optionsMap[scheduleCode] || scheduleCode), 'success');
            });
        });
    }

    /* ---- Accept all high confidence ---- */
    document.getElementById('btnAcceptHigh').addEventListener('click', function() {
        var count = 0;
        for (var i=0;i<allData.length;i++) {
            var d = allData[i];
            if (d.suggested && (d.confidence||0)>=90 && d.final_mapping!==d.suggested) {
                d.final_mapping = d.suggested;
                d.status = 'Mapped';
                dirtyRows[d.ledger_name] = true;
                count++;
            }
        }
        refreshGrid();
        updateStats();
        showToast(count+' ledgers auto-accepted (>= 90% confidence)', 'success');
    });

    /* ---- Accept group suggestions (visible rows only) ---- */
    document.getElementById('btnAcceptGroup').addEventListener('click', function() {
        if (!table) return;
        var rows = table.getRows();
        var count = 0;
        rows.forEach(function(row) {
            var d = row.getData();
            if (d.suggested && d.suggested !== '' && d.final_mapping !== d.suggested &&
                (d.suggestion_source === 'parent_group_rule' || d.suggestion_source === 'group_rule')) {
                d.final_mapping = d.suggested;
                d.status = 'Mapped';
                dirtyRows[d.ledger_name] = true;
                count++;
            }
        });
        refreshGrid();
        updateStats();
        refreshGroupPanel();
        showToast(count + ' group suggestions applied to visible rows', 'success');
    });

    /* ---- Accept selected ---- */
    document.getElementById('btnAcceptSelected').addEventListener('click', function() {
        if (!table) return;
        var selected = table.getSelectedRows();
        if (!selected.length) { showToast('Select rows first', 'error'); return; }
        var count = 0;
        selected.forEach(function(row) {
            var d = row.getData();
            if (d.suggested && d.suggested!=='' && d.final_mapping!==d.suggested) {
                d.final_mapping = d.suggested;
                d.status = 'Mapped';
                dirtyRows[d.ledger_name] = true;
                count++;
            }
        });
        refreshGrid();
        updateStats();
        showToast(count+' selected ledgers accepted', 'success');
    });

    /* ---- Bulk apply group ---- */
    document.getElementById('btnBulkApply').addEventListener('click', function() {
        var groupCode = document.getElementById('bulkGroupSelect').value;
        if (!groupCode) { showToast('Select a group first', 'error'); return; }
        if (!table) return;
        var selected = table.getSelectedRows();
        if (!selected.length) { showToast('Select rows first', 'error'); return; }
        var count = 0;
        selected.forEach(function(row) {
            var d = row.getData();
            d.final_mapping = groupCode;
            d.status = 'Mapped';
            dirtyRows[d.ledger_name] = true;
            count++;
        });
        refreshGrid();
        updateStats();
        showToast(count+' ledgers set to '+(optionsMap[groupCode]||groupCode), 'success');
    });

    /* ---- Reset ---- */
    document.getElementById('btnReset').addEventListener('click', function() {
        allData = JSON.parse(JSON.stringify(originalData));
        dirtyRows = {};
        populateParentGroupDropdown();
        refreshGrid();
        updateStats();
        showToast('All changes reset', 'info');
    });

    /* ---- Risk detection for dirty rows ---- */
    function detectRisksInDirtyRows() {
        var dirty = Object.keys(dirtyRows);
        var criticals = [];
        var warnings = [];
        var assetSchedules = ['ppe','inventory','receivables','cash','bank_balances_other','other_current_assets','investments_non_current','loans_non_current','intangible_assets','cwip','deferred_tax_asset','other_non_current_assets','investments_current','loans_current'];
        var liabilitySchedules = ['trade_payables','lt_borrowings','st_borrowings','other_current_liabilities','short_term_provisions','share_capital','reserves','deferred_tax_liability','other_non_current_liabilities','long_term_provisions'];
        var plVariants = ['profit & loss a/c','profit and loss a/c','profit & loss account','profit and loss account','p&l a/c','p and l a/c','surplus in statement of profit and loss'];
        var bankOdVariants = ['bank od','od account','overdraft','cash credit','bank overdraft','current account'];

        dirty.forEach(function(name) {
            for (var i=0;i<allData.length;i++) {
                var d = allData[i];
                if (d.ledger_name !== name) continue;
                var code = d.final_mapping || d.current_mapping || '';
                var group = (d.parent_group||'').toLowerCase();
                var closingCr = parseFloat(d.closing_cr)||0;
                var closingDr = parseFloat(d.closing_dr)||0;
                var riskLevel = 'none';
                var riskReason = '';

                // Critical: P&L A/c not mapped to reserves
                if (plVariants.indexOf(group) !== -1 && code !== 'reserves') {
                    riskLevel = 'critical';
                    riskReason = 'Profit & Loss A/c should map to Reserves (Equity). Current: ' + (code||'Unmapped');
                }
                // Critical: Bank OD/CC credit mapped to cash
                if (bankOdVariants.indexOf(group) !== -1 && closingCr > 0 && code === 'cash') {
                    riskLevel = 'critical';
                    riskReason = 'Bank OD/CC with credit balance should map to st_borrowings, not cash.';
                }
                // Critical: Patient advance credit mapped to receivables
                if ((group === 'advance in patient' || group === 'patient advance') && closingCr > 0 && code === 'receivables') {
                    riskLevel = 'critical';
                    riskReason = 'Patient advance with credit balance is a liability, not a receivable.';
                }
                // Critical: Insurance patient credit mapped to receivables
                if (group === 'insurance patient' && closingCr > 0 && code === 'receivables') {
                    riskLevel = 'critical';
                    riskReason = 'Insurance patient with credit balance is a liability, not a receivable.';
                }
                // Warning: Credit balance in asset schedule
                if (riskLevel === 'none' && closingCr > 0 && assetSchedules.indexOf(code) !== -1) {
                    riskLevel = 'warning';
                    riskReason = 'Credit balance in asset schedule.';
                }
                // Warning: Debit balance in liability/equity schedule
                if (riskLevel === 'none' && closingDr > 0 && liabilitySchedules.indexOf(code) !== -1) {
                    riskLevel = 'warning';
                    riskReason = 'Debit balance in liability/equity schedule.';
                }

                if (riskLevel === 'critical') criticals.push({name: d.ledger_name, group: d.parent_group, code: code, reason: riskReason});
                else if (riskLevel === 'warning') warnings.push({name: d.ledger_name, group: d.parent_group, code: code, reason: riskReason});
                break;
            }
        });
        return {criticals: criticals, warnings: warnings};
    }

    /* ---- Show risk modal ---- */
    function showRiskModal(title, items, type) {
        var existing = document.getElementById('riskModal');
        if (existing) existing.remove();

        var modal = document.createElement('div');
        modal.id = 'riskModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';

        var isCritical = type === 'critical';
        var accentColor = isCritical ? '#dc2626' : '#e65100';
        var icon = isCritical ? '&#9888;' : '&#9888;';

        var html = '<div style="background:#fff;border-radius:12px;max-width:600px;width:90%;max-height:80vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">';
        html += '<div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;">';
        html += '<div style="display:flex;align-items:center;gap:10px;">';
        html += '<span style="font-size:1.5rem;color:' + accentColor + ';">' + icon + '</span>';
        html += '<h3 style="margin:0;font-size:1.1rem;color:#1f2937;">' + title + '</h3>';
        html += '</div></div>';
        html += '<div style="padding:16px 24px;">';
        html += '<p style="font-size:0.9rem;color:#4b5563;margin-bottom:12px;">' + items.length + ' ledger(s) have mapping risks that should be reviewed before saving:</p>';
        html += '<div style="max-height:300px;overflow-y:auto;">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:0.82rem;">';
        html += '<thead><tr style="border-bottom:2px solid #e5e7eb;background:#f9fafb;"><th style="text-align:left;padding:8px;">Ledger</th><th style="text-align:left;padding:8px;">Schedule</th><th style="text-align:left;padding:8px;">Risk</th></tr></thead>';
        html += '<tbody>';
        var showMax = Math.min(items.length, 20);
        for (var idx = 0; idx < showMax; idx++) {
            var item = items[idx];
            html += '<tr style="border-bottom:1px solid #f3f4f6;">';
            html += '<td style="padding:8px;font-weight:500;">' + escHtml(item.name) + '</td>';
            html += '<td style="padding:8px;color:#6b7280;">' + escHtml(item.code) + '</td>';
            html += '<td style="padding:8px;color:' + (isCritical ? '#dc2626' : '#e65100') + ';font-weight:500;">' + escHtml(item.reason) + '</td>';
            html += '</tr>';
        }
        if (items.length > 20) {
            html += '<tr><td colspan="3" style="padding:8px;color:#6b7280;text-align:center;font-style:italic;">... and ' + (items.length - 20) + ' more</td></tr>';
        }
        html += '</tbody></table></div></div>';
        html += '<div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">';
        html += '<button class="btn btn-outline" onclick="document.getElementById(\'riskModal\').remove()" style="padding:8px 16px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:0.85rem;">Review Now</button>';
        html += '<button class="btn ' + (isCritical ? 'btn-danger' : 'btn-success') + '" id="riskModalConfirm" style="padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;color:#fff;background:' + (isCritical ? accentColor : '#16a34a') + ';">Save Anyway (' + items.length + ' risks)</button>';
        html += '</div></div>';
        modal.innerHTML = html;
        document.body.appendChild(modal);

        modal.addEventListener('click', function(e) {
            if (e.target === modal) modal.remove();
        });
        document.getElementById('riskModalConfirm').addEventListener('click', function() {
            modal.remove();
            executeSave();
        });
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function executeSave() {
        var dirty = Object.keys(dirtyRows);
        if (!dirty.length) { showToast('No changes to save', 'info'); return; }
        var mappings = {};
        var remarksMap = {};
        dirty.forEach(function(name) {
            for (var i=0;i<allData.length;i++) {
                if (allData[i].ledger_name===name && allData[i].final_mapping && allData[i].final_mapping!=='') {
                    mappings[name] = allData[i].final_mapping;
                    if (allData[i].remarks) remarksMap[name] = allData[i].remarks;
                    break;
                }
            }
        });
        if (Object.keys(mappings).length === 0) { showToast('No valid mappings to save', 'error'); return; }
        document.getElementById('statusText').textContent = 'Saving ' + Object.keys(mappings).length + ' mappings...';
        document.getElementById('btnSave').disabled = true;
        document.getElementById('btnSave').textContent = 'Saving...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_save.php', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},
            body: JSON.stringify({mappings:mappings, overrides:{}, remember:{}, remarks:remarksMap}),
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            document.getElementById('btnSave').disabled = false;
            document.getElementById('btnSave').textContent = '\uD83D\uDCBE Save Changes';
            if (resp.redirect) {
                showToast(resp.error || 'Session expired', 'error');
                setTimeout(function(){ window.location.href = ebalBaseUrl + 'login.php'; }, 1500);
                return;
            }
            if (resp.success) {
                var savedCount = resp.saved || 0;
                var conflictCount = resp.conflicts || 0;
                var errorCount = (resp.errors || []).length;
                /* Update all saved rows' current_mapping from final_mapping */
                Object.keys(mappings).forEach(function(name){
                    for(var i=0;i<allData.length;i++){
                        if(allData[i].ledger_name===name){
                            allData[i].current_mapping=mappings[name];
                            allData[i].current_label=optionsMap[mappings[name]]||mappings[name];
                            break;
                        }
                    }
                });
                /* Clear dirty rows only for successfully saved ones */
                dirtyRows = {};
                originalData = JSON.parse(JSON.stringify(allData));
                updateStats(); refreshGrid(); refreshGroupPanel(); populateParentGroupDropdown();
                var msg = savedCount + ' mappings saved.';
                if (conflictCount > 0) msg += ' ' + conflictCount + ' skipped (parent group conflict).';
                if (errorCount > 0) msg += ' ' + errorCount + ' errors.';
                if(resp.pending>0) msg+=' '+resp.pending+' remaining.';
                if(resp.mapping_complete) msg+=' All ledgers mapped!';
                document.getElementById('statusText').textContent='Saved '+savedCount+' rows';
                showToast(msg, savedCount > 0 ? 'success' : (conflictCount > 0 ? 'info' : 'error'));
            } else {
                document.getElementById('statusText').textContent='Save failed';
                showToast(resp.error||'Save failed','error');
            }
        })
        .catch(function(e){
            document.getElementById('btnSave').disabled=false;
            document.getElementById('btnSave').textContent = '\uD83D\uDCBE Save Changes';
            document.getElementById('statusText').textContent='Network error';
            showToast('Network error. Please try again.','error');
        });
    }

    /* ---- Save via AJAX ---- */
    document.getElementById('btnSave').addEventListener('click', function() {
        var dirty = Object.keys(dirtyRows);
        if (!dirty.length) { showToast('No changes to save', 'info'); return; }

        var risks = detectRisksInDirtyRows();
        if (risks.criticals.length > 0) {
            showRiskModal('Critical Mapping Risks Detected', risks.criticals, 'critical');
            return;
        }
        if (risks.warnings.length > 0) {
            showRiskModal('Mapping Warnings', risks.warnings, 'warning');
            return;
        }

        executeSave();
    });

    /* ---- Export via fetch with CSRF header ---- */
    document.getElementById('btnExport').addEventListener('click', function() {
        document.getElementById('statusText').textContent = 'Exporting...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_export.php?filter=all', {
            method: 'GET',
            headers: { 'X-CSRF-Token': csrfToken },
        })
        .then(function(r) {
            if (!r.ok) return r.json().then(function(d){ throw new Error(d.error || 'Export failed'); });
            return r.blob();
        })
        .then(function(blob) {
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'ledger_mapping_'+new Date().toISOString().slice(0,10)+'.xlsx';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            document.getElementById('statusText').textContent = 'Export complete';
        })
        .catch(function(e) {
            showToast(e.message || 'Export failed', 'error');
            document.getElementById('statusText').textContent = 'Export failed';
        });
    });

    /* ---- Import ---- */
    document.getElementById('btnImport').addEventListener('click', function() {
        document.getElementById('importFile').click();
    });

    document.getElementById('importFile').addEventListener('change', function() {
        var file = this.files[0];
        if (!file) return;
        var fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'validate');
        document.getElementById('statusText').textContent = 'Validating import...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_import.php', {
            method:'POST',
            headers:{'X-CSRF-Token':csrfToken},
            body: fd,
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            if (!resp.success) { showToast(resp.error||'Import failed','error'); document.getElementById('statusText').textContent='Import failed'; return; }
            if (resp.invalid_count>0) {
                if (confirm(resp.valid_count+' valid, '+resp.invalid_count+' invalid rows.\n\nSave only valid rows?')) { saveImportedRows(file); }
            } else if (resp.valid_count>0) {
                if (confirm(resp.valid_count+' rows to import. Save now?')) { saveImportedRows(file); }
            } else { showToast('No valid rows found','error'); }
        })
        .catch(function(){showToast('Import failed: network error','error');});
        document.getElementById('importFile').value='';
    });

    function saveImportedRows(file) {
        var fd = new FormData();
        fd.append('file', file);
        fd.append('action', 'save');
        document.getElementById('statusText').textContent = 'Importing...';
        fetch(ebalBaseUrl+'data_console/ajax_mapping_import.php', {
            method:'POST',
            headers:{'X-CSRF-Token':csrfToken},
            body: fd,
        })
        .then(function(r){return r.json();})
        .then(function(resp){
            if (resp.success) {
                showToast(resp.saved+' mappings imported','success');
                document.getElementById('statusText').textContent='Imported '+resp.saved+' rows';
                setTimeout(function(){window.location.reload();},1500);
            } else { showToast(resp.error||'Import failed','error'); document.getElementById('statusText').textContent='Import failed'; }
        })
        .catch(function(){showToast('Import failed: network error','error');});
    }

    /* ---- Init ---- */
    var clientStart = performance.now();
    populateParentGroupDropdown();
    var clientFilters = performance.now();
    initTable();
    var clientGrid = performance.now();
    updateStats();
    refreshGroupPanel();
    var clientTotal = performance.now();
    if (window.location.search.indexOf('debug=1') !== -1) {
        console.log('ReconHub client timing: filters=' + Math.round(clientFilters - clientStart) + 'ms, grid=' + Math.round(clientGrid - clientFilters) + 'ms, group_panel=' + Math.round(clientTotal - clientGrid) + 'ms, total=' + Math.round(clientTotal - clientStart) + 'ms');
    }

})();
</script>
<?php endif; /* $isLedgerMode */ ?>
<?php if ($isGroupMode): ?>
<!-- Group-wise Save + Render Script -->
<script>
(function() {
    'use strict';
    var ebalBaseUrl = <?= json_encode(BASE_URL) ?>;
    var csrfToken = <?= json_encode($csrfTokenValue) ?>;
    var mappingOptions = <?= json_encode($mappingOptionsJson) ?>;
    var groupMappingData = <?= json_encode($groupMappingData) ?>;
    var gridData = <?= json_encode($paginatedGridData) ?>;
    var optionsMap = {};
    var optionList = [];
    mappingOptions.forEach(function(o) { optionsMap[o.id] = o.label; optionList.push(o); });
    var groupDirty = {};
    var currentFilter = 'all';
    var currentParentGroup = '';
    var searchMode = 'all';

    function showToast(msg, type) {
        var t = document.createElement('div');
        t.className = 'toast ' + (type || 'info');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function() { t.remove(); }, 3500);
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtMoney(v) {
        if (v === null || v === undefined || v === '') return '';
        return parseFloat(v).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    /* ---- Filter logic (group-aware) ---- */
    function filterGroup(pg, d) {
        if (currentFilter === 'all' && !currentParentGroup) return true;
        if (currentParentGroup && pg !== currentParentGroup) return false;
        if (currentFilter === 'all') return true;
        switch (currentFilter) {
            case 'unmapped': return d._unmapped > 0;
            case 'mapped': return d._mapped > 0;
            case 'suggested': return d.confidence >= 70 && d._unmapped > 0;
            case 'high_conf': return d.confidence >= 90 && d._unmapped > 0;
            case 'low_conf': return d.confidence > 0 && d.confidence < 70 && d._unmapped > 0;
            case 'review': return d.confidence < 70 && d._unmapped > 0;
            case 'risky': return d.risk_count > 0;
            case 'critical': return d.risk_count > 0;
            default: return true;
        }
    }

    function searchGroup(pg) {
        var search = (document.getElementById('hotSearch').value || '').toLowerCase().trim();
        if (!search) return true;
        return pg.toLowerCase().indexOf(search) !== -1;
    }

    /* ---- Populate parent group dropdown ---- */
    function populateParentGroupDropdown() {
        var sel = document.getElementById('pgFilter');
        if (!sel) return;
        var current = sel.value;
        sel.innerHTML = '<option value="">All Parent Groups (' + groupMappingData.length + ')</option>';
        var sorted = groupMappingData.slice().sort(function(a, b) { return b.ledger_count - a.ledger_count; });
        sorted.forEach(function(g) {
            var opt = document.createElement('option');
            opt.value = g.parent_group;
            opt.textContent = g.parent_group + ' (' + g.ledger_count + ')';
            sel.appendChild(opt);
        });
        if (current) sel.value = current;
    }

    /* ---- Dynamic group panel builder ---- */
    function refreshGroupPanel() {
        /* Compute unmapped counts from grid data, respecting final_mapping (pending save) */
        var unmappedCounts = {};
        gridData.forEach(function(r) {
            var pg = r.parent_group || '';
            if (!pg) return;
            var mappedCode = r.final_mapping || r.current_mapping || '';
            if (!mappedCode || mappedCode === '') {
                unmappedCounts[pg] = (unmappedCounts[pg] || 0) + 1;
            }
        });

        var filtered = [];
        groupMappingData.forEach(function(g) {
            g._unmapped = unmappedCounts[g.parent_group] || 0;
            g._mapped = g.ledger_count - g._unmapped;
            if (filterGroup(g.parent_group, g) && searchGroup(g.parent_group)) {
                filtered.push(g);
            }
        });
        var body = document.getElementById('groupMappingBody');
        var info = document.getElementById('groupPanelInfo');
        if (!body || !info) return;
        if (filtered.length === 0) {
            body.innerHTML = '<div style="padding:12px;color:var(--muted);font-size:0.82rem;">No parent groups in current filter.</div>';
            info.textContent = '0 groups';
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;font-size:0.78rem;"><thead><tr style="border-bottom:2px solid var(--border);">';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Parent Group</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Total</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Unmapped</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Dr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Closing Cr</th>';
        html += '<th style="text-align:right;padding:6px 8px;font-weight:600;color:var(--muted);">Net Balance</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Suggested</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Conf</th>';
        html += '<th style="text-align:center;padding:6px 8px;font-weight:600;color:var(--muted);">Risk</th>';
        html += '<th style="text-align:left;padding:6px 8px;font-weight:600;color:var(--muted);">Schedule III</th>';
        html += '<th style="text-align:center;padding:6px 8px;"></th>';
        html += '</tr></thead><tbody>';
        var showMax = Math.min(filtered.length, 30);
        for (var i = 0; i < showMax; i++) {
            var g = filtered[i];
            var netBal = g.closing_dr - g.closing_cr;
            var netColor = netBal < 0 ? 'color:var(--danger)' : '';
            var dominantLabel = g.dominant_mapping ? (optionsMap[g.dominant_mapping] || g.dominant_mapping) : '';
            var suggestedLabel = g.suggested_code ? (optionsMap[g.suggested_code] || g.suggested_code) : '';
            var confClass = g.confidence >= 90 ? 'color:var(--success)' : (g.confidence >= 70 ? 'color:var(--warning)' : 'color:var(--danger)');
            html += '<tr style="border-bottom:1px solid var(--border);" data-pg="' + escHtml(g.parent_group) + '">';
            html += '<td style="padding:6px 8px;font-weight:500;white-space:nowrap;">' + escHtml(g.parent_group) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + g.ledger_count + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;color:' + (g._unmapped > 0 ? 'var(--danger)' : 'var(--success)') + ';">' + g._unmapped + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(g.closing_dr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;">' + fmtMoney(g.closing_cr) + '</td>';
            html += '<td style="padding:6px 8px;text-align:right;font-weight:600;' + netColor + '">' + fmtMoney(netBal) + '</td>';
            html += '<td style="padding:6px 8px;">' + (dominantLabel || '<span style="color:var(--muted);">\u2014</span>') + '</td>';
            html += '<td style="padding:6px 8px;text-align:center;font-weight:600;' + confClass + '">' + g.confidence + '%</td>';
            html += '<td style="padding:6px 8px;text-align:center;">' + (g.risk_count > 0 ? '<span style="color:var(--danger);font-weight:600;">' + g.risk_count + '</span>' : '<span style="color:var(--success);">0</span>') + '</td>';
            html += '<td style="padding:6px 8px;"><select class="gm-select" data-pg="' + escHtml(g.parent_group) + '" style="padding:4px 8px;border:1px solid var(--border-strong);border-radius:4px;font-size:0.78rem;min-width:140px;"><option value="">Select...</option>';
            optionList.forEach(function(o) {
                var sel = (o.id === g.suggested_code) ? ' selected' : '';
                html += '<option value="' + escHtml(o.id) + '"' + sel + '>' + escHtml(o.label) + '</option>';
            });
            html += '</select></td>';
            html += '<td style="padding:6px 8px;display:flex;gap:4px;flex-wrap:nowrap;">';
            html += '<button class="btn btn-sm gm-apply-btn" data-pg="' + escHtml(g.parent_group) + '" style="padding:4px 10px;font-size:0.72rem;">Apply</button>';
            if (g.risk_count > 0 || g._unmapped > 0) {
                html += '<button class="btn btn-sm gm-override-btn" data-pg="' + escHtml(g.parent_group) + '" style="padding:4px 8px;font-size:0.68rem;background:#7c3aed;color:#fff;border:none;border-radius:4px;cursor:pointer;" title="Include with manual override despite risk/conflict">Override</button>';
            }
            html += '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        if (filtered.length > 30) {
            html += '<div style="margin-top:8px;font-size:0.75rem;color:var(--muted);">Showing 30 of ' + filtered.length + ' groups.</div>';
        }
        body.innerHTML = html;
        info.textContent = filtered.length + ' groups from ' + gridData.length + ' ledgers';
    }

    /* ---- Filter chip click ---- */
    var filterChips = document.getElementById('filterChips');
    if (filterChips) {
        filterChips.addEventListener('click', function(e) {
            var chip = e.target.closest('.filter-chip');
            if (!chip) return;
            var filter = chip.getAttribute('data-filter');
            document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            chip.classList.add('active');
            currentFilter = filter;
            currentParentGroup = '';
            refreshGroupPanel();
        });
    }

    /* ---- Parent group dropdown filter ---- */
    var pgFilter = document.getElementById('pgFilter');
    if (pgFilter) {
        pgFilter.addEventListener('change', function() {
            currentParentGroup = this.value;
            refreshGroupPanel();
        });
    }

    /* ---- Search ---- */
    var searchTimer;
    var hotSearch = document.getElementById('hotSearch');
    if (hotSearch) {
        hotSearch.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(refreshGroupPanel, 250);
        });
    }

    /* ---- KPI tile click ---- */
    var wbSummary = document.getElementById('wbSummary');
    if (wbSummary) {
        wbSummary.addEventListener('click', function(e) {
            var card = e.target.closest('.wb-card[data-filter]');
            if (!card) return;
            var filter = card.getAttribute('data-filter');
            if (filter === 'unsaved' || filter === 'tb_impact') return;
            document.querySelectorAll('#wbSummary .wb-card').forEach(function(c) { c.classList.remove('active-tile'); });
            card.classList.add('active-tile');
            currentFilter = filter;
            currentParentGroup = '';
            document.querySelectorAll('#filterChips .filter-chip').forEach(function(c) { c.classList.remove('active'); });
            var target = document.querySelector('#filterChips .filter-chip[data-filter="' + filter + '"]');
            if (target) target.classList.add('active');
            else document.querySelector('#filterChips .filter-chip[data-filter="all"]').classList.add('active');
            refreshGroupPanel();
        });
    }

    /* ---- Mark group dirty on apply ---- */
    window.markGroupDirty = function(pg, scheduleCode) {
        groupDirty[pg] = scheduleCode;
        var unsaved = Object.keys(groupDirty).length;
        var el = document.getElementById('statUnsaved');
        if (el) el.textContent = unsaved;
    };

    /* ---- Group panel apply button handler ---- */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.gm-apply-btn');
        if (!btn) return;
        var pg = btn.getAttribute('data-pg');
        var select = document.querySelector('.gm-select[data-pg="' + pg + '"]');
        if (!select || !select.value) { showToast('Select a Schedule III head first', 'error'); return; }
        var scheduleCode = select.value;
        var count = 0;
        for (var i = 0; i < gridData.length; i++) {
            if (gridData[i].parent_group === pg && (!gridData[i].current_mapping || gridData[i].current_mapping === '')) {
                gridData[i].final_mapping = scheduleCode;
                count++;
            }
        }
        if (count === 0) { showToast('No unmapped ledgers under "' + pg + '"', 'info'); return; }
        markGroupDirty(pg, scheduleCode);
        refreshGroupPanel();
        showToast(count + ' unmapped ledgers under "' + pg + '" mapped to ' + (optionsMap[scheduleCode] || scheduleCode), 'success');
    });

    /* ---- Override & Include button handler ---- */
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.gm-override-btn');
        if (!btn) return;
        var pg = btn.getAttribute('data-pg');
        var select = document.querySelector('.gm-select[data-pg="' + pg + '"]');
        if (!select || !select.value) { showToast('Select a Schedule III head first', 'error'); return; }
        var scheduleCode = select.value;
        showOverrideModal(pg, scheduleCode);
    });

    function showOverrideModal(pg, scheduleCode) {
        var existing = document.getElementById('overrideModal');
        if (existing) existing.remove();
        var modal = document.createElement('div');
        modal.id = 'overrideModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        var label = optionsMap[scheduleCode] || scheduleCode;
        var html = '<div style="background:#fff;border-radius:12px;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.3);">';
        html += '<div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;"><h3 style="margin:0;font-size:1.05rem;color:#1f2937;">&#9998; Manual Override Confirmation</h3></div>';
        html += '<div style="padding:20px 24px;">';
        html += '<p style="font-size:0.88rem;color:#4b5563;margin-bottom:12px;">Group <strong>' + escHtml(pg) + '</strong> will be mapped to <strong>' + escHtml(label) + '</strong> via manual override.</p>';
        html += '<p style="font-size:0.82rem;color:#6b7285;margin-bottom:14px;">This group/ledger has a mapping risk or validation conflict. By overriding, you confirm that the classification has been professionally reviewed and should be included in the financial statements under the selected Schedule III head.</p>';
        html += '<div style="margin-bottom:12px;"><label style="font-size:0.82rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Override Reason / Remarks *</label>';
        html += '<textarea id="overrideReason" rows="3" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:0.85rem;box-sizing:border-box;" placeholder="Describe the professional review and rationale for inclusion..."></textarea></div>';
        html += '<div style="margin-bottom:4px;"><label style="font-size:0.82rem;color:#374151;display:flex;align-items:start;gap:8px;cursor:pointer;"><input type="checkbox" id="overrideConfirm" style="margin-top:3px;"><span>I confirm that this mapping has been reviewed and approved for financial statement inclusion.</span></label></div>';
        html += '</div>';
        html += '<div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;gap:10px;justify-content:flex-end;">';
        html += '<button onclick="document.getElementById(\'overrideModal\').remove()" style="padding:8px 16px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;font-size:0.85rem;background:#fff;">Cancel</button>';
        html += '<button id="overrideConfirmBtn" style="padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;color:#fff;background:#7c3aed;">Confirm Override</button>';
        html += '</div></div>';
        modal.innerHTML = html;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(ev) { if (ev.target === modal) modal.remove(); });
        document.getElementById('overrideConfirmBtn').addEventListener('click', function() {
            var reason = (document.getElementById('overrideReason').value || '').trim();
            var confirmed = document.getElementById('overrideConfirm').checked;
            if (!reason) { showToast('Override reason is required', 'error'); return; }
            if (!confirmed) { showToast('Please confirm the override', 'error'); return; }
            modal.remove();
            applyOverride(pg, scheduleCode, reason);
        });
    }

    function applyOverride(pg, scheduleCode, reason) {
        var count = 0;
        for (var i = 0; i < gridData.length; i++) {
            if (gridData[i].parent_group === pg) {
                gridData[i].final_mapping = scheduleCode;
                gridData[i].override_reason = reason;
                count++;
            }
        }
        markGroupDirty(pg, scheduleCode);
        groupDirty[pg + '::__override'] = reason;
        refreshGroupPanel();
        showToast(count + ' ledgers under "' + pg + '" included by manual override to ' + (optionsMap[scheduleCode] || scheduleCode), 'success');
    }

    /* ---- Group save ---- */
    var btnSave = document.getElementById('btnGroupSave');
    if (btnSave) {
        btnSave.addEventListener('click', function() {
            var dirty = Object.keys(groupDirty);
            if (!dirty.length) { showToast('No changes to save', 'info'); return; }
            var mappings = {};
            var overrides = {};
            var overrideReasons = {};
            dirty.forEach(function(pg) {
                var code = groupDirty[pg];
                var overrideReason = groupDirty[pg + '::__override'] || '';
                for (var i = 0; i < gridData.length; i++) {
                    var r = gridData[i];
                    if (r.parent_group === pg && (!r.current_mapping || r.current_mapping === '')) {
                        mappings[r.ledger_name] = r.final_mapping || code;
                        if (overrideReason || r.override_reason) {
                            overrides[r.ledger_name] = 1;
                            overrideReasons[r.ledger_name] = overrideReason || r.override_reason || '';
                        }
                    }
                }
            });
            if (!Object.keys(mappings).length) { showToast('No unmapped ledgers to save', 'info'); return; }
            document.getElementById('statusText').textContent = 'Saving ' + Object.keys(mappings).length + ' mappings...';
            btnSave.disabled = true;
            fetch(ebalBaseUrl + 'data_console/ajax_mapping_save.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({mappings: mappings, overrides: overrides, override_reasons: overrideReasons, remember: {}, remarks: {}}),
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                btnSave.disabled = false;
                btnSave.textContent = '\uD83D\uDCBE Save Changes';
                if (resp.redirect) {
                    showToast(resp.error || 'Session expired', 'error');
                    setTimeout(function() { window.location.href = ebalBaseUrl + 'login.php'; }, 1500);
                    return;
                }
                if (resp.success) {
                    var savedCount = resp.saved || 0;
                    var conflictCount = resp.conflicts || 0;
                    var errorCount = (resp.errors || []).length;
                    groupDirty = {};
                    var el = document.getElementById('statUnsaved');
                    if (el) el.textContent = '0';
                    var msg = savedCount + ' mappings saved.';
                    if (conflictCount > 0) {
                        msg += ' ' + conflictCount + ' skipped (parent group conflict).';
                        var details = (resp.conflict_details || []).map(function(c) {
                            return c.ledger_name + ': ' + c.parent_group + ' \u2194 ' + c.schedule_code;
                        }).join(', ');
                        if (details) msg += ' ' + details;
                    }
                    if (errorCount > 0) {
                        msg += ' ' + errorCount + ' error(s): ' + (resp.errors || []).join('; ');
                    }
                    if (resp.pending > 0) msg += ' ' + resp.pending + ' remaining.';
                    if (resp.mapping_complete) msg += ' All ledgers mapped!';
                    document.getElementById('statusText').textContent = 'Saved ' + savedCount + (conflictCount > 0 ? ', ' + conflictCount + ' conflicts' : '') + ' rows';
                    showToast(msg, savedCount > 0 ? 'success' : (conflictCount > 0 ? 'info' : 'error'));
                    setTimeout(function() { window.location.reload(); }, conflictCount > 0 ? 3000 : 1500);
                } else {
                    document.getElementById('statusText').textContent = 'Save failed';
                    showToast(resp.error || 'Save failed', 'error');
                }
            })
            .catch(function() {
                btnSave.disabled = false;
                document.getElementById('statusText').textContent = 'Network error';
                showToast('Network error. Please try again.', 'error');
            });
        });
    }

    /* ---- Group reset ---- */
    var btnReset = document.getElementById('btnGroupReset');
    if (btnReset) {
        btnReset.addEventListener('click', function() {
            groupDirty = {};
            for (var i = 0; i < gridData.length; i++) {
                gridData[i].final_mapping = '';
            }
            var el = document.getElementById('statUnsaved');
            if (el) el.textContent = '0';
            refreshGroupPanel();
            showToast('All changes reset', 'info');
        });
    }

    /* ---- Init ---- */
    populateParentGroupDropdown();
    refreshGroupPanel();

})();
</script>
<?php endif; /* $isGroupMode */ ?>