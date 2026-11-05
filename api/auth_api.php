<?php
/**
 * CafeFlow - Authentication API
 * Handles login, session inspection, and logout for authorized personnel.
 * Public account registration is permanently disabled.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----------------------------------------------------------------------------
// GET: Current Authenticated User (Session Check)
// -----------------------------------------------------------------------------
if ($method === 'GET') {
    $user = current_user();
    if (!$user) {
        send_json([
            'success'       => false,
            'authenticated' => false,
            'csrf_token'    => get_csrf_token()
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
        SELECT user_id, full_name, username, email, password_hash, role, status
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

    // Successful authentication
    record_login_attempt($clientIp, $identifier, true, $pdo);
    login_user($user);

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
// 2. Action: Register (PERMANENTLY DISABLED)
// -----------------------------------------------------------------------------
if ($action === 'register') {
    fail(403, 'Public account registration is permanently disabled. Employee accounts must be provisioned by an authorized Manager.');
}

// -----------------------------------------------------------------------------
// 3. Action: Logout
// -----------------------------------------------------------------------------
if ($action === 'logout') {
    logout_user();
    send_json([
        'success'  => true,
        'message'  => 'Successfully signed out.',
        'redirect' => 'login.php'
    ]);
}

fail(400, 'Unknown authentication action.');
