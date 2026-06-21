/**
 * e-BAL Smart Bridge — Connectivity Manager v2
 *
 * Manages bridge health detection, auto-retry, custom protocol launch,
 * header status indicators, workspace launch panel, and action locking.
 *
 * Architecture: Browser -> Smart Bridge (localhost:9123) -> Tally (9000)
 *
 * Health response format (v2):
 * { ok, bridge, tally, version, uptime_seconds }
 */
(function () {
    'use strict';

    /* ---- Configuration ---- */
    var BRIDGE_URL = (window.ebalBridgeUrl || 'http://127.0.0.1:9123').replace(/\/+$/, '');
    var POLL_INTERVAL = 60000;
    var RETRY_INTERVAL = 3000;
    var RETRY_MAX = 20;
    var LAUNCH_WAIT_INITIAL = 2000;

    function log() { try { console.log.apply(console, ['[Bridge]'].concat(Array.prototype.slice.call(arguments))); } catch(e){} }
    function logErr() { try { console.error.apply(console, ['[Bridge]'].concat(Array.prototype.slice.call(arguments))); } catch(e){} }

    /* ---- State ---- */
    var state = {
        bridge: 'unknown',
        tally: 'unknown',
        version: '',
        uptime: 0,
        lastSync: '',
        lastUpload: '',
        syncing: false,
        polling: null,
        retrying: false,
        retryCount: 0,
        retryTimer: null,
        panelOpen: false,
        launchOpen: false
    };

    /* ---- DOM helpers ---- */
    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return (ctx || document).querySelectorAll(sel); }

    /* ---- Health Check ---- */
    function checkHealth() {
        var url = BRIDGE_URL + '/health';
        log('Health check:', url);

        return fetch(url, { mode: 'cors', cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) throw new Error('Non-JSON');
                return r.json();
            })
            .then(function (data) {
                var wasOffline = state.bridge === 'offline';
                state.bridge = (data && data.ok) ? 'online' : 'offline';
                state.tally = (data && data.tally) ? data.tally : 'unknown';
                state.version = (data && data.version) || state.version || '';
                state.uptime = (data && data.uptime_seconds) || 0;
                updateIndicators();
                updatePanel();
                if (wasOffline && state.bridge === 'online') onBridgeReconnected();
                log('Result: bridge=' + state.bridge + ' tally=' + state.tally);
                return state.bridge === 'online';
            })
            .catch(function (e) {
                logErr('Health check failed:', e.message);
                state.bridge = 'offline';
                state.tally = 'unknown';
                updateIndicators();
                updatePanel();
                return false;
            });
    }

    /* Fetch detailed status from /status endpoint */
    function fetchStatus() {
        var url = BRIDGE_URL + '/status';
        return fetch(url, { mode: 'cors', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    state.lastSync = data.last_sync || '';
                    state.lastUpload = data.last_upload || '';
                    state.syncing = data.syncing || false;
                    state.uptime = data.uptime_seconds || 0;
                    updatePanel();
                }
            })
            .catch(function () {});
    }

    /* ---- Indicator Updates ---- */
    function updateIndicators() {
        var dots = $$('.bc-dot');
        for (var i = 0; i < dots.length; i++) {
            var dot = dots[i];
            var kind = dot.getAttribute('data-status-kind');
            var val = kind === 'bridge' ? state.bridge : state.tally;
            dot.className = 'bc-dot bc-dot--' + statusColor(val);
        }

        var vals = $$('.bc-status-val');
        for (var j = 0; j < vals.length; j++) {
            var el = vals[j];
            var k = el.getAttribute('data-status-kind');
            var v = k === 'bridge' ? state.bridge : state.tally;
            el.textContent = capitalize(v === 'unknown' ? 'checking' : v);
        }

        var launchStatus = $('#bc-launch-status-val');
        if (launchStatus) {
            launchStatus.innerHTML = dotHtml(state.bridge) + ' ' + capitalize(state.bridge === 'unknown' ? 'checking' : state.bridge);
        }

        var launchVersion = $('#bc-launch-version');
        if (launchVersion && state.version) {
            launchVersion.textContent = state.version;
        }

        if (typeof CustomEvent !== 'undefined') {
            document.dispatchEvent(new CustomEvent('bridge:status', {
                detail: { bridge: state.bridge, tally: state.tally, version: state.version }
            }));
        }
    }

    function updatePanel() {
        var versionEl = $('#bc-panel-version');
        if (versionEl) versionEl.textContent = state.version || '—';

        var uptimeEl = $('#bc-panel-uptime');
        if (uptimeEl) uptimeEl.textContent = formatUptime(state.uptime);

        var syncEl = $('#bc-panel-last-sync');
        if (syncEl) syncEl.textContent = state.lastSync || 'Never';

        var uploadEl = $('#bc-panel-last-upload');
        if (uploadEl) uploadEl.textContent = state.lastUpload || 'None';
    }

    function formatUptime(s) {
        if (!s || s < 1) return '—';
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm ' + (s % 60) + 's';
    }

    function statusColor(val) {
        if (val === 'online' || val === 'connected') return 'green';
        if (val === 'waiting') return 'yellow';
        return 'red';
    }

    function dotHtml(val) {
        return '<span class="bc-dot bc-dot--' + statusColor(val) + '"></span>';
    }

    function capitalize(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }

    /* ---- Polling ---- */
    function startPolling() {
        stopPolling();
        state.polling = setInterval(function () {
            checkHealth();
            fetchStatus();
        }, POLL_INTERVAL);
    }

    function stopPolling() {
        if (state.polling) { clearInterval(state.polling); state.polling = null; }
    }

    /* ---- Auto Retry ---- */
    function startRetry() {
        if (state.retrying) return;
        state.retrying = true;
        state.retryCount = 0;
        doRetry();
    }

    function doRetry() {
        if (state.retryCount >= RETRY_MAX || state.bridge === 'online') {
            state.retrying = false;
            if (state.bridge === 'online') showLaunchSuccess();
            else showLaunchFailed();
            return;
        }
        state.retryCount++;
        updateRetryProgress();
        state.retryTimer = setTimeout(function () {
            checkHealth().then(function (ok) {
                if (!ok) doRetry();
            });
        }, RETRY_INTERVAL);
    }

    function stopRetry() {
        state.retrying = false;
        if (state.retryTimer) { clearTimeout(state.retryTimer); state.retryTimer = null; }
    }

    function updateRetryProgress() {
        var el = $('#bc-retry-text');
        if (el) el.textContent = 'Checking connection... (' + state.retryCount + '/' + RETRY_MAX + ')';
    }

    /* ---- Launch ---- */
    function launchBridge() {
        var btn = $('#bc-start-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Starting Smart Bridge...'; }
        var prog = $('#bc-launch-progress');
        if (prog) prog.classList.add('active');
        var success = $('#bc-launch-success');
        if (success) success.classList.remove('active');

        try { window.location.href = 'ebalbridge://start'; } catch (e) {}
        setTimeout(function () { startRetry(); }, LAUNCH_WAIT_INITIAL);
    }

    function onBridgeReconnected() {
        if (state.retrying) { stopRetry(); showLaunchSuccess(); }
    }

    function showLaunchSuccess() {
        var prog = $('#bc-launch-progress');
        if (prog) prog.classList.remove('active');
        var success = $('#bc-launch-success');
        if (success) success.classList.add('active');
        var btn = $('#bc-start-btn');
        if (btn) { btn.disabled = false; btn.textContent = 'Start Smart Bridge'; }
        setTimeout(function () { closeLaunchPanel(); updateIndicators(); }, 1500);
    }

    function showLaunchFailed() {
        var prog = $('#bc-launch-progress');
        if (prog) prog.classList.remove('active');
        var btn = $('#bc-start-btn');
        if (btn) { btn.disabled = false; btn.textContent = 'Start Smart Bridge'; }
        var el = $('#bc-retry-text');
        if (el) { el.style.color = '#dc2626'; el.textContent = 'Could not connect. Ensure Smart Bridge is installed and try again.'; }
    }

    /* ---- Panel (dropdown) ---- */
    function togglePanel() {
        if (state.panelOpen) closePanel(); else openPanel();
    }

    function openPanel() {
        var overlay = $('#bc-panel-overlay');
        var panel = $('#bc-panel');
        if (overlay) overlay.classList.add('open');
        if (panel) panel.style.display = '';
        state.panelOpen = true;
        updateIndicators();
        fetchStatus();
    }

    function closePanel() {
        var overlay = $('#bc-panel-overlay');
        var panel = $('#bc-panel');
        if (overlay) overlay.classList.remove('open');
        if (panel) panel.style.display = 'none';
        state.panelOpen = false;
    }

    /* ---- Launch Panel ---- */
    function openLaunchPanel() {
        var el = $('#bc-launch-overlay');
        if (el) el.classList.add('open');
        state.launchOpen = true;
    }

    function closeLaunchPanel() {
        var el = $('#bc-launch-overlay');
        if (el) el.classList.remove('open');
        state.launchOpen = false;
        stopRetry();
    }

    /* ---- Bridge Actions ---- */
    function fetchCompanies() {
        if (state.bridge !== 'online') { alert('Smart Bridge is not connected.'); return; }
        var btn = $('#bc-btn-companies');
        if (btn) btn.disabled = true;

        fetch(BRIDGE_URL + '/companies', { mode: 'cors' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btn) btn.disabled = false;
                if (data.ok && data.companies) {
                    document.dispatchEvent(new CustomEvent('bridge:companies', { detail: data }));
                    alert('Found ' + data.companies.length + ' companies:\n\n' + data.companies.join('\n'));
                } else {
                    alert('Error: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(function (e) {
                if (btn) btn.disabled = false;
                alert('Failed to fetch companies: ' + e.message);
            });
    }

    function fetchLedgers() {
        if (state.bridge !== 'online') { alert('Smart Bridge is not connected.'); return; }
        var btn = $('#bc-btn-ledgers');
        if (btn) btn.disabled = true;

        fetch(BRIDGE_URL + '/company', { mode: 'cors' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btn) btn.disabled = false;
                if (data.ok && data.company) {
                    document.dispatchEvent(new CustomEvent('bridge:ledger', { detail: data }));
                    var c = data.company;
                    alert('Active Company:\n' + (c.name || 'N/A') + '\n' + (c.state_name || c.state || '') + '\n' + (c.country_name || ''));
                } else {
                    alert('Error: ' + (data.message || 'Tally may not be open'));
                }
            })
            .catch(function (e) {
                if (btn) btn.disabled = false;
                alert('Failed: ' + e.message);
            });
    }

    function fetchTB() {
        if (state.bridge !== 'online') { alert('Smart Bridge is not connected.'); return; }
        alert('Trial Balance fetch is triggered from the workspace. Use the TB import button in the data console.');
    }

    /* ---- Workspace Entry Check ---- */
    window.bridgeCheckWorkspace = function (opts) {
        opts = opts || {};
        return checkHealth().then(function (ok) {
            if (!ok) { openLaunchPanel(); if (typeof opts.onOffline === 'function') opts.onOffline(); }
            else { if (typeof opts.onOnline === 'function') opts.onOnline(); }
            return ok;
        });
    };

    /* ---- Action Locking ---- */
    function applyActionLocking() {
        var lockEls = $$('[data-bridge-lock]');
        for (var i = 0; i < lockEls.length; i++) {
            var el = lockEls[i];
            if (state.bridge !== 'online') {
                if (!el.classList.contains('bc-locked')) {
                    el.classList.add('bc-locked');
                    var tip = document.createElement('div');
                    tip.className = 'bc-locked-tooltip';
                    tip.innerHTML = '<span class="bc-locked-tooltip-text">Smart Bridge is not connected</span>';
                    el.appendChild(tip);
                }
            } else {
                el.classList.remove('bc-locked');
                var existingTip = el.querySelector('.bc-locked-tooltip');
                if (existingTip) existingTip.remove();
            }
        }
    }

    document.addEventListener('bridge:status', function () { applyActionLocking(); });

    /* ---- Init ---- */
    function init() {
        var widget = $('#bc-status-widget');
        if (widget) widget.addEventListener('click', function (e) { e.stopPropagation(); togglePanel(); });

        var overlay = $('#bc-panel-overlay');
        if (overlay) overlay.addEventListener('click', closePanel);

        var closeBtn = $('#bc-panel-close');
        if (closeBtn) closeBtn.addEventListener('click', function (e) { e.stopPropagation(); closePanel(); });

        var startBtn = $('#bc-start-btn');
        if (startBtn) startBtn.addEventListener('click', launchBridge);

        var retryBtn = $('#bc-retry-btn');
        if (retryBtn) retryBtn.addEventListener('click', function () {
            checkHealth().then(function (ok) {
                if (!ok) {
                    var el = $('#bc-retry-text');
                    if (el) { el.style.color = '#dc2626'; el.textContent = 'Still offline. Ensure Smart Bridge is running.'; }
                }
            });
        });

        var launchCloseBtn = $('#bc-launch-close');
        if (launchCloseBtn) launchCloseBtn.addEventListener('click', closeLaunchPanel);

        var companiesBtn = $('#bc-btn-companies');
        if (companiesBtn) companiesBtn.addEventListener('click', fetchCompanies);

        var ledgersBtn = $('#bc-btn-ledgers');
        if (ledgersBtn) ledgersBtn.addEventListener('click', fetchLedgers);

        var tbBtn = $('#bc-btn-tb');
        if (tbBtn) tbBtn.addEventListener('click', fetchTB);

        var refreshBtn = $('#bc-btn-refresh');
        if (refreshBtn) refreshBtn.addEventListener('click', function () {
            checkHealth().then(function () { fetchStatus(); });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (state.launchOpen) closeLaunchPanel();
                else if (state.panelOpen) closePanel();
            }
        });

        log('Init — BRIDGE_URL:', BRIDGE_URL);
        checkHealth().then(function () { fetchStatus(); });
        startPolling();
    }

    /* Expose for manual trigger */
    window.ebalBridge = {
        check: checkHealth,
        state: state,
        openPanel: openPanel,
        closePanel: closePanel,
        openLaunch: openLaunchPanel,
        closeLaunch: closeLaunchPanel,
        fetchCompanies: fetchCompanies,
        fetchLedgers: fetchLedgers,
        fetchTB: fetchTB
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
