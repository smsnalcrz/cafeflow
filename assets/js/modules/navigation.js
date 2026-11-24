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

  let currentViewStartTime = Date.now();
  let lastActiveView = currentUser.role === 'manager' ? 'dashboard' : 'pos';

  function trackViewVisit(newView) {
    if (!newView || newView === lastActiveView) return;
    const now = Date.now();
    const timeSpentMs = now - currentViewStartTime;

    // Enforce 2-second minimum dwell-time before dispatching navigation beacon
    if (timeSpentMs >= 2000 && navigator.sendBeacon && currentUser && currentUser.csrf_token) {
      try {
        const payload = JSON.stringify({
          category: 'navigation',
          action: 'tab_view',
          description: `Switched from ${lastActiveView} to ${newView}`,
          target_type: 'view',
          target_id: newView,
          metadata: { from_view: lastActiveView, to_view: newView, duration_prev_ms: timeSpentMs },
          csrf_token: currentUser.csrf_token
        });
        const blob = new Blob([payload], { type: 'application/json' });
        navigator.sendBeacon('api/audit_beacon.php', blob);
      } catch (e) {
        // Non-blocking
      }
    }

    lastActiveView = newView;
    currentViewStartTime = now;
  }

  function showView(view) {
    if (currentUser.role === 'manager' && (view === 'pos' || view === 'products')) {
      toast('Access restricted: Manager role does not have access to POS or Products.', 'error', 3500);
      return;
    }
    if (currentUser.role !== 'manager' && (view === 'accounts' || view === 'predictive' || view === 'waste' || view === 'audit')) {
      toast('Access restricted: Only Managers have access to ' + (view === 'predictive' ? 'Predictive Analytics.' : (view === 'waste' ? 'Waste Reports.' : (view === 'audit' ? 'Audit Log.' : 'Account Management.'))), 'error', 3500);
      return;
    }

    trackViewVisit(view);
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

    const predModal = $('#predictiveModal');
    if (predModal) predModal.hidden = view !== 'predictive';

    const wasteModal = $('#wasteModal');
    if (wasteModal) wasteModal.hidden = view !== 'waste';

    const secModal = $('#security2faModal');
    if (secModal) secModal.hidden = view !== 'security_2fa';

    const auditModal = $('#auditModal');
    if (auditModal) auditModal.hidden = view !== 'audit';

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
      if (window.CafeFlow.accounts.closeReset2faSheet) window.CafeFlow.accounts.closeReset2faSheet();
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
    if (view === 'waste' && currentUser.role === 'manager' && window.CafeFlow.waste && window.CafeFlow.waste.loadWasteReport) {
      window.CafeFlow.waste.loadWasteReport();
    }
    if (view === 'predictive' && currentUser.role === 'manager' && window.CafeFlow.predictive && window.CafeFlow.predictive.loadPredictiveView) {
      window.CafeFlow.predictive.loadPredictiveView();
    }
    if (view === 'security_2fa' && window.CafeFlow.security2fa && window.CafeFlow.security2fa.load2faStatus) {
      window.CafeFlow.security2fa.load2faStatus();
    }
    if (view === 'audit' && currentUser.role === 'manager' && window.CafeFlow.audit && window.CafeFlow.audit.loadAuditLogs) {
      window.CafeFlow.audit.loadAuditLogs();
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

          // Dismiss topmost visible modal sheet first before switching views
          const openSheets = Array.from(document.querySelectorAll('.sheet, .modal-sheet')).filter(s => {
            return !s.hidden && s.style.display !== 'none' && window.getComputedStyle(s).display !== 'none';
          });
          if (openSheets.length > 0) {
            const topSheet = openSheets[openSheets.length - 1];
            const closeBtn = topSheet.querySelector('.btn-close-sheet, #accountSheetCloseIcon, #resetPassCloseIcon, #reset2faCloseIcon, #confirmDialogCloseBtn, #sheetCancel, #productSheetCancel, #accountSheetCancel, #resetPassCancel, #cancelCashPayBtn, #cancelGcashBtn, #dismissCancelOrderBtn, #cancelManualWasteBtn, .btn.ghost');
            if (closeBtn && typeof closeBtn.click === 'function') {
              closeBtn.click();
            } else {
              topSheet.hidden = true;
              topSheet.style.display = 'none';
            }
            return;
          }

          if (state.currentView && state.currentView !== 'pos' && state.currentView !== 'dashboard') {
            showView(currentUser.role === 'manager' ? 'dashboard' : 'pos');
          }
        }
      });
    }

    const openSecurity2faBtn = $('#openSecurity2faBtn');
    if (openSecurity2faBtn) {
      openSecurity2faBtn.addEventListener('click', () => {
        if (profileDropdown) profileDropdown.hidden = true;
        if (profileBtn) profileBtn.classList.remove('active');
        showView('security_2fa');
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

    const openAuditBtn = $('#openAuditBtn');
    if (openAuditBtn) {
      openAuditBtn.addEventListener('click', () => {
        if (profileDropdown) profileDropdown.hidden = true;
        if (profileBtn) profileBtn.classList.remove('active');
        showView('audit');
      });
    }
  }

  window.CafeFlow.nav = {
    showView,
    initNavEvents
  };
})();
