/**
 * REVIEW WORKSPACE - Sprint 4C
 * Section expand/collapse, remark CRUD, sign-off actions, severity selection
 */
(function () {
    'use strict';

    function init() {
        bindSectionToggles();
        bindCategoryToggles();
        bindSeverityButtons();
        bindRemarkActions();
        bindSignoffActions();
        bindTimelineToggle();
    }

    function bindSectionToggles() {
        document.querySelectorAll('.rw-section-header').forEach(function (header) {
            header.addEventListener('click', function () {
                var body = this.nextElementSibling;
                var toggle = this.querySelector('.rw-toggle');
                if (body) body.classList.toggle('collapsed');
                if (toggle) toggle.classList.toggle('collapsed');
            });
        });
    }

    function bindCategoryToggles() {
        document.querySelectorAll('.rw-v-category-header').forEach(function (header) {
            header.addEventListener('click', function () {
                var items = this.nextElementSibling;
                if (items) items.style.display = items.style.display === 'none' ? '' : 'none';
            });
        });
    }

    function bindSeverityButtons() {
        document.querySelectorAll('.rw-sev-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = this.closest('.rw-remark-severity');
                if (group) group.querySelectorAll('.rw-sev-btn').forEach(function (b) { b.classList.remove('active'); });
                this.classList.add('active');
                var hidden = this.closest('.rw-remark-body').querySelector('input[name*="severity"]');
                if (hidden) hidden.value = this.getAttribute('data-sev');
            });
        });
    }

    function bindRemarkActions() {
        document.querySelectorAll('.rw-remark-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var formData = new FormData(this);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function () {
                    if (xhr.status === 200) window.location.reload();
                };
                xhr.send(formData);
            });
        });

        document.querySelectorAll('.rw-resolve-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var section = this.getAttribute('data-section');
                var action = this.getAttribute('data-action');
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="review_action" value="' + action + '">' +
                    '<input type="hidden" name="remark_section" value="' + section + '">' +
                    '<input type="hidden" name="csrf_token" value="' + (document.querySelector('meta[name="csrf-token"]')?.content || '') + '">';
                document.body.appendChild(form);
                form.submit();
            });
        });
    }

    function bindSignoffActions() {
        document.querySelectorAll('.rw-signoff-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var role = this.getAttribute('data-role');
                var action = this.getAttribute('data-action');
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="review_action" value="signoff_' + action + '">' +
                    '<input type="hidden" name="signoff_role" value="' + role + '">' +
                    '<input type="hidden" name="csrf_token" value="' + (document.querySelector('meta[name="csrf-token"]')?.content || '') + '">';
                document.body.appendChild(form);
                form.submit();
            });
        });

        document.querySelectorAll('.rw-revoke-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var role = this.getAttribute('data-role');
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="review_action" value="signoff_revoke">' +
                    '<input type="hidden" name="signoff_role" value="' + role + '">' +
                    '<input type="hidden" name="csrf_token" value="' + (document.querySelector('meta[name="csrf-token"]')?.content || '') + '">';
                document.body.appendChild(form);
                form.submit();
            });
        });
    }

    function bindTimelineToggle() {
        var showMore = document.getElementById('rw-timeline-more');
        var hiddenEntries = document.querySelectorAll('.rw-timeline-entry.hidden');
        if (showMore && hiddenEntries.length > 0) {
            showMore.addEventListener('click', function () {
                hiddenEntries.forEach(function (e) { e.classList.remove('hidden'); e.style.display = ''; });
                this.style.display = 'none';
            });
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
