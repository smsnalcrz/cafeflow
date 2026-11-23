<?php
/**
 * CafeFlow - Secure Login (Authorized Personnel Only)
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

$pdo = db();

// If user is already authenticated, redirect them to appropriate landing page
if (is_authenticated()) {
    $dest = 'index.php';
    header("Location: {$dest}");
    exit;
}

$pending2fa = get_pending_2fa();
$hasPending2fa = $pending2fa !== null;
$pendingUsername = $pending2fa['username'] ?? '';
$pendingReenroll = ($pending2fa['mode'] ?? '') === 'reenroll';

$pendingReset = get_pending_password_reset();
$hasPendingReset = $pendingReset !== null;
$pendingResetStage = $pendingReset['stage'] ?? '';
$pendingResetUsername = $pendingReset['username'] ?? '';

$redirectParam = $_GET['redirect'] ?? '';
$loggedOut = !empty($_GET['logged_out']);
$timeoutParam = !empty($_GET['timeout']);
$errorParam = $_GET['error'] ?? '';
$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="theme-color" content="#162318">
  <title>Sign In · CafeFlow & DK Cafe</title>
  <link rel="stylesheet" href="assets/css/variables.css">
  <link rel="stylesheet" href="assets/css/base.css">
  <style>
    body {
      margin: 0;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: radial-gradient(circle at 50% 30%, #1f3325 0%, #162318 80%);
      color: var(--ink);
      padding: 20px;
      overflow-y: auto;
    }

    .auth-container {
      width: 100%;
      max-width: 440px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    /* Brand Header Lockup */
    .auth-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 24px;
      user-select: none;
    }
    .auth-brand-logos {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 8px;
    }
    .auth-emblem {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      filter: drop-shadow(0 3px 8px rgba(0, 0, 0, 0.4));
    }
    .auth-logo-dk {
      height: 42px;
      width: auto;
      filter: brightness(1.1) drop-shadow(0 3px 8px rgba(0, 0, 0, 0.3));
    }
    .auth-pill-bar {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(0, 0, 0, 0.45);
      border: 1px solid rgba(255, 255, 255, 0.16);
      border-radius: 999px;
      padding: 3px 14px;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
    }
    .auth-pill-item {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 1.2px;
      color: rgba(255, 255, 255, 0.95);
      text-transform: uppercase;
    }
    .auth-pill-amp {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 15px;
      height: 15px;
      border-radius: 50%;
      background: var(--caramel);
      color: #fff;
      font-size: 10px;
      font-weight: 900;
    }

    /* Card */
    .auth-card {
      width: 100%;
      background: #ffffff;
      border-radius: var(--radius-xl);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
      border: 1px solid rgba(255, 255, 255, 0.1);
      overflow: hidden;
      animation: card-in var(--dur-slow) var(--ease-spring);
    }
    @keyframes card-in {
      from { opacity: 0; transform: translateY(14px) scale(0.97); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    .auth-card-header {
      padding: 24px 28px 18px;
      text-align: center;
      background: var(--cream);
      border-bottom: 1.5px solid var(--line);
    }
    .auth-card-title {
      font-size: 19px;
      font-weight: 800;
      color: var(--espresso);
      margin: 0 0 4px 0;
      letter-spacing: -0.3px;
    }
    .auth-card-subtitle {
      font-size: 13px;
      color: var(--muted);
      margin: 0;
      font-weight: 500;
    }

    .auth-body {
      padding: 24px 28px 28px;
    }

    /* Alerts */
    .auth-alert {
      padding: 11px 14px;
      border-radius: var(--radius-sm);
      font-size: 13.5px;
      font-weight: 600;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
      line-height: 1.35;
      transition: opacity 0.5s ease, transform 0.5s ease, max-height 0.5s ease, margin 0.5s ease, padding 0.5s ease;
      box-sizing: border-box;
      max-height: 120px;
    }
    .auth-alert.fade-out {
      opacity: 0;
      transform: translateY(-6px);
      max-height: 0;
      padding-top: 0;
      padding-bottom: 0;
      margin-top: 0;
      margin-bottom: 0;
      overflow: hidden;
      border-color: transparent;
    }
    .alert-dismiss-btn {
      margin-left: auto;
      background: transparent;
      border: none;
      color: currentColor;
      font-size: 16px;
      line-height: 1;
      padding: 0 4px;
      cursor: pointer;
      opacity: 0.6;
      transition: opacity var(--dur-fast);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .alert-dismiss-btn:hover {
      opacity: 1;
    }
    .auth-alert.error {
      background: var(--red-light);
      color: var(--red);
      border: 1px solid rgba(184, 50, 38, 0.2);
    }
    .auth-alert.success {
      background: var(--green-light);
      color: var(--green);
      border: 1px solid rgba(42, 155, 82, 0.2);
    }
    .auth-alert.info {
      background: #ebf8ff;
      color: #2b6cb0;
      border: 1px solid #bee3f8;
    }

    /* Form Elements */
    .auth-field {
      margin-bottom: 16px;
    }
    .auth-field label {
      display: block;
      font-size: 13px;
      font-weight: 700;
      color: var(--espresso);
      margin-bottom: 6px;
    }
    .auth-input-box {
      position: relative;
      display: flex;
      align-items: center;
    }
    .auth-input {
      width: 100%;
      height: 44px;
      padding: 0 14px;
      border: 1.5px solid var(--line);
      border-radius: var(--radius-sm);
      font-size: 14.5px;
      color: var(--ink);
      background: var(--cream);
      transition: all var(--dur-fast);
    }
    .auth-input:focus {
      outline: none;
      background: #ffffff;
      border-color: var(--caramel);
      box-shadow: 0 0 0 3px rgba(232, 98, 58, 0.18);
    }
    .auth-input.has-toggle {
      padding-right: 42px;
    }
    .toggle-pass-btn {
      position: absolute;
      right: 10px;
      background: transparent;
      border: none;
      cursor: pointer;
      font-size: 16px;
      opacity: 0.6;
      padding: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .toggle-pass-btn:hover {
      opacity: 1;
    }

    /* Submit Button */
    .auth-submit-btn {
      width: 100%;
      height: 46px;
      border-radius: var(--radius-sm);
      background: var(--caramel);
      color: #ffffff;
      font-size: 15.5px;
      font-weight: 800;
      letter-spacing: 0.2px;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 3px 12px rgba(232, 98, 58, 0.35);
      transition: all var(--dur-fast);
      margin-top: 20px;
    }
    .auth-submit-btn:hover {
      filter: brightness(1.08);
      transform: translateY(-1px);
    }
    .auth-submit-btn:active {
      transform: scale(0.98);
    }
    .auth-submit-btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* Footer */
    .auth-footer {
      margin-top: 18px;
      text-align: center;
      font-size: 13px;
      color: var(--muted);
    }
    .auth-footer-note {
      font-size: 12.5px;
      color: var(--muted);
      font-weight: 600;
      letter-spacing: 0.2px;
    }

    .system-footer {
      margin-top: 24px;
      text-align: center;
      font-size: 12px;
      color: rgba(255, 255, 255, 0.45);
      letter-spacing: 0.5px;
    }

    /* Spinner */
    .spinner {
      width: 18px;
      height: 18px;
      border: 2.5px solid rgba(255, 255, 255, 0.4);
      border-top-color: #ffffff;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* 2FA Challenge & Re-enrollment UI Styles */
    .two-factor-icon-wrap {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      background: var(--green-light);
      color: var(--green);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 12px;
    }
    .two-factor-icon-wrap svg {
      width: 24px;
      height: 24px;
    }
    .auth-user-badge {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 5px 12px;
      background: var(--cream);
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 13px;
      font-weight: 700;
      color: var(--ink-mid);
      margin-bottom: 18px;
      width: 100%;
      box-sizing: border-box;
    }
    .totp-input {
      width: 100%;
      height: 52px;
      text-align: center;
      font-family: 'Consolas', 'Courier New', monospace;
      font-size: 24px;
      font-weight: 800;
      letter-spacing: 8px;
      border: 2px solid var(--line);
      border-radius: var(--radius-sm);
      background: var(--cream);
      color: var(--ink);
      box-sizing: border-box;
      transition: all var(--dur-fast);
    }
    .totp-input:focus {
      outline: none;
      background: #ffffff;
      border-color: var(--caramel);
      box-shadow: 0 0 0 3px rgba(232, 98, 58, 0.18);
    }
    .recovery-input {
      width: 100%;
      height: 50px;
      text-align: center;
      font-family: 'Consolas', 'Courier New', monospace;
      font-size: 19px;
      font-weight: 800;
      letter-spacing: 3px;
      text-transform: uppercase;
      border: 2px solid var(--line);
      border-radius: var(--radius-sm);
      background: var(--cream);
      color: var(--ink);
      box-sizing: border-box;
      transition: all var(--dur-fast);
    }
    .recovery-input:focus {
      outline: none;
      background: #ffffff;
      border-color: var(--caramel);
      box-shadow: 0 0 0 3px rgba(232, 98, 58, 0.18);
    }
    .auth-toggle-link {
      background: transparent;
      border: none;
      color: var(--caramel);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      padding: 6px 0;
      text-decoration: underline;
      display: inline-block;
      margin-top: 14px;
      text-align: center;
      width: 100%;
      transition: opacity var(--dur-fast);
    }
    .auth-toggle-link:hover {
      opacity: 0.85;
    }
    .auth-back-btn {
      width: 100%;
      height: 42px;
      border-radius: var(--radius-sm);
      background: transparent;
      border: 1.5px solid var(--line);
      color: var(--muted);
      font-size: 14px;
      font-weight: 700;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      margin-top: 12px;
      transition: all var(--dur-fast);
    }
    .auth-back-btn:hover {
      background: var(--parchment);
      border-color: var(--muted);
      color: var(--ink);
    }
    .setup-key-card {
      background: #F2EBE0;
      border: 1.5px dashed #2D6A4F;
      border-radius: var(--radius-sm);
      padding: 12px 14px;
      margin: 10px 0 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }
    .setup-key-text {
      font-family: 'Consolas', 'Courier New', monospace;
      font-size: 16px;
      font-weight: 800;
      color: #1B4332;
      letter-spacing: 1.5px;
      user-select: all;
    }
    .setup-key-copy-btn {
      background: #1B4332;
      color: #ffffff;
      border: none;
      border-radius: 6px;
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      white-space: nowrap;
      transition: background var(--dur-fast);
    }
    .setup-key-copy-btn:hover {
      background: #2D6A4F;
    }
    .reenroll-steps {
      margin: 0 0 16px;
      padding-left: 18px;
      font-size: 12.5px;
      line-height: 1.45;
      color: var(--ink-mid);
    }
    .reenroll-steps li {
      margin-bottom: 4px;
    }
    .recovery-codes-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      margin: 12px 0 16px;
    }
    .recovery-code-pill {
      background: var(--cream);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 8px 6px;
      font-family: 'Consolas', monospace;
      font-size: 14px;
      font-weight: 800;
      text-align: center;
      color: var(--espresso);
      letter-spacing: 1.5px;
    }
    .auth-divider {
      display: flex;
      align-items: center;
      text-align: center;
      margin: 18px 0 14px;
      color: var(--muted);
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .auth-divider::before,
    .auth-divider::after {
      content: '';
      flex: 1;
      border-bottom: 1.5px solid var(--line);
    }
    .auth-divider span {
      padding: 0 10px;
    }
    .auth-secondary-btn {
      width: 100%;
      height: 44px;
      border-radius: var(--radius-sm);
      background: #1F3325;
      color: #D8E2DC;
      font-size: 14px;
      font-weight: 700;
      border: 1px solid rgba(255, 255, 255, 0.15);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: all var(--dur-fast);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
    }
    .auth-secondary-btn:hover {
      background: #2D6A4F;
      color: #ffffff;
      border-color: rgba(255, 255, 255, 0.3);
      transform: translateY(-1px);
    }
    .auth-secondary-btn:active {
      transform: scale(0.98);
    }
    .auth-link-btn {
      background: transparent;
      border: none;
      color: var(--caramel);
      font-size: 12.5px;
      font-weight: 700;
      cursor: pointer;
      padding: 0;
      transition: opacity var(--dur-fast);
      text-decoration: underline;
    }
    .auth-link-btn:hover {
      opacity: 0.8;
    }
  </style>
</head>
<body>

  <div class="auth-container">
    <!-- Brand Emblem Lockup -->
    <div class="auth-brand">
      <div class="auth-brand-logos">
        <img src="assets/images/cafeflow_logo.svg" alt="CafeFlow" class="auth-emblem">
        <img src="assets/images/dk_cafe_logo_cropped.png" alt="DK Cafe" class="auth-logo-dk">
      </div>
      <div class="auth-pill-bar">
        <span class="auth-pill-item">CAFEFLOW</span>
        <span class="auth-pill-amp" aria-hidden="true">&amp;</span>
        <span class="auth-pill-item">DK CAFE</span>
      </div>
    </div>

    <!-- Main Card -->
    <div class="auth-card">
      <?php
        $cardHeaderTitle = 'Sign In';
        $cardHeaderSubtitle = 'Enter your staff credentials to access CafeFlow POS';
        if ($hasPendingReset) {
            if ($pendingResetStage === 'awaiting_new_password') {
                $cardHeaderTitle = 'Set New Password';
                $cardHeaderSubtitle = 'Create and confirm your new account password.';
            } else {
                $cardHeaderTitle = 'Verify Identity';
                $cardHeaderSubtitle = 'Enter your Google Authenticator code to authorize password reset.';
            }
        } elseif ($hasPending2fa) {
            $cardHeaderTitle = $pendingReenroll ? 'Re-enroll Two-Factor' : 'Two-Factor Authentication';
            $cardHeaderSubtitle = $pendingReenroll ? 'Configure Google Authenticator to regain account access' : 'Enter the 6-digit code generated by your Google Authenticator app.';
        }
      ?>
      <div class="auth-card-header" id="authCardHeader">
        <h1 class="auth-card-title" id="authCardTitle"><?= htmlspecialchars($cardHeaderTitle) ?></h1>
        <p class="auth-card-subtitle" id="authCardSubtitle"><?= htmlspecialchars($cardHeaderSubtitle) ?></p>
      </div>

      <div class="auth-body">
        <!-- System Alerts -->
        <?php if ($loggedOut): ?>
          <div class="auth-alert success" id="signedOutAlert">
            <span>✓ You have been signed out safely.</span>
            <button type="button" class="alert-dismiss-btn" aria-label="Dismiss">&times;</button>
          </div>
        <?php endif; ?>
        <?php if ($timeoutParam): ?>
          <div class="auth-alert info auto-dismiss" id="timeoutAlert">
            <span>⏱️ Your session has expired due to 30 minutes of inactivity. Please sign in again.</span>
            <button type="button" class="alert-dismiss-btn" aria-label="Dismiss">&times;</button>
          </div>
        <?php endif; ?>
        <?php if ($errorParam === 'forbidden'): ?>
          <div class="auth-alert error auto-dismiss">
            <span>✕ Access denied. Your role is not authorized for that page.</span>
            <button type="button" class="alert-dismiss-btn" aria-label="Dismiss">&times;</button>
          </div>
        <?php endif; ?>
        <?php if ($errorParam === 'session_expired'): ?>
          <div class="auth-alert info auto-dismiss">
            <span>ℹ️ Your session has expired. Please sign in again.</span>
            <button type="button" class="alert-dismiss-btn" aria-label="Dismiss">&times;</button>
          </div>
        <?php endif; ?>

        <div id="authAlert" class="auth-alert" hidden></div>

        <!-- 1. SIGN IN FORM -->
        <form id="loginForm" autocomplete="on" novalidate <?= ($hasPending2fa || $hasPendingReset) ? 'hidden' : '' ?>>
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectParam) ?>">

          <div class="auth-field">
            <label for="loginIdentifier">Username or Email</label>
            <div class="auth-input-box">
              <input type="text" id="loginIdentifier" class="auth-input" placeholder="e.g. barista or user@cafeflow.com" required autocomplete="username" autofocus>
            </div>
          </div>

          <div class="auth-field">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <label for="loginPassword" style="margin-bottom: 0;">Password</label>
              <button type="button" class="auth-link-btn" id="forgotPassLink">Forgot password?</button>
            </div>
            <div class="auth-input-box">
              <input type="password" id="loginPassword" class="auth-input has-toggle" placeholder="Enter your password" required autocomplete="current-password">
              <button type="button" class="toggle-pass-btn" data-target="loginPassword" aria-label="Show password" title="Show password">👁️</button>
            </div>
          </div>

          <button type="submit" class="auth-submit-btn" id="loginSubmitBtn">
            <span>Sign In to CafeFlow</span>
          </button>

          <div class="auth-divider">
            <span>or</span>
          </div>

          <button type="button" class="auth-secondary-btn" id="openDirect2faBtn">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            <span>Sign In using 2FA Code</span>
          </button>

          <div class="auth-footer">
            <span class="auth-footer-note">🔒 Authorized personnel only · Managed POS system</span>
          </div>
        </form>

        <!-- 2. TWO-FACTOR AUTHENTICATION CHALLENGE -->
        <div id="twoFactorChallenge" <?= ($hasPending2fa && !$pendingReenroll) ? '' : 'hidden' ?>>
          <div class="two-factor-icon-wrap" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
              <path d="m9 12 2 2 4-4"></path>
            </svg>
          </div>

          <div class="auth-user-badge">
            <span>Signing in as</span>
            <strong id="twoFactorUserLabel">@<?= htmlspecialchars($pendingUsername ?: 'employee') ?></strong>
          </div>

          <form id="twoFactorForm" novalidate>
            <!-- Mode A: TOTP 6-digit input -->
            <div id="totpInputSection">
              <div class="auth-field">
                <label for="totpCodeInput" style="text-align: center;">Authentication Code</label>
                <div class="auth-input-box">
                  <input type="text" id="totpCodeInput" class="totp-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="······" autofocus>
                </div>
              </div>
            </div>

            <!-- Mode B: Recovery Code input -->
            <div id="recoveryInputSection" hidden>
              <div class="auth-field">
                <label for="recoveryCodeInput" style="text-align: center;">Emergency Recovery Code</label>
                <div class="auth-input-box">
                  <input type="text" id="recoveryCodeInput" class="recovery-input" maxlength="9" placeholder="XXXX-XXXX" autocomplete="off">
                </div>
                <p class="muted" style="font-size: 12px; margin: 6px 0 0; text-align: center; color: var(--muted);">Each 8-character recovery code can only be used once.</p>
              </div>
            </div>

            <button type="submit" class="auth-submit-btn" id="twoFactorSubmitBtn">
              <span>Verify Code</span>
            </button>

            <!-- Mode Toggle -->
            <button type="button" class="auth-toggle-link" id="toggleRecoveryBtn">
              Use an emergency recovery code
            </button>

            <button type="button" class="auth-back-btn" id="cancel2faBtn">
              ← Back to Sign In
            </button>
          </form>
        </div>

        <!-- 3. MANDATORY RE-ENROLLMENT CHALLENGE -->
        <div id="reenrollChallenge" <?= ($hasPending2fa && $pendingReenroll) ? '' : 'hidden' ?>>
          <div class="two-factor-icon-wrap" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
          </div>

          <!-- Step 1: Show Setup Key -->
          <div id="reenrollStepSetup">
            <div class="auth-user-badge">
              <span>Account:</span>
              <strong id="reenrollUserLabel">@<?= htmlspecialchars($pendingUsername ?: 'employee') ?></strong>
            </div>

            <p style="font-size: 13px; color: var(--muted); margin: 0 0 8px;">
              1. Open Google Authenticator on your phone.<br>
              2. Tap <strong>+</strong> and select <strong>Enter a setup key</strong>.<br>
              3. Enter account name and paste the setup key below:
            </p>

            <div class="setup-key-card">
              <span class="setup-key-text" id="reenrollSetupKey">-------- --------</span>
              <button type="button" class="setup-key-copy-btn" id="reenrollCopyKeyBtn">Copy Key</button>
            </div>

            <form id="reenrollConfirmForm" novalidate>
              <div class="auth-field">
                <label for="reenrollCodeInput" style="text-align: center;">Enter 6-Digit Code to Confirm</label>
                <div class="auth-input-box">
                  <input type="text" id="reenrollCodeInput" class="totp-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="······">
                </div>
              </div>

              <button type="submit" class="auth-submit-btn" id="reenrollConfirmBtn">
                <span>Confirm &amp; Enable 2FA</span>
              </button>

              <button type="button" class="auth-back-btn" id="cancelReenrollBtn">
                ← Back to Sign In
              </button>
            </form>
          </div>

          <!-- Step 2: Show New Recovery Codes -->
          <div id="reenrollStepSuccess" hidden>
            <div class="auth-alert success" style="margin-bottom: 14px;">
              <span>✓ Two-Factor Authentication is now active!</span>
            </div>

            <p style="font-size: 13px; color: var(--ink); margin: 0 0 6px; font-weight: 700;">
              Save your emergency recovery codes:
            </p>
            <p style="font-size: 12px; color: var(--muted); margin: 0 0 12px;">
              Store these codes in a safe place. Each code can be used once if you ever lose your phone.
            </p>

            <div class="recovery-codes-grid" id="reenrollRecoveryList"></div>

            <div style="display: flex; gap: 8px; margin-bottom: 14px;">
              <button type="button" class="auth-back-btn" id="reenrollCopyAllCodesBtn" style="margin-top: 0; flex: 1;">Copy Codes</button>
              <button type="button" class="auth-back-btn" id="reenrollDownloadCodesBtn" style="margin-top: 0; flex: 1;">Download (.txt)</button>
            </div>

            <button type="button" class="auth-submit-btn" id="reenrollFinishBtn">
              <span>Proceed to CafeFlow POS →</span>
            </button>
          </div>
        </div>

        <!-- 4. DIRECT 2FA LOGIN FORM (Passwordless) -->
        <div id="direct2faForm" hidden>
          <div class="two-factor-icon-wrap" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
          </div>

          <form id="direct2faSubmitForm" novalidate>
            <div class="auth-field">
              <label for="direct2faIdentifier">Username or Email</label>
              <div class="auth-input-box">
                <input type="text" id="direct2faIdentifier" class="auth-input" placeholder="e.g. barista or user@cafeflow.com" required autocomplete="username">
              </div>
            </div>

            <!-- Mode A: TOTP 6-digit input -->
            <div id="directTotpSection">
              <div class="auth-field">
                <label for="directTotpCodeInput" style="text-align: center;">6-Digit Authentication Code</label>
                <div class="auth-input-box">
                  <input type="text" id="directTotpCodeInput" class="totp-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="······">
                </div>
              </div>
            </div>

            <!-- Mode B: Recovery Code input -->
            <div id="directRecoverySection" hidden>
              <div class="auth-field">
                <label for="directRecoveryCodeInput" style="text-align: center;">Emergency Recovery Code</label>
                <div class="auth-input-box">
                  <input type="text" id="directRecoveryCodeInput" class="recovery-input" maxlength="9" placeholder="XXXX-XXXX" autocomplete="off">
                </div>
                <p class="muted" style="font-size: 12px; margin: 6px 0 0; text-align: center; color: var(--muted);">Each recovery code can only be used once.</p>
              </div>
            </div>

            <button type="submit" class="auth-submit-btn" id="direct2faSubmitBtn">
              <span>Sign In with 2FA</span>
            </button>

            <!-- Mode Toggle -->
            <button type="button" class="auth-toggle-link" id="directToggleRecoveryBtn">
              Use an emergency recovery code
            </button>

            <button type="button" class="auth-back-btn" id="direct2faBackBtn">
              ← Back to Password Sign In
            </button>
          </form>
        </div>

        <!-- 5. FORGOT PASSWORD FLOW (Self-Service 2FA Reset) -->
        <div id="forgotPassFlow" <?= $hasPendingReset ? '' : 'hidden' ?>>
          <div class="two-factor-icon-wrap" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 2l-2 2m-1.5 1.5L16 7l-1.5-1.5M19 5l-1.5 1.5"></path>
              <circle cx="7.5" cy="15.5" r="5.5"></circle>
              <path d="m11.5 11.5 8.5-8.5"></path>
            </svg>
          </div>

          <!-- Step 1: Enter Username/Email -->
          <div id="forgotStepIdentifier" <?= (!$hasPendingReset || $pendingResetStage === 'awaiting_2fa') ? '' : 'hidden' ?>>
            <form id="forgotInitForm" novalidate>
              <div class="auth-field">
                <label for="forgotIdentifierInput">Enter your Username or Email</label>
                <div class="auth-input-box">
                  <input type="text" id="forgotIdentifierInput" class="auth-input" placeholder="e.g. barista or user@cafeflow.com" required autocomplete="username">
                </div>
                <p style="font-size: 12.5px; color: var(--muted); margin: 6px 0 0; line-height: 1.4;">
                  You will verify your identity using your Google Authenticator app before creating a new password.
                </p>
              </div>

              <button type="submit" class="auth-submit-btn" id="forgotSubmitInitBtn">
                <span>Continue with 2FA →</span>
              </button>

              <button type="button" class="auth-back-btn" id="forgotCancelBtn1">
                ← Back to Sign In
              </button>
            </form>
          </div>

          <!-- Step 2: 2FA Verification -->
          <div id="forgotStep2fa" <?= ($hasPendingReset && $pendingResetStage === 'awaiting_2fa') ? '' : 'hidden' ?>>
            <div class="auth-user-badge">
              <span>Resetting password for</span>
              <strong id="forgotUserLabel">@<?= htmlspecialchars($pendingResetUsername ?: 'employee') ?></strong>
            </div>

            <form id="forgotVerifyForm" novalidate>
              <!-- Mode A: TOTP 6-digit input -->
              <div id="forgotTotpSection">
                <div class="auth-field">
                  <label for="forgotTotpCodeInput" style="text-align: center;">Authentication Code</label>
                  <div class="auth-input-box">
                    <input type="text" id="forgotTotpCodeInput" class="totp-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="······">
                  </div>
                </div>
              </div>

              <!-- Mode B: Recovery Code input -->
              <div id="forgotRecoverySection" hidden>
                <div class="auth-field">
                  <label for="forgotRecoveryCodeInput" style="text-align: center;">Emergency Recovery Code</label>
                  <div class="auth-input-box">
                    <input type="text" id="forgotRecoveryCodeInput" class="recovery-input" maxlength="9" placeholder="XXXX-XXXX" autocomplete="off">
                  </div>
                  <p class="muted" style="font-size: 12px; margin: 6px 0 0; text-align: center; color: var(--muted);">Each recovery code can only be used once.</p>
                </div>
              </div>

              <button type="submit" class="auth-submit-btn" id="forgotSubmit2faBtn">
                <span>Verify &amp; Set New Password</span>
              </button>

              <!-- Mode Toggle -->
              <button type="button" class="auth-toggle-link" id="forgotToggleRecoveryBtn">
                Use an emergency recovery code
              </button>

              <button type="button" class="auth-back-btn" id="forgotCancelBtn2">
                ← Back to Sign In
              </button>
            </form>
          </div>

          <!-- Step 3: New Password & Confirm Password -->
          <div id="forgotStepNewPass" <?= ($hasPendingReset && $pendingResetStage === 'awaiting_new_password') ? '' : 'hidden' ?>>
            <div class="auth-user-badge">
              <span>Account:</span>
              <strong id="forgotPassUserLabel">@<?= htmlspecialchars($pendingResetUsername ?: 'employee') ?></strong>
            </div>

            <form id="forgotResetPasswordForm" novalidate>
              <div class="auth-field">
                <label for="resetNewPassword">New Password</label>
                <div class="auth-input-box">
                  <input type="password" id="resetNewPassword" class="auth-input has-toggle" placeholder="At least 6 characters" required autocomplete="new-password">
                  <button type="button" class="toggle-pass-btn" data-target="resetNewPassword" aria-label="Show password" title="Show password">👁️</button>
                </div>
              </div>

              <div class="auth-field">
                <label for="resetConfirmPassword">Confirm New Password</label>
                <div class="auth-input-box">
                  <input type="password" id="resetConfirmPassword" class="auth-input has-toggle" placeholder="Re-enter new password" required autocomplete="new-password">
                  <button type="button" class="toggle-pass-btn" data-target="resetConfirmPassword" aria-label="Show password" title="Show password">👁️</button>
                </div>
              </div>

              <button type="submit" class="auth-submit-btn" id="forgotSubmitNewPassBtn">
                <span>Reset Password &amp; Sign In</span>
              </button>

              <button type="button" class="auth-back-btn" id="forgotCancelBtn3">
                ← Cancel
              </button>
            </form>
          </div>
        </div>

      </div>
    </div>

    <div class="system-footer">
      CafeFlow POS &copy; <?= date('Y') ?> · DK Cafe Point of Sale System
    </div>
  </div>

  <script>
    (function() {
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
      let cachedUsername = <?= json_encode($pendingUsername ?: '') ?>;
      let cachedForgotUsername = <?= json_encode($pendingResetUsername ?: '') ?>;
      let cachedGeneratedCodes = [];

      function showAlert(msg, type = 'error') {
        authAlert.className = 'auth-alert ' + type;
        authAlert.innerHTML = (type === 'error' ? '✕ ' : type === 'success' ? '✓ ' : 'ℹ️ ') + msg;
        authAlert.hidden = false;
        authAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }

      function clearAlert() {
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
        cardTitle.textContent = 'Sign In';
        cardSubtitle.textContent = 'Enter your staff credentials to access CafeFlow POS';
        if (loginForm) loginForm.hidden = false;
        clearAlert();
        if (loginIdentifier) loginIdentifier.focus();
      }

      function showTwoFactorScreen(username) {
        hideAllCards();
        cachedUsername = username || cachedUsername;
        if (twoFactorUserLabel) twoFactorUserLabel.textContent = '@' + cachedUsername;
        cardTitle.textContent = 'Two-Factor Authentication';
        cardSubtitle.textContent = 'Enter the 6-digit code generated by your Google Authenticator app.';
        twoFactorChallenge.hidden = false;

        current2faMode = 'totp';
        totpInputSection.hidden = false;
        recoveryInputSection.hidden = true;
        toggleRecoveryBtn.textContent = 'Use an emergency recovery code';

        totpCodeInput.value = '';
        recoveryCodeInput.value = '';
        setTimeout(() => totpCodeInput.focus(), 80);
      }

      function showDirect2faScreen() {
        hideAllCards();
        cardTitle.textContent = 'Sign In with 2FA';
        cardSubtitle.textContent = 'Fast login using your 6-digit Google Authenticator code';
        direct2faForm.hidden = false;

        direct2faMode = 'totp';
        directTotpSection.hidden = false;
        directRecoverySection.hidden = true;
        directToggleRecoveryBtn.textContent = 'Use an emergency recovery code';

        clearAlert();
        if (loginIdentifier && loginIdentifier.value.trim() && !direct2faIdentifier.value) {
          direct2faIdentifier.value = loginIdentifier.value.trim();
        }
        setTimeout(() => {
          if (direct2faIdentifier.value) {
            directTotpCodeInput.focus();
          } else {
            direct2faIdentifier.focus();
          }
        }, 80);
      }

      function showForgotStage1() {
        hideAllCards();
        cardTitle.textContent = 'Password Recovery';
        cardSubtitle.textContent = 'Enter your username or email to verify identity with 2FA';
        forgotPassFlow.hidden = false;
        forgotStepIdentifier.hidden = false;

        clearAlert();
        if (loginIdentifier && loginIdentifier.value.trim() && !forgotIdentifierInput.value) {
          forgotIdentifierInput.value = loginIdentifier.value.trim();
        }
        setTimeout(() => forgotIdentifierInput.focus(), 80);
      }

      function showForgotStage2(username) {
        hideAllCards();
        cachedForgotUsername = username || cachedForgotUsername;
        if (forgotUserLabel) forgotUserLabel.textContent = '@' + cachedForgotUsername;
        cardTitle.textContent = 'Verify Identity';
        cardSubtitle.textContent = 'Enter the 6-digit code from Google Authenticator to authorize password reset.';
        forgotPassFlow.hidden = false;
        forgotStep2fa.hidden = false;

        forgot2faMode = 'totp';
        forgotTotpSection.hidden = false;
        forgotRecoverySection.hidden = true;
        forgotToggleRecoveryBtn.textContent = 'Use an emergency recovery code';

        forgotTotpCodeInput.value = '';
        forgotRecoveryCodeInput.value = '';
        clearAlert();
        setTimeout(() => forgotTotpCodeInput.focus(), 80);
      }

      function showForgotStage3(username) {
        hideAllCards();
        cachedForgotUsername = username || cachedForgotUsername;
        if (forgotPassUserLabel) forgotPassUserLabel.textContent = '@' + cachedForgotUsername;
        cardTitle.textContent = 'Set New Password';
        cardSubtitle.textContent = 'Create and confirm your new account password.';
        forgotPassFlow.hidden = false;
        forgotStepNewPass.hidden = false;

        resetNewPassword.value = '';
        resetConfirmPassword.value = '';
        clearAlert();
        setTimeout(() => resetNewPassword.focus(), 80);
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
                password: password
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

          const payload = { action: 'login_via_2fa', identifier: identifier };
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
      // Stage 1: Identifier lookup
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

      // Stage 2: 2FA Verification
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

      // Stage 3: New Password & Confirm Password
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
        cardTitle.textContent = 'Setup Two-Factor Authentication';
        cardSubtitle.textContent = 'An administrator has reset your 2FA. Re-enrollment is required.';

        hideAllCards();
        reenrollChallenge.hidden = false;
        reenrollStepSetup.hidden = false;
        reenrollStepSuccess.hidden = true;
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
              csrf_token: '<?= htmlspecialchars($csrfToken) ?>'
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

      // Copy setup key button
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

      // Re-enroll confirmation submit
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
                csrf_token: '<?= htmlspecialchars($csrfToken) ?>'
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

      // Copy All Recovery Codes
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

      // Download Backup Codes .txt
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

      // Proceed to App after Re-enrollment
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
      <?php if ($hasPendingReset): ?>
        <?php if ($pendingResetStage === 'awaiting_new_password'): ?>
          showForgotStage3(<?= json_encode($pendingResetUsername) ?>);
        <?php else: ?>
          showForgotStage2(<?= json_encode($pendingResetUsername) ?>);
        <?php endif; ?>
      <?php endif; ?>
    })();
  </script>
</body>
</html>
