/**
 * Platform Control Centre - JavaScript
 * Pipeline configuration, health refresh, activity feed
 */
(function () {
    'use strict';

    function init() {
        bindPipelineToggle();
        bindHealthRefresh();
    }

    function bindPipelineToggle() {
        var toggle = document.getElementById('pipeline-toggle');
        var form = document.getElementById('pipeline-form');
        if (toggle && form) {
            toggle.addEventListener('click', function () {
                form.style.display = form.style.display === 'none' ? '' : 'none';
            });
        }
    }

    function bindHealthRefresh() {
        var btn = document.getElementById('health-refresh');
        if (btn) {
            btn.addEventListener('click', function () {
                window.location.reload();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
