<?php
/**
 * CafeFlow - Two-Factor Authentication Engine (RFC 6238 TOTP)
 *
 * Provides Google Authenticator-compatible Time-based One-Time Password (TOTP)
 * generation and verification, Base32 encoding/decoding, AES-256-GCM secret
 * encryption, and emergency recovery code lifecycle management.
 *
 * Strictly vanilla PHP 8.2 using built-in OpenSSL and cryptographic primitives.
 */

declare(strict_types=1);

if (!defined('TOTP_PERIOD')) {
    define('TOTP_PERIOD', 30);
}

if (!defined('TOTP_DIGITS')) {
    define('TOTP_DIGITS', 6);
}

if (!defined('TOTP_ALGORITHM')) {
    define('TOTP_ALGORITHM', 'sha1');
}

/**
 * Standard RFC 4648 Base32 alphabet.
 */
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

// -----------------------------------------------------------------------------
// 1. Base32 Encoding and Decoding
// -----------------------------------------------------------------------------

/**
 * Encodes binary data to standard Base32 string (RFC 4648).
 */
function totp_base32_encode(string $data, bool $pad = true): string
{
    if ($data === '') {
        return '';
    }

    $chars = BASE32_ALPHABET;
    $binary = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $b32 = '';
    $chunks = str_split($binary, 5);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $b32 .= $chars[bindec($chunk)];
    }

    if ($pad) {
        $rem = strlen($b32) % 8;
        if ($rem !== 0) {
            $b32 .= str_repeat('=', 8 - $rem);
        }
    }

    return $b32;
}

/**
 * Decodes a Base32 string back to binary data.
 * Handles case-insensitivity, whitespace, and padding.
 *
 * @throws InvalidArgumentException on invalid characters.
 */
function totp_base32_decode(string $b32): string
{
    $clean = strtoupper(trim($b32));
    $clean = preg_replace('/\s+/', '', $clean) ?? '';
    $clean = rtrim($clean, '=');

    if ($clean === '') {
        return '';
    }

    $alphabet = BASE32_ALPHABET;
    $binary = '';
    $len = strlen($clean);

    for ($i = 0; $i < $len; $i++) {
        $char = $clean[$i];
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            throw new InvalidArgumentException("Invalid Base32 character: '{$char}'");
        }
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $data = '';
    $chunks = str_split($binary, 8);
    foreach ($chunks as $chunk) {
        if (strlen($chunk) === 8) {
            $data .= chr((int) bindec($chunk));
        }
    }

    return $data;
}

// -----------------------------------------------------------------------------
// 2. Secret Protection (AES-256-GCM)
// -----------------------------------------------------------------------------

/**
 * Retrieves or initializes the 2FA master encryption key.
 * Prioritizes the CAFEFLOW_2FA_KEY environment variable.
 * Fallbacks to a protected key file in the core directory.
 */
function totp_get_master_key(): string
{
    $envKey = getenv('CAFEFLOW_2FA_KEY') ?: ($_ENV['CAFEFLOW_2FA_KEY'] ?? null);
    if (!empty($envKey) && is_string($envKey)) {
        return hash('sha256', $envKey, true);
    }

    $keyFile = __DIR__ . '/.2fa_key';
    if (file_exists($keyFile)) {
        $keyData = @file_get_contents($keyFile);
        if ($keyData !== false && strlen($keyData) >= 32) {
            return substr($keyData, 0, 32);
        }
    }

    // Auto-generate a secure 32-byte key if no key is present
    $newKey = random_bytes(32);
    @file_put_contents($keyFile, $newKey, LOCK_EX);
    if (function_exists('chmod')) {
        @chmod($keyFile, 0600);
    }
    return $newKey;
}

/**
 * Encrypts a plaintext TOTP secret using AES-256-GCM.
 * Stored format: base64(IV[12] . Tag[16] . Ciphertext)
 */
function totp_encrypt_secret(string $plainSecret): string
{
    if ($plainSecret === '') {
        return '';
    }

    $key = totp_get_master_key();
    $iv = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plainSecret,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption of 2FA secret failed.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

/**
 * Decrypts an encrypted TOTP secret using AES-256-GCM.
 */
function totp_decrypt_secret(string $encryptedPayload): string
{
    if ($encryptedPayload === '') {
        return '';
    }

    $raw = base64_decode($encryptedPayload, true);
    if ($raw === false || strlen($raw) < 28) {
        // If not base64 or shorter than IV(12) + Tag(16), handle potential unencrypted legacy secret safely
        if (preg_match('/^[A-Z2-7]{16,64}$/i', trim($encryptedPayload))) {
            return strtoupper(trim($encryptedPayload));
        }
        throw new RuntimeException('Invalid encrypted 2FA secret payload.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $key = totp_get_master_key();

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        throw new RuntimeException('Decryption of 2FA secret failed: MAC verification failure.');
    }

    return $plaintext;
}

// -----------------------------------------------------------------------------
// 3. Secret Generation & Setup Formatting
// -----------------------------------------------------------------------------

/**
 * Generates a cryptographically secure random Base32 TOTP secret.
 * Default 20 bytes produces a 32-character Base32 string (RFC recommendation).
 */
function totp_generate_secret(int $length = 20): string
{
    $randomBytes = random_bytes($length);
    return totp_base32_encode($randomBytes);
}

/**
 * Formats a Base32 secret into readable four-character groups for manual entry.
 * Example: 'JBSW Y3DP EHPK 3PXP'
 */
function totp_format_setup_key(string $secret): string
{
    $clean = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
    return trim(chunk_split($clean, 4, ' '));
}

/**
 * Builds a standard RFC 6238 otpauth:// URI for Google Authenticator.
 */
function totp_get_otpauth_url(string $secret, string $accountName, string $issuer = 'CafeFlow'): string
{
    $cleanSecret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
    $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
    
    $params = [
        'secret'    => $cleanSecret,
        'issuer'    => $issuer,
        'algorithm' => 'SHA1',
        'digits'    => (string) TOTP_DIGITS,
        'period'    => (string) TOTP_PERIOD
    ];

    return 'otpauth://totp/' . $label . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// -----------------------------------------------------------------------------
// 4. TOTP Code Calculation & Verification (RFC 6238)
// -----------------------------------------------------------------------------

/**
 * Calculates the TOTP code for a specific time step counter.
 */
function totp_calculate_code_for_step(string $secretBytes, int $timeStep, int $digits = 6): string
{
    // Pack 64-bit big-endian binary counter
    $binaryCounter = pack('J', $timeStep);

    // Compute HMAC-SHA1
    $hmac = hash_hmac('sha1', $binaryCounter, $secretBytes, true);

    // Dynamic Truncation
    $offset = ord($hmac[19]) & 0x0f;
    $binaryCode = (
        ((ord($hmac[$offset]) & 0x7f) << 24) |
        ((ord($hmac[$offset + 1]) & 0xff) << 16) |
        ((ord($hmac[$offset + 2]) & 0xff) << 8) |
        (ord($hmac[$offset + 3]) & 0xff)
    );

    $mod = 10 ** $digits;
    $otp = $binaryCode % $mod;

    return str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);
}

/**
 * Generates a 6-digit TOTP code for a secret at a given timestamp.
 */
function totp_generate_code(string $secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $secretBytes = totp_base32_decode($secret);
    $ts = $timestamp ?? time();
    $timeStep = (int) floor($ts / $period);

    return totp_calculate_code_for_step($secretBytes, $timeStep, $digits);
}

/**
 * Verifies a submitted TOTP code against a secret within a drift tolerance.
 */
function totp_verify_code(string $secret, string $enteredCode, ?int $timestamp = null, int $drift = 1, int $period = 30, int $digits = 6): bool
{
    return totp_find_matching_step($secret, $enteredCode, $timestamp, $drift, null, $period, $digits) !== null;
}

/**
 * Validates a submitted TOTP code and returns the matched time-step counter.
 * Enforces drift tolerance and database-backed replay protection.
 *
 * @param string   $secret        Plain Base32 secret.
 * @param string   $enteredCode   User-submitted 6-digit code.
 * @param int|null $timestamp     Reference timestamp (defaults to current time).
 * @param int      $drift         Acceptable drift steps before and after (default 1 = ±30s).
 * @param int|null $lastUsedStep  Last successfully used step from DB (prevents replay).
 * @param int      $period        Step duration in seconds (30).
 * @param int      $digits        Code length (6).
 *
 * @return int|null Matching time-step counter if valid and not replayed, null otherwise.
 */
function totp_find_matching_step(
    string $secret,
    string $enteredCode,
    ?int $timestamp = null,
    int $drift = 1,
    ?int $lastUsedStep = null,
    int $period = 30,
    int $digits = 6
): ?int {
    $cleanCode = preg_replace('/\s+/', '', $enteredCode) ?? '';
    if (!preg_match('/^\d{' . $digits . '}$/', $cleanCode)) {
        return null;
    }

    try {
        $secretBytes = totp_base32_decode($secret);
    } catch (Throwable) {
        return null;
    }

    $currentTimestamp = $timestamp ?? time();
    $currentStep = (int) floor($currentTimestamp / $period);

    $matchedStep = null;

    for ($offset = -$drift; $offset <= $drift; $offset++) {
        $step = $currentStep + $offset;

        // Skip steps that have already been used to prevent replay
        if ($lastUsedStep !== null && $step <= $lastUsedStep) {
            continue;
        }

        $expectedCode = totp_calculate_code_for_step($secretBytes, $step, $digits);
        if (hash_equals($expectedCode, $cleanCode)) {
            $matchedStep = $step;
            break;
        }
    }

    return $matchedStep;
}

// -----------------------------------------------------------------------------
// 5. Emergency Recovery Codes
// -----------------------------------------------------------------------------

/**
 * Generates an array of unique emergency recovery codes.
 * Returns both formatted plaintext codes for the user and hashed versions for DB storage.
 *
 * Format: 'A1B2-C3D4' (8 alphanumeric characters with dash).
 *
 * @return array{plain: list<string>, hashes: list<string>}
 */
function totp_generate_recovery_codes(int $count = 8): array
{
    // Avoid visually ambiguous characters (0, O, 1, I)
    $charset = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $charsetLen = strlen($charset);

    $plainCodes = [];
    $hashes = [];

    while (count($plainCodes) < $count) {
        $random = random_bytes(8);
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $charset[ord($random[$i]) % $charsetLen];
        }
        $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4);

        if (!in_array($formatted, $plainCodes, true)) {
            $plainCodes[] = $formatted;
            $hashes[] = password_hash($formatted, PASSWORD_DEFAULT);
        }
    }

    return [
        'plain'  => $plainCodes,
        'hashes' => $hashes
    ];
}

/**
 * Verifies and atomically consumes (burns) a one-time recovery code for a user.
 * Prevents race conditions and double-spending using transactional row locking.
 */
function totp_verify_and_burn_recovery_code(PDO $pdo, int $userId, string $enteredCode): bool
{
    $clean = strtoupper(trim($enteredCode));
    $clean = preg_replace('/[^A-Z0-9]/', '', $clean) ?? '';
    if (strlen($clean) !== 8) {
        return false;
    }
    $formatted = substr($clean, 0, 4) . '-' . substr($clean, 4, 4);

    $inTransaction = $pdo->inTransaction();
    if (!$inTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT two_factor_recovery_codes FROM users WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || empty($row['two_factor_recovery_codes'])) {
            if (!$inTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $hashes = json_decode($row['two_factor_recovery_codes'], true);
        if (!is_array($hashes) || empty($hashes)) {
            if (!$inTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $matchedIndex = null;
        foreach ($hashes as $index => $hash) {
            if (password_verify($formatted, $hash)) {
                $matchedIndex = $index;
                break;
            }
        }

        if ($matchedIndex === null) {
            if (!$inTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        // Remove the matched recovery code (burn it)
        unset($hashes[$matchedIndex]);
        $updatedHashes = array_values($hashes);
        $newJson = json_encode($updatedHashes);

        $update = $pdo->prepare('UPDATE users SET two_factor_recovery_codes = ? WHERE user_id = ?');
        $update->execute([$newJson, $userId]);

        if (!$inTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if (!$inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CafeFlow 2FA] Recovery code consumption error: ' . $e->getMessage());
        return false;
    }
}
