<?php
/**
 * CafeFlow - Two-Factor Authentication API
 *
 * Handles self-service 2FA enrollment, TOTP code confirmation, disabling 2FA,
 * recovery code regeneration, and status checks for authenticated users.
 * Supports restricted re-enrollment flow for users with pending admin resets.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/totp.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

init_session();
$currentUser = current_user();
$pending2fa = get_pending_2fa();

// Helper to get active user ID in normal session or re-enrollment mode
$activeUserId = null;
$isReenrolling = false;

if ($currentUser) {
    $activeUserId = (int) $currentUser['user_id'];
} elseif ($pending2fa && ($pending2fa['mode'] ?? '') === 'reenroll') {
    $activeUserId = (int) $pending2fa['user_id'];
    $isReenrolling = true;
}

if ($activeUserId === null) {
    fail(401, 'Authentication required. Please sign in.');
}

// -----------------------------------------------------------------------------
// GET: Status Check
// -----------------------------------------------------------------------------
if ($method === 'GET') {
    $stmt = $pdo->prepare('
        SELECT user_id, username, full_name, role, two_factor_enabled, two_factor_confirmed_at, 
               two_factor_recovery_codes, two_factor_reenroll_required
          FROM users 
         WHERE user_id = ?
         LIMIT 1
    ');
    $stmt->execute([$activeUserId]);
    $u = $stmt->fetch();

    if (!$u) {
        fail(404, 'User account not found.');
    }

    $codes = json_decode($u['two_factor_recovery_codes'] ?? '[]', true);
    $remainingCodes = is_array($codes) ? count($codes) : 0;

    send_json([
        'success'                  => true,
        'two_factor_enabled'       => (bool) $u['two_factor_enabled'],
        'confirmed_at'             => $u['two_factor_confirmed_at'],
        'remaining_recovery_codes' => $remainingCodes,
        'reenroll_required'        => (bool) $u['two_factor_reenroll_required'],
        'is_reenrolling'           => $isReenrolling,
        'csrf_token'               => get_csrf_token()
    ]);
}

// -----------------------------------------------------------------------------
// POST: Enrollment, Confirmation, Disabling, Recovery Code Management
// -----------------------------------------------------------------------------
require_method('POST');

$isForm = !empty($_POST) || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded');
$data = $isForm ? $_POST : read_json();

// CSRF Validation
$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrfToken)) {
    fail(403, 'CSRF security token invalid or missing. Please refresh and try again.');
}

$action = trim($data['action'] ?? '');

// Fetch fresh user record
$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ? LIMIT 1');
$stmt->execute([$activeUserId]);
$targetUser = $stmt->fetch();
if (!$targetUser || $targetUser['status'] !== 'active') {
    fail(403, 'Account is inactive or does not exist.');
}

// -----------------------------------------------------------------------------
// 1. Action: setup_init (Initialize 2FA Enrollment)
// -----------------------------------------------------------------------------
if ($action === 'setup_init') {
    // Require current password for security verification
    $password = (string) ($data['password'] ?? $data['current_password'] ?? '');
    if ($password === '') {
        fail(422, 'Current password is required to configure Two-Factor Authentication.');
    }

    if (!password_verify($password, $targetUser['password_hash'])) {
        fail(401, 'Incorrect password. Verification failed.');
    }

    // Generate fresh Base32 TOTP secret
    $secret = totp_generate_secret();
    $setupKey = totp_format_setup_key($secret);
    $otpauthUrl = totp_get_otpauth_url($secret, $targetUser['username']);

    // Store unconfirmed pending enrollment in server session (valid for 10 minutes)
    $_SESSION['pending_enrollment'] = [
        'user_id'    => $activeUserId,
        'secret'     => $secret,
        'created_at' => time()
    ];

    send_json([
        'success'      => true,
        'setup_key'    => $setupKey,
        'account_name' => 'CafeFlow: ' . $targetUser['username'],
        'otpauth_url'  => $otpauthUrl,
        'message'      => 'Add this account to Google Authenticator.'
    ]);
}

// -----------------------------------------------------------------------------
// 2. Action: setup_confirm (Verify TOTP Code and Activate 2FA)
// -----------------------------------------------------------------------------
if ($action === 'setup_confirm') {
    $enrollment = $_SESSION['pending_enrollment'] ?? null;
    if (
        !$enrollment ||
        !is_array($enrollment) ||
        (int) ($enrollment['user_id'] ?? 0) !== $activeUserId ||
        empty($enrollment['secret']) ||
        (time() - (int) ($enrollment['created_at'] ?? 0)) > 600
    ) {
        unset($_SESSION['pending_enrollment']);
        fail(400, '2FA setup session has expired or is invalid. Please start enrollment again.');
    }

    $code = trim($data['code'] ?? '');
    if (!preg_match('/^\d{6}$/', $code)) {
        fail(422, 'Please enter a valid 6-digit authentication code.');
    }

    $secret = (string) $enrollment['secret'];
    $matchedStep = totp_find_matching_step($secret, $code, null, 1, null);

    if ($matchedStep === null) {
        fail(401, 'Invalid authentication code. Please check your authenticator app and try again.');
    }

    // Code is valid! Encrypt secret and generate recovery codes
    $encryptedSecret = totp_encrypt_secret($secret);
    $recovery = totp_generate_recovery_codes(8);
    $hashesJson = json_encode($recovery['hashes']);

    $upStmt = $pdo->prepare('
        UPDATE users
           SET two_factor_secret = ?,
               two_factor_enabled = 1,
               two_factor_confirmed_at = NOW(),
               two_factor_recovery_codes = ?,
               two_factor_last_used_step = ?,
               two_factor_reenroll_required = 0
         WHERE user_id = ?
    ');
    $upStmt->execute([$encryptedSecret, $hashesJson, $matchedStep, $activeUserId]);

    log_security_audit($pdo, $activeUserId, $activeUserId, '2fa_enabled', [
        'mode' => $isReenrolling ? 'reenrollment' : 'self_service'
    ]);

    unset($_SESSION['pending_enrollment']);

    // If user was completing forced re-enrollment, finalize their session
    if ($isReenrolling) {
        clear_pending_2fa();
        login_user($targetUser);
    }

    send_json([
        'success'        => true,
        'recovery_codes' => $recovery['plain'],
        'message'        => 'Two-Factor Authentication is now active on your account.',
        'redirect'       => $isReenrolling ? 'index.php' : null
    ]);
}

// -----------------------------------------------------------------------------
// 3. Action: setup_cancel (Cancel Unconfirmed Enrollment)
// -----------------------------------------------------------------------------
if ($action === 'setup_cancel') {
    unset($_SESSION['pending_enrollment']);
    send_json([
        'success' => true,
        'message' => '2FA setup cancelled.'
    ]);
}

// -----------------------------------------------------------------------------
// 4. Action: disable_2fa (Disable 2FA with Password & TOTP Code Verification)
// -----------------------------------------------------------------------------
if ($action === 'disable_2fa') {
    if ($isReenrolling) {
        fail(403, 'Cannot disable 2FA while re-enrollment is required.');
    }

    if (empty($targetUser['two_factor_enabled'])) {
        fail(400, 'Two-Factor Authentication is not currently enabled on this account.');
    }

    $password = (string) ($data['password'] ?? $data['current_password'] ?? '');
    $code = trim($data['code'] ?? '');

    if ($password === '') {
        fail(422, 'Current password is required to disable Two-Factor Authentication.');
    }
    if (!password_verify($password, $targetUser['password_hash'])) {
        fail(401, 'Incorrect password. Verification failed.');
    }

    if (!preg_match('/^\d{6}$/', $code)) {
        fail(422, 'A valid 6-digit authentication code is required to disable 2FA.');
    }

    try {
        $secret = totp_decrypt_secret($targetUser['two_factor_secret'] ?? '');
    } catch (Throwable) {
        fail(500, 'Security error: Unable to read 2FA configuration.');
    }

    $lastStep = $targetUser['two_factor_last_used_step'] !== null ? (int) $targetUser['two_factor_last_used_step'] : null;
    $matchedStep = totp_find_matching_step($secret, $code, null, 1, $lastStep);

    if ($matchedStep === null) {
        fail(401, 'Invalid authentication code.');
    }

    // Reset 2FA fields transactionally
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('
            UPDATE users 
               SET two_factor_secret = NULL,
                   two_factor_enabled = 0,
                   two_factor_confirmed_at = NULL,
                   two_factor_recovery_codes = NULL,
                   two_factor_last_used_step = NULL,
                   two_factor_reenroll_required = 0
             WHERE user_id = ?
        ');
        $up->execute([$activeUserId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail(500, 'Failed to disable Two-Factor Authentication.');
    }

    log_security_audit($pdo, $activeUserId, $activeUserId, '2fa_disabled');
    unset($_SESSION['pending_enrollment']);

    send_json([
        'success' => true,
        'message' => 'Two-Factor Authentication has been successfully disabled.'
    ]);
}

// -----------------------------------------------------------------------------
// 5. Action: regenerate_recovery_codes
// -----------------------------------------------------------------------------
if ($action === 'regenerate_recovery_codes') {
    if ($isReenrolling) {
        fail(403, 'Cannot regenerate recovery codes while re-enrollment is required.');
    }

    if (empty($targetUser['two_factor_enabled'])) {
        fail(400, 'Two-Factor Authentication is not enabled on this account.');
    }

    $password = (string) ($data['password'] ?? $data['current_password'] ?? '');
    $code = trim($data['code'] ?? '');

    if ($password === '') {
        fail(422, 'Current password is required to regenerate recovery codes.');
    }
    if (!password_verify($password, $targetUser['password_hash'])) {
        fail(401, 'Incorrect password. Verification failed.');
    }

    if (!preg_match('/^\d{6}$/', $code)) {
        fail(422, 'A valid 6-digit authentication code is required.');
    }

    try {
        $secret = totp_decrypt_secret($targetUser['two_factor_secret'] ?? '');
    } catch (Throwable) {
        fail(500, 'Security error: Unable to read 2FA configuration.');
    }

    $lastStep = $targetUser['two_factor_last_used_step'] !== null ? (int) $targetUser['two_factor_last_used_step'] : null;
    $matchedStep = totp_find_matching_step($secret, $code, null, 1, $lastStep);

    if ($matchedStep === null) {
        fail(401, 'Invalid authentication code.');
    }

    // Atomically burn the step and regenerate 8 new codes
    $recovery = totp_generate_recovery_codes(8);
    $hashesJson = json_encode($recovery['hashes']);

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('
            UPDATE users 
               SET two_factor_recovery_codes = ?,
                   two_factor_last_used_step = ?
             WHERE user_id = ?
        ');
        $up->execute([$hashesJson, $matchedStep, $activeUserId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail(500, 'Failed to update recovery codes.');
    }

    log_security_audit($pdo, $activeUserId, $activeUserId, '2fa_regenerate_recovery_codes');

    send_json([
        'success'        => true,
        'recovery_codes' => $recovery['plain'],
        'message'        => 'New emergency recovery codes generated successfully. Previous codes are now invalid.'
    ]);
}

fail(400, 'Unknown 2FA action.');
