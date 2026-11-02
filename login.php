<?php
/**
 * CafeFlow - Secure Login & Account Registration
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

$pdo = db();

// If user is already authenticated, redirect them to appropriate landing page
if (is_authenticated()) {
    $user = current_user();
    $role = strtolower($user['role'] ?? 'barista');
    $dest = 'index.php';
    header("Location: {$dest}");
    exit;
}

// Check if first-time system setup is needed (0 accounts)
$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$isFirstTimeSetup = ($userCount === 0);

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

    /* Mode Tabs */
    .auth-tabs {
      display: flex;
      background: var(--cream);
      border-bottom: 1.5px solid var(--line);
    }
    .auth-tab-btn {
      flex: 1;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 14.5px;
      font-weight: 700;
      color: var(--muted);
      background: transparent;
      border: none;
      border-bottom: 2.5px solid transparent;
      cursor: pointer;
      transition: all var(--dur-fast);
    }
    .auth-tab-btn.active {
      color: var(--caramel);
      background: #ffffff;
      border-bottom-color: var(--caramel);
    }

    .auth-body {
      padding: 24px 28px 28px;
    }

    /* Setup Banner */
    .setup-banner {
      background: #ebf8ff;
      border: 1.5px solid #bee3f8;
      border-radius: var(--radius-sm);
      padding: 12px 14px;
      margin-bottom: 18px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
      color: #2b6cb0;
      font-size: 13.5px;
      line-height: 1.4;
    }
    .setup-banner strong {
      display: block;
      font-size: 14px;
      margin-bottom: 2px;
      color: #2c5282;
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

    .auth-select {
      width: 100%;
      height: 44px;
      padding: 0 12px;
      border: 1.5px solid var(--line);
      border-radius: var(--radius-sm);
      font-size: 14px;
      font-weight: 600;
      background: var(--cream);
      color: var(--ink);
    }

    .auth-hint {
      display: block;
      font-size: 12px;
      color: var(--muted);
      margin-top: 5px;
      line-height: 1.35;
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

    /* Footer / Switch */
    .auth-footer {
      margin-top: 18px;
      text-align: center;
      font-size: 13.5px;
      color: var(--muted);
    }
    .auth-link {
      color: var(--caramel);
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
    }
    .auth-link:hover {
      text-decoration: underline;
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
      <?php if (!$isFirstTimeSetup): ?>
      <div class="auth-tabs">
        <button type="button" class="auth-tab-btn active" id="tabLoginBtn">Sign In</button>
        <button type="button" class="auth-tab-btn" id="tabRegisterBtn">Create Account</button>
      </div>
      <?php endif; ?>

      <div class="auth-body">
        <!-- System Alerts -->
        <?php if ($loggedOut): ?>
          <div class="auth-alert success">✓ You have been signed out safely.</div>
        <?php endif; ?>
        <?php if ($errorParam === 'forbidden'): ?>
          <div class="auth-alert error">✕ Access denied. Your role is not authorized for that page.</div>
        <?php endif; ?>
        <?php if ($errorParam === 'session_expired'): ?>
          <div class="auth-alert info">ℹ️ Your session has expired. Please sign in again.</div>
        <?php endif; ?>

        <div id="authAlert" class="auth-alert" hidden></div>

        <!-- FIRST-TIME SETUP BANNER (IF 0 ACCOUNTS) -->
        <?php if ($isFirstTimeSetup): ?>
        <div class="setup-banner">
          <div>
            <strong>🚀 Initial System Setup</strong>
            Welcome to CafeFlow! No accounts were detected in the database. Create the initial administrator account below to get started.
          </div>
        </div>
        <?php endif; ?>

        <!-- ==========================================
             FORM 1: SIGN IN (LOGIN)
             ========================================== -->
        <form id="loginForm" autocomplete="on" novalidate <?= $isFirstTimeSetup ? 'hidden' : '' ?>>
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
            Don't have an account? <a class="auth-link" id="linkToRegister">Create an Account</a>
          </div>
        </form>

        <!-- ==========================================
             FORM 2: REGISTRATION / INITIAL SETUP
             ========================================== -->
        <form id="registerForm" autocomplete="off" novalidate <?= !$isFirstTimeSetup ? 'hidden' : '' ?>>
          <input type="hidden" name="action" value="register">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectParam) ?>">

          <div class="auth-field">
            <label for="regFullName">Full Name *</label>
            <div class="auth-input-box">
              <input type="text" id="regFullName" class="auth-input" placeholder="e.g. Maria Santos" maxlength="100" required>
            </div>
          </div>

          <div class="auth-field">
            <label for="regUsername">Username *</label>
            <div class="auth-input-box">
              <input type="text" id="regUsername" class="auth-input" placeholder="e.g. maria_barista" maxlength="50" required>
            </div>
            <small class="auth-hint">3-50 alphanumeric characters (letters, numbers, underscores).</small>
          </div>

          <div class="auth-field">
            <label for="regEmail">Email Address (Optional)</label>
            <div class="auth-input-box">
              <input type="email" id="regEmail" class="auth-input" placeholder="e.g. maria@cafeflow.com" maxlength="100">
            </div>
          </div>

          <div class="auth-field">
            <label for="regRole">Account Role *</label>
            <select id="regRole" class="auth-select">
              <option value="barista">Barista (Full POS & System Access)</option>
              <option value="manager" <?= $isFirstTimeSetup ? 'selected' : '' ?>>Manager (Inventory, Reports & Accounts)</option>
            </select>
            <?php if (!$isFirstTimeSetup): ?>
            <small class="auth-hint">Note: Public registrations require approval by a Manager before activation.</small>
            <?php else: ?>
            <small class="auth-hint">Initial administrator account will be active immediately.</small>
            <?php endif; ?>
          </div>

          <div class="auth-field">
            <label for="regPassword">Password *</label>
            <div class="auth-input-box">
              <input type="password" id="regPassword" class="auth-input has-toggle" placeholder="Minimum 6 characters" minlength="6" required>
              <button type="button" class="toggle-pass-btn" data-target="regPassword" aria-label="Show password" title="Show password">👁️</button>
            </div>
          </div>

          <div class="auth-field">
            <label for="regConfirmPassword">Confirm Password *</label>
            <div class="auth-input-box">
              <input type="password" id="regConfirmPassword" class="auth-input has-toggle" placeholder="Repeat password" minlength="6" required>
              <button type="button" class="toggle-pass-btn" data-target="regConfirmPassword" aria-label="Show password" title="Show password">👁️</button>
            </div>
          </div>

          <button type="submit" class="auth-submit-btn" id="registerSubmitBtn">
            <span><?= $isFirstTimeSetup ? 'Initialize System & Sign In' : 'Submit Registration' ?></span>
          </button>

          <?php if (!$isFirstTimeSetup): ?>
          <div class="auth-footer">
            Already have an account? <a class="auth-link" id="linkToLogin">Sign In</a>
          </div>
          <?php endif; ?>
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
      const registerForm = document.getElementById('registerForm');
      const tabLoginBtn = document.getElementById('tabLoginBtn');
      const tabRegisterBtn = document.getElementById('tabRegisterBtn');
      const linkToRegister = document.getElementById('linkToRegister');
      const linkToLogin = document.getElementById('linkToLogin');
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

      function switchTab(mode) {
        clearAlert();
        if (mode === 'register') {
          if (tabLoginBtn) tabLoginBtn.classList.remove('active');
          if (tabRegisterBtn) tabRegisterBtn.classList.add('active');
          if (loginForm) loginForm.hidden = true;
          if (registerForm) registerForm.hidden = false;
          const nameInput = document.getElementById('regFullName');
          if (nameInput) nameInput.focus();
        } else {
          if (tabRegisterBtn) tabRegisterBtn.classList.remove('active');
          if (tabLoginBtn) tabLoginBtn.classList.add('active');
          if (registerForm) registerForm.hidden = true;
          if (loginForm) loginForm.hidden = false;
          const userInp = document.getElementById('loginIdentifier');
          if (userInp) userInp.focus();
        }
      }

      if (tabLoginBtn) tabLoginBtn.addEventListener('click', () => switchTab('login'));
      if (tabRegisterBtn) tabRegisterBtn.addEventListener('click', () => switchTab('register'));
      if (linkToRegister) linkToRegister.addEventListener('click', (e) => { e.preventDefault(); switchTab('register'); });
      if (linkToLogin) linkToLogin.addEventListener('click', (e) => { e.preventDefault(); switchTab('login'); });

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
            }, 500);
          } catch (err) {
            showAlert(err.message, 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnHtml;
          }
        });
      }

      // Handle Account Registration submission
      if (registerForm) {
        registerForm.addEventListener('submit', async (e) => {
          e.preventDefault();
          clearAlert();

          const fullName = document.getElementById('regFullName').value.trim();
          const username = document.getElementById('regUsername').value.trim();
          const email = document.getElementById('regEmail').value.trim();
          const role = document.getElementById('regRole').value;
          const password = document.getElementById('regPassword').value;
          const confirm = document.getElementById('regConfirmPassword').value;
          const submitBtn = document.getElementById('registerSubmitBtn');

          if (!fullName) {
            showAlert('Full Name is required.');
            return;
          }
          if (!username) {
            showAlert('Username is required.');
            return;
          }
          if (password.length < 6) {
            showAlert('Password must be at least 6 characters long.');
            return;
          }
          if (password !== confirm) {
            showAlert('Passwords do not match. Please verify your password confirmation.');
            return;
          }

          submitBtn.disabled = true;
          const originalBtnHtml = submitBtn.innerHTML;
          submitBtn.innerHTML = '<div class="spinner"></div><span>Submitting…</span>';

          try {
            const res = await fetch('api/auth_api.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: JSON.stringify({
                action: 'register',
                full_name: fullName,
                username: username,
                email: email,
                role: role,
                password: password,
                confirm_password: confirm
              })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
              throw new Error(data.error || 'Failed to register account.');
            }

            if (data.is_setup) {
              showAlert(data.message || 'System initialized! Redirecting…', 'success');
              setTimeout(() => {
                window.location.href = data.redirect || 'index.php';
              }, 800);
            } else {
              showAlert(data.message, 'success');
              registerForm.reset();
              setTimeout(() => {
                switchTab('login');
              }, 2500);
            }
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
