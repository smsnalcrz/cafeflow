<?php
/**
 * CafeFlow - Secure CLI Account Provisioning Tool
 *
 * Usage:
 *   php tools/provision_user.php --username=admin --role=manager --password=secret123 --name="Store Administrator"
 *   php tools/provision_user.php --interactive
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access denied: CLI execution only.\n");
}

require_once __DIR__ . '/../core/db.php';
$pdo = db();

echo "========================================================\n";
echo " CafeFlow POS - Secure CLI Account Provisioning Tool\n";
echo "========================================================\n\n";

// Parse CLI options
$opts = getopt('', [
    'username::',
    'password::',
    'role::',
    'name::',
    'email::',
    'status::',
    'interactive',
    'help'
]);

if (isset($opts['help'])) {
    echo "Usage:\n";
    echo "  php tools/provision_user.php --username=<user> --password=<pass> --role=<barista|manager> --name=\"<Full Name>\" [--email=<email>] [--status=<active|inactive>]\n";
    echo "  php tools/provision_user.php --interactive\n";
    exit(0);
}

$isInteractive = isset($opts['interactive']) || empty($opts['username']);

function prompt(string $label, string $default = ''): string {
    $promptText = $default !== '' ? "{$label} [{$default}]: " : "{$label}: ";
    echo $promptText;
    $handle = fopen('php://stdin', 'r');
    $line = fgets($handle);
    fclose($handle);
    $val = trim($line);
    return $val !== '' ? $val : $default;
}

if ($isInteractive) {
    echo "Enter new account details:\n";
    $fullName = prompt("Full Name");
    $username = prompt("Username");
    $role     = strtolower(prompt("Role (barista or manager)", "barista"));
    $email    = prompt("Email Address (Optional)", "");
    $status   = strtolower(prompt("Status (active or inactive)", "active"));
    $password = prompt("Password (min 6 characters)");
} else {
    $fullName = trim($opts['name'] ?? '');
    $username = trim($opts['username'] ?? '');
    $role     = strtolower(trim($opts['role'] ?? 'barista'));
    $email    = trim($opts['email'] ?? '');
    $status   = strtolower(trim($opts['status'] ?? 'active'));
    $password = (string) ($opts['password'] ?? '');
}

// Validation
$errors = [];
if (strlen($fullName) < 2 || strlen($fullName) > 100) {
    $errors[] = "Full Name must be between 2 and 100 characters.";
}
if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
    $errors[] = "Username must be 3-50 alphanumeric characters (letters, numbers, _, ., -).";
}
if (!in_array($role, ['barista', 'manager'], true)) {
    $errors[] = "Role must be either 'barista' or 'manager'.";
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
}
if (!in_array($status, ['active', 'inactive'], true)) {
    $errors[] = "Status must be 'active' or 'inactive'.";
}
if (strlen($password) < 6) {
    $errors[] = "Password must be at least 6 characters long.";
}

if (!empty($errors)) {
    echo "\n❌ Validation Errors:\n";
    foreach ($errors as $err) {
        echo " - {$err}\n";
    }
    exit(1);
}

// Check duplicate username
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetchColumn() > 0) {
    echo "\n❌ Error: Username '{$username}' already exists in the database.\n";
    exit(1);
}

// Check duplicate email
if ($email !== '') {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() > 0) {
        echo "\n❌ Error: Email '{$email}' is already registered.\n";
        exit(1);
    }
}

// Create account
$hash = password_hash($password, PASSWORD_DEFAULT);
$ins = $pdo->prepare('
    INSERT INTO users (full_name, username, email, password_hash, role, status)
    VALUES (?, ?, ?, ?, ?, ?)
');
$ins->execute([$fullName, $username, $email ?: null, $hash, $role, $status]);
$newId = (int) $pdo->lastInsertId();

echo "\n✅ Successfully provisioned account!\n";
echo " - User ID:  {$newId}\n";
echo " - Full Name: {$fullName}\n";
echo " - Username:  {$username}\n";
echo " - Role:      {$role}\n";
echo " - Status:    {$status}\n";
echo " - Email:     " . ($email ?: '(None)') . "\n";
echo "Account is ready for authorized sign-in.\n";
