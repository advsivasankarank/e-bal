/**
 * e-BAL — Opening Balance Diagnostics Drill-down Panel
 *
 * Reusable modal comparing Tally's own reported opening balance (captured
 * at import time) against e-BAL's carried-forward expected opening, per
 * ledger. Backed by ajax_opening_balance_diagnostics.php (GET) and
 * ajax_opening_balance_diagnostics_resolve.php (POST). See
 * app/helpers/opening_balance_diagnostics_helper.php for the payload shape.
 */
(function () {
    'use strict';

    var state = {
        data: null,
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
        return (window.OB_DIAG_BASE_URL || '/').replace(/\/?$/, '/');
    }

    function ensureDom() {
        if (els.overlay) {
            return;
        }

        var overlay = document.createElement('div');
        overlay.id = 'ob-diag-overlay';
        overlay.className = 'bs-diag-overlay';
        overlay.innerHTML =
            '<div class="bs-diag-modal" role="dialog" aria-modal="true">' +
            '  <div class="bs-diag-header">' +
            '    <h2 class="bs-diag-title">Opening Balance Diagnostics</h2>' +
            '    <button type="button" class="bs-diag-close" aria-label="Close">&times;</button>' +
            '  </div>' +
            '  <div class="bs-diag-status"></div>' +
            '  <div class="ob-diag-body"></div>' +
            '</div>';
        document.body.appendChild(overlay);

        els.overlay = overlay;
        els.title = overlay.querySelector('.bs-diag-title');
        els.status = overlay.querySelector('.bs-diag-status');
        els.body = overlay.querySelector('.ob-diag-body');

        overlay.querySelector('.bs-diag-close').addEventListener('click', closeOpeningBalanceDiagnosticsPanel);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closeOpeningBalanceDiagnosticsPanel();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('bs-diag-open')) {
                closeOpeningBalanceDiagnosticsPanel();
            }
        });
    }

    function closeOpeningBalanceDiagnosticsPanel() {
        if (els.overlay) {
            els.overlay.classList.remove('bs-diag-open');
        }
    }

    window.openOpeningBalanceDiagnosticsPanel = function () {
        ensureDom();
        els.overlay.classList.add('bs-diag-open');
        loadDiagnostics();
    };

    window.closeOpeningBalanceDiagnosticsPanel = closeOpeningBalanceDiagnosticsPanel;

    function loadDiagnostics() {
        state.loading = true;
        state.error = null;
        render();

        fetch(baseUrl() + 'data_console/ajax_opening_balance_diagnostics.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (resp) { return resp.json(); })
            .then(function (json) {
                state.loading = false;
                if (!json.success) {
                    state.error = json.error || 'Could not load opening balance diagnostics.';
                    render();
                    return;
                }
                state.data = json;
                render();
            })
            .catch(function () {
                state.loading = false;
                state.error = 'Network error while loading diagnostics.';
                render();
            });
    }

    function render() {
        if (state.loading) {
            els.status.innerHTML = '';
            els.body.innerHTML = '<div class="bs-diag-empty">Loading…</div>';
            return;
        }

        if (state.error) {
            els.status.innerHTML = '<div class="bs-diag-banner bs-diag-banner-error">' + escapeHtml(state.error) + '</div>';
            els.body.innerHTML = '';
            return;
        }

        if (!state.data) {
            return;
        }

        var data = state.data;
        els.status.innerHTML = summaryBannerHtml(data);
        els.body.innerHTML = mismatchesTableHtml(data) + firstYearGapsTableHtml(data);
        wireRowActions();
    }

    function summaryBannerHtml(data) {
        var mismatchCount = data.mismatches.length;
        var gapCount = data.first_year_gaps.length;

        if (mismatchCount === 0 && gapCount === 0) {
            return '<div class="bs-diag-banner bs-diag-banner-success">✓ ' + data.compared +
                ' ledger(s) compared — opening balances match Tally. Nothing to review.</div>';
        }

        var parts = [];
        if (mismatchCount > 0) {
            parts.push(mismatchCount + ' ledger(s) with a mismatched opening balance');
        }
        if (gapCount > 0) {
            parts.push(gapCount + ' ledger(s) where Tally has an opening balance e-BAL doesn\'t have yet' +
                (data.has_previous_fy ? '' : ' (first year imported into e-BAL)'));
        }
        return '<div class="bs-diag-banner bs-diag-banner-warning">⚠ ' + escapeHtml(parts.join('; ')) + '.</div>';
    }

    function mismatchesTableHtml(data) {
        if (data.mismatches.length === 0) {
            return '';
        }

        var rows = data.mismatches.map(function (row) {
            return '<tr data-ledger-name="' + escapeHtml(row.ledger_name) + '">' +
                '<td>' + escapeHtml(row.ledger_name) + '</td>' +
                '<td class="ob-diag-figure">' + formatInr(row.tally_opening) + '</td>' +
                '<td class="ob-diag-figure">' + formatInr(row.app_expected_opening) + '</td>' +
                '<td class="ob-diag-figure ob-diag-diff">' + formatInr(row.difference) + '</td>' +
                '<td class="ob-diag-actions">' +
                '<button type="button" class="bs-diag-btn bs-diag-btn-primary" data-resolution="accept_tally">Accept Tally\'s Figure</button>' +
                '<button type="button" class="bs-diag-btn" data-resolution="keep_app">Keep e-BAL\'s Figure</button>' +
                '</td>' +
                '</tr>';
        }).join('');

        return '<h3 class="ob-diag-section-title">Mismatched Opening Balances</h3>' +
            '<div class="ob-diag-table-wrap"><table class="ob-diag-table">' +
            '<thead><tr><th>Ledger</th><th>Tally\'s Opening</th><th>e-BAL\'s Expected Opening</th><th>Difference</th><th></th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table></div>';
    }

    function firstYearGapsTableHtml(data) {
        if (data.first_year_gaps.length === 0) {
            return '';
        }

        var rows = data.first_year_gaps.map(function (row) {
            return '<tr data-ledger-name="' + escapeHtml(row.ledger_name) + '">' +
                '<td>' + escapeHtml(row.ledger_name) + '</td>' +
                '<td class="ob-diag-figure">' + formatInr(row.tally_opening) + '</td>' +
                '<td class="ob-diag-actions">' +
                '<button type="button" class="bs-diag-btn bs-diag-btn-primary" data-resolution="accept_tally">Use Tally\'s Figure</button>' +
                '</td>' +
                '</tr>';
        }).join('');

        return '<h3 class="ob-diag-section-title">Opening Balances Not Yet in e-BAL</h3>' +
            '<p class="ob-diag-section-note">Tally reports an opening balance for these ledgers, but e-BAL has none on file yet' +
            (data.has_previous_fy ? ' for this ledger' : ' — this is the first year this company has been imported into e-BAL') + '.</p>' +
            '<div class="ob-diag-table-wrap"><table class="ob-diag-table">' +
            '<thead><tr><th>Ledger</th><th>Tally\'s Opening</th><th></th></tr></thead>' +
            '<tbody>' + rows + '</tbody></table></div>';
    }

    function wireRowActions() {
        Array.prototype.forEach.call(els.body.querySelectorAll('[data-resolution]'), function (btn) {
            btn.addEventListener('click', function () {
                var row = btn.closest('tr');
                var ledgerName = row ? row.getAttribute('data-ledger-name') : '';
                resolve(ledgerName, btn.getAttribute('data-resolution'), btn);
            });
        });
    }

    function resolve(ledgerName, resolution, triggerBtn) {
        var rowButtons = triggerBtn.closest('tr').querySelectorAll('button');
        Array.prototype.forEach.call(rowButtons, function (b) { b.disabled = true; });
        triggerBtn.textContent = 'Saving…';

        fetch(baseUrl() + 'data_console/ajax_opening_balance_diagnostics_resolve.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken()
            },
            body: JSON.stringify({
                entity_id: window.OB_DIAG_ENTITY_ID,
                fy_id: window.OB_DIAG_FY_ID,
                ledger_name: ledgerName,
                resolution: resolution
            })
        })
            .then(function (resp) { return resp.json(); })
            .then(function (json) {
                if (!json.success) {
                    els.status.innerHTML = '<div class="bs-diag-banner bs-diag-banner-error">' + escapeHtml(json.error || 'Could not save this resolution.') + '</div>';
                    Array.prototype.forEach.call(rowButtons, function (b) { b.disabled = false; });
                    return;
                }

                state.data = json.diagnostics;
                render();
            })
            .catch(function () {
                els.status.innerHTML = '<div class="bs-diag-banner bs-diag-banner-error">Network error while saving.</div>';
                Array.prototype.forEach.call(rowButtons, function (b) { b.disabled = false; });
            });
    }
})();
