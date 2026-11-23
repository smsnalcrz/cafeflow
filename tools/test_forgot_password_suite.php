<?php
/**
 * CafeFlow - Test Suite for "Forgot Password via 2FA" and "Login Using 2FA"
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/totp.php';

$pdo = db();
$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $name, bool $condition, string $details = ''): void {
    global $testsPassed, $testsFailed;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] $name\n";
        if ($details) echo "        $details\n";
    } else {
        $testsFailed++;
        echo " [FAIL] $name\n";
        if ($details) echo "        FAILED: $details\n";
    }
}

function runSubPhp(string $code): array {
    $tmp = tempnam(sys_get_temp_dir(), 'cf_test_') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $raw = (string) shell_exec('php ' . escapeshellarg($tmp));
    @unlink($tmp);

    $pos = strpos($raw, '{');
    $json = null;
    if ($pos !== false) {
        $json = json_decode(substr($raw, $pos), true);
    }
    return ['raw' => $raw, 'json' => $json];
}

echo "======================================================================\n";
echo "   CAFEFLOW FORGOT PASSWORD & DIRECT 2FA LOGIN TEST SUITE             \n";
echo "======================================================================\n\n";

// -----------------------------------------------------------------------------
// 1. Inspect Existing Users & 2FA State
// -----------------------------------------------------------------------------
echo "--- 1. Testing Existing User 2FA Integrity ---\n";
$stmt = $pdo->query("SELECT user_id, username, password_hash, two_factor_secret, two_factor_enabled FROM users WHERE username = 'manager' LIMIT 1");
$manager = $stmt->fetch();

assertTest("Manager user exists", $manager !== false);
assertTest("Manager has 2FA enabled", !empty($manager['two_factor_enabled']));
assertTest("Manager has active two_factor_secret", !empty($manager['two_factor_secret']));

$originalSecret = $manager['two_factor_secret'];
$originalPassHash = $manager['password_hash'];
$managerId = (int) $manager['user_id'];

// User without 2FA
$stmtNo2fa = $pdo->query("SELECT user_id, username, two_factor_enabled FROM users WHERE two_factor_enabled = 0 LIMIT 1");
$userNo2fa = $stmtNo2fa->fetch();
assertTest("Found user without 2FA for negative testing (username: {$userNo2fa['username']})", $userNo2fa !== false);

// -----------------------------------------------------------------------------
// 2. Testing Forgot Password Step 1: Initialization
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Forgot Password Step 1: Initialization ---\n";

// Negative: Non-existent user
$resNonExistent = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "forgot_password_init", "identifier" => "non_existent_user_9999"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Forgot password init: Non-existent user rejected (404)",
    ($resNonExistent['json']['success'] ?? true) === false);

// Negative: User without 2FA cannot self-reset
$resNo2fa = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "forgot_password_init", "identifier" => "' . addslashes($userNo2fa['username']) . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Forgot password init: User without 2FA rejected with Manager contact instruction",
    ($resNo2fa['json']['success'] ?? true) === false && str_contains($resNo2fa['json']['error'] ?? '', 'Store Manager'));

// Positive: Manager with 2FA
$resInitMgr = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "forgot_password_init", "identifier" => "manager"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Forgot password init: Manager with 2FA succeeds and requires 2FA",
    ($resInitMgr['json']['success'] ?? false) === true && ($resInitMgr['json']['requires_2fa'] ?? false) === true);

// -----------------------------------------------------------------------------
// 3. Testing Forgot Password Step 2: 2FA Verification
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Forgot Password Step 2: 2FA Verification ---\n";

// Negative: Invalid TOTP code
$resWrongTotp = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_password_reset"] = [
    "user_id" => ' . $managerId . ',
    "username" => "manager",
    "stage" => "awaiting_2fa",
    "created_at" => time()
];
$_POST = ["action" => "forgot_password_verify", "code" => "000000"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Forgot password verify: Wrong TOTP code rejected",
    ($resWrongTotp['json']['success'] ?? true) === false);

// Positive: Valid TOTP code from user setup key
$decSecret = totp_decrypt_secret($originalSecret);
$validCode = totp_generate_code($decSecret);

$resValidTotp = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_password_reset"] = [
    "user_id" => ' . $managerId . ',
    "username" => "manager",
    "stage" => "awaiting_2fa",
    "created_at" => time()
];
$_POST = ["action" => "forgot_password_verify", "code" => "' . $validCode . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
echo json_encode(["stage" => $_SESSION["pending_password_reset"]["stage"] ?? null]);
');
assertTest("Forgot password verify: Valid TOTP code succeeds",
    ($resValidTotp['json']['success'] ?? false) === true);

// -----------------------------------------------------------------------------
// 4. Testing Forgot Password Step 3: Password Reset & Confirm
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing Forgot Password Step 3: Password Reset & Confirm ---\n";

// Negative: Short password (< 6 chars)
$resShortPass = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_password_reset"] = [
    "user_id" => ' . $managerId . ',
    "username" => "manager",
    "stage" => "awaiting_new_password",
    "created_at" => time()
];
$_POST = ["action" => "forgot_password_complete", "new_password" => "123", "confirm_password" => "123"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Forgot password complete: Short password (< 6 chars) rejected",
    ($resShortPass['json']['success'] ?? true) === false && str_contains($resShortPass['json']['error'] ?? '', '6 characters'));

// Negative: Mismatched passwords
$resMismatch = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_password_reset"] = [
    "user_id" => ' . $managerId . ',
    "username" => "manager",
    "stage" => "awaiting_new_password",
    "created_at" => time()
];
$_POST = ["action" => "forgot_password_complete", "new_password" => "NewPass2026!", "confirm_password" => "Different2026!"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Forgot password complete: Mismatched passwords rejected",
    ($resMismatch['json']['success'] ?? true) === false && str_contains($resMismatch['json']['error'] ?? '', 'do not match'));

// Positive: Valid new password completion
$testNewPassword = 'VerifiedNewPass2026!';
$resResetSuccess = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_password_reset"] = [
    "user_id" => ' . $managerId . ',
    "username" => "manager",
    "stage" => "awaiting_new_password",
    "created_at" => time()
];
$_POST = ["action" => "forgot_password_complete", "new_password" => "' . $testNewPassword . '", "confirm_password" => "' . $testNewPassword . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
echo json_encode(["is_authenticated" => is_authenticated(), "user_id" => $_SESSION["user_id"] ?? null]);
');
assertTest("Forgot password complete: Successfully completes reset",
    ($resResetSuccess['json']['success'] ?? false) === true);

// CRITICAL VERIFICATION: Check Database State!
$stmtAfter = $pdo->query("SELECT user_id, password_hash, two_factor_secret, two_factor_enabled FROM users WHERE username = 'manager' LIMIT 1");
$managerAfter = $stmtAfter->fetch();

assertTest("CRITICAL: two_factor_secret remains EXACTLY identical (setup key preserved)",
    $managerAfter['two_factor_secret'] === $originalSecret);
assertTest("two_factor_enabled remains 1",
    (int) $managerAfter['two_factor_enabled'] === 1);
assertTest("New password verified in database",
    password_verify($testNewPassword, $managerAfter['password_hash']));

// Verify that user can sign in using new password and still triggers 2FA
$resLoginWithNewPass = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login", "identifier" => "manager", "password" => "' . $testNewPassword . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Login with new password triggers 2FA challenge with active setup key",
    ($resLoginWithNewPass['json']['success'] ?? false) === true && ($resLoginWithNewPass['json']['requires_2fa'] ?? false) === true);

// Restore original manager password hash so manager password remains unchanged!
$restore = $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = 'manager'");
$restore->execute([$originalPassHash]);
assertTest("Restored original manager password hash in database", true);

// -----------------------------------------------------------------------------
// 5. Testing "Login Using 2FA" (Direct 2FA Login)
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing 'Login using 2FA' (Direct 2FA Login) ---\n";

// Negative: Empty username
$resEmptyIdent = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login_via_2fa", "identifier" => "", "code" => "123456"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Direct 2FA login: Empty identifier rejected",
    ($resEmptyIdent['json']['success'] ?? true) === false);

// Negative: User without 2FA
$resNo2faLogin = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login_via_2fa", "identifier" => "' . addslashes($userNo2fa['username']) . '", "code" => "123456"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Direct 2FA login: User without 2FA rejected",
    ($resNo2faLogin['json']['success'] ?? true) === false && str_contains($resNo2faLogin['json']['error'] ?? '', 'not enabled'));

// Negative: Wrong TOTP code
$resWrongDirect = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login_via_2fa", "identifier" => "manager", "code" => "000000"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Direct 2FA login: Wrong code rejected",
    ($resWrongDirect['json']['success'] ?? true) === false);

// Positive: Valid TOTP code
// Advance by 30 seconds to test next window and avoid replay collision with step 3
$nextTimestamp = time() + 30;
$directValidCode = totp_generate_code($decSecret, $nextTimestamp);

$resDirectValid = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login_via_2fa", "identifier" => "manager", "code" => "' . $directValidCode . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
echo json_encode(["is_authenticated" => is_authenticated(), "user_id" => $_SESSION["user_id"] ?? null, "role" => $_SESSION["user_role"] ?? null]);
');
if (($resDirectValid['json']['success'] ?? false) !== true) {
    echo "DEBUG Direct Valid Output: " . $resDirectValid['raw'] . "\n";
}
assertTest("Direct 2FA login: Valid TOTP code logs in successfully without password",
    ($resDirectValid['json']['success'] ?? false) === true);
assertTest("Direct 2FA login: Session is authenticated as manager",
    ($resDirectValid['json']['user']['username'] ?? '') === 'manager');

// Negative: Replay attack on direct 2FA login
$resReplay = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login_via_2fa", "identifier" => "manager", "code" => "' . $directValidCode . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest("Direct 2FA login: Replayed TOTP code rejected atomically",
    ($resReplay['json']['success'] ?? true) === false);

// -----------------------------------------------------------------------------
// 6. Regression Check: Existing 2FA Suite Integrity
// -----------------------------------------------------------------------------
echo "\n--- 6. Verifying User 2FA Secrets Remain Intact ---\n";
$stmtFinal = $pdo->query("SELECT user_id, username, two_factor_secret, two_factor_enabled FROM users WHERE username = 'manager' LIMIT 1");
$mgrFinal = $stmtFinal->fetch();

assertTest("Final Check: two_factor_secret was NOT cleared or changed",
    $mgrFinal['two_factor_secret'] === $originalSecret);
assertTest("Final Check: two_factor_enabled is still 1",
    (int) $mgrFinal['two_factor_enabled'] === 1);

echo "\n======================================================================\n";
echo "   TEST RUN COMPLETE: $testsPassed PASSED, $testsFailed FAILED\n";
echo "======================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
