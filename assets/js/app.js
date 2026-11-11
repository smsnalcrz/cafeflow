/**
 * CafeFlow POS - Application Main Entry Point
 * Initializes state, boots modules, starts live clock, attaches global listeners,
 * and launches role-specific views (POS for Barista, Dashboard for Manager).
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, tickClock } = window.CafeFlow.utils;
  const { currentUser, POLL_MS } = window.CafeFlow.config;
  const { showView, initNavEvents } = window.CafeFlow.nav;
  const { loadMenu, renderMenu, renderCategoryBar, initMenuEvents } = window.CafeFlow.menu;
  const { renderCart, initCartEvents } = window.CafeFlow.cart;
  const { initReceiptEvents } = window.CafeFlow.receipt || {};
  const { pollQueue, initQueueEvents } = window.CafeFlow.queue;
  const { initProductEvents } = window.CafeFlow.products;
  const { initInventoryEvents } = window.CafeFlow.inventory;
  const { initReportEvents } = window.CafeFlow.reports;
  const { initAccountEvents } = window.CafeFlow.accounts;
  const { loadManagerDashboard, initDashboardEvents } = window.CafeFlow.dashboard;

  function initApp() {
    // 1. Initialize event listeners across modules
    initNavEvents();
    initMenuEvents();
    initCartEvents();
    if (initReceiptEvents) initReceiptEvents();
    initQueueEvents();
    initProductEvents();
    initInventoryEvents();
    initReportEvents();
    initAccountEvents();
    initDashboardEvents();

    // 2. Global Brand Logo click behavior
    const brandEl = $('.brand');
    if (brandEl) {
      brandEl.addEventListener('click', () => {
        if (currentUser.role === 'manager') {
          showView('dashboard');
        } else {
          showView('pos');
          window.CafeFlow.state.activeCategory = 'all';
          window.CafeFlow.state.searchQuery = '';
          const ps = $('#productSearch');
          if (ps) ps.value = '';
          const sc = $('#searchClearBtn');
          if (sc) sc.hidden = true;
          renderCategoryBar();
          renderMenu();
        }
      });
    }

    // Modal close triggers
    $$('[data-close]').forEach(b => b.addEventListener('click', () => {
      showView(currentUser.role === 'manager' ? 'dashboard' : 'pos');
    }));

    // Password Show/Hide Toggle Buttons
    $$('.btn-toggle-pass').forEach(btn => {
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (!input) return;
        if (input.type === 'password') {
          input.type = 'text';
          btn.textContent = '🙈';
        } else {
          input.type = 'password';
          btn.textContent = '👁️';
        }
      });
    });

    // 3. Start live clock
    tickClock();
    setInterval(tickClock, 15000);

    // 4. Role-based startup
    if (currentUser.role === 'manager') {
      loadManagerDashboard();
      const urlParams = new URLSearchParams(window.location.search);
      const reqView = urlParams.get('view');
      if (reqView === 'inventory' || reqView === 'report' || reqView === 'accounts') {
        showView(reqView);
      } else {
        showView('dashboard');
      }
    } else {
      renderCart();
      loadMenu();
      pollQueue();
      setInterval(pollQueue, POLL_MS);

      const urlParams = new URLSearchParams(window.location.search);
      const reqView = urlParams.get('view');
      if (reqView && ['pos', 'products', 'inventory', 'report'].includes(reqView)) {
        showView(reqView);
      }
    }
  }

  // Boot when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
  } else {
    initApp();
  }
})();
