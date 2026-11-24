<?php
/**
 * CafeFlow - Authentication API
 * Handles login, session inspection, and logout for authorized personnel.
 * Public account registration is permanently disabled.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/totp.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----------------------------------------------------------------------------
// GET: Current Authenticated User (Session Check)
// -----------------------------------------------------------------------------
if ($method === 'GET') {
    $user = current_user();
    if (!$user) {
        $pending = get_pending_2fa();
        $pendingReset = get_pending_password_reset();
        send_json([
            'success'             => false,
            'authenticated'       => false,
            'requires_2fa'        => $pending !== null,
            'reenroll_required'   => ($pending['mode'] ?? '') === 'reenroll',
            'pending_username'    => $pending['username'] ?? ($pendingReset['username'] ?? ''),
            'pending_reset'       => $pendingReset !== null,
            'pending_reset_stage' => $pendingReset['stage'] ?? null,
            'csrf_token'          => get_csrf_token()
        ]);
    }

    send_json([
        'success'       => true,
        'authenticated' => true,
        'user'          => [
            'user_id'    => (int) $user['user_id'],
            'full_name'  => $user['full_name'],
            'username'   => $user['username'],
            'email'      => $user['email'],
            'role'       => strtolower($user['role']),
            'status'     => $user['status'],
            'created_at' => $user['created_at'],
            'last_login' => $user['last_login']
        ],
        'csrf_token'    => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// POST: Login, Logout (Registration Permanently Disabled)
// -----------------------------------------------------------------------------
require_method('POST');

// Handle both JSON body and form-urlencoded
$isForm = !empty($_POST) || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded');
$data = $isForm ? $_POST : read_json();

$action = trim($data['action'] ?? '');
$clientIp = get_client_ip();

// -----------------------------------------------------------------------------
// 1. Action: Login
// -----------------------------------------------------------------------------
if ($action === 'login') {
    $identifier = trim($data['identifier'] ?? $data['username'] ?? '');
    $password   = (string) ($data['password'] ?? '');

    if ($identifier === '' || $password === '') {
        fail(422, 'Please enter both username/email and password.');
    }

    // Rate limiting check: max 5 failed attempts in last 15 minutes
    if (is_login_rate_limited($clientIp, $identifier, $pdo)) {
        fail(429, 'Too many failed login attempts. For security reasons, please wait 15 minutes before trying again.');
    }

    // Lookup user by username or email
    $stmt = $pdo->prepare('
        SELECT user_id, full_name, username, email, password_hash, role, status,
               two_factor_secret, two_factor_enabled, two_factor_confirmed_at,
               two_factor_recovery_codes, two_factor_last_used_step, two_factor_reenroll_required
          FROM users
         WHERE (username = ? OR email = ?)
         LIMIT 1
    ');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_login_attempt($clientIp, $identifier, false, $pdo);
        fail(401, 'Invalid username/email or password.');
    }

    // Check account status
    if ($user['status'] === 'pending') {
        record_login_attempt($clientIp, $identifier, false, $pdo);
        fail(403, 'Your account registration is currently pending approval by a Manager.');
    }
    if ($user['status'] !== 'active') {
        record_login_attempt($clientIp, $identifier, false, $pdo);
        fail(403, 'This account has been deactivated. Please contact an authorized administrator.');
    }

    // Clear primary login rate limiting counter on valid credentials
    record_login_attempt($clientIp, $identifier, true, $pdo);

    $rememberMe = !empty($data['remember_me']);

    // Case A: Administrative reset requires 2FA re-enrollment
    if (!empty($user['two_factor_reenroll_required'])) {
        init_session();
        $_SESSION['pending_2fa'] = [
            'user_id'     => (int) $user['user_id'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name'],
            'mode'        => 'reenroll',
            'remember_me' => $rememberMe,
            'created_at'  => time()
        ];

        send_json([
            'success'           => true,
            'requires_2fa'      => true,
            'reenroll_required' => true,
            'username'          => $user['username'],
            'message'           => 'Administrative reset requires Two-Factor Authentication re-enrollment.',
            'csrf_token'        => get_csrf_token()
        ]);
    }

    // Case B: 2FA is active on this account
    if (!empty($user['two_factor_enabled'])) {
        init_session();
        $_SESSION['pending_2fa'] = [
            'user_id'     => (int) $user['user_id'],
            'username'    => $user['username'],
            'full_name'   => $user['full_name'],
            'mode'        => 'login',
            'remember_me' => $rememberMe,
            'created_at'  => time()
        ];

        send_json([
            'success'           => true,
            'requires_2fa'      => true,
            'reenroll_required' => false,
            'username'          => $user['username'],
            'message'           => 'Two-factor authentication required.',
            'csrf_token'        => get_csrf_token()
        ]);
    }

    // Case C: Standard password-only login (2FA disabled)
    clear_pending_2fa();
    login_user($user);
    if ($rememberMe) {
        set_remember_me_token((int) $user['user_id'], $pdo);
    }

    // Determine target redirect based on RBAC
    $role = strtolower($user['role']);
    $redirect = 'index.php';

    send_json([
        'success'    => true,
        'message'    => 'Signed in successfully. Welcome back, ' . htmlspecialchars($user['full_name']) . '!',
        'redirect'   => $redirect,
        'user'       => [
            'user_id'   => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'role'      => $role,
            'status'    => $user['status']
        ],
        'csrf_token' => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// 2. Action: Verify 2FA Challenge (TOTP Code or Recovery Code)
// -----------------------------------------------------------------------------
if ($action === 'verify_2fa') {
    $pending = get_pending_2fa();
    if (!$pending || empty($pending['user_id'])) {
        clear_pending_2fa();
        fail(401, 'Two-factor authentication session expired or invalid. Please sign in again.');
    }

    $pendingUserId = (int) $pending['user_id'];

    // Retrieve target user securely from session-stored ID
    $stmt = $pdo->prepare('
        SELECT user_id, full_name, username, email, role, status,
               two_factor_secret, two_factor_enabled, two_factor_last_used_step, two_factor_recovery_codes
          FROM users
         WHERE user_id = ?
         LIMIT 1
    ');
    $stmt->execute([$pendingUserId]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || empty($user['two_factor_enabled'])) {
        clear_pending_2fa();
        fail(401, 'Two-factor authentication session is invalid.');
    }

    // Enforce 2FA brute force rate limiting (max 5 failed attempts in 15 mins)
    if (is_2fa_rate_limited($pendingUserId, $clientIp, $pdo)) {
        fail(429, 'Too many failed 2FA verification attempts. Please wait 15 minutes before trying again.');
    }

    $code = trim($data['code'] ?? '');
    $recoveryCode = trim($data['recovery_code'] ?? '');

    $verified = false;

    // Method A: One-time Recovery Code
    if ($recoveryCode !== '') {
        $burned = totp_verify_and_burn_recovery_code($pdo, $pendingUserId, $recoveryCode);
        if (!$burned) {
            record_2fa_attempt($pendingUserId, $clientIp, 'recovery', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }
        record_2fa_attempt($pendingUserId, $clientIp, 'recovery', true, $pdo);
        $verified = true;
    }
    // Method B: 6-Digit Authenticator TOTP Code
    elseif ($code !== '') {
        if (!preg_match('/^\d{6}$/', $code)) {
            record_2fa_attempt($pendingUserId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        try {
            $secret = totp_decrypt_secret($user['two_factor_secret'] ?? '');
        } catch (Throwable) {
            record_2fa_attempt($pendingUserId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        $lastStep = $user['two_factor_last_used_step'] !== null ? (int) $user['two_factor_last_used_step'] : null;
        $matchedStep = totp_find_matching_step($secret, $code, null, 1, $lastStep);

        if ($matchedStep === null) {
            record_2fa_attempt($pendingUserId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        // Enforce atomic replay protection in database
        $upStep = $pdo->prepare('
            UPDATE users 
               SET two_factor_last_used_step = ? 
             WHERE user_id = ? 
               AND (two_factor_last_used_step IS NULL OR two_factor_last_used_step < ?)
        ');
        $upStep->execute([$matchedStep, $pendingUserId, $matchedStep]);

        if ($upStep->rowCount() === 0) {
            // Concurrent request already claimed this step
            record_2fa_attempt($pendingUserId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        record_2fa_attempt($pendingUserId, $clientIp, 'totp', true, $pdo);
        $verified = true;
    } else {
        fail(422, 'Please enter your authentication code.');
    }

    if (!$verified) {
        fail(401, 'Invalid authentication code. Please try again.');
    }

    // 2FA Verified! Clear pending state and establish fully authenticated session
    $rememberMe = !empty($pending['remember_me']);
    clear_pending_2fa();
    login_user($user, 'password_2fa');
    if ($rememberMe) {
        set_remember_me_token((int) $user['user_id'], $pdo);
    }

    $role = strtolower($user['role']);
    $redirect = 'index.php';

    send_json([
        'success'    => true,
        'message'    => 'Signed in successfully. Welcome back, ' . htmlspecialchars($user['full_name']) . '!',
        'redirect'   => $redirect,
        'user'       => [
            'user_id'   => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'role'      => $role,
            'status'    => $user['status']
        ],
        'csrf_token' => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// 3. Action: Cancel Pending 2FA Authentication
// -----------------------------------------------------------------------------
if ($action === 'cancel_2fa') {
    clear_pending_2fa();
    send_json([
        'success' => true,
        'message' => 'Two-factor verification cancelled.'
    ]);
}

// -----------------------------------------------------------------------------
// 4. Action: Register (PERMANENTLY DISABLED)
// -----------------------------------------------------------------------------
if ($action === 'register') {
    fail(403, 'Public account registration is permanently disabled. Employee accounts must be provisioned by an authorized Manager.');
}

// -----------------------------------------------------------------------------
// 5. Action: Logout
// -----------------------------------------------------------------------------
if ($action === 'logout') {
    clear_pending_2fa();
    clear_pending_password_reset();
    logout_user();
    send_json([
        'success'  => true,
        'message'  => 'Successfully signed out.',
        'redirect' => 'login.php'
    ]);
}

// -----------------------------------------------------------------------------
// 6. Action: Login via 2FA (Direct TOTP / Recovery Code Sign-In)
// -----------------------------------------------------------------------------
if ($action === 'login_via_2fa') {
    $identifier = trim($data['identifier'] ?? $data['username'] ?? '');
    $code = trim($data['code'] ?? '');
    $recoveryCode = trim($data['recovery_code'] ?? '');

    if ($identifier === '') {
        fail(422, 'Please enter your username or email address.');
    }
    if ($code === '' && $recoveryCode === '') {
        fail(422, 'Please enter your 6-digit Google Authenticator code or recovery code.');
    }

    if (is_login_rate_limited($clientIp, $identifier, $pdo)) {
        fail(429, 'Too many failed login attempts. Please wait 15 minutes before trying again.');
    }

    $stmt = $pdo->prepare('
        SELECT user_id, full_name, username, email, password_hash, role, status,
               two_factor_secret, two_factor_enabled, two_factor_confirmed_at,
               two_factor_recovery_codes, two_factor_last_used_step, two_factor_reenroll_required
          FROM users
         WHERE (username = ? OR email = ?)
         LIMIT 1
    ');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        record_login_attempt($clientIp, $identifier, false, $pdo);
        fail(401, 'Invalid username or authentication code.');
    }

    if ($user['status'] !== 'active') {
        record_login_attempt($clientIp, $identifier, false, $pdo);
        fail(403, 'This account is not active. Please contact an authorized administrator.');
    }

    if (empty($user['two_factor_enabled'])) {
        fail(400, 'Two-Factor Authentication is not enabled for this account. Please sign in using your password.');
    }

    if (!empty($user['two_factor_reenroll_required'])) {
        fail(400, 'Administrative reset requires Two-Factor Authentication re-enrollment. Please sign in with your password.');
    }

    $userId = (int) $user['user_id'];
    if (is_2fa_rate_limited($userId, $clientIp, $pdo)) {
        fail(429, 'Too many failed 2FA verification attempts. Please wait 15 minutes before trying again.');
    }

    $verified = false;
    if ($recoveryCode !== '') {
        $burned = totp_verify_and_burn_recovery_code($pdo, $userId, $recoveryCode);
        if (!$burned) {
            record_2fa_attempt($userId, $clientIp, 'recovery', false, $pdo);
            record_login_attempt($clientIp, $identifier, false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }
        record_2fa_attempt($userId, $clientIp, 'recovery', true, $pdo);
        $verified = true;
    } elseif ($code !== '') {
        if (!preg_match('/^\d{6}$/', $code)) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        try {
            $secret = totp_decrypt_secret($user['two_factor_secret'] ?? '');
        } catch (Throwable) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        $lastStep = $user['two_factor_last_used_step'] !== null ? (int) $user['two_factor_last_used_step'] : null;
        $matchedStep = totp_find_matching_step($secret, $code, null, 1, $lastStep);

        if ($matchedStep === null) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            record_login_attempt($clientIp, $identifier, false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        $upStep = $pdo->prepare('
            UPDATE users 
               SET two_factor_last_used_step = ? 
             WHERE user_id = ? 
               AND (two_factor_last_used_step IS NULL OR two_factor_last_used_step < ?)
        ');
        $upStep->execute([$matchedStep, $userId, $matchedStep]);
        if ($upStep->rowCount() === 0) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        record_2fa_attempt($userId, $clientIp, 'totp', true, $pdo);
        $verified = true;
    }

    if (!$verified) {
        fail(401, 'Invalid authentication code. Please try again.');
    }

    record_login_attempt($clientIp, $identifier, true, $pdo);
    log_security_audit($pdo, $userId, $userId, 'login_via_2fa', ['method' => $recoveryCode ? 'recovery' : 'totp']);

    clear_pending_2fa();
    clear_pending_password_reset();
    login_user($user, 'direct_2fa');
    if (!empty($data['remember_me'])) {
        set_remember_me_token((int) $user['user_id'], $pdo);
    }

    send_json([
        'success'    => true,
        'message'    => 'Signed in successfully via Two-Factor Authentication. Welcome back, ' . htmlspecialchars($user['full_name']) . '!',
        'redirect'   => 'index.php',
        'user'       => [
            'user_id'   => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'role'      => strtolower($user['role']),
            'status'    => $user['status']
        ],
        'csrf_token' => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// 7. Action: Forgot Password - Step 1: Initialize
// -----------------------------------------------------------------------------
if ($action === 'forgot_password_init') {
    $identifier = trim($data['identifier'] ?? $data['username'] ?? '');

    if ($identifier === '') {
        fail(422, 'Please enter your username or email address.');
    }

    if (is_login_rate_limited($clientIp, $identifier, $pdo)) {
        fail(429, 'Too many failed attempts. Please wait 15 minutes before trying again.');
    }

    $stmt = $pdo->prepare('
        SELECT user_id, full_name, username, email, role, status,
               two_factor_enabled, two_factor_reenroll_required
          FROM users
         WHERE (username = ? OR email = ?)
         LIMIT 1
    ');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user) {
        record_login_attempt($clientIp, $identifier, false, $pdo);
        fail(404, 'No account found with that username or email address.');
    }

    if ($user['status'] !== 'active') {
        fail(403, 'This account is not active. Please contact an authorized administrator.');
    }

    if (empty($user['two_factor_enabled'])) {
        fail(400, 'Two-Factor Authentication is not enabled for this account. Self-service password reset requires active 2FA. Please ask your Store Manager to reset your password.');
    }

    if (!empty($user['two_factor_reenroll_required'])) {
        fail(400, 'Two-Factor Authentication re-enrollment is pending for this account. Please ask your Store Manager to reset your password.');
    }

    init_session();
    $_SESSION['pending_password_reset'] = [
        'user_id'    => (int) $user['user_id'],
        'username'   => $user['username'],
        'full_name'  => $user['full_name'],
        'stage'      => 'awaiting_2fa',
        'created_at' => time()
    ];

    send_json([
        'success'      => true,
        'requires_2fa' => true,
        'username'     => $user['username'],
        'message'      => 'Enter your Google Authenticator code to verify your identity.',
        'csrf_token'   => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// 8. Action: Forgot Password - Step 2: Verify 2FA
// -----------------------------------------------------------------------------
if ($action === 'forgot_password_verify') {
    $pending = get_pending_password_reset();
    if (!$pending || empty($pending['user_id']) || ($pending['stage'] ?? '') !== 'awaiting_2fa') {
        clear_pending_password_reset();
        fail(401, 'Password reset session has expired or is invalid. Please start again.');
    }

    $userId = (int) $pending['user_id'];
    $stmt = $pdo->prepare('
        SELECT user_id, full_name, username, status, two_factor_secret, two_factor_enabled,
               two_factor_last_used_step, two_factor_recovery_codes
          FROM users
         WHERE user_id = ?
         LIMIT 1
    ');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active' || empty($user['two_factor_enabled'])) {
        clear_pending_password_reset();
        fail(401, 'User account is not eligible for password reset.');
    }

    if (is_2fa_rate_limited($userId, $clientIp, $pdo)) {
        fail(429, 'Too many failed 2FA verification attempts. Please wait 15 minutes before trying again.');
    }

    $code = trim($data['code'] ?? '');
    $recoveryCode = trim($data['recovery_code'] ?? '');
    $verified = false;

    if ($recoveryCode !== '') {
        $burned = totp_verify_and_burn_recovery_code($pdo, $userId, $recoveryCode);
        if (!$burned) {
            record_2fa_attempt($userId, $clientIp, 'recovery', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }
        record_2fa_attempt($userId, $clientIp, 'recovery', true, $pdo);
        $verified = true;
    } elseif ($code !== '') {
        if (!preg_match('/^\d{6}$/', $code)) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        try {
            $secret = totp_decrypt_secret($user['two_factor_secret'] ?? '');
        } catch (Throwable) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        $lastStep = $user['two_factor_last_used_step'] !== null ? (int) $user['two_factor_last_used_step'] : null;
        $matchedStep = totp_find_matching_step($secret, $code, null, 1, $lastStep);

        if ($matchedStep === null) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        $upStep = $pdo->prepare('
            UPDATE users 
               SET two_factor_last_used_step = ? 
             WHERE user_id = ? 
               AND (two_factor_last_used_step IS NULL OR two_factor_last_used_step < ?)
        ');
        $upStep->execute([$matchedStep, $userId, $matchedStep]);
        if ($upStep->rowCount() === 0) {
            record_2fa_attempt($userId, $clientIp, 'totp', false, $pdo);
            fail(401, 'Invalid authentication code. Please try again.');
        }

        record_2fa_attempt($userId, $clientIp, 'totp', true, $pdo);
        $verified = true;
    } else {
        fail(422, 'Please enter your authentication code.');
    }

    if (!$verified) {
        fail(401, 'Invalid authentication code. Please try again.');
    }

    $_SESSION['pending_password_reset']['stage'] = 'awaiting_new_password';
    log_security_audit($pdo, $userId, $userId, 'forgot_password_2fa_verified', ['method' => $recoveryCode ? 'recovery' : 'totp']);

    send_json([
        'success'    => true,
        'message'    => 'Two-Factor Authentication verified. Please enter your new password.',
        'username'   => $user['username'],
        'csrf_token' => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// 9. Action: Forgot Password - Step 3: Complete Reset (Set New Password)
// -----------------------------------------------------------------------------
if ($action === 'forgot_password_complete') {
    $pending = get_pending_password_reset();
    if (!$pending || empty($pending['user_id']) || ($pending['stage'] ?? '') !== 'awaiting_new_password') {
        clear_pending_password_reset();
        fail(401, 'Password reset session has expired or is unauthorized. Please start again.');
    }

    $userId = (int) $pending['user_id'];
    $newPassword = (string) ($data['new_password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');

    if (strlen($newPassword) < 6) {
        fail(422, 'New password must be at least 6 characters long.');
    }
    if ($newPassword !== $confirmPassword) {
        fail(422, 'Passwords do not match. Please re-enter your new password.');
    }

    $stmt = $pdo->prepare('SELECT user_id, full_name, username, email, role, status FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        clear_pending_password_reset();
        fail(403, 'Account is inactive or not found.');
    }

    // Update password hash ONLY. Leave two_factor_secret and all 2FA settings completely intact!
    $passHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upStmt = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?');
    $upStmt->execute([$passHash, $userId]);

    log_security_audit($pdo, $userId, $userId, 'forgot_password_reset_success');
    clear_pending_password_reset();

    // Automatically establish authenticated session
    login_user($user);

    send_json([
        'success'    => true,
        'message'    => 'Password has been reset successfully. Welcome back, ' . htmlspecialchars($user['full_name']) . '!',
        'redirect'   => 'index.php',
        'user'       => [
            'user_id'   => (int) $user['user_id'],
            'full_name' => $user['full_name'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'role'      => strtolower($user['role']),
            'status'    => $user['status']
        ],
        'csrf_token' => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// 10. Action: Cancel Password Reset
// -----------------------------------------------------------------------------
if ($action === 'cancel_reset') {
    clear_pending_password_reset();
    send_json([
        'success' => true,
        'message' => 'Password reset cancelled.'
    ]);
}

fail(400, 'Unknown authentication action.');
