/**
 * CafeFlow POS - Account Management Module
 * Handles user management, role assignments (Barista/Manager),
 * active/pending/inactive statuses, account creation, and password resets.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, h, toast, emptyState } = window.CafeFlow.utils;
  const { currentUser } = window.CafeFlow.config;
  const api = window.CafeFlow.api;
  const state = window.CafeFlow.state;

  /* ------------------------------------------------------------------ *

   *  Account Management Module

   * ------------------------------------------------------------------ */

  let accountsList = [];

  let accountsOverview = { total: 0, baristas: 0, managers: 0, active: 0, pending: 0, inactive: 0 };

  let accountFilter = 'all';

  let accountSearchQuery = '';



  async function loadAccounts() {

    try {

      const data = await api('accounts_api.php');

      accountsList = data.items || [];

      accountsOverview = data.overview || {};

      renderAccountStats();

      renderAccountsTable();

    } catch (e) {

      toast(e.message, 'error', 4000);

    }

  }



  function renderAccountStats() {

    if ($('#statTotalAccounts')) $('#statTotalAccounts').textContent = accountsOverview.total || 0;

    if ($('#statBaristaAccounts')) $('#statBaristaAccounts').textContent = accountsOverview.baristas || 0;

    if ($('#statManagerAccounts')) $('#statManagerAccounts').textContent = accountsOverview.managers || 0;

    if ($('#statActiveAccounts')) $('#statActiveAccounts').textContent = accountsOverview.active || 0;

    if ($('#statPendingAccounts')) $('#statPendingAccounts').textContent = (accountsOverview.pending || 0) + (accountsOverview.inactive || 0);



    // Also update manager dashboard card if present

    if ($('#mgrCardUserCount')) {

      $('#mgrCardUserCount').textContent = `${accountsOverview.total || 0} accounts`;

    }

  }



  function renderAccountsTable() {

    const tbody = $('#accountsTableBody');

    if (!tbody) return;

    tbody.textContent = '';



    const q = accountSearchQuery.toLowerCase().trim();

    const filtered = accountsList.filter(acc => {

      // Filter by tab

      if (accountFilter === 'barista' && acc.role !== 'barista') return false;

      if (accountFilter === 'manager' && acc.role !== 'manager') return false;

      if (accountFilter === 'active' && acc.status !== 'active') return false;

      if (accountFilter === 'pending' && acc.status !== 'pending') return false;



      // Filter by search query

      if (q) {

        const matchName = (acc.full_name || '').toLowerCase().includes(q);

        const matchUser = (acc.username || '').toLowerCase().includes(q);

        const matchEmail = (acc.email || '').toLowerCase().includes(q);

        return matchName || matchUser || matchEmail;

      }

      return true;

    });



    if (filtered.length === 0) {
      const isSearch = Boolean(q);
      const tr = h('tr', {},
        h('td', { colspan: '8' },
          emptyState({
            icon: '👥',
            title: isSearch ? `No accounts match "${q}"` : `No ${accountFilter !== 'all' ? accountFilter + ' ' : ''}accounts found`,
            desc: isSearch
              ? `No user records match your search query under the "${accountFilter}" filter.`
              : `There are currently no staff accounts matching the "${accountFilter}" filter.`,
            tip: '🛡️ Role Access: Managers control business operations; Baristas operate the POS terminal.',
            actionText: isSearch ? '✕ Clear Account Search' : '+ Add New Account',
            onAction: isSearch ? () => {
              const inp = $('#accountSearch');
              if (inp) inp.value = '';
              const clr = $('#accountSearchClearBtn');
              if (clr) clr.hidden = true;
              accountSearchQuery = '';
              renderAccountsTable();
            } : () => openCreateAccountSheet()
          })
        )
      );
      tbody.append(tr);
      return;
    }



    for (const acc of filtered) {

      const isSelf = Boolean(acc.is_self);

      const isManager = (acc.role === 'manager');

      const isCurrentUserMgr = (currentUser.role === 'manager');



      // Status Badge

      const statusBadge = h('span', { class: `status-badge ${acc.status}` },

        h('span', { class: 'status-dot' }),

        acc.status.toUpperCase()

      );



      // Role Badge

      const roleBadge = h('span', { class: `role-badge role-${acc.role}` },

        acc.role === 'barista' ? '☕ Barista' : '🛡️ Manager'

      );



      // Actions Container

      const actWrap = h('div', { style: 'display: flex; gap: 6px; align-items: center; justify-content: flex-end;' });



      // Edit Button

      const editBtn = h('button', {

        type: 'button',

        class: 'acc-act-btn',

        title: 'Edit account details',

        onclick: () => openEditAccountSheet(acc)

      }, '✏️ Edit');

      actWrap.append(editBtn);



      // Reset Password Button

      const resetPassBtn = h('button', {

        type: 'button',

        class: 'acc-act-btn',

        title: 'Reset account password',

        onclick: () => openResetPasswordSheet(acc)

      }, '🔑 Reset');

      actWrap.append(resetPassBtn);



      // Status Action (Approve / Deactivate / Activate)

      if (acc.status === 'pending') {

        const approveBtn = h('button', {

          type: 'button',

          class: 'acc-act-btn btn-activate',

          title: 'Approve and activate this account',

          onclick: () => toggleAccountStatus(acc.user_id, 'active', `Approve and activate account for @${acc.username}?`)

        }, '✓ Approve');

        actWrap.append(approveBtn);

      } else if (acc.status === 'active') {

        const deactBtn = h('button', {

          type: 'button',

          class: 'acc-act-btn btn-deactivate',

          title: 'Deactivate account',

          onclick: () => toggleAccountStatus(acc.user_id, 'inactive', `Are you sure you want to deactivate the account @${acc.username}?`)

        }, 'Deactivate');



        // Manager cannot deactivate self

        if (isCurrentUserMgr && isSelf) {

          deactBtn.disabled = true;

          deactBtn.title = 'Security Rule: You cannot deactivate your own Manager account.';

        }

        // Protect last active manager

        if (isManager && accountsOverview.managers <= 1) {

          deactBtn.disabled = true;

          deactBtn.title = 'Security Rule: Cannot deactivate the last active Manager account.';

        }

        actWrap.append(deactBtn);

      } else {

        const actBtn = h('button', {

          type: 'button',

          class: 'acc-act-btn btn-activate',

          title: 'Activate account',

          onclick: () => toggleAccountStatus(acc.user_id, 'active', `Reactivate account for @${acc.username}?`)

        }, 'Activate');

        actWrap.append(actBtn);

      }



      const formattedCreated = acc.created_at ? acc.created_at.replace('T', ' ').slice(0, 16) : '--';

      const loginText = acc.last_login ? acc.last_login.replace('T', ' ').slice(0, 16) : 'Never';



      const tr = h('tr', {},

        h('td', { style: 'font-weight: 700;' }, acc.full_name + (isSelf ? ' (You)' : '')),

        h('td', { style: 'font-family: monospace; font-size: 13.5px;' }, '@' + acc.username),

        h('td', { style: 'color: var(--muted); font-size: 13px;' }, acc.email || '—'),

        h('td', {}, roleBadge),

        h('td', {}, statusBadge),

        h('td', { style: 'font-size: 12.5px; color: var(--muted); white-space: nowrap;' }, formattedCreated),

        h('td', { style: 'font-size: 12.5px; color: acc.last_login ? "inherit" : "var(--muted)"; white-space: nowrap;' }, loginText),

        h('td', { class: 'act' }, actWrap)

      );



      tbody.append(tr);

    }

  }



  function openCreateAccountSheet() {

    $('#accountSheetTitle').textContent = 'Create New Account';

    $('#accUserId').value = '';

    $('#accFullName').value = '';

    $('#accUsername').value = '';

    $('#accUsername').disabled = false;

    $('#accEmail').value = '';

    $('#accRole').value = 'barista';

    $('#accRole').disabled = false;

    $('#accStatus').value = 'active';

    $('#accStatus').disabled = false;

    $('#accPassword').value = '';

    $('#accPassword').required = true;

    $('#accConfirmPassword').value = '';

    $('#accConfirmPassword').required = true;

    $('#accPassLabel').textContent = 'Password *';

    $('#accPassConfirmLabel').textContent = 'Confirm Password *';

    $('#accPassHint').hidden = true;

    $('#accountSheetError').hidden = true;

    $('#accountSheet').hidden = false;

    $('#accFullName').focus();

  }



  function openEditAccountSheet(acc) {

    $('#accountSheetTitle').textContent = `Edit Account: @${acc.username}`;

    $('#accUserId').value = acc.user_id;

    $('#accFullName').value = acc.full_name;

    $('#accUsername').value = acc.username;

    $('#accUsername').disabled = true; // Username cannot be changed

    $('#accEmail').value = acc.email || '';

    $('#accRole').value = acc.role;

    $('#accStatus').value = acc.status;



    // Security Rules for Manager:

    // Manager cannot change their own role or deactivate themselves

    const isCurrentUserMgr = (currentUser.role === 'manager');

    const isSelf = Boolean(acc.is_self);

    if (isCurrentUserMgr && isSelf) {

      $('#accRole').disabled = true;

      $('#accRole').title = 'Security Rule: Managers cannot alter their own role.';

      $('#accStatus').disabled = true;

      $('#accStatus').title = 'Security Rule: You cannot deactivate your own account.';

    } else {

      $('#accRole').disabled = false;

      $('#accRole').title = '';

      $('#accStatus').disabled = false;

      $('#accStatus').title = '';

    }



    $('#accPassword').value = '';

    $('#accPassword').required = false;

    $('#accConfirmPassword').value = '';

    $('#accConfirmPassword').required = false;

    $('#accPassLabel').textContent = 'New Password (Optional)';

    $('#accPassConfirmLabel').textContent = 'Confirm New Password';

    $('#accPassHint').hidden = false;

    $('#accountSheetError').hidden = true;

    $('#accountSheet').hidden = false;

    $('#accFullName').focus();

  }



  function closeAccountSheet() {

    const ash = $('#accountSheet');

    if (ash) ash.hidden = true;

    const asErr = $('#accountSheetError');

    if (asErr) asErr.hidden = true;

  }



  function openResetPasswordSheet(acc) {

    $('#resetUserId').value = acc.user_id;

    $('#resetTargetUsername').textContent = `@${acc.username} (${acc.full_name})`;

    $('#resetNewPass').value = '';

    $('#resetConfirmPass').value = '';

    $('#resetPassError').hidden = true;

    $('#resetPassSheet').hidden = false;

    $('#resetNewPass').focus();

  }



  function closeResetPassSheet() {

    const rsh = $('#resetPassSheet');

    if (rsh) rsh.hidden = true;

    const rshErr = $('#resetPassError');

    if (rshErr) rshErr.hidden = true;

  }



  async function toggleAccountStatus(userId, newStatus, promptMsg) {

    if (!confirm(promptMsg)) return;

    try {

      const res = await api('accounts_api.php', {

        action: 'toggle_status',

        user_id: userId,

        status: newStatus,

        csrf_token: currentUser.csrf_token

      });

      toast(res.message || 'Status updated.', 'success', 3000);

      loadAccounts();

    } catch (e) {

      toast(e.message, 'error', 4500);

    }

  }



  /* ------------------------------------------------------------------ *

   *  Account Management Form & Search Handlers

   * ------------------------------------------------------------------ */

  const accSearch = $('#accountSearch');

  const accSearchClear = $('#accountSearchClearBtn');

  if (accSearch) {

    accSearch.addEventListener('input', () => {

      accountSearchQuery = accSearch.value;

      if (accSearchClear) accSearchClear.hidden = !accSearch.value.trim();

      renderAccountsTable();

    });

  }

  if (accSearchClear) {

    accSearchClear.addEventListener('click', () => {

      if (accSearch) accSearch.value = '';

      accountSearchQuery = '';

      accSearchClear.hidden = true;

      renderAccountsTable();

      accSearch?.focus();

    });

  }



  // Filter Tabs

  $$('.acc-filter-tab').forEach(tab => {

    tab.addEventListener('click', () => {

      $$('.acc-filter-tab').forEach(t => t.classList.remove('active'));

      tab.classList.add('active');

      accountFilter = tab.dataset.filter || 'all';

      renderAccountsTable();

    });

  });



  // Open New Account Sheet

  const addNewAccBtn = $('#addNewAccountBtn');

  if (addNewAccBtn) addNewAccBtn.addEventListener('click', openCreateAccountSheet);



  // Close Sheets

  const accCancelBtn = $('#accountSheetCancel');

  if (accCancelBtn) accCancelBtn.addEventListener('click', closeAccountSheet);

  const accSheet = $('#accountSheet');

  if (accSheet) {

    accSheet.addEventListener('click', (e) => {

      if (e.target === accSheet) closeAccountSheet();

    });

  }



  const resetCancelBtn = $('#resetPassCancel');

  if (resetCancelBtn) resetCancelBtn.addEventListener('click', closeResetPassSheet);

  const resetSheet = $('#resetPassSheet');

  if (resetSheet) {

    resetSheet.addEventListener('click', (e) => {

      if (e.target === resetSheet) closeResetPassSheet();

    });

  }



  // Account Form Save

  const accForm = $('#accountForm');

  if (accForm) {

    accForm.addEventListener('submit', async (e) => {

      e.preventDefault();

      const errBox = $('#accountSheetError');

      errBox.hidden = true;

      errBox.textContent = '';



      const userId = $('#accUserId').value;

      const isEdit = Boolean(userId);

      const fullName = $('#accFullName').value.trim();

      const username = $('#accUsername').value.trim();

      const email = $('#accEmail').value.trim();

      const role = $('#accRole').value;

      const status = $('#accStatus').value;

      const pass = $('#accPassword').value;

      const confirmPass = $('#accConfirmPassword').value;



      if (!fullName) {

        errBox.textContent = 'Full Name is required.';

        errBox.hidden = false;

        return;

      }

      if (!isEdit && !username) {

        errBox.textContent = 'Username is required.';

        errBox.hidden = false;

        return;

      }

      if (!isEdit && (!pass || pass.length < 6)) {

        errBox.textContent = 'Password must be at least 6 characters.';

        errBox.hidden = false;

        return;

      }

      if (pass && pass !== confirmPass) {

        errBox.textContent = 'Passwords do not match.';

        errBox.hidden = false;

        return;

      }



      const saveBtn = $('#accountSheetSaveBtn');

      saveBtn.disabled = true;

      saveBtn.textContent = 'Saving…';



      try {

        const payload = isEdit ? {

          action: 'update',

          user_id: parseInt(userId, 10),

          full_name: fullName,

          email: email,

          role: role,

          status: status,

          password: pass,

          csrf_token: currentUser.csrf_token

        } : {

          action: 'create',

          full_name: fullName,

          username: username,

          email: email,

          role: role,

          status: status,

          password: pass,

          csrf_token: currentUser.csrf_token

        };



        const res = await api('accounts_api.php', payload);

        toast(res.message || 'Account saved.', 'success', 3000);

        closeAccountSheet();

        loadAccounts();

      } catch (err) {

        errBox.textContent = err.message;

        errBox.hidden = false;

      } finally {

        saveBtn.disabled = false;

        saveBtn.textContent = 'Save Account';

      }

    });

  }



  // Reset Password Form Submit

  const resetForm = $('#resetPassForm');

  if (resetForm) {

    resetForm.addEventListener('submit', async (e) => {

      e.preventDefault();

      const errBox = $('#resetPassError');

      errBox.hidden = true;

      errBox.textContent = '';



      const userId = $('#resetUserId').value;

      const newPass = $('#resetNewPass').value;

      const confirmPass = $('#resetConfirmPass').value;



      if (newPass.length < 6) {

        errBox.textContent = 'New password must be at least 6 characters.';

        errBox.hidden = false;

        return;

      }

      if (newPass !== confirmPass) {

        errBox.textContent = 'Passwords do not match.';

        errBox.hidden = false;

        return;

      }



      const saveBtn = $('#resetPassSaveBtn');

      saveBtn.disabled = true;

      saveBtn.textContent = 'Resetting…';



      try {

        const res = await api('accounts_api.php', {

          action: 'reset_password',

          user_id: parseInt(userId, 10),

          new_password: newPass,

          confirm_password: confirmPass,

          csrf_token: currentUser.csrf_token

        });

        toast(res.message || 'Password reset successfully.', 'success', 3000);

        closeResetPassSheet();

      } catch (err) {

        errBox.textContent = err.message;

        errBox.hidden = false;

      } finally {

        saveBtn.disabled = false;

        saveBtn.textContent = 'Set New Password';

      }

    });

  }



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


  function initAccountEvents() {
    // Search input
    const accSearch = $('#accountSearch');
    const accSearchClear = $('#accountSearchClearBtn');
    if (accSearch) {
      accSearch.addEventListener('input', () => {
        accountSearchQuery = accSearch.value;
        if (accSearchClear) accSearchClear.hidden = !accSearch.value.trim();
        renderAccountsTable();
      });
    }
    if (accSearchClear) {
      accSearchClear.addEventListener('click', () => {
        if (accSearch) accSearch.value = '';
        accountSearchQuery = '';
        accSearchClear.hidden = true;
        renderAccountsTable();
        accSearch?.focus();
      });
    }

    // Filter Tabs
    $$('.acc-filter-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        $$('.acc-filter-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        accountFilter = tab.dataset.filter || 'all';
        renderAccountsTable();
      });
    });

    // Open New Account Sheet
    const addNewAccBtn = $('#addNewAccountBtn');
    if (addNewAccBtn) addNewAccBtn.addEventListener('click', openCreateAccountSheet);

    // Close Sheets
    const accCancelBtn = $('#accountSheetCancel');
    if (accCancelBtn) accCancelBtn.addEventListener('click', closeAccountSheet);
    const accSheet = $('#accountSheet');
    if (accSheet) {
      accSheet.addEventListener('click', (e) => {
        if (e.target === accSheet) closeAccountSheet();
      });
    }

    const resetCancelBtn = $('#resetPassCancel');
    if (resetCancelBtn) resetCancelBtn.addEventListener('click', closeResetPassSheet);
    const resetSheet = $('#resetPassSheet');
    if (resetSheet) {
      resetSheet.addEventListener('click', (e) => {
        if (e.target === resetSheet) closeResetPassSheet();
      });
    }

    // Account Form Save
    const accForm = $('#accountForm');
    if (accForm) {
      accForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const errBox = $('#accountSheetError');
        if (errBox) {
          errBox.hidden = true;
          errBox.textContent = '';
        }

        const userId = $('#accUserId') ? $('#accUserId').value : '';
        const isEdit = Boolean(userId);
        const fullName = $('#accFullName') ? $('#accFullName').value.trim() : '';
        const username = $('#accUsername') ? $('#accUsername').value.trim() : '';
        const email = $('#accEmail') ? $('#accEmail').value.trim() : '';
        const role = $('#accRole') ? $('#accRole').value : 'barista';
        const status = $('#accStatus') ? $('#accStatus').value : 'active';
        const pass = $('#accPassword') ? $('#accPassword').value : '';
        const confirmPass = $('#accConfirmPassword') ? $('#accConfirmPassword').value : '';

        if (!fullName) {
          if (errBox) { errBox.textContent = 'Full Name is required.'; errBox.hidden = false; }
          return;
        }
        if (!isEdit && !username) {
          if (errBox) { errBox.textContent = 'Username is required.'; errBox.hidden = false; }
          return;
        }
        if (!isEdit && (!pass || pass.length < 6)) {
          if (errBox) { errBox.textContent = 'Password must be at least 6 characters.'; errBox.hidden = false; }
          return;
        }
        if (pass && pass !== confirmPass) {
          if (errBox) { errBox.textContent = 'Passwords do not match.'; errBox.hidden = false; }
          return;
        }

        const saveBtn = $('#accountSheetSaveBtn');
        if (saveBtn) {
          saveBtn.disabled = true;
          saveBtn.textContent = 'Saving…';
        }

        try {
          const payload = isEdit ? {
            action: 'update',
            user_id: parseInt(userId, 10),
            full_name: fullName,
            email: email,
            role: role,
            status: status,
            password: pass,
            csrf_token: currentUser.csrf_token
          } : {
            action: 'create',
            full_name: fullName,
            username: username,
            email: email,
            role: role,
            status: status,
            password: pass,
            csrf_token: currentUser.csrf_token
          };

          const res = await api('accounts_api.php', payload);
          toast(res.message || 'Account saved.', 'success', 3000);
          closeAccountSheet();
          loadAccounts();
        } catch (err) {
          if (errBox) {
            errBox.textContent = err.message;
            errBox.hidden = false;
          }
        } finally {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Account';
          }
        }
      });
    }

    // Reset Password Form Submit
    const resetForm = $('#resetPassForm');
    if (resetForm) {
      resetForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const errBox = $('#resetPassError');
        if (errBox) {
          errBox.hidden = true;
          errBox.textContent = '';
        }

        const userId = $('#resetUserId') ? $('#resetUserId').value : '';
        const newPass = $('#resetNewPass') ? $('#resetNewPass').value : '';
        const confirmPass = $('#resetConfirmPass') ? $('#resetConfirmPass').value : '';

        if (!newPass || newPass.length < 6) {
          if (errBox) {
            errBox.textContent = 'New password must be at least 6 characters.';
            errBox.hidden = false;
          }
          return;
        }
        if (newPass !== confirmPass) {
          if (errBox) {
            errBox.textContent = 'Passwords do not match.';
            errBox.hidden = false;
          }
          return;
        }

        const saveBtn = $('#resetPassSaveBtn');
        if (saveBtn) {
          saveBtn.disabled = true;
          saveBtn.textContent = 'Resetting…';
        }

        try {
          const res = await api('accounts_api.php', {
            action: 'reset_password',
            user_id: parseInt(userId, 10),
            new_password: newPass,
            confirm_password: confirmPass,
            csrf_token: currentUser.csrf_token
          });
          toast(res.message || 'Password reset successfully.', 'success', 3000);
          closeResetPassSheet();
        } catch (err) {
          if (errBox) {
            errBox.textContent = err.message;
            errBox.hidden = false;
          }
        } finally {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Set New Password';
          }
        }
      });
    }

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
  }

  window.CafeFlow.accounts = {
    loadAccounts,
    renderAccountStats,
    renderAccountsTable,
    openCreateAccountSheet,
    openEditAccountSheet,
    closeAccountSheet,
    openResetPasswordSheet,
    openResetPassSheet: openResetPasswordSheet,
    closeResetPassSheet,
    toggleAccountStatus,
    initAccountEvents
  };
})();
