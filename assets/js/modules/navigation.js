/**
 * CafeFlow POS - Navigation & Profile Module
 * Controls main application views (POS, Products, Inventory, Reports, Accounts, Dashboard),
 * view-level role access protection, and top-bar profile dropdown interactions.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, toast } = window.CafeFlow.utils;
  const { currentUser } = window.CafeFlow.config;
  const state = window.CafeFlow.state;

  function showView(view) {
    if (currentUser.role === 'manager' && (view === 'pos' || view === 'products')) {
      toast('Access restricted: Manager role does not have access to POS or Products.', 'error', 3500);
      return;
    }
    if (currentUser.role !== 'manager' && view === 'accounts') {
      toast('Access restricted: Only Managers have access to Account Management.', 'error', 3500);
      return;
    }

    state.currentView = view;
    $$('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));

    const pModal = $('#productModal');
    if (pModal) pModal.hidden = view !== 'products';

    const invModal = $('#inventoryModal');
    if (invModal) invModal.hidden = view !== 'inventory';

    const repModal = $('#reportModal');
    if (repModal) repModal.hidden = view !== 'report';

    const accModal = $('#accountsModal');
    if (accModal) accModal.hidden = view !== 'accounts';

    const recModal = $('#receiptModal');
    if (recModal) recModal.hidden = true;

    if (window.CafeFlow.cart && window.CafeFlow.cart.closePayModal) {
      window.CafeFlow.cart.closePayModal();
    }
    if (window.CafeFlow.cart && window.CafeFlow.cart.syncMobOrderBar) {
      window.CafeFlow.cart.syncMobOrderBar();
    }

    if (view !== 'products' && window.CafeFlow.products && window.CafeFlow.products.closeProductSheet) {
      window.CafeFlow.products.closeProductSheet();
    }
    if (view !== 'inventory' && window.CafeFlow.inventory && window.CafeFlow.inventory.closeSheet) {
      window.CafeFlow.inventory.closeSheet();
    }
    if (view !== 'accounts' && window.CafeFlow.accounts) {
      if (window.CafeFlow.accounts.closeAccountSheet) window.CafeFlow.accounts.closeAccountSheet();
      if (window.CafeFlow.accounts.closeResetPassSheet) window.CafeFlow.accounts.closeResetPassSheet();
    }

    if (view !== 'report') {
      const drawer = $('#reportCalDrawer');
      if (drawer) drawer.hidden = true;
    }

    if (view === 'products' && window.CafeFlow.products && window.CafeFlow.products.loadManageProducts) {
      window.CafeFlow.products.loadManageProducts();
    }
    if (view === 'inventory' && window.CafeFlow.inventory && window.CafeFlow.inventory.loadInventory) {
      window.CafeFlow.inventory.loadInventory();
    }
    if (view === 'report' && window.CafeFlow.reports && window.CafeFlow.reports.loadReport) {
      window.CafeFlow.reports.loadReport();
    }
    if (view === 'accounts' && currentUser.role === 'manager' && window.CafeFlow.accounts && window.CafeFlow.accounts.loadAccounts) {
      window.CafeFlow.accounts.loadAccounts();
    }
    if (view === 'dashboard' && currentUser.role === 'manager' && window.CafeFlow.dashboard && window.CafeFlow.dashboard.loadManagerDashboard) {
      window.CafeFlow.dashboard.loadManagerDashboard();
    }
  }

  function initNavEvents() {
    $$('.nav-btn').forEach(b => {
      b.addEventListener('click', () => showView(b.dataset.view));
    });

    const profileBtn = $('#profileBtn');
    const profileDropdown = $('#profileDropdown');
    if (profileBtn && profileDropdown) {
      profileBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isHidden = profileDropdown.hidden;
        profileDropdown.hidden = !isHidden;
        profileBtn.classList.toggle('active', isHidden);
        profileBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
      });

      document.addEventListener('click', (e) => {
        if (!profileDropdown.hidden && !e.target.closest('#userProfileWrap')) {
          profileDropdown.hidden = true;
          profileBtn.classList.remove('active');
          profileBtn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          if (!profileDropdown.hidden) {
            profileDropdown.hidden = true;
            profileBtn.classList.remove('active');
            profileBtn.setAttribute('aria-expanded', 'false');
            return;
          }
          if (state.currentView && state.currentView !== 'pos' && state.currentView !== 'dashboard') {
            showView(currentUser.role === 'manager' ? 'dashboard' : 'pos');
          }
        }
      });
    }

    const openAccountsBtn = $('#openAccountsBtn');
    if (openAccountsBtn) {
      openAccountsBtn.addEventListener('click', () => {
        if (profileDropdown) profileDropdown.hidden = true;
        if (profileBtn) profileBtn.classList.remove('active');
        showView('accounts');
      });
    }
  }

  window.CafeFlow.nav = {
    showView,
    initNavEvents
  };
})();
