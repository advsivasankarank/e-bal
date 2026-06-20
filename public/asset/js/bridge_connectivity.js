/**
 * e-BAL Smart Bridge — Connectivity Manager
 *
 * Manages bridge health detection, auto-retry, custom protocol launch,
 * header status indicators, workspace launch panel, and action locking.
 *
 * Architecture: Browser → Smart Bridge (localhost:9123) → Tally (9000)
 */
(function () {
    'use strict';

    /* ---- Configuration ---- */
    var BRIDGE_URL = (window.ebalBridgeUrl || 'http://127.0.0.1:9123').replace(/\/+$/, '');
    var HTTPS_BRIDGE_URL = BRIDGE_URL.replace(/^http:/, 'https:');
    var POLL_INTERVAL = 60000;        /* 60 seconds */
    var RETRY_INTERVAL = 3000;        /* 3 seconds during auto-retry */
    var RETRY_MAX = 20;               /* max retries after launch */
    var LAUNCH_WAIT_INITIAL = 2000;   /* wait before first retry after launch */

    /* ---- State ---- */
    var state = {
        bridge: 'unknown',   /* 'online' | 'offline' | 'unknown' */
        tally: 'unknown',    /* 'connected' | 'disconnected' | 'unknown' */
        version: '',
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
    function tryFetch(url) {
        return fetch(url, { mode: 'cors', cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) {
                    return r.text().then(function (t) {
                        throw new Error('Non-JSON (Content-Type: ' + (ct || 'none') + ')');
                    });
                }
                return r.json();
            });
    }

    function checkHealth() {
        var httpsUrl = HTTPS_BRIDGE_URL + '/health';
        var httpUrl = BRIDGE_URL + '/health';
        console.log('[Bridge] Health check → HTTPS:', httpsUrl, '| HTTP:', httpUrl);

        /* Try HTTPS first (no mixed content issues), then HTTP fallback */
        return tryFetch(httpsUrl)
            .catch(function (httpsErr) {
                console.log('[Bridge] HTTPS failed:', httpsErr && httpsErr.message ? httpsErr.message : httpsErr);
                console.log('[Bridge] Falling back to HTTP:', httpUrl);
                return tryFetch(httpUrl);
            })
            .then(function (data) {
                var wasOffline = state.bridge === 'offline';
                state.bridge = (data && data.ok) ? 'online' : 'offline';
                state.version = (data && data.version) || state.version || '';
                state.tally = state.bridge === 'online' ? 'connected' : 'unknown';
                updateIndicators();
                if (wasOffline && state.bridge === 'online') {
                    onBridgeReconnected();
                }
                console.log('[Bridge] Final state:', state.bridge);
                return state.bridge === 'online';
            })
            .catch(function (e) {
                console.error('[Bridge] All attempts failed:', e && e.message ? e.message : e);
                state.bridge = 'offline';
                state.tally = 'unknown';
                updateIndicators();
                return false;
            });
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

        /* Update launch panel status if open */
        var launchStatus = $('#bc-launch-status-val');
        if (launchStatus) {
            launchStatus.innerHTML = dotHtml(state.bridge) + ' ' + capitalize(state.bridge === 'unknown' ? 'checking' : state.bridge);
        }

        var launchVersion = $('#bc-launch-version');
        if (launchVersion && state.version) {
            launchVersion.textContent = state.version;
        }

        /* Dispatch event for other modules */
        if (typeof CustomEvent !== 'undefined') {
            document.dispatchEvent(new CustomEvent('bridge:status', {
                detail: { bridge: state.bridge, tally: state.tally, version: state.version }
            }));
        }
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
        state.polling = setInterval(checkHealth, POLL_INTERVAL);
    }

    function stopPolling() {
        if (state.polling) {
            clearInterval(state.polling);
            state.polling = null;
        }
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
            if (state.bridge === 'online') {
                showLaunchSuccess();
            } else {
                showLaunchFailed();
            }
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
        if (state.retryTimer) {
            clearTimeout(state.retryTimer);
            state.retryTimer = null;
        }
    }

    function updateRetryProgress() {
        var el = $('#bc-retry-text');
        if (el) {
            el.textContent = 'Checking connection... (' + state.retryCount + '/' + RETRY_MAX + ')';
        }
    }

    /* ---- Launch ---- */
    function launchBridge() {
        var btn = $('#bc-start-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Starting Smart Bridge...';
        }

        var prog = $('#bc-launch-progress');
        if (prog) prog.classList.add('active');

        var success = $('#bc-launch-success');
        if (success) success.classList.remove('active');

        /* Try custom protocol */
        try {
            window.location.href = 'ebalbridge://start';
        } catch (e) {
            /* protocol not registered — fallback shown */
        }

        /* Start retrying after a brief delay */
        setTimeout(function () {
            startRetry();
        }, LAUNCH_WAIT_INITIAL);
    }

    function onBridgeReconnected() {
        if (state.retrying) {
            stopRetry();
            showLaunchSuccess();
        }
    }

    function showLaunchSuccess() {
        var prog = $('#bc-launch-progress');
        if (prog) prog.classList.remove('active');

        var success = $('#bc-launch-success');
        if (success) success.classList.add('active');

        var btn = $('#bc-start-btn');
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Start Smart Bridge';
        }

        /* Auto-close launch panel after 1.5s */
        setTimeout(function () {
            closeLaunchPanel();
            updateIndicators();
        }, 1500);
    }

    function showLaunchFailed() {
        var prog = $('#bc-launch-progress');
        if (prog) prog.classList.remove('active');

        var btn = $('#bc-start-btn');
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Start Smart Bridge';
        }

        var el = $('#bc-retry-text');
        if (el) {
            el.textContent = 'Could not connect. Ensure Smart Bridge is installed and try again.';
            el.style.color = '#dc2626';
        }
    }

    /* ---- Panel (dropdown) ---- */
    function togglePanel() {
        if (state.panelOpen) closePanel();
        else openPanel();
    }

    function openPanel() {
        var overlay = $('#bc-panel-overlay');
        var panel = $('#bc-panel');
        if (overlay) overlay.classList.add('open');
        if (panel) panel.style.display = '';
        state.panelOpen = true;
        updateIndicators();
    }

    function closePanel() {
        var overlay = $('#bc-panel-overlay');
        var panel = $('#bc-panel');
        if (overlay) overlay.classList.remove('open');
        if (panel) panel.style.display = 'none';
        state.panelOpen = false;
    }

    /* ---- Launch Panel (workspace block) ---- */
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

    /* ---- Workspace Entry Check ---- */
    window.bridgeCheckWorkspace = function (opts) {
        opts = opts || {};
        return checkHealth().then(function (ok) {
            if (!ok) {
                openLaunchPanel();
                if (typeof opts.onOffline === 'function') opts.onOffline();
            } else {
                if (typeof opts.onOnline === 'function') opts.onOnline();
            }
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

    /* Listen for status changes to update locks */
    document.addEventListener('bridge:status', function () {
        applyActionLocking();
    });

    /* ---- Init ---- */
    function init() {
        /* Bind header widget click */
        var widget = $('#bc-status-widget');
        if (widget) {
            widget.addEventListener('click', function (e) {
                e.stopPropagation();
                togglePanel();
            });
        }

        /* Bind panel overlay click to close */
        var overlay = $('#bc-panel-overlay');
        if (overlay) {
            overlay.addEventListener('click', closePanel);
        }

        /* Bind panel close button */
        var closeBtn = $('#bc-panel-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closePanel();
            });
        }

        /* Bind launch panel buttons */
        var startBtn = $('#bc-start-btn');
        if (startBtn) {
            startBtn.addEventListener('click', launchBridge);
        }

        var retryBtn = $('#bc-retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                checkHealth().then(function (ok) {
                    if (!ok) {
                        var el = $('#bc-retry-text');
                        if (el) {
                            el.style.color = '#dc2626';
                            el.textContent = 'Still offline. Ensure Smart Bridge is running.';
                        }
                    }
                });
            });
        }

        var launchCloseBtn = $('#bc-launch-close');
        if (launchCloseBtn) {
            launchCloseBtn.addEventListener('click', closeLaunchPanel);
        }

        /* Close panels on Escape */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (state.launchOpen) closeLaunchPanel();
                else if (state.panelOpen) closePanel();
            }
        });

        /* Initial health check + start polling */
        console.log('[Bridge] Init — HTTPS:', HTTPS_BRIDGE_URL, '| HTTP:', BRIDGE_URL, '| Page:', location.href);
        checkHealth();
        startPolling();
    }

    /* Expose for manual trigger */
    window.ebalBridge = {
        check: checkHealth,
        state: state,
        openPanel: openPanel,
        closePanel: closePanel,
        openLaunch: openLaunchPanel,
        closeLaunch: closeLaunchPanel
    };

    /* Boot */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
