# Master Prompt: CafeFlow 2FA Implementation (Google Authenticator TOTP & Setup Key)

```markdown
You are a senior full-stack PHP/Security engineer working on the **CafeFlow POS** web application. Your task is to implement enterprise-grade Two-Factor Authentication (2FA) using Google Authenticator (RFC 6238 TOTP) with a manual **Setup Key**, optional QR code, and backup recovery codes.

You must write clean, production-ready, fully backward-compatible code following the project's exact conventions, architecture, and aesthetic design system.

---

## 1. Project Context & Technical Architecture

- **Stack**: PHP 8.2 (ZTS/Vanilla) / Apache (XAMPP) / MariaDB (MySQL) / Vanilla JavaScript (ES6 Modules) / CSS3 Design Tokens.
- **Dependency Philosophy**: **ZERO external Composer packages.** All cryptographic operations (Base32 decoding, HMAC-SHA1 byte packaging, RFC 6238 time-step truncation, random secret generation) must be implemented in native PHP 8.2 using standard built-in functions (`hash_hmac`, `pack`, `unpack`, `random_bytes`).
- **Styling**: Coffee-themed dark botanical palette (`#162318`, `#1F3325`, `#1B4332`, `#2D6A4F`, `#D8E2DC`, `#C5705D`).
- **Current Authentication Core**:
  - `core/db.php`: Database connection (`db()`), schema migrations (`ensure_schema()`), JSON helpers (`send_json()`, `fail()`).
  - `core/auth.php`: Session initialization (`init_session()`), session inspection (`current_user()`), login state creation (`login_user()`), role enforcement (`require_role()`), CSRF protection (`validate_csrf_token()`), rate-limiting (`is_login_rate_limited()`, `record_login_attempt()`).
  - `login.php`: Standalone sign-in portal. Form submits via asynchronous `fetch()` to `api/auth_api.php`.
  - `api/auth_api.php`: Authentication endpoint handling `action=login` and `action=logout`.
  - `api/accounts_api.php`: Manager portal account management (RBAC, password resets, active/inactive toggles).
  - `database/schema.sql`: Authoritative table definitions.

---

## 2. Core Requirements & Specification

### A. Database Schema Migration (`database/schema.sql` & `core/db.php`)
1. Extend the `users` table with the following columns:
   - `two_factor_secret VARCHAR(64) NULL DEFAULT NULL` — Stores the 16 or 32-character Base32 secret key.
   - `two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0` — `0` = disabled, `1` = active.
   - `two_factor_confirmed_at DATETIME NULL DEFAULT NULL` — Timestamp when the user confirmed initial 2FA setup.
   - `two_factor_recovery_codes TEXT NULL DEFAULT NULL` — JSON array storing 8 hashed one-time recovery backup codes.
2. In `core/db.php` inside `ensure_schema(PDO $pdo)`:
   - Implement non-destructive automatic column detection (`SHOW COLUMNS FROM users LIKE 'two_factor_enabled'`) and execute `ALTER TABLE users ADD COLUMN ...` migrations safely so existing databases update automatically without manual SQL execution.

---

### B. Pure PHP RFC 6238 TOTP Engine (`core/totp.php`)
Create a dedicated standalone utility file `core/totp.php` with zero external dependencies:
1. **Base32 Alphabet**: `ABCDEFGHIJKLMNOPQRSTUVWXYZ234567`.
2. **`totp_generate_secret(int $length = 16): string`**:
   - Generates a cryptographically secure Base32 secret string using `random_bytes()`.
3. **`totp_verify_code(string $secret, string $code, int $discrepancy = 1): bool`**:
   - Implements RFC 6238 TOTP verification with HMAC-SHA1.
   - Supports 30-second time steps (`$timeSlice = floor(time() / 30)`).
   - Allows a configurable drift window of `±1` step (checking `$timeSlice - 1`, `$timeSlice`, and `$timeSlice + 1`) to account for client-server clock skew.
   - Constant-time comparison (`hash_equals`) to prevent timing attacks.
4. **`totp_format_setup_key(string $secret): string`**:
   - Formats the Base32 string into 4-character chunks separated by spaces (e.g. `JBSW Y3DP EHPK 3PXP`) for effortless human reading and manual entry into Google Authenticator.
5. **`totp_get_otpauth_url(string $secret, string $accountName, string $issuer = 'CafeFlow'): string`**:
   - Generates the standard URI: `otpauth://totp/CafeFlow:{$accountName}?secret={$secret}&issuer=CafeFlow&algorithm=SHA1&digits=6&period=30`.
6. **`totp_generate_recovery_codes(int $count = 8): array`**:
   - Generates `$count` unique 8-character human-readable alphanumeric backup codes (e.g., `A1B2-C3D4`).
   - Returns both the plain-text codes (to show once to the user) and an array of `password_hash()` strings to store in `users.two_factor_recovery_codes`.
7. **`totp_verify_and_burn_recovery_code(PDO $pdo, int $userId, string $enteredCode): bool`**:
   - Verifies the entered code against the stored hashes.
   - If valid, removes that specific hashed code from the database so it cannot be reused, updates the database, and returns `true`.

---

### C. Two-Step Authentication Flow (`api/auth_api.php`)
Refactor `api/auth_api.php` to support the two-step login sequence:
1. **Step 1: Credential Verification (`action=login`)**:
   - Validate username/email and password against `users` table.
   - Check account status (`active`).
   - If `two_factor_enabled == 0`:
     - Perform normal login via `login_user($user)`.
     - Return `{ success: true, requires_2fa: false, redirect: 'index.php' }`.
   - If `two_factor_enabled == 1`:
     - Do NOT issue a full session or call `login_user()`.
     - Issue a temporary, signed session token:
       - `$_SESSION['2fa_pending_user_id'] = $user['user_id'];`
       - `$_SESSION['2fa_pending_timestamp'] = time();`
       - `$_SESSION['2fa_temp_token'] = bin2hex(random_bytes(32));`
     - Return:
       ```json
       {
         "success": true,
         "requires_2fa": true,
         "temp_token": "...",
         "user_id": 123,
         "full_name": "John Doe",
         "message": "Two-factor authentication required. Please enter your 6-digit Google Authenticator code."
       }
       ```
2. **Step 2: 2FA Verification (`action=verify_2fa`)**:
   - Validate temporary pending session (`$_SESSION['2fa_pending_user_id']` must exist and be less than 5 minutes old).
   - Apply brute-force rate-limiting on 2FA verification attempts (max 5 failed attempts per 15 minutes).
   - Check input:
     - If `code` (6 digits): Verify with `totp_verify_code($secret, $code)`.
     - If `recovery_code`: Verify with `totp_verify_and_burn_recovery_code($pdo, $userId, $code)`.
   - If verification fails:
     - Increment 2FA fail count and return HTTP 401 `{ success: false, error: "Invalid authentication code. Please try again." }`.
   - If verification succeeds:
     - Clear `2fa_pending_*` session variables.
     - Call `login_user($user)` to establish full authenticated session.
     - Return `{ success: true, redirect: 'index.php', message: "Authenticated successfully." }`.

---

### D. Redesigned Sign-In UI (`login.php`)
Upgrade `login.php` to provide a seamless two-step transition without page reloads:
1. **Primary Screen**: Username/email + Password fields (existing).
2. **2FA Challenge Screen**:
   - When `requires_2fa: true` is returned, smoothly transition or replace the form with the 2FA Challenge View:
     - Header: **Two-Factor Authentication** with a security shield icon 🛡️.
     - Subtitle: "Enter the 6-digit code generated by your **Google Authenticator** app."
     - User Identity Chip: Shows user's name/email with a small avatar.
     - **Input**: Clean, centered, spaced 6-digit input (`letter-spacing: 6px`, `font-size: 24px`, `font-family: monospace`, `text-align: center`, `maxlength="6"`, `inputmode="numeric"`, `autocomplete="one-time-code"`). Auto-focuses on render.
     - Submit Button: "Verify & Sign In".
     - Secondary Action: "Use an emergency recovery code" (toggles input to 8-character recovery code field).
     - Back Button: "← Back to Sign In" (cancels pending 2FA and returns to password view).

---

### E. 2FA Setup & Enrollment Workflow (`api/2fa_api.php` or `api/accounts_api.php`)
Provide full enrollment and management functionality for logged-in users:
1. **Action: `setup_init` (Manager/Barista self-service)**:
   - User must be authenticated (`require_login()`).
   - Generates a new Base32 secret using `totp_generate_secret()`.
   - Formats the **Setup Key** with spaces (`JBSW Y3DP EHPK 3PXP`).
   - Generates 8 one-time recovery backup codes.
   - Stores secret and hashed recovery codes in temporary session (`$_SESSION['pending_2fa_secret']` and `$_SESSION['pending_2fa_recovery_codes']`).
   - Returns:
     ```json
     {
       "success": true,
       "setup_key": "JBSW Y3DP EHPK 3PXP",
       "raw_secret": "JBSWY3DPEHPK3PXP",
       "account_name": "CafeFlow: username",
       "otpauth_url": "otpauth://totp/...",
       "recovery_codes": ["A1B2-C3D4", "E5F6-G7H8", ...]
     }
     ```
2. **Action: `setup_confirm`**:
   - Requires user to submit the current 6-digit code from Google Authenticator to confirm the secret was correctly registered in their app.
   - Validates code with `totp_verify_code($_SESSION['pending_2fa_secret'], $code)`.
   - On success:
     - Saves `two_factor_secret`, `two_factor_enabled = 1`, `two_factor_confirmed_at = NOW()`, and `two_factor_recovery_codes` to database.
     - Clears temporary session variables.
     - Returns `{ success: true, message: "Two-Factor Authentication is now active on your account." }`.
3. **Action: `disable_2fa`**:
   - Requires verification of current account password and current 2FA code before turning off 2FA.
   - Sets `two_factor_enabled = 0`, `two_factor_secret = NULL`, `two_factor_recovery_codes = NULL`.
4. **Manager Administration Guard**:
   - In `api/accounts_api.php`, Managers can view 2FA status of all staff (`2fa_enabled: true/false`).
   - If an employee loses their phone and backup codes, a Manager can trigger an administrative 2FA Reset (`action=reset_2fa`) with confirmation.

---

### F. Visual Setup Modal Design Token Specifications
When designing the enrollment modal:
- Card width: `max-width: 540px`, padding `32px`.
- Step 1: Display the **SETUP KEY** inside an styled credential pill box:
  - Monospace font, bold, 16px, background `#F2EBE0`, border `1.5px dashed #2D6A4F`, color `#1B4332`.
  - Include an interactive **"📋 Copy Key"** button that copies to clipboard and displays a green "Copied!" tooltip.
  - Clear copy instructions:
    1. Open the **Google Authenticator** app on your phone.
    2. Tap **+** (Add) and select **Enter a setup key**.
    3. Account Name: `CafeFlow (your username)`.
    4. Key: Paste the Setup Key above.
    5. Type of key: Ensure **Time-based** is selected.
- Step 2: Display Backup Recovery Codes in a 2-column grid with a **"📥 Download Backup Codes"** button.
- Step 3: 6-digit verification input with instant confirmation button.

---

## 3. Deliverables Expected

1. **`core/totp.php`**: Complete RFC 6238 TOTP implementation in pure PHP 8.2 (Base32, HMAC-SHA1, drift tolerance, recovery codes).
2. **`core/db.php` & `database/schema.sql`**: Updated table definitions and automatic migration logic in `ensure_schema()`.
3. **`api/auth_api.php`**: Updated login flow supporting two-step verification (`action=login` and `action=verify_2fa`).
4. **`login.php`**: Redesigned responsive login interface with seamless 2FA prompt transition.
5. **`api/2fa_api.php`** (or extensions to `api/accounts_api.php`): Full enrollment, confirmation, and disable endpoints.
6. **`assets/js/modules/accounts.js` & `index.php`**: Account settings UI showing 2FA enrollment modal and Manager status badges.
7. **Verification Script**: A CLI test script (e.g. `tools/test_2fa_suite.php`) validating TOTP calculation, drift tolerance, recovery code burning, and rate limiting.

Ensure all file paths are exact, all existing login functionality remains backward-compatible for users without 2FA, and no external Composer libraries are introduced.
```
