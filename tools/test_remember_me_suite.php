<?php
/**
 * CafeFlow - Remember Me Persistent Token Verification Suite
 */
define('CAFEFLOW_TESTING', true);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = db();
$pass = 0;
$fail = 0;

function assert_test(bool $cond, string $desc, ?string $detail = null): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo " [PASS] $desc\n";
    } else {
        $fail++;
        echo " [FAIL] $desc" . ($detail ? " ($detail)" : "") . "\n";
    }
}

echo "======================================================================\n";
echo "   CAFEFLOW REMEMBER ME PERSISTENT AUTHENTICATION TEST SUITE          \n";
echo "======================================================================\n\n";

// Create an isolated test barista user so tests never mutate real store accounts
$pdo->exec("DELETE FROM users WHERE username = 'test_remember_barista'");
$insStmt = $pdo->prepare("
    INSERT INTO users (full_name, username, email, password_hash, role, status, two_factor_enabled)
    VALUES ('Remember Test Barista', 'test_remember_barista', 'test_remember@cafeflow.com', ?, 'barista', 'active', 0)
");
$insStmt->execute([password_hash('TestPass123!', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();

// Clean up any existing tokens for this user
$pdo->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?")->execute([$userId]);
unset($_COOKIE[REMEMBER_ME_COOKIE_NAME]);
$_SESSION = [];

// --- 1. Testing Token Generation & Setting ---
echo "--- 1. Testing Token Generation & Storage ---\n";
$success = set_remember_me_token($userId, $pdo);
assert_test($success === true, "set_remember_me_token() returns true");

$cookieVal = $_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? null;
assert_test(!empty($cookieVal) && str_contains($cookieVal, ':'), "Cookie set in format selector:validator ($cookieVal)");

[$selector, $validator] = explode(':', $cookieVal, 2);
assert_test(strlen($selector) === 16, "Selector is exact 16-hex characters");
assert_test(strlen($validator) === 64, "Validator is exact 64-hex characters");

$rowStmt = $pdo->prepare("SELECT * FROM user_remember_tokens WHERE selector = ?");
$rowStmt->execute([$selector]);
$dbRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

assert_test($dbRow !== false, "Token row inserted into user_remember_tokens table");
assert_test((int)$dbRow['user_id'] === $userId, "Token belongs to target user ID");
assert_test($dbRow['hashed_validator'] === hash('sha256', $validator), "DB stores sha256 hash of validator");
assert_test(strtotime($dbRow['expires_at']) > time() + (23 * 3600), "Expires at is set ~24 hours in the future");

// --- 2. Testing Automatic Authentication & Token Rotation ---
echo "\n--- 2. Testing Automatic Authentication & Token Rotation ---\n";
// Wipe session to simulate browser restart
$_SESSION = [];
clear_auth_cache();

$authenticated = check_remember_me_token($pdo);
assert_test($authenticated !== null, "check_remember_me_token() successfully authenticates valid token");
assert_test((int)($authenticated['user_id'] ?? 0) === $userId, "Authenticated user matches remembered user ID");
assert_test(!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $userId, "Session user_id populated");

// Check token rotation (validator must change, selector stays same)
$rotatedCookie = $_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? '';
[$rotSelector, $rotValidator] = explode(':', $rotatedCookie, 2);
assert_test($rotSelector === $selector, "Selector preserved across rotation");
assert_test($rotValidator !== $validator, "Validator rotated to new secret string");

$recheckStmt = $pdo->prepare("SELECT hashed_validator FROM user_remember_tokens WHERE selector = ?");
$recheckStmt->execute([$selector]);
$newHashed = $recheckStmt->fetchColumn();
assert_test($newHashed === hash('sha256', $rotValidator), "DB updated with new hashed validator");

// --- 3. Testing Tampered Validator Rejection ---
echo "\n--- 3. Testing Tampered Validator Defense ---\n";
$_SESSION = [];
clear_auth_cache();
$_COOKIE[REMEMBER_ME_COOKIE_NAME] = $selector . ':' . str_repeat('a', 64); // forged validator

$tamperedResult = check_remember_me_token($pdo);
assert_test($tamperedResult === null, "Tampered validator rejected");

$tamperCheck = $pdo->prepare("SELECT COUNT(*) FROM user_remember_tokens WHERE selector = ?");
$tamperCheck->execute([$selector]);
assert_test((int)$tamperCheck->fetchColumn() === 0, "Compromised token automatically revoked and deleted from DB");
assert_test(empty($_COOKIE[REMEMBER_ME_COOKIE_NAME]), "Compromised cookie deleted from client");

// --- 4. Testing Device Trust Persistence Across Logout (2FA Bypass) ---
echo "\n--- 4. Testing Device Trust Persistence Across Logout ---\n";
set_remember_me_token($userId, $pdo);
$cookieToPreserve = $_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? '';
[$presSelector, $presValidator] = explode(':', $cookieToPreserve, 2);

// Verify device is recognized
assert_test(is_device_remembered($userId, $pdo) === true, "is_device_remembered() recognizes trusted terminal before logout");

// Staff logs out to lock screen
logout_user('user_logout');
assert_test(empty($_SESSION['user_id']), "Logout clears active session user_id");
assert_test(!empty($_COOKIE[REMEMBER_ME_COOKIE_NAME]), "Logout preserves trusted device cookie on POS terminal");

// Device should still be remembered for 24 hours
assert_test(is_device_remembered($userId, $pdo) === true, "is_device_remembered() retains device trust after session logout");

$tokenCheck = $pdo->prepare("SELECT COUNT(*) FROM user_remember_tokens WHERE selector = ? AND expires_at > NOW()");
$tokenCheck->execute([$presSelector]);
assert_test((int)$tokenCheck->fetchColumn() === 1, "Token remains active in user_remember_tokens table");

// Device trust must fail for a different user ID
assert_test(is_device_remembered($userId + 999, $pdo) === false, "is_device_remembered() rejects different user ID on same device");

// --- 5. Testing 2FA Bypass Flow on Remembered Device ---
echo "\n--- 5. Testing 2FA Bypass Flow on Remembered Device ---\n";
// Temporarily enable 2FA on test user
$pdo->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_secret = 'JBSWY3DPEHPK3PXP' WHERE user_id = ?")->execute([$userId]);

// Simulate login with password on trusted device: is_device_remembered() is true -> 2FA prompt bypassed
$deviceTrusted = is_device_remembered($userId, $pdo);
assert_test($deviceTrusted === true, "Login detects trusted device within 24 hours");

// Simulate unrecognized device (different or no cookie)
$savedCookie = $_COOKIE[REMEMBER_ME_COOKIE_NAME];
unset($_COOKIE[REMEMBER_ME_COOKIE_NAME]);
$unrecognizedCheck = is_device_remembered($userId, $pdo);
assert_test($unrecognizedCheck === false, "Unrecognized device correctly requires 2FA challenge");
$_COOKIE[REMEMBER_ME_COOKIE_NAME] = $savedCookie;

// --- 6. Testing Token Revocation on 2FA Reset / Manual Revoke ---
echo "\n--- 6. Testing Token Revocation on 2FA Reset / Manual Revoke ---\n";
clear_remember_me_token($pdo);
assert_test(empty($_COOKIE[REMEMBER_ME_COOKIE_NAME]), "clear_remember_me_token() removes client cookie");
assert_test(is_device_remembered($userId, $pdo) === false, "is_device_remembered() returns false after explicit revocation");

// Clean up isolated test user & remember tokens
$pdo->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$userId]);
$_SESSION = [];

echo "\n======================================================================\n";
echo "   REMEMBER ME SUITE COMPLETE: $pass PASSED, $fail FAILED             \n";
echo "======================================================================\n";
exit($fail > 0 ? 1 : 0);
