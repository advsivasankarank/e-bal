/**
 * DELIVERABLES WORKSPACE - Sprint 4D
 * Section expand/collapse, export actions, preview toggle
 */
(function () {
    'use strict';

    function init() {
        bindSectionToggles();
        bindExportActions();
    }

    function bindSectionToggles() {
        document.querySelectorAll('.dw-section-header').forEach(function (header) {
            header.addEventListener('click', function () {
                var body = this.nextElementSibling;
                var toggle = this.querySelector('.dw-toggle');
                if (body) body.classList.toggle('collapsed');
                if (toggle) toggle.classList.toggle('collapsed');
            });
        });
    }

    function bindExportActions() {
        document.querySelectorAll('.dw-export-trigger').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var format = this.getAttribute('data-format');
                var pkgType = this.getAttribute('data-pkg');
                var baseUrl = this.getAttribute('data-url');
                if (baseUrl) {
                    window.location.href = baseUrl + '?format=' + format;
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
