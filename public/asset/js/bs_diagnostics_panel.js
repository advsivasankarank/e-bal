/**
 * e-BAL — Balance Sheet Diagnostics Drill-down Panel
 *
 * Reusable modal, triggered from any of the diff banners (Financial
 * Statements, Trial Balance Preview, Quick Filters "Conflict" tag).
 * Backed by ajax_bs_diagnostics.php (GET) and
 * ajax_bs_diagnostics_resolve.php (POST). See app/helpers/bs_diagnostics_helper.php
 * for the shape of the payload this renders.
 */
(function () {
    'use strict';

    var TYPE_META = {
        validation_error: { icon: '❌', label: 'Structural error' },
        missing_note_heading: { icon: '📝', label: 'Missing note heading' },
        parent_group_conflict: { icon: '🚨', label: 'Parent-group conflict' },
        validation_warning: { icon: '⚠️', label: 'Warning' }
    };

    var state = {
        data: null,
        selectedId: null,
        loading: false,
        error: null
    };

    var els = {};

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatInr(amount) {
        var n = Number(amount) || 0;
        var negative = n < 0;
        n = Math.abs(n);
        var fixed = n.toFixed(2);
        var parts = fixed.split('.');
        var intPart = parts[0];
        var decPart = parts[1];
        var lastThree = intPart.slice(-3);
        var rest = intPart.slice(0, -3);
        if (rest !== '') {
            lastThree = ',' + lastThree;
            rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
        }
        return (negative ? '-₹' : '₹') + rest + lastThree + '.' + decPart;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function baseUrl() {
        return (window.BS_DIAG_BASE_URL || '/').replace(/\/?$/, '/');
    }

    function ensureDom() {
        if (els.overlay) {
            return;
        }

        var overlay = document.createElement('div');
        overlay.id = 'bs-diag-overlay';
        overlay.className = 'bs-diag-overlay';
        overlay.innerHTML =
            '<div class="bs-diag-modal" role="dialog" aria-modal="true">' +
            '  <div class="bs-diag-header">' +
            '    <h2 class="bs-diag-title"></h2>' +
            '    <button type="button" class="bs-diag-close" aria-label="Close">&times;</button>' +
            '  </div>' +
            '  <div class="bs-diag-status"></div>' +
            '  <div class="bs-diag-body">' +
            '    <div class="bs-diag-list"></div>' +
            '    <div class="bs-diag-detail"></div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(overlay);

        els.overlay = overlay;
        els.title = overlay.querySelector('.bs-diag-title');
        els.status = overlay.querySelector('.bs-diag-status');
        els.list = overlay.querySelector('.bs-diag-list');
        els.detail = overlay.querySelector('.bs-diag-detail');

        overlay.querySelector('.bs-diag-close').addEventListener('click', closeBsDiagnosticsPanel);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closeBsDiagnosticsPanel();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('bs-diag-open')) {
                closeBsDiagnosticsPanel();
            }
        });
    }

    function closeBsDiagnosticsPanel() {
        if (els.overlay) {
            els.overlay.classList.remove('bs-diag-open');
        }
    }

    window.openBsDiagnosticsPanel = function (focusIssueId) {
        ensureDom();
        els.overlay.classList.add('bs-diag-open');
        state.selectedId = focusIssueId || null;
        loadDiagnostics();
    };

    window.closeBsDiagnosticsPanel = closeBsDiagnosticsPanel;

    function loadDiagnostics() {
        state.loading = true;
        state.error = null;
        renderShell();

        fetch(baseUrl() + 'data_console/ajax_bs_diagnostics.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (resp) { return resp.json(); })
            .then(function (json) {
                state.loading = false;
                if (!json.success) {
                    state.error = json.error || 'Could not load diagnostics.';
                    renderShell();
                    return;
                }
                state.data = json;
                if (!state.selectedId && json.issues.length > 0) {
                    state.selectedId = json.issues[0].issue_id;
                }
                renderShell();
            })
            .catch(function () {
                state.loading = false;
                state.error = 'Network error while loading diagnostics.';
                renderShell();
            });
    }

    function renderShell() {
        if (state.loading) {
            els.title.textContent = 'Loading diagnostics…';
            els.status.innerHTML = '';
            els.list.innerHTML = '<div class="bs-diag-empty">Loading…</div>';
            els.detail.innerHTML = '';
            return;
        }

        if (state.error) {
            els.title.textContent = 'Balance sheet diagnostics';
            els.status.innerHTML = '<div class="bs-diag-banner bs-diag-banner-error">' + escapeHtml(state.error) + '</div>';
            els.list.innerHTML = '';
            els.detail.innerHTML = '';
            return;
        }

        if (!state.data) {
            return;
        }

        var data = state.data;
        var count = data.issues.length;
        els.title.textContent = 'Balance sheet residual: ' + formatInr(data.diff_amount) +
            ' — ' + count + (count === 1 ? ' issue' : ' issues') + ' found, ranked below';

        els.status.innerHTML = residualBannerHtml(data);
        renderList(data);
        renderDetail(data);
    }

    function residualBannerHtml(data) {
        if (data.issues.length === 0 && data.diff_amount > 0.01) {
            return '<div class="bs-diag-banner bs-diag-banner-warning">' +
                'Residual ' + formatInr(data.diff_amount) + ' remains — no further known issues detected, manual review required.' +
                '</div>';
        }
        if (data.issues.length === 0) {
            return '<div class="bs-diag-banner bs-diag-banner-success">No known issues detected.</div>';
        }
        return '';
    }

    function renderList(data) {
        if (data.issues.length === 0) {
            els.list.innerHTML = '<div class="bs-diag-empty">Nothing to show.</div>';
            return;
        }

        var html = data.issues.map(function (issue) {
            var meta = TYPE_META[issue.type] || { icon: '•', label: issue.type };
            var amount = issueAmount(issue);
            var active = issue.issue_id === state.selectedId ? ' bs-diag-row-active' : '';
            return '<button type="button" class="bs-diag-row' + active + '" data-issue-id="' + escapeHtml(issue.issue_id) + '">' +
                '<span class="bs-diag-row-icon">' + meta.icon + '</span>' +
                '<span class="bs-diag-row-main">' +
                '<span class="bs-diag-row-label">' + escapeHtml(issueLabel(issue)) + '</span>' +
                '<span class="bs-diag-row-type">' + escapeHtml(meta.label) + '</span>' +
                '</span>' +
                (amount !== null ? '<span class="bs-diag-row-amount">' + formatInr(amount) + '</span>' : '') +
                '</button>';
        }).join('');

        els.list.innerHTML = html;

        Array.prototype.forEach.call(els.list.querySelectorAll('.bs-diag-row'), function (btn) {
            btn.addEventListener('click', function () {
                state.selectedId = btn.getAttribute('data-issue-id');
                renderList(state.data);
                renderDetail(state.data);
            });
        });
    }

    function issueLabel(issue) {
        switch (issue.type) {
            case 'parent_group_conflict':
                return issue.ledger_name;
            case 'missing_note_heading':
                return issue.note_no ? ('Note ' + issue.note_no + ' - ' + issue.note_name) : issue.note_name;
            case 'validation_error':
                return issue.ledgers_affected + ' unmapped ledger(s)';
            case 'validation_warning':
                return issue.message;
            default:
                return issue.issue_id;
        }
    }

    function issueAmount(issue) {
        if (issue.type === 'parent_group_conflict') {
            return issue.ledger_balance;
        }
        if (issue.type === 'missing_note_heading' || issue.type === 'validation_error') {
            return issue.total_amount;
        }
        return null;
    }

    function findIssue(id) {
        if (!state.data) {
            return null;
        }
        for (var i = 0; i < state.data.issues.length; i++) {
            if (state.data.issues[i].issue_id === id) {
                return state.data.issues[i];
            }
        }
        return null;
    }

    function renderDetail(data) {
        var issue = findIssue(state.selectedId);
        if (!issue) {
            els.detail.innerHTML = '<div class="bs-diag-empty">Select an issue from the list.</div>';
            return;
        }

        var meta = TYPE_META[issue.type] || { icon: '•', label: issue.type };
        var html = '<div class="bs-diag-card">' +
            '<div class="bs-diag-card-head"><span>' + meta.icon + '</span><h3>' + escapeHtml(meta.label) + '</h3></div>' +
            '<p class="bs-diag-card-message">' + escapeHtml(issue.message || '') + '</p>';

        if (issue.type === 'parent_group_conflict') {
            html += renderConflictBody(issue);
        } else if (issue.type === 'missing_note_heading') {
            html += renderMissingHeadingBody(issue);
        } else if (issue.type === 'validation_error') {
            html += renderStructuralBody(issue);
        } else {
            html += '<div class="bs-diag-note">No further action available here for warnings — review from the Validation Centre.</div>';
        }

        html += '</div>';
        els.detail.innerHTML = html;
        wireDetailActions(issue);
    }

    function renderConflictBody(issue) {
        var rows = '';
        rows += kvRow('Tally group', issue.tally_group);
        rows += kvRow('Ledger nature (from Tally group)', issue.ledger_nature);
        rows += kvRow('Currently mapped to', issue.current_note + ' (' + issue.current_note_nature + ')');
        rows += kvRow('Ledger balance', formatInr(issue.ledger_balance) + ' ' + escapeHtml(issue.ledger_dr_cr || ''));

        var html = '<div class="bs-diag-kv">' + rows + '</div>';

        if (issue.auto_fixable && issue.suggested_note) {
            html += '<div class="bs-diag-suggestion">' +
                '<div>Suggested note: <strong>' + escapeHtml(issue.suggested_note) + '</strong> (' + escapeHtml(issue.suggested_note_nature || '') + ')</div>' +
                '<div class="bs-diag-actions">' +
                '<button type="button" class="bs-diag-btn bs-diag-btn-primary" data-action="apply_suggested">Apply fix</button>' +
                '<a class="bs-diag-btn" href="' + reviewManuallyUrl(issue) + '">Review manually</a>' +
                '</div>' +
                '<p class="bs-diag-fineprint">Applying this only resolves this one ledger’s mapping — it does not guarantee the Balance Sheet will balance.</p>' +
                '</div>';
        } else {
            var candidates = issue.suggested_note_candidates || [];
            if (candidates.length > 0) {
                var options = candidates.map(function (c) {
                    return '<option value="' + escapeHtml(c.note_id) + '">' + escapeHtml(c.label) + '</option>';
                }).join('');
                html += '<div class="bs-diag-suggestion">' +
                    '<div>Multiple notes match this ledger’s nature — pick the correct one:</div>' +
                    '<select class="bs-diag-select">' + options + '</select>' +
                    '<div class="bs-diag-actions">' +
                    '<button type="button" class="bs-diag-btn bs-diag-btn-primary" data-action="manual">Apply selected</button>' +
                    '<a class="bs-diag-btn" href="' + reviewManuallyUrl(issue) + '">Review manually</a>' +
                    '</div>' +
                    '</div>';
            } else {
                html += '<div class="bs-diag-actions">' +
                    '<a class="bs-diag-btn" href="' + reviewManuallyUrl(issue) + '">Review manually</a>' +
                    '</div>';
            }
        }

        return html;
    }

    function renderMissingHeadingBody(issue) {
        var rows = '';
        rows += kvRow('Ledgers affected', String(issue.ledgers_affected));
        rows += kvRow('Total amount', formatInr(issue.total_amount));
        rows += kvRow('Suggested action', issue.suggested_action);
        return '<div class="bs-diag-kv">' + rows + '</div>' +
            '<div class="bs-diag-actions"><a class="bs-diag-btn" href="' + notesMappingUrl() + '">Review manually</a></div>';
    }

    function renderStructuralBody(issue) {
        var rows = '';
        rows += kvRow('Ledgers affected', String(issue.ledgers_affected));
        rows += kvRow('Total amount', formatInr(issue.total_amount));
        var names = (issue.ledger_names || []).slice(0, 8).map(escapeHtml).join(', ');
        if (names) {
            rows += kvRow('Examples', names + (issue.ledgers_affected > 8 ? '…' : ''));
        }
        return '<div class="bs-diag-kv">' + rows + '</div>' +
            '<div class="bs-diag-actions"><a class="bs-diag-btn" href="' + notesMappingUrl('unmapped') + '">Review manually</a></div>';
    }

    function kvRow(label, value) {
        return '<div class="bs-diag-kv-row"><span class="bs-diag-kv-label">' + escapeHtml(label) + '</span>' +
            '<span class="bs-diag-kv-value">' + escapeHtml(value) + '</span></div>';
    }

    function reviewManuallyUrl(issue) {
        return baseUrl() + 'data_console/trial_balance_preview.php?filter_validation=conflict&filter_ledger=' + encodeURIComponent(issue.ledger_name || '');
    }

    function notesMappingUrl(filter) {
        var url = baseUrl() + 'data_console/trial_balance_preview.php';
        if (filter) {
            url += '?filter=' + encodeURIComponent(filter);
        }
        return url;
    }

    function wireDetailActions(issue) {
        var applyBtn = els.detail.querySelector('[data-action="apply_suggested"]');
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                applyFix(issue.issue_id, 'apply_suggested', null, applyBtn);
            });
        }
        var manualBtn = els.detail.querySelector('[data-action="manual"]');
        if (manualBtn) {
            manualBtn.addEventListener('click', function () {
                var select = els.detail.querySelector('.bs-diag-select');
                var noteId = select ? select.value : '';
                applyFix(issue.issue_id, 'manual', noteId, manualBtn);
            });
        }
    }

    function applyFix(issueId, action, noteId, triggerBtn) {
        if (triggerBtn) {
            triggerBtn.disabled = true;
            triggerBtn.textContent = 'Applying…';
        }

        var body = { entity_id: window.BS_DIAG_ENTITY_ID, fy_id: window.BS_DIAG_FY_ID, issue_id: issueId, action: action };
        if (noteId) {
            body.note_id = noteId;
        }

        fetch(baseUrl() + 'data_console/ajax_bs_diagnostics_resolve.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken()
            },
            body: JSON.stringify(body)
        })
            .then(function (resp) { return resp.json(); })
            .then(function (json) {
                if (!json.success) {
                    els.status.innerHTML = '<div class="bs-diag-banner bs-diag-banner-error">' + escapeHtml(json.error || 'Could not apply the fix.') + '</div>';
                    if (triggerBtn) {
                        triggerBtn.disabled = false;
                        triggerBtn.textContent = action === 'apply_suggested' ? 'Apply fix' : 'Apply selected';
                    }
                    return;
                }

                state.data = json.diagnostics;
                state.selectedId = null;
                if (state.data.issues.length > 0) {
                    state.selectedId = state.data.issues[0].issue_id;
                }

                var count = state.data.issues.length;
                els.title.textContent = 'Balance sheet residual: ' + formatInr(state.data.diff_amount) +
                    ' — ' + count + (count === 1 ? ' issue' : ' issues') + ' found, ranked below';
                var successBanner = '<div class="bs-diag-banner bs-diag-banner-success">Resolved: ' +
                    escapeHtml(json.ledger_name) + ' moved from "' + escapeHtml(json.before_note) +
                    '" to "' + escapeHtml(json.after_note) + '". This resolved that one issue — residual re-checked below.</div>';
                els.status.innerHTML = successBanner + residualBannerHtml(state.data);
                renderList(state.data);
                renderDetail(state.data);
            })
            .catch(function () {
                els.status.innerHTML = '<div class="bs-diag-banner bs-diag-banner-error">Network error while applying the fix.</div>';
                if (triggerBtn) {
                    triggerBtn.disabled = false;
                }
            });
    }
})();
