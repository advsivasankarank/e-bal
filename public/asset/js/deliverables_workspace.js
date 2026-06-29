/**
 * DELIVERABLES WORKSPACE - Sprint 4D
 * Section expand/collapse, preview toggle
 */
(function () {
    'use strict';

    function init() {
        bindSectionToggles();
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

    document.addEventListener('DOMContentLoaded', init);
})();
