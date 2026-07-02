/**
 * FINANCIALS WORKSPACE - Sprint 4B
 * Tab switching, View Mode toggle, WP sub-view, Year toggle, Section sidebar
 */
(function () {
    'use strict';

    var tabBar, canvas, sidebar, sidebarTitle, workspace;
    var viewBtns, wpBtns, yearBtns, togglePanel;
    var pages;

    function init() {
        tabBar = document.getElementById('fsTabBar');
        canvas = document.getElementById('fsCanvas');
        sidebar = document.getElementById('fsSectionList');
        sidebarTitle = document.getElementById('fsSectionTitle');
        workspace = document.getElementById('fsWorkspace');
        togglePanel = document.getElementById('fsTogglePanel');

        if (!tabBar || !canvas) return;

        pages = canvas.querySelectorAll('.report-page');
        viewBtns = document.querySelectorAll('.fs-view-btn');
        wpBtns = document.querySelectorAll('.fs-wp-btn');
        yearBtns = document.querySelectorAll('.fs-year-btn');

        assignDataTabs();
        restoreState();
        bindTabSwitch();
        bindViewMode();
        bindWPToggle();
        bindYearToggle();
        bindPanelToggle();
    }

    function assignDataTabs() {
        pages.forEach(function (p) {
            var id = p.getAttribute('id');
            if (id) p.setAttribute('data-tab', id);
        });
    }

    function restoreState() {
        var savedTab = sessionStorage.getItem('fs-active-tab');
        var savedView = sessionStorage.getItem('fs-view-mode') || 'statement';
        var savedWP = sessionStorage.getItem('fs-wp-mode') || 'statement';

        if (savedTab) {
            var btn = tabBar.querySelector('.fs-tab[data-tab="' + savedTab + '"]');
            if (btn) {
                tabBar.querySelectorAll('.fs-tab').forEach(function (t) { t.classList.remove('active'); });
                btn.classList.add('active');
            }
        }

        var activeTab = tabBar.querySelector('.fs-tab.active');
        switchTab(activeTab ? activeTab.getAttribute('data-tab') : null);

        setViewMode(savedView);
        setWPToggle(savedWP);
    }

    function bindTabSwitch() {
        tabBar.addEventListener('click', function (e) {
            var btn = e.target.closest('.fs-tab');
            if (!btn || btn.classList.contains('active')) return;
            if (btn.onclick) return;
            var tabId = btn.getAttribute('data-tab');
            if (!tabId) return;
            tabBar.querySelectorAll('.fs-tab').forEach(function (t) { t.classList.remove('active'); });
            btn.classList.add('active');
            switchTab(tabId);
            sessionStorage.setItem('fs-active-tab', tabId);
            setViewMode('statement');
            setWPToggle('statement');
        });
    }

    function switchTab(tabId) {
        pages.forEach(function (p) {
            p.style.display = p.getAttribute('data-tab') === tabId ? '' : 'none';
        });
        updateVisibility();
        buildSectionSidebar(tabId);
    }

    function updateVisibility() {
        var activeTab = tabBar.querySelector('.fs-tab.active');
        if (!activeTab) return;
        var tabId = activeTab.getAttribute('data-tab');
        var viewMode = sessionStorage.getItem('fs-view-mode') || 'statement';
        var wpMode = sessionStorage.getItem('fs-wp-mode') || 'statement';

        pages.forEach(function (p) {
            /* Never hide report pages inside #fsPrintPreview — they are the print content */
            var printPreview = document.getElementById('fsPrintPreview');
            if (printPreview && printPreview.contains(p)) {
                p.style.display = '';
                return;
            }

            if (p.getAttribute('data-tab') !== tabId) {
                p.style.display = 'none';
                return;
            }
            /* In spreadsheet or print mode, hide all report pages (handled by setViewMode) */
            if (viewMode !== 'statement') {
                p.style.display = 'none';
                return;
            }
            if (wpMode === 'working') {
                if (p.classList.contains('fs-wp-page')) {
                    p.style.display = '';
                } else {
                    p.style.display = 'none';
                }
            } else {
                if (p.classList.contains('fs-wp-page')) {
                    p.style.display = 'none';
                } else {
                    p.style.display = '';
                }
            }
        });
    }

    /* --- View Mode Toggle --- */
    function bindViewMode() {
        viewBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = this.getAttribute('data-view');
                setViewMode(mode);
                sessionStorage.setItem('fs-view-mode', mode);
            });
        });
    }

    function setViewMode(mode) {
        viewBtns.forEach(function (b) { b.classList.remove('active'); });
        var target = document.querySelector('.fs-view-btn[data-view="' + mode + '"]');
        if (target) target.classList.add('active');

        var statementView = document.getElementById('fsStatementView');
        var spreadsheetView = document.getElementById('fsSpreadsheetView');
        var printView = document.getElementById('fsPrintPreview');

        if (statementView) {
            statementView.style.display = mode === 'statement' ? '' : 'none';
        } else {
            /* No fsStatementView wrapper — toggle report pages directly */
            pages.forEach(function (p) {
                if (!p.classList.contains('fs-wp-page')) {
                    p.style.display = mode === 'statement' ? '' : 'none';
                }
            });
        }
        if (spreadsheetView) spreadsheetView.style.display = mode === 'spreadsheet' ? '' : 'none';
        if (printView) printView.style.display = mode === 'print' ? '' : 'none';

        if (mode === 'spreadsheet' && spreadsheetView && !spreadsheetView.dataset.initialized) {
            initSpreadsheet();
            spreadsheetView.dataset.initialized = '1';
        }
    }

    /* --- WP Sub-view Toggle --- */
    function bindWPToggle() {
        wpBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = this.getAttribute('data-wp');
                setWPToggle(mode);
                sessionStorage.setItem('fs-wp-mode', mode);
            });
        });
    }

    function setWPToggle(mode) {
        wpBtns.forEach(function (b) { b.classList.remove('active'); });
        var target = document.querySelector('.fs-wp-btn[data-wp="' + mode + '"]');
        if (target) target.classList.add('active');
        updateVisibility();
        var activeTab = tabBar.querySelector('.fs-tab.active');
        if (activeTab) buildSectionSidebar(activeTab.getAttribute('data-tab'));
    }

    /* --- Year Toggle --- */
    function bindYearToggle() {
        yearBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                yearBtns.forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');
                var year = this.getAttribute('data-year');
                applyYearFilter(year);
            });
        });
    }

    function applyYearFilter(year) {
        var prevCols = canvas.querySelectorAll('[data-prev]');
        var allFigs = canvas.querySelectorAll('.figure, th.figure');

        if (year === 'current') {
            prevCols.forEach(function (c) { c.style.display = 'none'; });
            allFigs.forEach(function (f) { f.style.display = f.hasAttribute('data-prev') ? 'none' : ''; });
        } else if (year === 'previous') {
            allFigs.forEach(function (f) {
                f.style.display = f.hasAttribute('data-prev') ? '' : 'none';
            });
            prevCols.forEach(function (c) { c.style.display = ''; });
        } else {
            prevCols.forEach(function (c) { c.style.display = ''; });
            allFigs.forEach(function (f) { f.style.display = ''; });
        }
    }

    /* --- Panel Toggle (Manual Inputs) --- */
    function bindPanelToggle() {
        if (!togglePanel) return;
        togglePanel.addEventListener('click', function () {
            workspace.classList.toggle('input-open');
            this.innerHTML = workspace.classList.contains('input-open') ? '&#9654; Hide' : '&#9664; Show';
        });
    }

    /* --- Section Sidebar --- */
    function buildSectionSidebar(tabId) {
        var activePage = null;
        var wpMode = sessionStorage.getItem('fs-wp-mode') || 'statement';
        pages.forEach(function (p) {
            if (p.getAttribute('data-tab') === tabId && p.style.display !== 'none') {
                activePage = p;
            }
        });
        if (!activePage) {
            if (sidebarTitle) sidebarTitle.textContent = 'Sections';
            if (sidebar) sidebar.innerHTML = '<div style="font-size:0.85rem;color:var(--muted);padding:8px;">No sections</div>';
            return;
        }

        var headings = activePage.querySelectorAll('h2, h3, h4, .note-heading, .fs-wp-section h4');
        var items = [];
        headings.forEach(function (h) {
            var text = h.textContent.trim().replace(/\s+/g, ' ');
            if (text.length > 0 && text.length < 100 && items.indexOf(text) < 0) {
                items.push(text);
            }
        });

        if (items.length === 0) {
            var boldRows = activePage.querySelectorAll('tr td b, tr td strong');
            boldRows.forEach(function (b) {
                var text = b.textContent.trim().replace(/\s+/g, ' ');
                if (text.length > 0 && text.length < 80 && items.indexOf(text) < 0) {
                    items.push(text);
                }
            });
        }

        if (sidebarTitle) {
            var titles = {
                'balance-sheet': 'Balance Sheet Sections',
                'profit-loss': 'P&L Sections',
                'trading-account': 'Trading A/c Sections',
                'income-expenditure': 'I&E Sections',
                'cash-flow': 'Cash Flow Sections',
                'notes-to-accounts': 'Note Sections',
                'directors-report': 'Report Sections'
            };
            sidebarTitle.textContent = (wpMode === 'working' ? 'WP: ' : '') + (titles[tabId] || 'Sections');
        }

        if (!sidebar) return;
        var html = '';
        items.forEach(function (item) {
            var dot = 'ok';
            if (item.indexOf('Inventory') >= 0 || item.indexOf('Stock') >= 0) dot = 'warn';
            html += '<div class="fs-section-item" data-section="' + escAttr(item) + '"><span class="dot ' + dot + '"></span>' + escHtml(item) + '</div>';
        });
        sidebar.innerHTML = html;

        sidebar.querySelectorAll('.fs-section-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var text = this.getAttribute('data-section');
                sidebar.querySelectorAll('.fs-section-item').forEach(function (s) { s.classList.remove('active'); });
                this.classList.add('active');
                scrollToSection(text);
            });
        });
    }

    function scrollToSection(text) {
        var allElements = canvas.querySelectorAll('h2, h3, h4, .note-heading, td b, td strong, .fs-wp-section h4');
        var bestMatch = null;
        allElements.forEach(function (el) {
            if (el.offsetParent === null) return;
            var t = el.textContent.trim().replace(/\s+/g, ' ');
            if (t === text || t.indexOf(text) >= 0 || text.indexOf(t) >= 0) {
                bestMatch = el;
            }
        });
        if (bestMatch) bestMatch.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* --- Handsontable (lazy) --- */
    function initSpreadsheet() {
        if (typeof Handsontable === 'undefined') return;
        var container = document.getElementById('fsHandsontableContainer');
        if (!container) return;
        var data = window.__fsSpreadsheetData || [];
        new Handsontable(container, {
            data: data,
            colHeaders: ['Particulars', 'Note', 'Current Year', 'Previous Year'],
            columns: [
                { data: 0, type: 'text', readOnly: true },
                { data: 1, type: 'text', readOnly: true, width: 60 },
                { data: 2, type: 'numeric', numericFormat: { pattern: '0,0.00', culture: 'en-IN' } },
                { data: 3, type: 'numeric', readOnly: true, numericFormat: { pattern: '0,0.00', culture: 'en-IN' } }
            ],
            rowHeaders: false,
            stretchH: 'all',
            width: '100%',
            height: 550,
            licenseKey: 'non-commercial-and-evaluation'
        });
    }

    function escHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(s) {
        if (!s) return '';
        return String(s).replace(/"/g, '&quot;').replace(/&/g, '&amp;');
    }

    document.addEventListener('DOMContentLoaded', init);
})();
