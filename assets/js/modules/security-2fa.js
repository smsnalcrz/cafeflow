/**
 * CafeFlow POS - Two-Factor Authentication (2FA) Module
 * Self-service enrollment, Google Authenticator setup, recovery code lifecycle,
 * and security status management for Baristas and Managers.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, toast } = window.CafeFlow.utils;
  const { currentUser } = window.CafeFlow.config;
  const api = window.CafeFlow.api;

  let current2faState = {
    enabled: false,
    confirmed_at: null,
    remaining_recovery_codes: 0,
    reenroll_required: false
  };

  let generatedCodes = [];

  // ---------------------------------------------------------------------------
  // Load & Render 2FA Status
  // ---------------------------------------------------------------------------
  async function load2faStatus() {
    showStatusView();
    try {
      const data = await api('2fa_api.php');
      current2faState = {
        enabled: Boolean(data.two_factor_enabled),
        confirmed_at: data.confirmed_at,
        remaining_recovery_codes: data.remaining_recovery_codes || 0,
        reenroll_required: Boolean(data.reenroll_required)
      };
      renderStatus();
    } catch (e) {
      toast(e.message || 'Unable to check 2FA status.', 'error', 3500);
    }
  }

  function renderStatus() {
    const badge = $('#sec2faStatusBadge');
    const desc = $('#sec2faStatusDesc');
    const meta = $('#sec2faMetaInfo');
    const disabledActions = $('#sec2faDisabledActions');
    const enabledActions = $('#sec2faEnabledActions');

    if (!badge || !desc) return;

    if (current2faState.enabled) {
      badge.className = 'status-badge active';
      badge.textContent = 'ENABLED';
      badge.style.background = 'var(--green-light)';
      badge.style.color = 'var(--green)';

      desc.textContent = 'Two-Factor Authentication is currently protecting your CafeFlow account. Every sign-in requires your password plus a 6-digit Google Authenticator code.';

      if (meta) {
        meta.hidden = false;
        const dateStr = current2faState.confirmed_at ? current2faState.confirmed_at.replace('T', ' ').slice(0, 16) : 'Active';
        meta.textContent = `Confirmed: ${dateStr} · Remaining Recovery Codes: ${current2faState.remaining_recovery_codes}`;
      }

      if (disabledActions) disabledActions.hidden = true;
      if (enabledActions) enabledActions.hidden = false;
    } else {
      badge.className = 'status-badge inactive';
      badge.textContent = current2faState.reenroll_required ? 'RE-ENROLLMENT REQUIRED' : 'DISABLED';
      badge.style.background = current2faState.reenroll_required ? 'var(--amber-light)' : '';
      badge.style.color = current2faState.reenroll_required ? 'var(--amber)' : '';

      desc.textContent = current2faState.reenroll_required
        ? 'An administrator has reset your 2FA credentials. You must complete enrollment to secure your account.'
        : 'Two-Factor Authentication is not yet enabled. We strongly recommend enabling it to protect your account against unauthorized access.';

      if (meta) meta.hidden = true;
      if (disabledActions) disabledActions.hidden = false;
      if (enabledActions) enabledActions.hidden = true;
    }
  }

  function showStatusView() {
    if ($('#sec2faStatusView')) $('#sec2faStatusView').hidden = false;
    if ($('#sec2faEnrollView')) $('#sec2faEnrollView').hidden = true;
    if ($('#sec2faDisableView')) $('#sec2faDisableView').hidden = true;
    if ($('#sec2faRegenView')) $('#sec2faRegenView').hidden = true;
  }

  // ---------------------------------------------------------------------------
  // Enrollment Flow
  // ---------------------------------------------------------------------------
  function startEnrollment() {
    if ($('#sec2faStatusView')) $('#sec2faStatusView').hidden = true;
    if ($('#sec2faEnrollView')) $('#sec2faEnrollView').hidden = false;
    if ($('#sec2faEnrollStepPassword')) $('#sec2faEnrollStepPassword').hidden = false;
    if ($('#sec2faEnrollStepKey')) $('#sec2faEnrollStepKey').hidden = true;
    if ($('#sec2faEnrollStepSuccess')) $('#sec2faEnrollStepSuccess').hidden = true;

    const passInput = $('#sec2faCurrentPass');
    if (passInput) {
      passInput.value = '';
      setTimeout(() => passInput.focus(), 60);
    }
    const err = $('#sec2faPassError');
    if (err) err.hidden = true;
  }

  async function handlePasswordSubmit(e) {
    e.preventDefault();
    const passInput = $('#sec2faCurrentPass');
    const errBox = $('#sec2faPassError');
    const submitBtn = $('#sec2faPassSubmitBtn');

    if (!passInput || !passInput.value) {
      if (errBox) {
        errBox.textContent = 'Please enter your password.';
        errBox.hidden = false;
      }
      return;
    }

    if (errBox) errBox.hidden = true;
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Verifying…';
    }

    try {
      const res = await api('2fa_api.php', {
        action: 'setup_init',
        password: passInput.value
      });

      // Move to Step 2: Show Key & Code Input
      if ($('#sec2faSetupKeyDisplay')) $('#sec2faSetupKeyDisplay').textContent = res.setup_key;
      if ($('#sec2faAccountLabel')) $('#sec2faAccountLabel').textContent = res.account_name || 'CafeFlow';

      if ($('#sec2faEnrollStepPassword')) $('#sec2faEnrollStepPassword').hidden = true;
      if ($('#sec2faEnrollStepKey')) $('#sec2faEnrollStepKey').hidden = false;

      const codeInput = $('#sec2faConfirmCodeInput');
      if (codeInput) {
        codeInput.value = '';
        setTimeout(() => codeInput.focus(), 80);
      }
    } catch (err) {
      if (errBox) {
        errBox.textContent = err.message || 'Incorrect password.';
        errBox.hidden = false;
      }
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Continue →';
      }
    }
  }

  async function handleConfirmSubmit(e) {
    e.preventDefault();
    const codeInput = $('#sec2faConfirmCodeInput');
    const errBox = $('#sec2faConfirmError');
    const submitBtn = $('#sec2faConfirmSubmitBtn');

    const code = codeInput ? codeInput.value.trim() : '';
    if (!code || code.length !== 6) {
      if (errBox) {
        errBox.textContent = 'Please enter a 6-digit authentication code.';
        errBox.hidden = false;
      }
      return;
    }

    if (errBox) errBox.hidden = true;
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Activating…';
    }

    try {
      const res = await api('2fa_api.php', {
        action: 'setup_confirm',
        code: code
      });

      generatedCodes = res.recovery_codes || [];
      renderRecoveryCodes(generatedCodes);

      if ($('#sec2faEnrollStepKey')) $('#sec2faEnrollStepKey').hidden = true;
      if ($('#sec2faEnrollStepSuccess')) $('#sec2faEnrollStepSuccess').hidden = false;

      const ack = $('#sec2faAcknowledgeBox');
      const finishBtn = $('#sec2faFinishEnrollBtn');
      if (ack) ack.checked = false;
      if (finishBtn) finishBtn.disabled = true;

      toast('Two-Factor Authentication is now active!', 'success', 4000);
    } catch (err) {
      if (errBox) {
        errBox.textContent = err.message || 'Invalid code.';
        errBox.hidden = false;
      }
      if (codeInput) {
        codeInput.value = '';
        codeInput.focus();
      }
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Activate 2FA';
      }
    }
  }

  function renderRecoveryCodes(codes) {
    const grid = $('#sec2faRecoveryCodesGrid');
    if (!grid) return;
    grid.innerHTML = '';
    codes.forEach(code => {
      const el = document.createElement('div');
      el.className = 'recovery-code-pill';
      el.style.background = 'var(--cream)';
      el.style.border = '1px solid var(--line)';
      el.style.borderRadius = '6px';
      el.style.padding = '8px';
      el.style.fontFamily = 'monospace';
      el.style.fontSize = '14px';
      el.style.fontWeight = '800';
      el.style.textAlign = 'center';
      el.style.color = 'var(--espresso)';
      el.style.letterSpacing = '1.5px';
      el.textContent = code;
      grid.appendChild(el);
    });
  }

  // ---------------------------------------------------------------------------
  // Disable 2FA Flow
  // ---------------------------------------------------------------------------
  function openDisableView() {
    if ($('#sec2faStatusView')) $('#sec2faStatusView').hidden = true;
    if ($('#sec2faDisableView')) $('#sec2faDisableView').hidden = false;

    const pass = $('#sec2faDisablePass');
    const code = $('#sec2faDisableCode');
    const err = $('#sec2faDisableError');
    if (pass) pass.value = '';
    if (code) code.value = '';
    if (err) err.hidden = true;
    if (pass) setTimeout(() => pass.focus(), 60);
  }

  async function handleDisableSubmit(e) {
    e.preventDefault();
    const pass = $('#sec2faDisablePass') ? $('#sec2faDisablePass').value : '';
    const code = $('#sec2faDisableCode') ? $('#sec2faDisableCode').value.trim() : '';
    const errBox = $('#sec2faDisableError');
    const btn = $('#sec2faDisableSubmitBtn');

    if (!pass) {
      if (errBox) { errBox.textContent = 'Please enter your password.'; errBox.hidden = false; }
      return;
    }
    if (!code || code.length !== 6) {
      if (errBox) { errBox.textContent = 'Please enter your 6-digit authenticator code.'; errBox.hidden = false; }
      return;
    }

    if (errBox) errBox.hidden = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Disabling…'; }

    try {
      await api('2fa_api.php', {
        action: 'disable_2fa',
        password: pass,
        code: code
      });

      toast('Two-Factor Authentication disabled.', 'success', 3500);
      load2faStatus();
    } catch (err) {
      if (errBox) {
        errBox.textContent = err.message || 'Verification failed.';
        errBox.hidden = false;
      }
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Disable 2FA'; }
    }
  }

  // ---------------------------------------------------------------------------
  // Regenerate Recovery Codes Flow
  // ---------------------------------------------------------------------------
  function openRegenView() {
    if ($('#sec2faStatusView')) $('#sec2faStatusView').hidden = true;
    if ($('#sec2faRegenView')) $('#sec2faRegenView').hidden = false;

    const pass = $('#sec2faRegenPass');
    const code = $('#sec2faRegenCode');
    const err = $('#sec2faRegenError');
    if (pass) pass.value = '';
    if (code) code.value = '';
    if (err) err.hidden = true;
    if (pass) setTimeout(() => pass.focus(), 60);
  }

  async function handleRegenSubmit(e) {
    e.preventDefault();
    const pass = $('#sec2faRegenPass') ? $('#sec2faRegenPass').value : '';
    const code = $('#sec2faRegenCode') ? $('#sec2faRegenCode').value.trim() : '';
    const errBox = $('#sec2faRegenError');
    const btn = $('#sec2faRegenSubmitBtn');

    if (!pass) {
      if (errBox) { errBox.textContent = 'Please enter your password.'; errBox.hidden = false; }
      return;
    }
    if (!code || code.length !== 6) {
      if (errBox) { errBox.textContent = 'Please enter your 6-digit authenticator code.'; errBox.hidden = false; }
      return;
    }

    if (errBox) errBox.hidden = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Generating…'; }

    try {
      const res = await api('2fa_api.php', {
        action: 'regenerate_recovery_codes',
        password: pass,
        code: code
      });

      generatedCodes = res.recovery_codes || [];
      renderRecoveryCodes(generatedCodes);

      if ($('#sec2faRegenView')) $('#sec2faRegenView').hidden = true;
      if ($('#sec2faEnrollView')) $('#sec2faEnrollView').hidden = false;
      if ($('#sec2faEnrollStepPassword')) $('#sec2faEnrollStepPassword').hidden = true;
      if ($('#sec2faEnrollStepKey')) $('#sec2faEnrollStepKey').hidden = true;
      if ($('#sec2faEnrollStepSuccess')) $('#sec2faEnrollStepSuccess').hidden = false;

      const ack = $('#sec2faAcknowledgeBox');
      const finishBtn = $('#sec2faFinishEnrollBtn');
      if (ack) ack.checked = false;
      if (finishBtn) finishBtn.disabled = true;

      toast('New recovery codes generated successfully!', 'success', 3500);
    } catch (err) {
      if (errBox) {
        errBox.textContent = err.message || 'Verification failed.';
        errBox.hidden = false;
      }
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Generate New Codes'; }
    }
  }

  // ---------------------------------------------------------------------------
  // Event Bindings
  // ---------------------------------------------------------------------------
  function initSecurityEvents() {
    // Open / Close modal
    const closeBtn = $('#closeSecurity2faBtn');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        const secModal = $('#security2faModal');
        if (secModal) secModal.hidden = true;
      });
    }

    // Start enrollment button
    const startBtn = $('#start2faEnrollBtn');
    if (startBtn) startBtn.addEventListener('click', startEnrollment);

    // Password step form
    const passForm = $('#sec2faPasswordForm');
    if (passForm) passForm.addEventListener('submit', handlePasswordSubmit);

    const passCancel = $('#sec2faPassCancelBtn');
    if (passCancel) passCancel.addEventListener('click', showStatusView);

    // Confirm code form
    const confirmForm = $('#sec2faConfirmForm');
    if (confirmForm) confirmForm.addEventListener('submit', handleConfirmSubmit);

    const confirmCancel = $('#sec2faConfirmCancelBtn');
    if (confirmCancel) confirmCancel.addEventListener('click', showStatusView);

    // Copy Setup Key button
    const copyKeyBtn = $('#sec2faCopyKeyBtn');
    if (copyKeyBtn) {
      copyKeyBtn.addEventListener('click', async () => {
        const key = ($('#sec2faSetupKeyDisplay') ? $('#sec2faSetupKeyDisplay').textContent : '').trim();
        try {
          await navigator.clipboard.writeText(key);
          const orig = copyKeyBtn.textContent;
          copyKeyBtn.textContent = 'Copied!';
          setTimeout(() => { copyKeyBtn.textContent = orig; }, 2000);
        } catch (_) {
          toast(`Setup Key: ${key}`, 'info', 5000);
        }
      });
    }

    // Numeric filter on confirm code input
    const codeInput = $('#sec2faConfirmCodeInput');
    if (codeInput) {
      codeInput.addEventListener('input', () => {
        codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
      });
      codeInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const p = (e.clipboardData || window.clipboardData).getData('text');
        codeInput.value = p.replace(/\D/g, '').slice(0, 6);
      });
    }

    const disableCodeInput = $('#sec2faDisableCode');
    if (disableCodeInput) {
      disableCodeInput.addEventListener('input', () => {
        disableCodeInput.value = disableCodeInput.value.replace(/\D/g, '').slice(0, 6);
      });
    }

    const regenCodeInput = $('#sec2faRegenCode');
    if (regenCodeInput) {
      regenCodeInput.addEventListener('input', () => {
        regenCodeInput.value = regenCodeInput.value.replace(/\D/g, '').slice(0, 6);
      });
    }

    // Acknowledge checkbox
    const ack = $('#sec2faAcknowledgeBox');
    const finishBtn = $('#sec2faFinishEnrollBtn');
    if (ack && finishBtn) {
      ack.addEventListener('change', () => {
        finishBtn.disabled = !ack.checked;
      });
    }

    if (finishBtn) {
      finishBtn.addEventListener('click', () => {
        load2faStatus();
      });
    }

    // Copy All Recovery Codes
    const copyCodesBtn = $('#sec2faCopyAllCodesBtn');
    if (copyCodesBtn) {
      copyCodesBtn.addEventListener('click', async () => {
        if (!generatedCodes.length) return;
        const txt = `CafeFlow POS Recovery Codes\nUser: @${currentUser.username}\nGenerated: ${new Date().toLocaleString()}\n\n` + generatedCodes.join('\n');
        try {
          await navigator.clipboard.writeText(txt);
          const orig = copyCodesBtn.textContent;
          copyCodesBtn.textContent = 'Copied!';
          setTimeout(() => { copyCodesBtn.textContent = orig; }, 2000);
        } catch (_) {
          toast('Could not copy automatically. Please copy manually.', 'error', 3000);
        }
      });
    }

    // Download Recovery Codes .txt
    const dlCodesBtn = $('#sec2faDownloadCodesBtn');
    if (dlCodesBtn) {
      dlCodesBtn.addEventListener('click', () => {
        if (!generatedCodes.length) return;
        const txt = `CafeFlow POS Recovery Codes\nUser: @${currentUser.username}\nGenerated: ${new Date().toLocaleString()}\n\n` + generatedCodes.join('\n') + `\n\nNote: Each code can only be used once. Store safely.`;
        const blob = new Blob([txt], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `cafeflow-2fa-recovery-codes-${currentUser.username}.txt`;
        a.click();
        URL.revokeObjectURL(url);
      });
    }

    // Disable 2FA buttons
    const openDisBtn = $('#openDisable2faBtn');
    if (openDisBtn) openDisBtn.addEventListener('click', openDisableView);

    const disCancel = $('#sec2faDisableCancelBtn');
    if (disCancel) disCancel.addEventListener('click', showStatusView);

    const disForm = $('#sec2faDisableForm');
    if (disForm) disForm.addEventListener('submit', handleDisableSubmit);

    // Regenerate buttons
    const openRegBtn = $('#openRegenCodesBtn');
    if (openRegBtn) openRegBtn.addEventListener('click', openRegenView);

    const regCancel = $('#sec2faRegenCancelBtn');
    if (regCancel) regCancel.addEventListener('click', showStatusView);

    const regForm = $('#sec2faRegenForm');
    if (regForm) regForm.addEventListener('submit', handleRegenSubmit);
  }

  window.CafeFlow.security2fa = {
    load2faStatus,
    initSecurityEvents
  };
})();
