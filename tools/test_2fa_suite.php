<?php
/**
 * CafeFlow POS - Two-Factor Authentication (2FA) Automated Test Suite
 *
 * Verifies RFC 6238 TOTP engine, Base32 encoding/decoding, AES-256-GCM encryption,
 * recovery code lifecycle, two-step login, replay protection, rate limiting,
 * self-service enrollment, manager-assisted reset, RBAC enforcement, and DB schema.
 *
 * Run from terminal:
 *   php tools/test_2fa_suite.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/totp.php';

$pdo = db();

echo "======================================================================\n";
echo "   CAFEFLOW 2FA & GOOGLE AUTHENTICATOR TEST SUITE                     \n";
echo "======================================================================\n\n";

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
    $tmp = tempnam(sys_get_temp_dir(), 'cf_2fa_') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $raw = (string) shell_exec('php ' . escapeshellarg($tmp));
    @unlink($tmp);

    $pos = strpos($raw, '{');
    $json = null;
    if ($pos !== false) {
        $json = json_decode(substr($raw, $pos), true);
    }
    return [
        'raw'  => $raw,
        'json' => $json
    ];
}

// =============================================================================
// SECTION 1: TOTP Engine & RFC 6238 Tests
// =============================================================================
echo "--- 1. Testing Base32, RFC 6238 Vectors & Secret Generation ---\n";

// 1.1 Base32 RFC 4648 Vectors
$rfc4648Vectors = [
    ""       => "",
    "f"      => "MY======",
    "fo"     => "MZXQ====",
    "foo"    => "MZXW6===",
    "foob"   => "MZXW6YQ=",
    "fooba"  => "MZXW6YTB",
    "foobar" => "MZXW6YTBOI======"
];
$allRfc4648Encode = true;
$allRfc4648Decode = true;
foreach ($rfc4648Vectors as $plain => $expectedB32) {
    $encoded = totp_base32_encode($plain);
    if ($encoded !== $expectedB32) {
        $allRfc4648Encode = false;
        echo "Base32 encode failed for '$plain': expected '$expectedB32', got '$encoded'\n";
    }
    $decoded = totp_base32_decode($expectedB32);
    if ($decoded !== $plain) {
        $allRfc4648Decode = false;
        echo "Base32 decode failed for '$expectedB32': expected '$plain', got '$decoded'\n";
    }
}
assertTest('Base32: RFC 4648 standard test vectors encoding', $allRfc4648Encode);
assertTest('Base32: RFC 4648 standard test vectors decoding', $allRfc4648Decode);

// 1.2 Base32 Normalization & Edge Cases
$b32Normalize = totp_base32_decode("  mz xw 6y tb oi == == ==  ");
assertTest('Base32: Case-insensitivity & whitespace normalization', $b32Normalize === "foobar");

$invalidCaught = false;
try {
    totp_base32_decode('INVALID89!');
} catch (InvalidArgumentException) {
    $invalidCaught = true;
}
assertTest('Base32: Invalid character rejection', $invalidCaught);

// 1.3 Secret Generation
$secret = totp_generate_secret(20);
assertTest('TOTP: Secret generation length', strlen($secret) === 32, "Length: " . strlen($secret));
assertTest('TOTP: Secret character validation', (bool)preg_match('/^[A-Z2-7]+$/', $secret));

// 1.4 Setup Key Formatting
$formattedKey = totp_format_setup_key($secret);
assertTest('TOTP: Setup key chunk formatting', (bool)preg_match('/^([A-Z2-7]{4} ){7}[A-Z2-7]{4}$/', $formattedKey), "Key: $formattedKey");

// 1.5 otpauth:// URI Generation
$otpauthUrl = totp_get_otpauth_url($secret, 'barista_test', 'CafeFlow');
assertTest('TOTP: otpauth:// URI structure', str_starts_with($otpauthUrl, 'otpauth://totp/CafeFlow:barista_test?'));
assertTest('TOTP: otpauth:// URI parameters', str_contains($otpauthUrl, 'digits=6') && str_contains($otpauthUrl, 'period=30') && str_contains($otpauthUrl, 'algorithm=SHA1'));

// 1.6 RFC 6238 Test Vectors (ASCII secret: "12345678901234567890")
$rfcSecret = totp_base32_encode("12345678901234567890");
$rfcVectors = [
    59 => "287082",
    1111111109 => "081804",
    1111111111 => "050471",
    1234567890 => "005924",
    2000000000 => "279037"
];
$allVectorsPass = true;
foreach ($rfcVectors as $ts => $expected) {
    $calculated = totp_generate_code($rfcSecret, (int)$ts);
    if ($calculated !== $expected) {
        $allVectorsPass = false;
        echo "   Vector failed at TS $ts: Expected $expected, got $calculated\n";
    }
}
assertTest('RFC 6238: Official test vectors validation (SHA1, 6-digits)', $allVectorsPass);

// 1.7 Leading Zero Handling
$leadingZeroCode = totp_generate_code($rfcSecret, 1234567890);
assertTest('TOTP: Leading zero preservation (005924)', $leadingZeroCode === '005924', "Got: $leadingZeroCode");

// 1.8 Code Verification & Drift Tolerance
$now = time();
$currentCode = totp_generate_code($secret, $now);
$prevCode = totp_generate_code($secret, $now - 30);
$nextCode = totp_generate_code($secret, $now + 30);
$farPastCode = totp_generate_code($secret, $now - 90);
$farFutureCode = totp_generate_code($secret, $now + 90);

assertTest('TOTP Drift: Current time step valid', totp_verify_code($secret, $currentCode, $now, 1));
assertTest('TOTP Drift: Previous time step valid (-1 step)', totp_verify_code($secret, $prevCode, $now, 1));
assertTest('TOTP Drift: Next time step valid (+1 step)', totp_verify_code($secret, $nextCode, $now, 1));
assertTest('TOTP Drift: Outside drift window rejected (-3 steps)', !totp_verify_code($secret, $farPastCode, $now, 1));
assertTest('TOTP Drift: Outside drift window rejected (+3 steps)', !totp_verify_code($secret, $farFutureCode, $now, 1));
assertTest('TOTP: Malformed code rejected (letters/short)', !totp_verify_code($secret, 'ABC123', $now, 1));

// 1.9 TOTP Replay Rejection
$currentStep = (int) floor($now / 30);
$match1 = totp_find_matching_step($secret, $currentCode, $now, 1, null);
assertTest('TOTP Replay: First use accepted', $match1 === $currentStep);

$matchReplay = totp_find_matching_step($secret, $currentCode, $now, 1, $currentStep);
assertTest('TOTP Replay: Second use in same time step rejected', $matchReplay === null);

// 1.10 Secret Encryption & Decryption (AES-256-GCM)
$plainSecret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
$encryptedSecret = totp_encrypt_secret($plainSecret);
assertTest('Encryption: Ciphertext generated and non-empty', !empty($encryptedSecret) && $encryptedSecret !== $plainSecret);
$decryptedSecret = totp_decrypt_secret($encryptedSecret);
assertTest('Encryption: Decryption produces original plaintext', $decryptedSecret === $plainSecret);

$tampered = base64_encode(base64_decode($encryptedSecret) . 'tamper');
$tamperCaught = false;
try {
    totp_decrypt_secret($tampered);
} catch (Throwable) {
    $tamperCaught = true;
}
assertTest('Encryption: Tampered ciphertext rejected (GCM integrity check)', $tamperCaught);

echo "\n";

// =============================================================================
// SECTION 2: Recovery Codes Tests
// =============================================================================
echo "--- 2. Testing Emergency Recovery Codes Lifecycle ---\n";

$recovery = totp_generate_recovery_codes(8);
assertTest('Recovery: Generates exactly 8 codes', count($recovery['plain']) === 8);
assertTest('Recovery: Generates 8 unique codes', count(array_unique($recovery['plain'])) === 8);

$allFormatted = true;
foreach ($recovery['plain'] as $c) {
    if (!preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $c)) {
        $allFormatted = false;
    }
}
assertTest('Recovery: Format matches XXXX-XXXX', $allFormatted);

$hashesValid = true;
foreach ($recovery['plain'] as $idx => $plain) {
    if (!password_verify($plain, $recovery['hashes'][$idx])) {
        $hashesValid = false;
    }
}
assertTest('Recovery: Stored hashes verify against plaintext codes', $hashesValid);

// DB-backed Recovery Code Consumption Test
// Create temporary test user
$pdo->exec("DELETE FROM users WHERE username = 'test_2fa_runner'");
$testHash = password_hash('TestPass123!', PASSWORD_DEFAULT);
$ins = $pdo->prepare("
    INSERT INTO users (full_name, username, email, password_hash, role, status, two_factor_enabled, two_factor_recovery_codes)
    VALUES ('2FA Test Runner', 'test_2fa_runner', 'test_2fa@cafeflow.com', ?, 'barista', 'active', 1, ?)
");
$ins->execute([$testHash, json_encode($recovery['hashes'])]);
$testUserId = (int)$pdo->lastInsertId();

$firstCode = $recovery['plain'][0];
$burnSuccess = totp_verify_and_burn_recovery_code($pdo, $testUserId, $firstCode);
assertTest('Recovery: Valid recovery code consumed successfully', $burnSuccess);

$burnAgain = totp_verify_and_burn_recovery_code($pdo, $testUserId, $firstCode);
assertTest('Recovery: Consumed recovery code cannot be reused (one-time burn)', !$burnAgain);

$invalidCodeBurn = totp_verify_and_burn_recovery_code($pdo, $testUserId, 'ZZZZ-9999');
assertTest('Recovery: Invalid recovery code rejected', !$invalidCodeBurn);

// Check that remaining count in DB is 7
$remStmt = $pdo->prepare("SELECT two_factor_recovery_codes FROM users WHERE user_id = ?");
$remStmt->execute([$testUserId]);
$remainingHashes = json_decode($remStmt->fetchColumn() ?: '[]', true);
assertTest('Recovery: Remaining recovery codes count decremented to 7', count($remainingHashes) === 7);

echo "\n";

// =============================================================================
// SECTION 3: Authentication & Two-Step Login Tests
// =============================================================================
echo "--- 3. Testing Two-Step Login API & Session Security ---\n";

// 3.1 Standard User (2FA Disabled)
$pdo->exec("DELETE FROM users WHERE username = 'test_user_no2fa'");
$insNormal = $pdo->prepare("
    INSERT INTO users (full_name, username, email, password_hash, role, status, two_factor_enabled)
    VALUES ('Normal Staff', 'test_user_no2fa', 'normal@cafeflow.com', ?, 'barista', 'active', 0)
");
$insNormal->execute([$testHash]);

$resNormal = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login", "identifier" => "test_user_no2fa", "password" => "TestPass123!"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Auth (No 2FA): Logs in directly without 2FA prompt', 
    ($resNormal['json']['success'] ?? false) === true && empty($resNormal['json']['requires_2fa']));

// 3.2 2FA-Enabled User (Password Verification Only)
$testTotpSecret = totp_generate_secret();
$testEncSecret = totp_encrypt_secret($testTotpSecret);
$pdo->prepare("
    UPDATE users 
       SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_last_used_step = NULL
     WHERE user_id = ?
")->execute([$testEncSecret, $testUserId]);

$res2faStep1 = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login", "identifier" => "test_2fa_runner", "password" => "TestPass123!"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
echo json_encode(["session_user_id" => $_SESSION["user_id"] ?? null, "pending_2fa" => $_SESSION["pending_2fa"] ?? null]);
');
$resStep1Json = $res2faStep1['json'];
assertTest('Auth (2FA Enabled): Password verification triggers requires_2fa: true', 
    ($resStep1Json['requires_2fa'] ?? false) === true);

// Test Session Security: verify user_id is NOT set in session
$resSessionCheck = runSubPhp('
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time()];
echo json_encode(["is_authenticated" => is_authenticated(), "current_user" => current_user()]);
');
assertTest('Session Security: Pending 2FA session is NOT fully authenticated', 
    $resSessionCheck['json']['is_authenticated'] === false && $resSessionCheck['json']['current_user'] === null);

// 3.3 Verify 2FA with Valid TOTP Code
$validTotp = totp_generate_code($testTotpSecret);
$resTotpVerify = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time()];
$_POST = ["action" => "verify_2fa", "code" => "' . $validTotp . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Auth 2FA: Valid TOTP completes authentication', 
    ($resTotpVerify['json']['success'] ?? false) === true && ($resTotpVerify['json']['user']['user_id'] ?? 0) === $testUserId);

// 3.4 Verify Replay Protection in auth_api.php
$resReplayVerify = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time()];
$_POST = ["action" => "verify_2fa", "code" => "' . $validTotp . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Auth 2FA: Replayed TOTP code in API is rejected', 
    ($resReplayVerify['json']['success'] ?? false) === false);

// 3.5 Verify 2FA with Recovery Code
$secondCode = $recovery['plain'][1];
$resRecVerify = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time()];
$_POST = ["action" => "verify_2fa", "recovery_code" => "' . $secondCode . '"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Auth 2FA: Valid recovery code completes authentication', 
    ($resRecVerify['json']['success'] ?? false) === true);

// 3.6 Expired Pending 2FA Session Rejection (> 300 seconds)
$resExpired = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time() - 301];
$_POST = ["action" => "verify_2fa", "code" => "123456"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Auth 2FA: Expired pending session (>5 min) is rejected', 
    ($resExpired['json']['success'] ?? false) === false);

// 3.7 Cancel Pending 2FA
$resCancel = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time()];
$_POST = ["action" => "cancel_2fa"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
echo json_encode(["pending" => $_SESSION["pending_2fa"] ?? null]);
');
assertTest('Auth 2FA: cancel_2fa clears pending session state', 
    empty($resCancel['json']['pending']));

// 3.8 2FA Rate Limiting (5 failed attempts within 15 mins)
$pdo->prepare("DELETE FROM two_factor_attempts WHERE user_id = ?")->execute([$testUserId]);
for ($i = 0; $i < 5; $i++) {
    record_2fa_attempt($testUserId, '127.0.0.1', 'totp', false, $pdo);
}
assertTest('Rate Limiting: is_2fa_rate_limited returns true after 5 failures', 
    is_2fa_rate_limited($testUserId, '127.0.0.1', $pdo));

$resRateLimited = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["pending_2fa"] = ["user_id" => ' . $testUserId . ', "created_at" => time()];
$_POST = ["action" => "verify_2fa", "code" => "999999"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Rate Limiting: API blocks 2FA attempt with rate limit error', 
    str_contains($resRateLimited['json']['error'] ?? '', 'Too many failed'));

// Clear rate limits for subsequent tests
$pdo->prepare("DELETE FROM two_factor_attempts WHERE user_id = ?")->execute([$testUserId]);

echo "\n";

// =============================================================================
// SECTION 4: Enrollment API Tests (api/2fa_api.php)
// =============================================================================
echo "--- 4. Testing 2FA Enrollment, Confirmation & Lifecycle ---\n";

// 4.1 Unauthenticated Setup Request Blocked
$resUnauth = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "GET";
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
');
assertTest('Enrollment: Unauthenticated request to 2fa_api.php returns 401', 
    ($resUnauth['json']['success'] ?? false) === false);

// 4.2 setup_init requires correct current password
$resWrongPass = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = ["action" => "setup_init", "password" => "WrongPassword!", "csrf_token" => "valid_csrf_123"];
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
');
assertTest('Enrollment: setup_init with wrong password rejected', 
    ($resWrongPass['json']['success'] ?? false) === false);

// 4.3 setup_init succeeds with correct password
$resInit = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = ["action" => "setup_init", "password" => "TestPass123!", "csrf_token" => "valid_csrf_123"];
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
echo json_encode(["pending_enrollment" => $_SESSION["pending_enrollment"] ?? null]);
');
assertTest('Enrollment: setup_init returns setup key & otpauth URL', 
    !empty($resInit['json']['setup_key']) && !empty($resInit['json']['otpauth_url']));

// 4.4 setup_confirm with invalid TOTP code rejected
$resConfirmBad = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["csrf_token"] = "valid_csrf_123";
$_SESSION["pending_enrollment"] = ["user_id" => ' . $testUserId . ', "secret" => "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP", "created_at" => time()];
$_POST = ["action" => "setup_confirm", "code" => "000000", "csrf_token" => "valid_csrf_123"];
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
');
assertTest('Enrollment: setup_confirm with invalid TOTP rejected', 
    ($resConfirmBad['json']['success'] ?? false) === false);

// 4.5 setup_confirm with valid TOTP code enables 2FA and returns 8 recovery codes
$enrSecret = "JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP";
$enrValidCode = totp_generate_code($enrSecret);
$resConfirmGood = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["csrf_token"] = "valid_csrf_123";
$_SESSION["pending_enrollment"] = ["user_id" => ' . $testUserId . ', "secret" => "' . $enrSecret . '", "created_at" => time()];
$_POST = ["action" => "setup_confirm", "code" => "' . $enrValidCode . '", "csrf_token" => "valid_csrf_123"];
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
');
assertTest('Enrollment: setup_confirm activates 2FA and returns 8 recovery codes', 
    ($resConfirmGood['json']['success'] ?? false) === true && count($resConfirmGood['json']['recovery_codes'] ?? []) === 8);

// Verify DB state after enrollment
$chkStmt = $pdo->prepare("SELECT two_factor_enabled, two_factor_confirmed_at, two_factor_secret FROM users WHERE user_id = ?");
$chkStmt->execute([$testUserId]);
$dbUser = $chkStmt->fetch();
assertTest('Enrollment DB: two_factor_enabled = 1', (int)$dbUser['two_factor_enabled'] === 1);
assertTest('Enrollment DB: two_factor_confirmed_at is set', !empty($dbUser['two_factor_confirmed_at']));
assertTest('Enrollment DB: two_factor_secret is stored encrypted', totp_decrypt_secret($dbUser['two_factor_secret']) === $enrSecret);

// 4.6 Regenerate Recovery Codes
$pdo->prepare("UPDATE users SET two_factor_last_used_step = NULL WHERE user_id = ?")->execute([$testUserId]);
$current2faCode = totp_generate_code($enrSecret);
$resRegen = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = [
    "action" => "regenerate_recovery_codes",
    "password" => "TestPass123!",
    "code" => "' . $current2faCode . '",
    "csrf_token" => "valid_csrf_123"
];
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
');
assertTest('Recovery Codes: Regenerate returns 8 new codes with password & TOTP', 
    ($resRegen['json']['success'] ?? false) === true && count($resRegen['json']['recovery_codes'] ?? []) === 8);

// 4.7 Disable 2FA
$pdo->prepare("UPDATE users SET two_factor_last_used_step = NULL WHERE user_id = ?")->execute([$testUserId]);
$resDisable = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = [
    "action" => "disable_2fa",
    "password" => "TestPass123!",
    "code" => "' . $current2faCode . '",
    "csrf_token" => "valid_csrf_123"
];
include "' . addslashes(__DIR__ . '/../api/2fa_api.php') . '";
');
assertTest('Disable 2FA: Successfully disables 2FA with password & TOTP code', 
    ($resDisable['json']['success'] ?? false) === true);

$chkDis = $pdo->prepare("SELECT two_factor_enabled, two_factor_secret FROM users WHERE user_id = ?");
$chkDis->execute([$testUserId]);
$disRow = $chkDis->fetch();
assertTest('Disable 2FA DB: two_factor_enabled = 0 and secret is NULL', 
    (int)$disRow['two_factor_enabled'] === 0 && empty($disRow['two_factor_secret']));

echo "\n";

// =============================================================================
// SECTION 5: Manager Administrative Reset & RBAC Tests
// =============================================================================
echo "--- 5. Testing Manager Administrative Reset & Authorization ---\n";

// 5.1 Barista cannot perform administrative 2FA reset (RBAC check)
$resBaristaReset = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $testUserId . ';
$_SESSION["user_role"] = "barista";
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = [
    "action" => "reset_2fa",
    "user_id" => ' . $testUserId . ',
    "csrf_token" => "valid_csrf_123"
];
include "' . addslashes(__DIR__ . '/../api/accounts_api.php') . '";
');
assertTest('RBAC: Barista role forbidden from resetting 2FA (HTTP 403)', 
    str_contains($resBaristaReset['raw'], 'FORBIDDEN') || ($resBaristaReset['json']['success'] ?? false) === false);

// 5.2 Manager GET accounts list includes 2FA status
// Fetch an existing manager user ID
$mgrStmt = $pdo->query("SELECT user_id, password_hash FROM users WHERE role = 'manager' AND status = 'active' LIMIT 1");
$mgrUser = $mgrStmt->fetch();
$mgrId = (int)$mgrUser['user_id'];
$mgrOrigHash = $mgrUser['password_hash'];
// Set known password for manager for test
$mgrTestPass = 'ManagerPass123!';
$pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([password_hash($mgrTestPass, PASSWORD_DEFAULT), $mgrId]);

$resAccountsList = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "GET";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $mgrId . ';
$_SESSION["user_role"] = "manager";
include "' . addslashes(__DIR__ . '/../api/accounts_api.php') . '";
');
$has2faFields = false;
foreach ($resAccountsList['json']['items'] ?? [] as $item) {
    if (isset($item['two_factor_status'])) {
        $has2faFields = true;
        break;
    }
}
assertTest('Manager API: GET accounts_api.php includes two_factor_status', $has2faFields);

// 5.3 Manager reset_2fa without manager password confirmation fails
$resMgrNoPass = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $mgrId . ';
$_SESSION["user_role"] = "manager";
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = [
    "action" => "reset_2fa",
    "user_id" => ' . $testUserId . ',
    "manager_password" => "WrongManagerPass",
    "csrf_token" => "valid_csrf_123"
];
include "' . addslashes(__DIR__ . '/../api/accounts_api.php') . '";
');
assertTest('Manager Reset: Wrong manager password rejected', 
    ($resMgrNoPass['json']['success'] ?? false) === false);

// Re-enable 2FA on target user before testing reset
$pdo->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_secret = 'dummy_secret' WHERE user_id = ?")->execute([$testUserId]);

// 5.4 Manager reset_2fa with valid manager password succeeds
$resMgrReset = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
require_once "' . addslashes(__DIR__ . '/../core/auth.php') . '";
init_session();
$_SESSION["user_id"] = ' . $mgrId . ';
$_SESSION["user_role"] = "manager";
$_SESSION["csrf_token"] = "valid_csrf_123";
$_POST = [
    "action" => "reset_2fa",
    "user_id" => ' . $testUserId . ',
    "manager_password" => "' . $mgrTestPass . '",
    "csrf_token" => "valid_csrf_123"
];
include "' . addslashes(__DIR__ . '/../api/accounts_api.php') . '";
');
assertTest('Manager Reset: reset_2fa succeeds with manager password re-auth', 
    ($resMgrReset['json']['success'] ?? false) === true);

// Verify target user state after reset
$chkReset = $pdo->prepare("SELECT two_factor_enabled, two_factor_secret, two_factor_reenroll_required FROM users WHERE user_id = ?");
$chkReset->execute([$testUserId]);
$resetRow = $chkReset->fetch();
assertTest('Manager Reset DB: two_factor_enabled = 0 and secret is cleared', 
    (int)$resetRow['two_factor_enabled'] === 0 && empty($resetRow['two_factor_secret']));
assertTest('Manager Reset DB: two_factor_reenroll_required = 1', 
    (int)$resetRow['two_factor_reenroll_required'] === 1);

// Verify Security Audit Log
$auditStmt = $pdo->prepare("SELECT * FROM security_audit_log WHERE action = 'admin_reset_2fa' AND target_user_id = ? ORDER BY audit_id DESC LIMIT 1");
$auditStmt->execute([$testUserId]);
$auditRow = $auditStmt->fetch();
assertTest('Audit Log: admin_reset_2fa logged with actor ID and target ID', 
    !empty($auditRow) && (int)$auditRow['actor_user_id'] === $mgrId);

// 5.5 Forced Re-enrollment Login Flow
$resReenrollLogin = runSubPhp('
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["REMOTE_ADDR"] = "127.0.0.1";
$_POST = ["action" => "login", "identifier" => "test_2fa_runner", "password" => "TestPass123!"];
include "' . addslashes(__DIR__ . '/../api/auth_api.php') . '";
');
assertTest('Re-enrollment: Login prompts reenroll_required: true', 
    ($resReenrollLogin['json']['requires_2fa'] ?? false) === true && ($resReenrollLogin['json']['reenroll_required'] ?? false) === true);

echo "\n";

// =============================================================================
// SECTION 6: Database Schema & Migration Verification
// =============================================================================
echo "--- 6. Testing Database Schema & Migration Idempotency ---\n";

$cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$expectedCols = [
    'two_factor_secret',
    'two_factor_enabled',
    'two_factor_confirmed_at',
    'two_factor_recovery_codes',
    'two_factor_last_used_step',
    'two_factor_reenroll_required'
];
$allColsExist = true;
foreach ($expectedCols as $ec) {
    if (!in_array($ec, $cols, true)) {
        $allColsExist = false;
        echo "   Missing column on users: $ec\n";
    }
}
assertTest('Schema: All 6 required 2FA columns exist in users table', $allColsExist);

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
assertTest('Schema: two_factor_attempts table exists', in_array('two_factor_attempts', $tables, true));
assertTest('Schema: security_audit_log table exists', in_array('security_audit_log', $tables, true));

// Test ensure_schema idempotency
$migrationError = false;
try {
    ensure_schema($pdo);
} catch (Throwable $e) {
    $migrationError = true;
}
assertTest('Schema Migration: ensure_schema() runs safely and idempotently without errors', !$migrationError);

// Clean up test runner users and restore original manager password hash
$pdo->exec("DELETE FROM users WHERE username IN ('test_2fa_runner', 'test_user_no2fa')");
if (!empty($mgrOrigHash) && !empty($mgrId)) {
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$mgrOrigHash, $mgrId]);
}

echo "\n======================================================================\n";
echo "   TEST RUN COMPLETE: $testsPassed PASSED, $testsFailed FAILED         \n";
echo "======================================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
