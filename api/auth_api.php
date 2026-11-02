<?php
/**
 * CafeFlow - Authentication API
 * Handles login, registration, current session inspection, and logout.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----------------------------------------------------------------------------
// GET: Current Authenticated User (Session Check)
// -----------------------------------------------------------------------------
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'me';
    
    if ($action === 'check_setup') {
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        send_json([
            'success'     => true,
            'needs_setup' => $userCount === 0,
            'csrf_token'  => get_csrf_token()
        ]);
    }

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
// POST: Login, Register, Logout
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
// 2. Action: Register (Public Registration & Initial Setup)
// -----------------------------------------------------------------------------
if ($action === 'register') {
    $fullName = trim($data['full_name'] ?? '');
    $username = trim($data['username'] ?? '');
    $email    = trim($data['email'] ?? '');
    $password = (string) ($data['password'] ?? '');
    $confirm  = (string) ($data['confirm_password'] ?? '');
    $reqRole  = strtolower(trim($data['role'] ?? 'barista'));

    if ($fullName === '' || strlen($fullName) < 2 || strlen($fullName) > 100) {
        fail(422, 'Full Name must be between 2 and 100 characters.');
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        fail(422, 'Username must be 3-50 alphanumeric characters (letters, numbers, _, ., -).');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(422, 'Please enter a valid email address.');
    }
    if (strlen($password) < 6) {
        fail(422, 'Password must be at least 6 characters long.');
    }
    if ($password !== $confirm) {
        fail(422, 'Passwords do not match. Please verify your password confirmation.');
    }

    // Duplicate checks
    $chkUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $chkUser->execute([$username]);
    if ($chkUser->fetchColumn() > 0) {
        fail(422, 'This username is already taken. Please choose another.');
    }

    if ($email !== '') {
        $chkEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $chkEmail->execute([$email]);
        if ($chkEmail->fetchColumn() > 0) {
            fail(422, 'This email address is already registered to another account.');
        }
    }

    $passHash = password_hash($password, PASSWORD_DEFAULT);

    // Check if this is the first-time setup (0 accounts exist)
    $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $isFirstTimeSetup = ($totalUsers === 0);

    if ($isFirstTimeSetup) {
        // Safe first-time setup: establish initial active account with selected role
        $role = in_array($reqRole, ['manager', 'barista'], true) ? $reqRole : 'manager';
        $status = 'active';

        $insert = $pdo->prepare('
            INSERT INTO users (full_name, username, email, password_hash, role, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([$fullName, $username, $email ?: null, $passHash, $role, $status]);
        $newId = (int) $pdo->lastInsertId();

        // Auto-login initial user
        $newUser = [
            'user_id'   => $newId,
            'full_name' => $fullName,
            'username'  => $username,
            'email'     => $email ?: null,
            'role'      => $role,
            'status'    => $status
        ];
        login_user($newUser);

        $redirect = ($role === 'manager') ? 'index.php?view=report' : 'index.php';
        send_json([
            'success'     => true,
            'is_setup'    => true,
            'message'     => 'Initial administrator account setup completed! You are now signed in.',
            'redirect'    => $redirect,
            'user'        => $newUser,
            'csrf_token'  => get_csrf_token()
        ]);
    } else {
        // Controlled public registration:
        // NEVER trust browser-submitted role! Public self-registrations are saved as 'pending'
        // and must be approved by an existing authorized user before login is permitted.
        $role = 'barista'; // Default requested role
        if ($reqRole === 'manager') {
            $role = 'manager';
        }
        $status = 'pending';

        $insert = $pdo->prepare('
            INSERT INTO users (full_name, username, email, password_hash, role, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([$fullName, $username, $email ?: null, $passHash, $role, $status]);

        send_json([
            'success'     => true,
            'is_setup'    => false,
            'message'     => 'Account registration submitted successfully! Your account is currently pending approval by a Manager before you can sign in.',
            'status'      => 'pending'
        ]);
    }
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
