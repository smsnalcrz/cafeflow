<?php
/**
 * CafeFlow - Account Management API
 *
 * Full account administration for authorized Baristas and Managers:
 * overview statistics, accounts directory, role assignment, status updates,
 * credential resets, and self-demotion / last-manager safety guards.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();
$currentUser = require_role('manager'); // Account Management restricted to Managers only
$currentRole = strtolower($currentUser['role']);
$currentUserId = (int) $currentUser['user_id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -----------------------------------------------------------------------------
// GET: List Accounts and Directory Overview Stats
// -----------------------------------------------------------------------------
if ($method === 'GET') {
    $rows = $pdo->query('
        SELECT user_id, full_name, username, email, role, status, created_at, updated_at, last_login
          FROM users
         ORDER BY 
           CASE status 
             WHEN "pending" THEN 1 
             WHEN "active" THEN 2 
             ELSE 3 
           END ASC,
           role ASC,
           created_at DESC
    ')->fetchAll();

    $total = count($rows);
    $baristas = 0;
    $managers = 0;
    $active = 0;
    $pending = 0;
    $inactive = 0;

    $items = [];
    foreach ($rows as $r) {
        $uRole = strtolower($r['role']);
        $uStatus = strtolower($r['status']);

        if ($uRole === 'barista') $baristas++;
        if ($uRole === 'manager') $managers++;

        if ($uStatus === 'active') $active++;
        elseif ($uStatus === 'pending') $pending++;
        elseif ($uStatus === 'inactive') $inactive++;

        $items[] = [
            'user_id'    => (int) $r['user_id'],
            'full_name'  => $r['full_name'],
            'username'   => $r['username'],
            'email'      => $r['email'] ?? '',
            'role'       => $uRole,
            'status'     => $uStatus,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'last_login' => $r['last_login'],
            'is_self'    => ((int) $r['user_id'] === $currentUserId)
        ];
    }

    send_json([
        'success'  => true,
        'overview' => [
            'total'    => $total,
            'baristas' => $baristas,
            'managers' => $managers,
            'active'   => $active,
            'pending'  => $pending,
            'inactive' => $inactive
        ],
        'items'    => $items,
        'current_user' => [
            'user_id' => $currentUserId,
            'role'    => $currentRole
        ],
        'csrf_token' => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// POST: Create, Update, Toggle Status, Reset Password
// -----------------------------------------------------------------------------
require_method('POST');

$isForm = !empty($_POST) || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded');
$data = $isForm ? $_POST : read_json();

// Validate CSRF token for state changes
$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrfToken)) {
    fail(403, 'CSRF security token invalid or missing. Please refresh and try again.');
}

$action = trim($data['action'] ?? '');

// -----------------------------------------------------------------------------
// 1. Action: Create Account
// -----------------------------------------------------------------------------
if ($action === 'create') {
    $fullName = trim($data['full_name'] ?? '');
    $username = trim($data['username'] ?? '');
    $email    = trim($data['email'] ?? '');
    $role     = strtolower(trim($data['role'] ?? 'barista'));
    $status   = strtolower(trim($data['status'] ?? 'active'));
    $password = (string) ($data['password'] ?? '');

    if ($fullName === '' || strlen($fullName) < 2 || strlen($fullName) > 100) {
        fail(422, 'Full Name is required (2-100 characters).');
    }
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        fail(422, 'Username must be 3-50 alphanumeric characters.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(422, 'Invalid email address format.');
    }
    if (!in_array($role, ['barista', 'manager'], true)) {
        fail(422, 'Invalid role. Choose Barista or Manager.');
    }
    if (!in_array($status, ['active', 'inactive', 'pending'], true)) {
        $status = 'active';
    }
    if (strlen($password) < 6) {
        fail(422, 'Password must be at least 6 characters long.');
    }

    // Check duplicate username
    $chkUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $chkUser->execute([$username]);
    if ($chkUser->fetchColumn() > 0) {
        fail(422, 'Username is already in use.');
    }

    // Check duplicate email
    if ($email !== '') {
        $chkEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $chkEmail->execute([$email]);
        if ($chkEmail->fetchColumn() > 0) {
            fail(422, 'Email address is already registered to another account.');
        }
    }

    $passHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('
        INSERT INTO users (full_name, username, email, password_hash, role, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$fullName, $username, $email ?: null, $passHash, $role, $status]);

    send_json([
        'success' => true,
        'message' => "Account for '{$fullName}' ({$role}) created successfully."
    ]);
}

// -----------------------------------------------------------------------------
// 2. Action: Update Account Information
// -----------------------------------------------------------------------------
if ($action === 'update') {
    $targetId = as_int($data['user_id'] ?? null, 1, 4294967295);
    if ($targetId === null) {
        fail(422, 'Invalid user ID.');
    }

    $stmt = $pdo->prepare('SELECT user_id, full_name, username, email, role, status FROM users WHERE user_id = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        fail(404, 'User account not found.');
    }

    $fullName = trim($data['full_name'] ?? $target['full_name']);
    $email    = trim($data['email'] ?? ($target['email'] ?? ''));
    $newRole  = strtolower(trim($data['role'] ?? $target['role']));
    $newStatus= strtolower(trim($data['status'] ?? $target['status']));

    if ($fullName === '' || strlen($fullName) < 2 || strlen($fullName) > 100) {
        fail(422, 'Full Name is required (2-100 characters).');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(422, 'Invalid email address format.');
    }
    if (!in_array($newRole, ['barista', 'manager'], true)) {
        fail(422, 'Invalid role. Choose Barista or Manager.');
    }
    if (!in_array($newStatus, ['active', 'inactive', 'pending'], true)) {
        fail(422, 'Invalid account status.');
    }

    // Security Rules for Managers:
    // "A Manager must not be able to:
    //  - Change their own role or grant themselves additional permissions.
    //  - Deactivate their own account or remove the last active Manager account."
    if ($currentRole === 'manager') {
        if ($targetId === $currentUserId && $newRole !== 'manager') {
            fail(403, 'Security Rule: Managers are not permitted to alter their own role.');
        }
        if ($targetId === $currentUserId && $newStatus !== 'active') {
            fail(403, 'Security Rule: You cannot deactivate or suspend your own Manager account.');
        }
    }

    // Protection against removing or deactivating the last active Manager:
    if ($target['role'] === 'manager' && ($newRole !== 'manager' || $newStatus !== 'active')) {
        $activeManagers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager' AND status = 'active'")->fetchColumn();
        if ($activeManagers <= 1) {
            fail(422, 'Security Rule: Cannot deactivate or change the role of the last active Manager account.');
        }
    }

    // Check duplicate email on other users
    if ($email !== '') {
        $chkEmail = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?');
        $chkEmail->execute([$email, $targetId]);
        if ($chkEmail->fetchColumn() > 0) {
            fail(422, 'Email address is already in use by another account.');
        }
    }

    // Optional password update
    $newPassword = (string) ($data['password'] ?? '');
    if ($newPassword !== '') {
        if (strlen($newPassword) < 6) {
            fail(422, 'Password must be at least 6 characters long.');
        }
        $passHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upStmt = $pdo->prepare('
            UPDATE users 
               SET full_name = ?, email = ?, role = ?, status = ?, password_hash = ?
             WHERE user_id = ?
        ');
        $upStmt->execute([$fullName, $email ?: null, $newRole, $newStatus, $passHash, $targetId]);
    } else {
        $upStmt = $pdo->prepare('
            UPDATE users 
               SET full_name = ?, email = ?, role = ?, status = ?
             WHERE user_id = ?
        ');
        $upStmt->execute([$fullName, $email ?: null, $newRole, $newStatus, $targetId]);
    }

    send_json([
        'success' => true,
        'message' => "Account for '{$target['username']}' updated successfully."
    ]);
}

// -----------------------------------------------------------------------------
// 3. Action: Toggle Status (Activate / Deactivate / Approve)
// -----------------------------------------------------------------------------
if ($action === 'toggle_status') {
    $targetId = as_int($data['user_id'] ?? null, 1, 4294967295);
    $newStatus = strtolower(trim($data['status'] ?? ''));

    if ($targetId === null) {
        fail(422, 'Invalid user ID.');
    }
    if (!in_array($newStatus, ['active', 'inactive', 'pending'], true)) {
        fail(422, 'Invalid status.');
    }

    $stmt = $pdo->prepare('SELECT user_id, username, role, status FROM users WHERE user_id = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        fail(404, 'User account not found.');
    }

    // Manager self-deactivation guard
    if ($currentRole === 'manager' && $targetId === $currentUserId && $newStatus !== 'active') {
        fail(403, 'Security Rule: You cannot deactivate your own account.');
    }

    // Last active manager protection
    if ($target['role'] === 'manager' && $newStatus !== 'active') {
        $activeManagers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager' AND status = 'active'")->fetchColumn();
        if ($activeManagers <= 1) {
            fail(422, 'Security Rule: Cannot deactivate the last active Manager account.');
        }
    }

    $upStmt = $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?');
    $upStmt->execute([$newStatus, $targetId]);

    $statusLabel = ucfirst($newStatus);
    send_json([
        'success' => true,
        'message' => "Account '{$target['username']}' status set to {$statusLabel}."
    ]);
}

// -----------------------------------------------------------------------------
// 4. Action: Reset Password
// -----------------------------------------------------------------------------
if ($action === 'reset_password') {
    $targetId = as_int($data['user_id'] ?? null, 1, 4294967295);
    $newPassword = (string) ($data['new_password'] ?? '');
    $confirmPassword = (string) ($data['confirm_password'] ?? '');

    if ($targetId === null) {
        fail(422, 'Invalid user ID.');
    }
    if (strlen($newPassword) < 6) {
        fail(422, 'New password must be at least 6 characters long.');
    }
    if ($newPassword !== $confirmPassword) {
        fail(422, 'Passwords do not match.');
    }

    $stmt = $pdo->prepare('SELECT user_id, username FROM users WHERE user_id = ?');
    $stmt->execute([$targetId]);
    $target = $stmt->fetch();
    if (!$target) {
        fail(404, 'User account not found.');
    }

    $passHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $upStmt->execute([$passHash, $targetId]);

    send_json([
        'success' => true,
        'message' => "Password for '{$target['username']}' has been reset successfully."
    ]);
}

fail(400, 'Unknown account management action.');
