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

// Fetch or create a test user
$userStmt = $pdo->query("SELECT user_id, full_name, username, email, role, status FROM users WHERE role = 'barista' LIMIT 1");
$testUser = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$testUser) {
    echo "No test barista user found.\n";
    exit(1);
}
$userId = (int) $testUser['user_id'];

// Clean up any existing tokens for this user
$pdo->prepare("DELETE FROM user_remember_tokens WHERE user_id = ?")->execute([$userId]);
unset($_COOKIE[REMEMBER_ME_COOKIE_NAME]);
$_SESSION = [];

// --- 1. Testing Token Generation & Setting ---
echo "--- 1. Testing Token Generation & Storage ---\n";
$success = set_remember_me_token($userId, $pdo, 30 * 86400);
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
assert_test(strtotime($dbRow['expires_at']) > time() + (29 * 86400), "Expires at is set ~30 days in the future");

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

// --- 4. Testing Explicit Logout Revocation ---
echo "\n--- 4. Testing Explicit Logout Revocation ---\n";
set_remember_me_token($userId, $pdo, 30 * 86400);
$cookieToRevoke = $_COOKIE[REMEMBER_ME_COOKIE_NAME] ?? '';
[$delSelector] = explode(':', $cookieToRevoke, 2);

logout_user('user_logout');
assert_test(empty($_COOKIE[REMEMBER_ME_COOKIE_NAME]), "Logout deletes remember-me cookie");

$logoutCheck = $pdo->prepare("SELECT COUNT(*) FROM user_remember_tokens WHERE selector = ?");
$logoutCheck->execute([$delSelector]);
assert_test((int)$logoutCheck->fetchColumn() === 0, "Logout deletes token record from user_remember_tokens");

// --- 5. Testing current_user() Seamless Recovery ---
echo "\n--- 5. Testing current_user() Seamless Recovery ---\n";
set_remember_me_token($userId, $pdo, 30 * 86400);
$_SESSION = []; // simulated cold browser visit
clear_auth_cache();

$userFromCurrent = current_user();
assert_test($userFromCurrent !== null, "current_user() transparently authenticates via remember-me cookie");
assert_test((int)$userFromCurrent['user_id'] === $userId, "current_user() returned valid user record");

// Clean up
clear_remember_me_token($pdo);
$_SESSION = [];

echo "\n======================================================================\n";
echo "   REMEMBER ME SUITE COMPLETE: $pass PASSED, $fail FAILED             \n";
echo "======================================================================\n";
exit($fail > 0 ? 1 : 0);
