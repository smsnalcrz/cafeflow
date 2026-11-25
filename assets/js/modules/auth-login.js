/**
 * CafeFlow POS — Standalone Login, 2FA Challenge & Password Recovery Module
 * Extracted from login.php for modular maintainability and browser caching.
 */
'use strict';

(function() {
  const cfg = window.CafeFlowLoginConfig || {};

  // Primary Login Elements
  const loginForm = document.getElementById('loginForm');
  const loginIdentifier = document.getElementById('loginIdentifier');
  const loginPassword = document.getElementById('loginPassword');
  const loginSubmitBtn = document.getElementById('loginSubmitBtn');
  const forgotPassLink = document.getElementById('forgotPassLink');
  const openDirect2faBtn = document.getElementById('openDirect2faBtn');

  // 2FA Challenge Elements
  const twoFactorChallenge = document.getElementById('twoFactorChallenge');
  const twoFactorForm = document.getElementById('twoFactorForm');
  const twoFactorUserLabel = document.getElementById('twoFactorUserLabel');
  const totpInputSection = document.getElementById('totpInputSection');
  const recoveryInputSection = document.getElementById('recoveryInputSection');
  const totpCodeInput = document.getElementById('totpCodeInput');
  const recoveryCodeInput = document.getElementById('recoveryCodeInput');
  const toggleRecoveryBtn = document.getElementById('toggleRecoveryBtn');
  const cancel2faBtn = document.getElementById('cancel2faBtn');
  const twoFactorSubmitBtn = document.getElementById('twoFactorSubmitBtn');

  // Mandatory Re-enrollment Elements
  const reenrollChallenge = document.getElementById('reenrollChallenge');
  const reenrollStepSetup = document.getElementById('reenrollStepSetup');
  const reenrollStepSuccess = document.getElementById('reenrollStepSuccess');
  const reenrollUserLabel = document.getElementById('reenrollUserLabel');
  const reenrollSetupKey = document.getElementById('reenrollSetupKey');
  const reenrollCopyKeyBtn = document.getElementById('reenrollCopyKeyBtn');
  const reenrollConfirmForm = document.getElementById('reenrollConfirmForm');
  const reenrollCodeInput = document.getElementById('reenrollCodeInput');
  const reenrollConfirmBtn = document.getElementById('reenrollConfirmBtn');
  const cancelReenrollBtn = document.getElementById('cancelReenrollBtn');
  const reenrollRecoveryList = document.getElementById('reenrollRecoveryList');
  const reenrollCopyAllCodesBtn = document.getElementById('reenrollCopyAllCodesBtn');
  const reenrollDownloadCodesBtn = document.getElementById('reenrollDownloadCodesBtn');
  const reenrollFinishBtn = document.getElementById('reenrollFinishBtn');

  // Direct 2FA Login Elements
  const direct2faForm = document.getElementById('direct2faForm');
  const direct2faSubmitForm = document.getElementById('direct2faSubmitForm');
  const direct2faIdentifier = document.getElementById('direct2faIdentifier');
  const directTotpSection = document.getElementById('directTotpSection');
  const directRecoverySection = document.getElementById('directRecoverySection');
  const directTotpCodeInput = document.getElementById('directTotpCodeInput');
  const directRecoveryCodeInput = document.getElementById('directRecoveryCodeInput');
  const directToggleRecoveryBtn = document.getElementById('directToggleRecoveryBtn');
  const direct2faBackBtn = document.getElementById('direct2faBackBtn');
  const direct2faSubmitBtn = document.getElementById('direct2faSubmitBtn');

  // Forgot Password Elements
  const forgotPassFlow = document.getElementById('forgotPassFlow');
  const forgotStepIdentifier = document.getElementById('forgotStepIdentifier');
  const forgotInitForm = document.getElementById('forgotInitForm');
  const forgotIdentifierInput = document.getElementById('forgotIdentifierInput');
  const forgotSubmitInitBtn = document.getElementById('forgotSubmitInitBtn');
  const forgotCancelBtn1 = document.getElementById('forgotCancelBtn1');

  const forgotStep2fa = document.getElementById('forgotStep2fa');
  const forgotUserLabel = document.getElementById('forgotUserLabel');
  const forgotVerifyForm = document.getElementById('forgotVerifyForm');
  const forgotTotpSection = document.getElementById('forgotTotpSection');
  const forgotRecoverySection = document.getElementById('forgotRecoverySection');
  const forgotTotpCodeInput = document.getElementById('forgotTotpCodeInput');
  const forgotRecoveryCodeInput = document.getElementById('forgotRecoveryCodeInput');
  const forgotToggleRecoveryBtn = document.getElementById('forgotToggleRecoveryBtn');
  const forgotSubmit2faBtn = document.getElementById('forgotSubmit2faBtn');
  const forgotCancelBtn2 = document.getElementById('forgotCancelBtn2');

  const forgotStepNewPass = document.getElementById('forgotStepNewPass');
  const forgotPassUserLabel = document.getElementById('forgotPassUserLabel');
  const forgotResetPasswordForm = document.getElementById('forgotResetPasswordForm');
  const resetNewPassword = document.getElementById('resetNewPassword');
  const resetConfirmPassword = document.getElementById('resetConfirmPassword');
  const forgotSubmitNewPassBtn = document.getElementById('forgotSubmitNewPassBtn');
  const forgotCancelBtn3 = document.getElementById('forgotCancelBtn3');

  // Common Elements
  const authAlert = document.getElementById('authAlert');
  const cardTitle = document.getElementById('authCardTitle');
  const cardSubtitle = document.getElementById('authCardSubtitle');

  // State tracking
  let current2faMode = 'totp'; // 'totp' or 'recovery'
  let direct2faMode = 'totp';
  let forgot2faMode = 'totp';
  let cachedUsername = cfg.pendingUsername || '';
  let cachedForgotUsername = cfg.pendingResetUsername || '';
  let cachedGeneratedCodes = [];
  const csrfToken = cfg.csrfToken || '';

  function showAlert(msg, type = 'error') {
    if (!authAlert) return;
    authAlert.className = 'auth-alert ' + type;
    authAlert.innerHTML = (type === 'error' ? '✕ ' : type === 'success' ? '✓ ' : 'ℹ️ ') + msg;
    authAlert.hidden = false;
    authAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearAlert() {
    if (!authAlert) return;
    authAlert.hidden = true;
    authAlert.textContent = '';
  }

  function setupAutoDismiss(el, timeoutMs = 15000) {
    if (!el) return;
    const dismissBtn = el.querySelector('.alert-dismiss-btn');
    let timer = null;

    const dismiss = () => {
      if (timer) clearTimeout(timer);
      el.classList.add('fade-out');
      setTimeout(() => {
        if (el.parentNode) el.remove();
      }, 500);

      try {
        const url = new URL(window.location);
        if (url.searchParams.has('logged_out') || url.searchParams.has('error') || url.searchParams.has('timeout')) {
          url.searchParams.delete('logged_out');
          url.searchParams.delete('error');
          url.searchParams.delete('timeout');
          window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
        }
      } catch (e) {}
    };

    if (dismissBtn) dismissBtn.addEventListener('click', dismiss);
    timer = setTimeout(dismiss, timeoutMs);
  }

  const signedOutAlert = document.getElementById('signedOutAlert');
  if (signedOutAlert) setupAutoDismiss(signedOutAlert, 15000);
  document.querySelectorAll('.auth-alert.auto-dismiss').forEach(el => setupAutoDismiss(el, 15000));

  // Show/Hide password toggles
  document.querySelectorAll('.toggle-pass-btn').forEach(btn => {
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

  // Format & restrict 6-digit TOTP input to digits only
  function setupNumericInput(inputEl) {
    if (!inputEl) return;
    inputEl.addEventListener('input', () => {
      inputEl.value = inputEl.value.replace(/\D/g, '').slice(0, 6);
    });
    inputEl.addEventListener('paste', (e) => {
      e.preventDefault();
      const pasted = (e.clipboardData || window.clipboardData).getData('text');
      inputEl.value = pasted.replace(/\D/g, '').slice(0, 6);
    });
  }
  setupNumericInput(totpCodeInput);
  setupNumericInput(reenrollCodeInput);
  setupNumericInput(directTotpCodeInput);
  setupNumericInput(forgotTotpCodeInput);

  // Recovery code auto-hyphenation and uppercase formatting
  function setupRecoveryInput(inputEl) {
    if (!inputEl) return;
    inputEl.addEventListener('input', () => {
      let v = inputEl.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
      if (v.length > 4) v = v.slice(0, 4) + '-' + v.slice(4, 8);
      inputEl.value = v.slice(0, 9);
    });
  }
  setupRecoveryInput(recoveryCodeInput);
  setupRecoveryInput(directRecoveryCodeInput);
  setupRecoveryInput(forgotRecoveryCodeInput);

  // -------------------------------------------------------------
  // Screen Transition Helpers
  // -------------------------------------------------------------
  function hideAllCards() {
    if (loginForm) loginForm.hidden = true;
    if (twoFactorChallenge) twoFactorChallenge.hidden = true;
    if (reenrollChallenge) reenrollChallenge.hidden = true;
    if (direct2faForm) direct2faForm.hidden = true;
    if (forgotPassFlow) forgotPassFlow.hidden = true;
    if (forgotStepIdentifier) forgotStepIdentifier.hidden = true;
    if (forgotStep2fa) forgotStep2fa.hidden = true;
    if (forgotStepNewPass) forgotStepNewPass.hidden = true;
  }

  function showLoginScreen() {
    hideAllCards();
    if (cardTitle) cardTitle.textContent = 'Sign In';
    if (cardSubtitle) cardSubtitle.textContent = 'Enter your staff credentials to access CafeFlow POS';
    if (loginForm) loginForm.hidden = false;
    clearAlert();
    if (loginIdentifier) loginIdentifier.focus();
  }

  function showTwoFactorScreen(username) {
    hideAllCards();
    cachedUsername = username || cachedUsername;
    if (twoFactorUserLabel) twoFactorUserLabel.textContent = '@' + cachedUsername;
    if (cardTitle) cardTitle.textContent = 'Two-Factor Authentication';
    if (cardSubtitle) cardSubtitle.textContent = 'Enter the 6-digit code generated by your Google Authenticator app.';
    if (twoFactorChallenge) twoFactorChallenge.hidden = false;

    current2faMode = 'totp';
    if (totpInputSection) totpInputSection.hidden = false;
    if (recoveryInputSection) recoveryInputSection.hidden = true;
    if (toggleRecoveryBtn) toggleRecoveryBtn.textContent = 'Use an emergency recovery code';

    if (totpCodeInput) totpCodeInput.value = '';
    if (recoveryCodeInput) recoveryCodeInput.value = '';
    setTimeout(() => { if (totpCodeInput) totpCodeInput.focus(); }, 80);
  }

  function showDirect2faScreen() {
    hideAllCards();
    if (cardTitle) cardTitle.textContent = 'Sign In with 2FA';
    if (cardSubtitle) cardSubtitle.textContent = 'Fast login using your 6-digit Google Authenticator code';
    if (direct2faForm) direct2faForm.hidden = false;

    direct2faMode = 'totp';
    if (directTotpSection) directTotpSection.hidden = false;
    if (directRecoverySection) directRecoverySection.hidden = true;
    if (directToggleRecoveryBtn) directToggleRecoveryBtn.textContent = 'Use an emergency recovery code';

    clearAlert();
    if (loginIdentifier && loginIdentifier.value.trim() && direct2faIdentifier && !direct2faIdentifier.value) {
      direct2faIdentifier.value = loginIdentifier.value.trim();
    }
    setTimeout(() => {
      if (direct2faIdentifier && direct2faIdentifier.value && directTotpCodeInput) {
        directTotpCodeInput.focus();
      } else if (direct2faIdentifier) {
        direct2faIdentifier.focus();
      }
    }, 80);
  }

  function showForgotStage1() {
    hideAllCards();
    if (cardTitle) cardTitle.textContent = 'Password Recovery';
    if (cardSubtitle) cardSubtitle.textContent = 'Enter your username or email to verify identity with 2FA';
    if (forgotPassFlow) forgotPassFlow.hidden = false;
    if (forgotStepIdentifier) forgotStepIdentifier.hidden = false;

    clearAlert();
    if (loginIdentifier && loginIdentifier.value.trim() && forgotIdentifierInput && !forgotIdentifierInput.value) {
      forgotIdentifierInput.value = loginIdentifier.value.trim();
    }
    setTimeout(() => { if (forgotIdentifierInput) forgotIdentifierInput.focus(); }, 80);
  }

  function showForgotStage2(username) {
    hideAllCards();
    cachedForgotUsername = username || cachedForgotUsername;
    if (forgotUserLabel) forgotUserLabel.textContent = '@' + cachedForgotUsername;
    if (cardTitle) cardTitle.textContent = 'Verify Identity';
    if (cardSubtitle) cardSubtitle.textContent = 'Enter the 6-digit code from Google Authenticator to authorize password reset.';
    if (forgotPassFlow) forgotPassFlow.hidden = false;
    if (forgotStep2fa) forgotStep2fa.hidden = false;

    forgot2faMode = 'totp';
    if (forgotTotpSection) forgotTotpSection.hidden = false;
    if (forgotRecoverySection) forgotRecoverySection.hidden = true;
    if (forgotToggleRecoveryBtn) forgotToggleRecoveryBtn.textContent = 'Use an emergency recovery code';

    if (forgotTotpCodeInput) forgotTotpCodeInput.value = '';
    if (forgotRecoveryCodeInput) forgotRecoveryCodeInput.value = '';
    clearAlert();
    setTimeout(() => { if (forgotTotpCodeInput) forgotTotpCodeInput.focus(); }, 80);
  }

  function showForgotStage3(username) {
    hideAllCards();
    cachedForgotUsername = username || cachedForgotUsername;
    if (forgotPassUserLabel) forgotPassUserLabel.textContent = '@' + cachedForgotUsername;
    if (cardTitle) cardTitle.textContent = 'Set New Password';
    if (cardSubtitle) cardSubtitle.textContent = 'Create and confirm your new account password.';
    if (forgotPassFlow) forgotPassFlow.hidden = false;
    if (forgotStepNewPass) forgotStepNewPass.hidden = false;

    if (resetNewPassword) resetNewPassword.value = '';
    if (resetConfirmPassword) resetConfirmPassword.value = '';
    clearAlert();
    setTimeout(() => { if (resetNewPassword) resetNewPassword.focus(); }, 80);
  }

  // Handle Cancel / Back from 2FA login
  async function cancelPendingAuth() {
    try {
      await fetch('api/auth_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ action: 'cancel_2fa' })
      });
    } catch (_) {}
    showLoginScreen();
  }

  // Handle Cancel / Back from Password Reset
  async function cancelPendingReset() {
    try {
      await fetch('api/auth_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ action: 'cancel_reset' })
      });
    } catch (_) {}
    showLoginScreen();
  }

  if (cancel2faBtn) cancel2faBtn.addEventListener('click', cancelPendingAuth);
  if (cancelReenrollBtn) cancelReenrollBtn.addEventListener('click', cancelPendingAuth);
  if (direct2faBackBtn) direct2faBackBtn.addEventListener('click', showLoginScreen);
  if (openDirect2faBtn) openDirect2faBtn.addEventListener('click', showDirect2faScreen);
  if (forgotPassLink) forgotPassLink.addEventListener('click', showForgotStage1);
  if (forgotCancelBtn1) forgotCancelBtn1.addEventListener('click', showLoginScreen);
  if (forgotCancelBtn2) forgotCancelBtn2.addEventListener('click', cancelPendingReset);
  if (forgotCancelBtn3) forgotCancelBtn3.addEventListener('click', cancelPendingReset);

  // -------------------------------------------------------------
  // Toggle Recovery vs TOTP in challenges
  // -------------------------------------------------------------
  if (toggleRecoveryBtn) {
    toggleRecoveryBtn.addEventListener('click', () => {
      clearAlert();
      if (current2faMode === 'totp') {
        current2faMode = 'recovery';
        totpInputSection.hidden = true;
        recoveryInputSection.hidden = false;
        toggleRecoveryBtn.textContent = '← Use Google Authenticator code';
        cardSubtitle.textContent = 'Enter one of your 8-character emergency recovery codes.';
        recoveryCodeInput.value = '';
        setTimeout(() => recoveryCodeInput.focus(), 60);
      } else {
        current2faMode = 'totp';
        recoveryInputSection.hidden = true;
        totpInputSection.hidden = false;
        toggleRecoveryBtn.textContent = 'Use an emergency recovery code';
        cardSubtitle.textContent = 'Enter the 6-digit code generated by your Google Authenticator app.';
        totpCodeInput.value = '';
        setTimeout(() => totpCodeInput.focus(), 60);
      }
    });
  }

  if (directToggleRecoveryBtn) {
    directToggleRecoveryBtn.addEventListener('click', () => {
      clearAlert();
      if (direct2faMode === 'totp') {
        direct2faMode = 'recovery';
        directTotpSection.hidden = true;
        directRecoverySection.hidden = false;
        directToggleRecoveryBtn.textContent = '← Use Google Authenticator code';
        directRecoveryCodeInput.value = '';
        setTimeout(() => directRecoveryCodeInput.focus(), 60);
      } else {
        direct2faMode = 'totp';
        directRecoverySection.hidden = true;
        directTotpSection.hidden = false;
        directToggleRecoveryBtn.textContent = 'Use an emergency recovery code';
        directTotpCodeInput.value = '';
        setTimeout(() => directTotpCodeInput.focus(), 60);
      }
    });
  }

  if (forgotToggleRecoveryBtn) {
    forgotToggleRecoveryBtn.addEventListener('click', () => {
      clearAlert();
      if (forgot2faMode === 'totp') {
        forgot2faMode = 'recovery';
        forgotTotpSection.hidden = true;
        forgotRecoverySection.hidden = false;
        forgotToggleRecoveryBtn.textContent = '← Use Google Authenticator code';
        forgotRecoveryCodeInput.value = '';
        setTimeout(() => forgotRecoveryCodeInput.focus(), 60);
      } else {
        forgot2faMode = 'totp';
        forgotRecoverySection.hidden = true;
        forgotTotpSection.hidden = false;
        forgotToggleRecoveryBtn.textContent = 'Use an emergency recovery code';
        forgotTotpCodeInput.value = '';
        setTimeout(() => forgotTotpCodeInput.focus(), 60);
      }
    });
  }

  // -------------------------------------------------------------
  // 1. Submit Primary Login Form (Password based)
  // -------------------------------------------------------------
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const identifier = loginIdentifier.value.trim();
      const password = loginPassword.value;

      if (!identifier || !password) {
        showAlert('Please enter both username/email and password.');
        return;
      }

      loginSubmitBtn.disabled = true;
      const origText = loginSubmitBtn.innerHTML;
      loginSubmitBtn.innerHTML = '<div class="spinner"></div><span>Signing In…</span>';

      try {
        const res = await fetch('api/auth_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            action: 'login',
            identifier: identifier,
            password: password,
            remember_me: document.getElementById('rememberMe') ? document.getElementById('rememberMe').checked : false
          })
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Invalid credentials.');
        }

        // Case A: Mandatory Re-enrollment after Admin Reset
        if (data.requires_2fa && data.reenroll_required) {
          await initReenrollment(data.username || identifier, password);
          return;
        }

        // Case B: Standard 2FA Verification Challenge
        if (data.requires_2fa) {
          showTwoFactorScreen(data.username || identifier);
          return;
        }

        // Case C: Normal Login without 2FA
        handleSuccessfulAuth(data);
      } catch (err) {
        showAlert(err.message, 'error');
      } finally {
        loginSubmitBtn.disabled = false;
        loginSubmitBtn.innerHTML = origText;
      }
    });
  }

  // -------------------------------------------------------------
  // 2. Submit 2FA Verification Challenge (After Password)
  // -------------------------------------------------------------
  if (twoFactorForm) {
    twoFactorForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const payload = { action: 'verify_2fa' };
      if (current2faMode === 'totp') {
        const code = totpCodeInput.value.trim();
        if (!code || code.length !== 6) {
          showAlert('Please enter a complete 6-digit authentication code.');
          totpCodeInput.focus();
          return;
        }
        payload.code = code;
      } else {
        const recoveryCode = recoveryCodeInput.value.trim();
        if (!recoveryCode || recoveryCode.length < 8) {
          showAlert('Please enter a valid 8-character recovery code.');
          recoveryCodeInput.focus();
          return;
        }
        payload.recovery_code = recoveryCode;
      }

      twoFactorSubmitBtn.disabled = true;
      const origText = twoFactorSubmitBtn.innerHTML;
      twoFactorSubmitBtn.innerHTML = '<div class="spinner"></div><span>Verifying…</span>';

      try {
        const res = await fetch('api/auth_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Invalid authentication code.');
        }

        handleSuccessfulAuth(data);
      } catch (err) {
        showAlert(err.message, 'error');
        if (current2faMode === 'totp') {
          totpCodeInput.value = '';
          totpCodeInput.focus();
        }
      } finally {
        twoFactorSubmitBtn.disabled = false;
        twoFactorSubmitBtn.innerHTML = origText;
      }
    });
  }

  // -------------------------------------------------------------
  // 3. Submit Direct 2FA Login (Passwordless)
  // -------------------------------------------------------------
  if (direct2faSubmitForm) {
    direct2faSubmitForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const identifier = direct2faIdentifier.value.trim();
      if (!identifier) {
        showAlert('Please enter your username or email address.');
        direct2faIdentifier.focus();
        return;
      }

      const payload = {
        action: 'login_via_2fa',
        identifier: identifier,
        remember_me: document.getElementById('directRememberMe') ? document.getElementById('directRememberMe').checked : false
      };
      if (direct2faMode === 'totp') {
        const code = directTotpCodeInput.value.trim();
        if (!code || code.length !== 6) {
          showAlert('Please enter a complete 6-digit authentication code.');
          directTotpCodeInput.focus();
          return;
        }
        payload.code = code;
      } else {
        const recoveryCode = directRecoveryCodeInput.value.trim();
        if (!recoveryCode || recoveryCode.length < 8) {
          showAlert('Please enter a valid 8-character recovery code.');
          directRecoveryCodeInput.focus();
          return;
        }
        payload.recovery_code = recoveryCode;
      }

      direct2faSubmitBtn.disabled = true;
      const origText = direct2faSubmitBtn.innerHTML;
      direct2faSubmitBtn.innerHTML = '<div class="spinner"></div><span>Signing In…</span>';

      try {
        const res = await fetch('api/auth_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Authentication failed.');
        }

        handleSuccessfulAuth(data);
      } catch (err) {
        showAlert(err.message, 'error');
        if (direct2faMode === 'totp') {
          directTotpCodeInput.value = '';
          directTotpCodeInput.focus();
        }
      } finally {
        direct2faSubmitBtn.disabled = false;
        direct2faSubmitBtn.innerHTML = origText;
      }
    });
  }

  // -------------------------------------------------------------
  // 4. Forgot Password Flow Handlers
  // -------------------------------------------------------------
  if (forgotInitForm) {
    forgotInitForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const identifier = forgotIdentifierInput.value.trim();
      if (!identifier) {
        showAlert('Please enter your username or email address.');
        forgotIdentifierInput.focus();
        return;
      }

      forgotSubmitInitBtn.disabled = true;
      const origText = forgotSubmitInitBtn.innerHTML;
      forgotSubmitInitBtn.innerHTML = '<div class="spinner"></div><span>Verifying Account…</span>';

      try {
        const res = await fetch('api/auth_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            action: 'forgot_password_init',
            identifier: identifier
          })
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Account check failed.');
        }

        cachedForgotUsername = data.username || identifier;
        showForgotStage2(cachedForgotUsername);
      } catch (err) {
        showAlert(err.message, 'error');
      } finally {
        forgotSubmitInitBtn.disabled = false;
        forgotSubmitInitBtn.innerHTML = origText;
      }
    });
  }

  if (forgotVerifyForm) {
    forgotVerifyForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const payload = { action: 'forgot_password_verify' };
      if (forgot2faMode === 'totp') {
        const code = forgotTotpCodeInput.value.trim();
        if (!code || code.length !== 6) {
          showAlert('Please enter a 6-digit authentication code.');
          forgotTotpCodeInput.focus();
          return;
        }
        payload.code = code;
      } else {
        const recoveryCode = forgotRecoveryCodeInput.value.trim();
        if (!recoveryCode || recoveryCode.length < 8) {
          showAlert('Please enter a valid 8-character recovery code.');
          forgotRecoveryCodeInput.focus();
          return;
        }
        payload.recovery_code = recoveryCode;
      }

      forgotSubmit2faBtn.disabled = true;
      const origText = forgotSubmit2faBtn.innerHTML;
      forgotSubmit2faBtn.innerHTML = '<div class="spinner"></div><span>Verifying Code…</span>';

      try {
        const res = await fetch('api/auth_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify(payload)
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Invalid authentication code.');
        }

        showForgotStage3(cachedForgotUsername);
      } catch (err) {
        showAlert(err.message, 'error');
        if (forgot2faMode === 'totp') {
          forgotTotpCodeInput.value = '';
          forgotTotpCodeInput.focus();
        }
      } finally {
        forgotSubmit2faBtn.disabled = false;
        forgotSubmit2faBtn.innerHTML = origText;
      }
    });
  }

  if (forgotResetPasswordForm) {
    forgotResetPasswordForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const newPass = resetNewPassword.value;
      const confirmPass = resetConfirmPassword.value;

      if (!newPass || newPass.length < 6) {
        showAlert('New password must be at least 6 characters long.');
        resetNewPassword.focus();
        return;
      }
      if (newPass !== confirmPass) {
        showAlert('Passwords do not match. Please re-enter your new password.');
        resetConfirmPassword.focus();
        return;
      }

      forgotSubmitNewPassBtn.disabled = true;
      const origText = forgotSubmitNewPassBtn.innerHTML;
      forgotSubmitNewPassBtn.innerHTML = '<div class="spinner"></div><span>Updating Password…</span>';

      try {
        const res = await fetch('api/auth_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            action: 'forgot_password_complete',
            new_password: newPass,
            confirm_password: confirmPass
          })
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Password reset failed.');
        }

        handleSuccessfulAuth(data);
      } catch (err) {
        showAlert(err.message, 'error');
      } finally {
        forgotSubmitNewPassBtn.disabled = false;
        forgotSubmitNewPassBtn.innerHTML = origText;
      }
    });
  }

  // -------------------------------------------------------------
  // 5. Mandatory Re-enrollment Handling
  // -------------------------------------------------------------
  async function initReenrollment(username, password) {
    cachedUsername = username;
    if (cardTitle) cardTitle.textContent = 'Setup Two-Factor Authentication';
    if (cardSubtitle) cardSubtitle.textContent = 'An administrator has reset your 2FA. Re-enrollment is required.';

    hideAllCards();
    if (reenrollChallenge) reenrollChallenge.hidden = false;
    if (reenrollStepSetup) reenrollStepSetup.hidden = false;
    if (reenrollStepSuccess) reenrollStepSuccess.hidden = true;
    if (reenrollUserLabel) reenrollUserLabel.textContent = '@' + cachedUsername;

    try {
      const res = await fetch('api/2fa_api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          action: 'setup_init',
          password: password || '',
          csrf_token: csrfToken
        })
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || 'Failed to initialize 2FA setup.');
      }

      if (reenrollSetupKey) reenrollSetupKey.textContent = data.setup_key;
    } catch (e) {
      showAlert(e.message, 'error');
    }
  }

  if (reenrollCopyKeyBtn) {
    reenrollCopyKeyBtn.addEventListener('click', async () => {
      const keyText = reenrollSetupKey ? reenrollSetupKey.textContent.trim() : '';
      try {
        await navigator.clipboard.writeText(keyText);
        const orig = reenrollCopyKeyBtn.textContent;
        reenrollCopyKeyBtn.textContent = 'Copied!';
        setTimeout(() => { reenrollCopyKeyBtn.textContent = orig; }, 2000);
      } catch (_) {
        showAlert('Key: ' + keyText, 'info');
      }
    });
  }

  if (reenrollConfirmForm) {
    reenrollConfirmForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert();

      const code = reenrollCodeInput.value.trim();
      if (!code || code.length !== 6) {
        showAlert('Please enter the 6-digit code shown in Google Authenticator.');
        reenrollCodeInput.focus();
        return;
      }

      reenrollConfirmBtn.disabled = true;
      const orig = reenrollConfirmBtn.innerHTML;
      reenrollConfirmBtn.innerHTML = '<div class="spinner"></div><span>Activating…</span>';

      try {
        const res = await fetch('api/2fa_api.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({
            action: 'setup_confirm',
            code: code,
            csrf_token: csrfToken
          })
        });

        const data = await res.json();
        if (!res.ok || !data.success) {
          throw new Error(data.error || 'Invalid code.');
        }

        cachedGeneratedCodes = data.recovery_codes || [];
        renderReenrollRecoveryCodes(cachedGeneratedCodes);

        reenrollStepSetup.hidden = true;
        reenrollStepSuccess.hidden = false;
      } catch (err) {
        showAlert(err.message, 'error');
        reenrollCodeInput.value = '';
        reenrollCodeInput.focus();
      } finally {
        reenrollConfirmBtn.disabled = false;
        reenrollConfirmBtn.innerHTML = orig;
      }
    });
  }

  function renderReenrollRecoveryCodes(codes) {
    if (!reenrollRecoveryList) return;
    reenrollRecoveryList.innerHTML = '';
    codes.forEach(code => {
      const el = document.createElement('div');
      el.className = 'recovery-code-pill';
      el.textContent = code;
      reenrollRecoveryList.appendChild(el);
    });
  }

  if (reenrollCopyAllCodesBtn) {
    reenrollCopyAllCodesBtn.addEventListener('click', async () => {
      if (!cachedGeneratedCodes.length) return;
      const txt = "CafeFlow POS Emergency Recovery Codes\nAccount: " + cachedUsername + "\n\n" + cachedGeneratedCodes.join("\n");
      try {
        await navigator.clipboard.writeText(txt);
        const orig = reenrollCopyAllCodesBtn.textContent;
        reenrollCopyAllCodesBtn.textContent = 'Copied!';
        setTimeout(() => { reenrollCopyAllCodesBtn.textContent = orig; }, 2000);
      } catch (_) {}
    });
  }

  if (reenrollDownloadCodesBtn) {
    reenrollDownloadCodesBtn.addEventListener('click', () => {
      if (!cachedGeneratedCodes.length) return;
      const content = "CafeFlow POS Emergency Recovery Codes\nAccount: @" + cachedUsername + "\nGenerated: " + new Date().toLocaleString() + "\n\n" + cachedGeneratedCodes.join("\n") + "\n\nNote: Keep these codes in a safe place. Each code can only be used once.";
      const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'cafeflow-recovery-codes-' + cachedUsername + '.txt';
      a.click();
      URL.revokeObjectURL(url);
    });
  }

  if (reenrollFinishBtn) {
    reenrollFinishBtn.addEventListener('click', () => {
      window.location.href = 'index.php';
    });
  }

  // -------------------------------------------------------------
  // 6. Common Post-Auth Redirect Helper
  // -------------------------------------------------------------
  function handleSuccessfulAuth(data) {
    showAlert(data.message || 'Signed in! Redirecting…', 'success');
    const urlParams = new URLSearchParams(window.location.search);
    const redirectParam = urlParams.get('redirect');
    setTimeout(() => {
      if (redirectParam && redirectParam.startsWith('/') && !redirectParam.startsWith('//')) {
        window.location.href = redirectParam;
      } else {
        window.location.href = data.redirect || 'index.php';
      }
    }, 350);
  }

  // Resuming pending password reset on page refresh
  if (cfg.hasPendingReset) {
    if (cfg.pendingResetStage === 'awaiting_new_password') {
      showForgotStage3(cfg.pendingResetUsername);
    } else {
      showForgotStage2(cfg.pendingResetUsername);
    }
  }
})();
