/**
 * e-BAL V2 — Application Shell JavaScript
 *
 * Handles:
 *   1. Sidebar collapse / expand
 *   2. Persist sidebar state in localStorage
 *   3. Mobile sidebar toggle
 *   4. Active nav item detection
 *
 * Does NOT handle:
 *   - Context switching
 *   - Workflow logic
 *   - Any business logic
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'v2-sidebar-collapsed';
  var sidebar = document.querySelector('.v2-sidebar');
  var toggle = document.querySelector('.v2-sidebar-toggle');
  var overlay = document.querySelector('.v2-mobile-overlay');

  if (!sidebar || !toggle) return;

  /* ---- SIDEBAR STATE ---- */

  var isHoverExpand = false;
  var hoverTimer = null;

  function isMobile() {
    return window.matchMedia('(max-width: 768px)').matches;
  }

  function isDesktop() {
    return window.matchMedia('(min-width: 1025px)').matches;
  }

  function isCollapsed() {
    return sidebar.classList.contains('collapsed');
  }

  function setCollapsed(state) {
    if (state) {
      sidebar.classList.add('collapsed');
    } else {
      sidebar.classList.remove('collapsed');
    }
    try {
      localStorage.setItem(STORAGE_KEY, state ? '1' : '0');
    } catch (e) {
      /* localStorage unavailable — silent fail */
    }
  }

  function restoreState() {
    try {
      var stored = localStorage.getItem(STORAGE_KEY);
      if (stored === '1') {
        setCollapsed(true);
      } else if (stored === '0') {
        setCollapsed(false);
      } else {
        /* Default: collapsed for hover-expand on desktop */
        setCollapsed(true);
      }
    } catch (e) {
      setCollapsed(true);
    }
  }

  /* ---- TOGGLE ---- */

  function handleToggle() {
    if (isMobile()) {
      /* Mobile: slide sidebar in/out as overlay */
      sidebar.classList.toggle('mobile-open');
      if (overlay) {
        overlay.classList.toggle('visible', sidebar.classList.contains('mobile-open'));
      }
    } else {
      /* Desktop/laptop: collapse/expand */
      setCollapsed(!isCollapsed());
    }
  }

  toggle.addEventListener('click', handleToggle);

  /* ---- HOVER EXPAND (Desktop) ---- */

  if (isDesktop()) {
    sidebar.addEventListener('mouseenter', function () {
      if (isMobile()) return;
      clearTimeout(hoverTimer);
      sidebar.classList.add('hover-expand');
    });

    sidebar.addEventListener('mouseleave', function () {
      if (isMobile()) return;
      hoverTimer = setTimeout(function () {
        sidebar.classList.remove('hover-expand');
      }, 200);
    });
  }

  /* ---- MOBILE OVERLAY CLOSE ---- */

  if (overlay) {
    overlay.addEventListener('click', function () {
      sidebar.classList.remove('mobile-open');
      overlay.classList.remove('visible');
    });
  }

  /* ---- CLOSE SIDEBAR ON NAV CLICK (mobile) ---- */

  var navItems = sidebar.querySelectorAll('.v2-nav-item');
  for (var i = 0; i < navItems.length; i++) {
    navItems[i].addEventListener('click', function () {
      if (isMobile() && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        if (overlay) {
          overlay.classList.remove('visible');
        }
      }
    });
  }

  /* ---- ESC KEY CLOSES MOBILE SIDEBAR ---- */

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && sidebar.classList.contains('mobile-open')) {
      sidebar.classList.remove('mobile-open');
      if (overlay) {
        overlay.classList.remove('visible');
      }
    }
  });

  /* ---- ACTIVE NAV DETECTION ---- */

  function setActiveNav() {
    var path = window.location.pathname;
    var segments = path.split('/').filter(Boolean);
    var current = segments[segments.length - 1] || 'index.php';

    /* Also check second-to-last segment for directory-based routes */
    var dirSegment = segments.length >= 2 ? segments[segments.length - 2] : '';

    for (var j = 0; j < navItems.length; j++) {
      var href = navItems[j].getAttribute('href');
      if (!href) continue;

      var hrefPath = href.replace(/^https?:\/\/[^\/]+/, '').replace(/^\/e-bal\/public\//, '');
      var hrefSegments = hrefPath.split('/').filter(Boolean);
      var hrefLast = hrefSegments[hrefSegments.length - 1] || 'index.php';
      var hrefDir = hrefSegments.length >= 2 ? hrefSegments[hrefSegments.length - 2] : '';

      var match = false;

      /* Exact file match */
      if (hrefLast === current) {
        match = true;
      }

      /* Directory match (e.g. data/ matches data/mapping.php) */
      if (hrefDir && hrefDir === dirSegment) {
        match = true;
      }

      /* Special: my_assignments matches index.php root */
      if (hrefLast === 'my_assignments.php' && current === 'my_assignments.php') {
        match = true;
      }

      /* Special: assignment_home matches */
      if (hrefLast === 'assignment_home.php' && current === 'assignment_home.php') {
        match = true;
      }

      if (match) {
        navItems[j].classList.add('active');
      } else {
        navItems[j].classList.remove('active');
      }
    }
  }

  /* ---- WINDOW RESIZE HANDLER ---- */

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      /* Close mobile sidebar if resized to desktop */
      if (!isMobile() && sidebar.classList.contains('mobile-open')) {
        sidebar.classList.remove('mobile-open');
        if (overlay) {
          overlay.classList.remove('visible');
        }
      }
    }, 150);
  });

  /* ---- INIT ---- */

  restoreState();
  setActiveNav();

  /* ---- USER PROFILE DROPDOWN ---- */

  var profileTrigger = document.getElementById('v2-profile-trigger');
  var userMenu = document.getElementById('v2-user-menu');
  var dropdown = document.getElementById('v2-dropdown');

  if (profileTrigger && dropdown) {
    profileTrigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = userMenu.classList.contains('open');
      userMenu.classList.toggle('open');
      profileTrigger.setAttribute('aria-expanded', !isOpen);
    });

    /* Close on click outside */
    document.addEventListener('click', function (e) {
      if (userMenu && !userMenu.contains(e.target)) {
        userMenu.classList.remove('open');
        profileTrigger.setAttribute('aria-expanded', 'false');
      }
    });

    /* Close on Escape */
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && userMenu.classList.contains('open')) {
        userMenu.classList.remove('open');
        profileTrigger.setAttribute('aria-expanded', 'false');
        profileTrigger.focus();
      }
    });

    /* Keyboard navigation within dropdown */
    var dropdownItems = dropdown.querySelectorAll('.v2-dropdown-item');
    for (var d = 0; d < dropdownItems.length; d++) {
      dropdownItems[d].addEventListener('keydown', function (e) {
        var items = Array.prototype.slice.call(dropdown.querySelectorAll('.v2-dropdown-item'));
        var idx = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          var next = idx < items.length - 1 ? idx + 1 : 0;
          items[next].focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          var prev = idx > 0 ? idx - 1 : items.length - 1;
          items[prev].focus();
        }
      });
    }
  }

  /* ---- NOTIFICATION BELL ---- */

  var notifBtn = document.getElementById('v2-notif-btn');
  if (notifBtn) {
    notifBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      /* Placeholder: toggle notification panel */
    });
  }

})();
