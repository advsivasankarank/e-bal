/**
 * e-BAL Workspace Launcher
 * Handles entity navigation, FY timeline, intelligence panel, and workspace launch.
 */
(function() {
    'use strict';

    /* ---- State ---- */
    var selectedCompanyId = null;
    var selectedFYId = null;
    var companyCache = {};

    /* ---- DOM refs ---- */
    var entitySearch = document.getElementById('entity-search');
    var entityList = document.getElementById('entity-list');
    var fyPanelTitle = document.getElementById('fy-panel-title');
    var fyPanelSubtitle = document.getElementById('fy-panel-subtitle');
    var fyTimeline = document.getElementById('fy-timeline');
    var fyEmpty = document.getElementById('fy-empty');
    var fyList = document.getElementById('fy-list');
    var noFYMessage = document.getElementById('no-fy-message');
    var intelPanel = document.getElementById('intelligence-panel');
    var launchZone = document.getElementById('launch-zone');
    var launchCompany = document.getElementById('launch-company');
    var launchFY = document.getElementById('launch-fy');
    var launchBtn = document.getElementById('launch-btn');
    var gridSearch = document.getElementById('grid-search');

    /* ---- Entity Search ---- */
    if (entitySearch) {
        entitySearch.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var items = entityList.querySelectorAll('.awl-entity-item');
            var visible = 0;
            for (var i = 0; i < items.length; i++) {
                var search = items[i].getAttribute('data-entity-search') || '';
                var match = !q || search.indexOf(q) !== -1;
                items[i].style.display = match ? '' : 'none';
                if (match) visible++;
            }
        });

        /* Keyboard shortcut: / to focus search */
        document.addEventListener('keydown', function(e) {
            if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                e.preventDefault();
                entitySearch.focus();
            }
        });
    }

    /* ---- Select Entity ---- */
    window.selectEntity = function(companyId) {
        selectedCompanyId = companyId;
        selectedFYId = null;

        /* Highlight selected entity */
        var items = entityList.querySelectorAll('.awl-entity-item');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('selected', parseInt(items[i].getAttribute('data-entity-id')) === companyId);
        }

        /* Load FY data */
        loadFinancialYears(companyId);
    };

    /* ---- Load Financial Years ---- */
    function loadFinancialYears(companyId) {
        /* Show loading state */
        fyEmpty.style.display = 'none';
        fyList.style.display = 'none';
        noFYMessage.style.display = 'none';
        launchZone.style.display = 'none';
        intelPanel.style.display = 'none';

        /* Loading skeleton */
        fyList.innerHTML = '<div class="awl-fy-loading"><div class="awl-skeleton"></div><div class="awl-skeleton"></div><div class="awl-skeleton"></div></div>';
        fyList.style.display = '';
        fyPanelTitle.textContent = 'Loading...';
        fyPanelSubtitle.textContent = 'Fetching financial periods...';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', ebalBaseUrl + 'company_dashboard/financial_year_ajax.php?company_id=' + companyId);
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.ok && data.company) {
                        renderFYTimeline(data.company, data.fys || []);
                    } else {
                        showError(data.message || 'Failed to load data');
                    }
                } catch(e) {
                    showError('Invalid response');
                }
            } else if (xhr.status === 403) {
                showError('Access denied');
            } else {
                showError('Server error');
            }
        };
        xhr.onerror = function() {
            showError('Network error');
        };
        xhr.send();
    }

    /* ---- Render FY Timeline ---- */
    function renderFYTimeline(company, fys) {
        /* Update panel header */
        fyPanelTitle.textContent = company.name;
        fyPanelSubtitle.textContent = company.category ? company.category.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }) : '';

        /* Render intelligence panel */
        renderIntelligence(company, fys);

        if (fys.length === 0) {
            fyList.style.display = 'none';
            noFYMessage.style.display = '';
            launchZone.style.display = 'none';
            return;
        }

        noFYMessage.style.display = 'none';
        fyEmpty.style.display = 'none';

        /* Build FY list */
        var html = '';
        for (var i = 0; i < fys.length; i++) {
            var fy = fys[i];
            var statusClass = fy.status === 'completed' ? 'completed' :
                              fy.status === 'ready_for_review' ? 'ready' :
                              fy.status === 'new' ? 'new' : 'active';
            var currentBadge = fy.is_current ? '<span class="awl-fy-current">Current</span>' : '';
            var statusLabel = fy.status === 'completed' ? 'Completed' :
                              fy.status === 'ready_for_review' ? 'Ready for Review' :
                              fy.status === 'new' ? 'Not Started' : 'In Progress';
            var lastMod = fy.last_modified ? timeAgo(fy.last_modified) : '';

            html += '<div class="awl-fy-item" data-fy-id="' + fy.id + '" onclick="selectFY(' + fy.id + ', \'' + escapeAttr(fy.fy_label) + '\')">' +
                '<div class="awl-fy-label">' + escapeHtml(fy.fy_label) + currentBadge + '</div>' +
                '<div class="awl-fy-meta">' +
                    '<span class="awl-fy-status ' + statusClass + '">' + statusLabel + '</span>' +
                    '<span class="awl-fy-progress">' + fy.progress_pct + '%</span>' +
                    (lastMod ? '<span class="awl-fy-modified">' + lastMod + '</span>' : '') +
                '</div>' +
                '<div class="awl-fy-bar"><div class="awl-fy-bar-fill" style="width:' + fy.progress_pct + '%"></div></div>' +
            '</div>';
        }

        fyList.innerHTML = html;
        fyList.style.display = '';

        /* Auto-select current FY */
        for (var j = 0; j < fys.length; j++) {
            if (fys[j].is_current) {
                selectFY(fys[j].id, fys[j].fy_label);
                return;
            }
        }

        /* Auto-select first FY if only one */
        if (fys.length === 1) {
            selectFY(fys[0].id, fys[0].fy_label);
        }
    }

    /* ---- Select FY ---- */
    window.selectFY = function(fyId, fyLabel) {
        selectedFYId = fyId;

        /* Highlight selected FY */
        var items = fyList.querySelectorAll('.awl-fy-item');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('selected', parseInt(items[i].getAttribute('data-fy-id')) === fyId);
        }

        /* Show launch zone */
        launchZone.style.display = '';
        var companyName = '';
        var items2 = entityList.querySelectorAll('.awl-entity-item');
        for (var j = 0; j < items2.length; j++) {
            if (parseInt(items2[j].getAttribute('data-entity-id')) === selectedCompanyId) {
                companyName = items2[j].querySelector('.awl-entity-name').textContent;
                break;
            }
        }
        launchCompany.textContent = companyName;
        launchFY.textContent = 'FY ' + fyLabel;
        launchBtn.href = ebalBaseUrl + 'assignment_home.php?company_id=' + selectedCompanyId + '&fy_id=' + selectedFYId;
    };

    /* ---- Launch Workspace ---- */
    window.launchWorkspace = function() {
        if (!selectedCompanyId || !selectedFYId) return;
        window.location.href = ebalBaseUrl + 'assignment_home.php?company_id=' + selectedCompanyId + '&fy_id=' + selectedFYId;
    };

    /* ---- Render Intelligence Panel ---- */
    function renderIntelligence(company, fys) {
        intelPanel.style.display = '';

        document.getElementById('intel-type').textContent = company.category ? company.category.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); }) : '—';
        document.getElementById('intel-pan').textContent = company.pan || '—';
        document.getElementById('intel-cin').textContent = company.cin || company.llp_code || '—';
        document.getElementById('intel-fy-count').textContent = fys.length + ' period' + (fys.length !== 1 ? 's' : '');

        /* Current FY status */
        var currentFY = null;
        for (var i = 0; i < fys.length; i++) {
            if (fys[i].is_current) { currentFY = fys[i]; break; }
        }
        if (currentFY) {
            var statusText = currentFY.status === 'completed' ? 'Completed' :
                            currentFY.status === 'ready_for_review' ? 'Ready for Review' :
                            currentFY.status === 'new' ? 'Not Started' : 'In Progress (' + currentFY.progress_pct + '%)';
            document.getElementById('intel-status').textContent = statusText;
        } else {
            document.getElementById('intel-status').textContent = 'No current FY';
        }

        /* Last activity */
        var lastMod = '';
        for (var j = 0; j < fys.length; j++) {
            if (fys[j].last_modified && (!lastMod || fys[j].last_modified > lastMod)) {
                lastMod = fys[j].last_modified;
            }
        }
        document.getElementById('intel-activity').textContent = lastMod ? timeAgo(lastMod) : '—';
    }

    /* ---- Grid Search ---- */
    if (gridSearch) {
        gridSearch.addEventListener('input', function() {
            var q = this.value.toLowerCase().trim();
            var cards = document.querySelectorAll('.awl-card');
            var visible = 0;
            for (var i = 0; i < cards.length; i++) {
                var search = cards[i].getAttribute('data-search') || '';
                var match = !q || search.indexOf(q) !== -1;
                cards[i].style.display = match ? '' : 'none';
                if (match) visible++;
            }
        });
    }

    /* ---- Filter buttons ---- */
    var filterBtns = document.querySelectorAll('.awl-filter-btn');
    for (var f = 0; f < filterBtns.length; f++) {
        filterBtns[f].addEventListener('click', function() {
            for (var j = 0; j < filterBtns.length; j++) filterBtns[j].classList.remove('active');
            this.classList.add('active');
            var filter = this.getAttribute('data-filter');
            var cards = document.querySelectorAll('.awl-card');
            for (var k = 0; k < cards.length; k++) {
                var status = cards[k].getAttribute('data-status');
                var show = filter === 'all' ||
                    (filter === 'needs_action' && (status === 'new' || status === 'in_progress')) ||
                    (filter === 'completed' && status === 'completed');
                cards[k].style.display = show ? '' : 'none';
            }
        });
    }

    /* ---- Open Assignment (from grid) ---- */
    window.openAssignment = function(companyId, fyId) {
        /* Track recent */
        try {
            var recent = JSON.parse(localStorage.getItem('v2-recent-assignments') || '[]');
            var cards = document.querySelectorAll('.awl-card');
            var name = '';
            for (var i = 0; i < cards.length; i++) {
                if (parseInt(cards[i].getAttribute('data-company-id')) === companyId &&
                    parseInt(cards[i].getAttribute('data-fy-id')) === fyId) {
                    name = cards[i].querySelector('.awl-card-title').textContent.trim();
                    break;
                }
            }
            recent = recent.filter(function(r) { return !(r.companyId === companyId && r.fyId === fyId); });
            recent.unshift({ companyId: companyId, fyId: fyId, name: name, ts: Date.now() });
            if (recent.length > 5) recent = recent.slice(0, 5);
            localStorage.setItem('v2-recent-assignments', JSON.stringify(recent));
        } catch(e) {}
        window.location.href = ebalBaseUrl + 'assignment_home.php?company_id=' + companyId + '&fy_id=' + fyId;
    };

    /* ---- Helpers ---- */
    function escapeHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function escapeAttr(s) {
        return s.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    }

    function showError(msg) {
        fyList.style.display = 'none';
        fyEmpty.style.display = 'none';
        noFYMessage.style.display = 'none';
        fyPanelTitle.textContent = 'Error';
        fyPanelSubtitle.textContent = msg;
    }

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        var date = new Date(dateStr.replace(' ', 'T') + 'Z');
        var now = new Date();
        var diff = Math.floor((now - date) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
    }

})();
