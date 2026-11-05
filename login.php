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

$redirectParam = $_GET['redirect'] ?? '';
$loggedOut = !empty($_GET['logged_out']);
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
      <div class="auth-card-header">
        <h1 class="auth-card-title">Sign In</h1>
        <p class="auth-card-subtitle">Enter your staff credentials to access CafeFlow POS</p>
      </div>

      <div class="auth-body">
        <!-- System Alerts -->
        <?php if ($loggedOut): ?>
          <div class="auth-alert success" id="signedOutAlert">
            <span>✓ You have been signed out safely.</span>
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

        <!-- SIGN IN FORM (AUTHORIZED ACCOUNTS ONLY) -->
        <form id="loginForm" autocomplete="on" novalidate>
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
            <label for="loginPassword">Password</label>
            <div class="auth-input-box">
              <input type="password" id="loginPassword" class="auth-input has-toggle" placeholder="Enter your password" required autocomplete="current-password">
              <button type="button" class="toggle-pass-btn" data-target="loginPassword" aria-label="Show password" title="Show password">👁️</button>
            </div>
          </div>

          <button type="submit" class="auth-submit-btn" id="loginSubmitBtn">
            <span>Sign In to CafeFlow</span>
          </button>

          <div class="auth-footer">
            <span class="auth-footer-note">🔒 Authorized personnel only · Managed POS system</span>
          </div>
        </form>

      </div>
    </div>

    <div class="system-footer">
      CafeFlow POS &copy; <?= date('Y') ?> · DK Cafe Point of Sale System
    </div>
  </div>

  <script>
    (function() {
      const loginForm = document.getElementById('loginForm');
      const authAlert = document.getElementById('authAlert');

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

      // Auto-dismiss alert after timeoutMs (defaults to 15,000ms / 15 seconds)
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

          // Clean URL params (e.g. ?logged_out=1) so banner won't persist on page refresh
          try {
            const url = new URL(window.location);
            if (url.searchParams.has('logged_out') || url.searchParams.has('error')) {
              url.searchParams.delete('logged_out');
              url.searchParams.delete('error');
              window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
            }
          } catch (e) {}
        };

        if (dismissBtn) {
          dismissBtn.addEventListener('click', dismiss);
        }

        timer = setTimeout(dismiss, timeoutMs);
      }

      // Initialize auto-dismissal for the signed-out alert (15 seconds)
      const signedOutAlert = document.getElementById('signedOutAlert');
      if (signedOutAlert) {
        setupAutoDismiss(signedOutAlert, 15000);
      }

      // Also auto-dismiss any other system alerts after 15 seconds
      document.querySelectorAll('.auth-alert.auto-dismiss').forEach(el => {
        setupAutoDismiss(el, 15000);
      });

      // Show/Hide password toggles
      document.querySelectorAll('.toggle-pass-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const targetId = btn.getAttribute('data-target');
          const input = document.getElementById(targetId);
          if (!input) return;
          if (input.type === 'password') {
            input.type = 'text';
            btn.textContent = '🙈';
            btn.title = 'Hide password';
          } else {
            input.type = 'password';
            btn.textContent = '👁️';
            btn.title = 'Show password';
          }
        });
      });

      // Handle Sign In submission
      if (loginForm) {
        loginForm.addEventListener('submit', async (e) => {
          e.preventDefault();
          clearAlert();

          const identifier = document.getElementById('loginIdentifier').value.trim();
          const password = document.getElementById('loginPassword').value;
          const submitBtn = document.getElementById('loginSubmitBtn');

          if (!identifier || !password) {
            showAlert('Please enter both username/email and password.');
            return;
          }

          submitBtn.disabled = true;
          const originalBtnHtml = submitBtn.innerHTML;
          submitBtn.innerHTML = '<div class="spinner"></div><span>Signing In…</span>';

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

            showAlert(data.message || 'Signed in! Redirecting…', 'success');
            const urlParams = new URLSearchParams(window.location.search);
            const redirectParam = urlParams.get('redirect');
            setTimeout(() => {
              if (redirectParam && redirectParam.startsWith('/') && !redirectParam.startsWith('//')) {
                window.location.href = redirectParam;
              } else {
                window.location.href = data.redirect || 'index.php';
              }
            }, 400);
          } catch (err) {
            showAlert(err.message, 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
          }
        });
      }
    })();
  </script>
</body>
</html>
