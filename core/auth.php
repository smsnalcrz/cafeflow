<?php
/**
 * CafeFlow - Core Authentication & RBAC Engine
 *
 * Centralized session handling, password authentication, role-based access
 * control (Barista vs Manager), CSRF token defense, and rate-limited brute force protection.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit.php';

if (!defined('AUTH_SESSION_NAME')) {
    define('AUTH_SESSION_NAME', 'cafeflow_session');
}

if (!defined('SESSION_IDLE_TIMEOUT')) {
    define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutes idle inactivity threshold
}

/**
 * Initializes secure PHP session with hardened cookie settings.
 */
function init_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                       (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

            session_name(AUTH_SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => 0,              // Valid until browser session ends
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,           // Protect against XSS session theft
                'samesite' => 'Lax'           // Protect against CSRF
            ]);
        }
        @session_start();
    }
}

function clear_auth_cache(): void
{
    current_user(true);
}

/**
 * Returns current authenticated user record or null if not logged in.
 */
function current_user(bool $forceFresh = false): ?array
{
    static $cachedUser = null;
    if ($forceFresh) {
        $cachedUser = null;
        return null;
    }

    init_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // Server-enforced idle session inactivity timeout
    $now = time();
    if (!empty($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        logout_user();
        if (is_api_request()) {
            send_json([
                'success' => false,
                'error'   => 'Your session has expired due to 30 minutes of inactivity. Please sign in again.',
                'code'    => 'SESSION_EXPIRED'
            ], 401);
        } else {
            header('Location: login.php?timeout=1');
            exit;
        }
    }
    $_SESSION['last_activity'] = $now;

    if ($cachedUser !== null && $cachedUser['user_id'] === $_SESSION['user_id']) {
        return $cachedUser;
    }

    try {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id, full_name, username, email, role, status, created_at, last_login FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        // Inactive or pending accounts cannot stay authenticated
        if (!$user || $user['status'] !== 'active') {
            logout_user();
            return null;
        }

        // Keep session payload in sync with database
        $_SESSION['user_role'] = strtolower($user['role']);
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['username']  = $user['username'];
        $cachedUser = $user;
        return $user;
    } catch (Throwable $e) {
        error_log('[CafeFlow Auth] ' . $e->getMessage());
        return null;
    }
}

/**
 * Alias for current_user() matching common naming conventions.
 */
function get_auth_user(): ?array
{
    return current_user();
}

/**
 * Checks whether user is currently authenticated.
 */
function is_authenticated(): bool
{
    return current_user() !== null;
}

/**
 * Returns current user role ('barista' or 'manager') or null.
 */
function current_user_role(): ?string
{
    $user = current_user();
    return $user ? strtolower($user['role']) : null;
}

/**
 * Helper to determine if current request is expecting an API response.
 */
function is_api_request(): bool
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, '/api/')) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (str_contains($accept, 'application/json')) {
        return true;
    }
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return strtolower($requestedWith) === 'xmlhttprequest';
}

/**
 * Enforces authentication.
 * For API requests: sends HTTP 401 with JSON error.
 * For web requests: redirects to login.php.
 */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        if (is_api_request()) {
            send_json([
                'success' => false,
                'error'   => 'Authentication required. Please sign in.',
                'code'    => 'UNAUTHENTICATED'
            ], 401);
        } else {
            $currentUri = $_SERVER['REQUEST_URI'] ?? 'index.php';
            $redirect = urlencode($currentUri);
            header("Location: login.php?redirect={$redirect}");
            exit;
        }
    }
    return $user;
}

/**
 * Enforces role authorization (RBAC).
 * e.g. require_role('barista') or require_role(['barista', 'manager'])
 *
 * For API requests: sends HTTP 403 with JSON error.
 * For web requests: redirects to index.php with error notice.
 */
function require_role(string|array $allowedRoles): array
{
    $user = require_login();
    $allowed = is_array($allowedRoles) ? array_map('strtolower', $allowedRoles) : [strtolower($allowedRoles)];
    $currentRole = strtolower($user['role'] ?? '');

    if (!in_array($currentRole, $allowed, true)) {
        if (is_api_request()) {
            send_json([
                'success' => false,
                'error'   => 'Access denied. Your role (' . ucfirst($currentRole) . ') is not authorized for this operation.',
                'code'    => 'FORBIDDEN'
            ], 403);
        } else {
            header('Location: index.php?error=forbidden');
            exit;
        }
    }
    return $user;
}

/**
 * Logs in a user, regenerates session ID to prevent fixation, sets security tokens,
 * and initializes tracking in user_sessions and audit_logs.
 */
function login_user(array $user, string $authMethod = 'password'): void
{
    clear_auth_cache();
    init_session();
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
    }

    $sessId = session_id();
    if (empty($sessId)) {
        $sessId = 'sess_' . bin2hex(random_bytes(16));
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_id($sessId);
        }
    }

    $_SESSION['user_id']    = (int) $user['user_id'];
    $_SESSION['user_role']  = strtolower($user['role']);
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['user_email'] = $user['email'] ?? '';
    $_SESSION['logged_at']     = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
    $_SESSION['user_session_id'] = $sessId;

    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?');
        $stmt->execute([(int) $user['user_id']]);

        // Record active session in user_sessions
        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 255);
        $sessStmt = $pdo->prepare('
            INSERT INTO user_sessions (
                session_id, user_id, ip_address, user_agent, auth_method, login_at, last_heartbeat_at, duration_seconds
            ) VALUES (?, ?, ?, ?, ?, NOW(3), NOW(3), 0)
            ON DUPLICATE KEY UPDATE 
                user_id = VALUES(user_id),
                last_heartbeat_at = NOW(3)
        ');
        $sessStmt->execute([$sessId, (int) $user['user_id'], $ip, $ua, $authMethod]);

        audit_log(
            'security',
            'auth_login',
            'Signed in successfully via ' . $authMethod,
            'user',
            (string) $user['user_id'],
            ['auth_method' => $authMethod, 'username' => $user['username']],
            $pdo,
            $user
        );
    } catch (Throwable $e) {
        error_log('[CafeFlow Auth] ' . $e->getMessage());
    }
}

/**
 * Destroys session, updates logout timestamp/duration in user_sessions, and cleans up cookies.
 */
function logout_user(string $reason = 'user_logout'): void
{
    init_session();
    $sessId = session_id() ?: ($_SESSION['user_session_id'] ?? null);
    $user = current_user();

    if ($sessId) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('
                UPDATE user_sessions
                   SET logout_at = NOW(3),
                       duration_seconds = TIMESTAMPDIFF(SECOND, login_at, NOW(3)),
                       logout_reason = ?
                 WHERE session_id = ?
            ');
            $stmt->execute([$reason, $sessId]);

            audit_log(
                'security',
                'auth_logout',
                'User signed out (' . $reason . ')',
                'user',
                $user ? (string)$user['user_id'] : null,
                ['reason' => $reason],
                $pdo,
                $user
            );
        } catch (Throwable $e) {
            error_log('[CafeFlow Auth Logout] ' . $e->getMessage());
        }
    }

    clear_auth_cache();
    $_SESSION = [];
    if (!headers_sent() && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
}

/**
 * Generates or returns active CSRF token.
 */
function get_csrf_token(): string
{
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates CSRF token from header or request.
 */
function validate_csrf_token(?string $token = null): bool
{
    init_session();
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    if ($token === null) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    }
    return is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rate limit check: blocks after 5 failed login attempts within 15 minutes.
 */
function is_login_rate_limited(string $ip, string $username, PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
              FROM login_attempts 
             WHERE (ip_address = ? OR username = ?)
               AND success = 0 
               AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$ip, $username]);
        $fails = (int) $stmt->fetchColumn();
        return $fails >= 5;
    } catch (Throwable $e) {
        error_log('[CafeFlow RateLimit] ' . $e->getMessage());
        return false;
    }
}

/**
 * Records a login attempt.
 */
function record_login_attempt(string $ip, string $username, bool $success, PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (ip_address, username, success) VALUES (?, ?, ?)');
        $stmt->execute([$ip, $username, $success ? 1 : 0]);
        if ($success) {
            $del = $pdo->prepare('DELETE FROM login_attempts WHERE (ip_address = ? OR username = ?) AND success = 0');
            $del->execute([$ip, $username]);
        }
    } catch (Throwable $e) {
        error_log('[CafeFlow RateLimit] ' . $e->getMessage());
    }
}

/**
 * Helper to obtain client IP safely.
 */
function get_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return substr($ip, 0, 45);
}

/**
 * 2FA Rate limit check: blocks after 5 failed 2FA attempts within 15 minutes.
 */
function is_2fa_rate_limited(int $userId, string $ip, PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
              FROM two_factor_attempts 
             WHERE user_id = ? 
               AND ip_address = ?
               AND success = 0 
               AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $stmt->execute([$userId, $ip]);
        $fails = (int) $stmt->fetchColumn();
        return $fails >= 5;
    } catch (Throwable $e) {
        error_log('[CafeFlow 2FA RateLimit] ' . $e->getMessage());
        return false;
    }
}

/**
 * Records a 2FA verification attempt.
 */
function record_2fa_attempt(int $userId, string $ip, string $attemptType, bool $success, PDO $pdo): void
{
    try {
        $validType = in_array($attemptType, ['totp', 'recovery'], true) ? $attemptType : 'totp';
        $stmt = $pdo->prepare('INSERT INTO two_factor_attempts (user_id, ip_address, attempt_type, success) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $ip, $validType, $success ? 1 : 0]);
        if ($success) {
            $del = $pdo->prepare('DELETE FROM two_factor_attempts WHERE user_id = ? AND ip_address = ? AND success = 0');
            $del->execute([$userId, $ip]);
        }
    } catch (Throwable $e) {
        error_log('[CafeFlow 2FA RateLimit] ' . $e->getMessage());
    }
}

/**
 * Records an entry in the security audit log, with dual-write bridge to primary audit_logs.
 */
function log_security_audit(PDO $pdo, ?int $actorUserId, ?int $targetUserId, string $action, ?array $metadata = null): void
{
    try {
        $ip = get_client_ip();
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 255);
        $metaJson = $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare('
            INSERT INTO security_audit_log (actor_user_id, target_user_id, action, ip_address, user_agent, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$actorUserId, $targetUserId, $action, $ip, $userAgent, $metaJson]);

        // Dual-write bridge to unified audit_logs
        $meta = is_array($metadata) ? $metadata : [];
        if ($targetUserId !== null) {
            $meta['target_user_id'] = $targetUserId;
        }
        $desc = 'Security event: ' . str_replace('_', ' ', $action);
        audit_log(
            'security',
            $action,
            $desc,
            'user',
            $targetUserId !== null ? (string)$targetUserId : ($actorUserId !== null ? (string)$actorUserId : null),
            $meta,
            $pdo
        );
    } catch (Throwable $e) {
        error_log('[CafeFlow Audit] ' . $e->getMessage());
    }
}

/**
 * Returns pending 2FA state from session, or null if not pending or expired (5 min).
 */
function get_pending_2fa(): ?array
{
    init_session();
    if (empty($_SESSION['pending_2fa']) || !is_array($_SESSION['pending_2fa'])) {
        return null;
    }
    $pending = $_SESSION['pending_2fa'];
    $createdAt = (int) ($pending['created_at'] ?? 0);
    // Strict 5-minute expiration
    if (time() - $createdAt > 300) {
        clear_pending_2fa();
        return null;
    }
    return $pending;
}

/**
 * Clears pending 2FA authentication state from session.
 */
function clear_pending_2fa(): void
{
    init_session();
    unset($_SESSION['pending_2fa']);
}

/**
 * Returns pending password reset state from session, or null if not pending or expired (5 min).
 */
function get_pending_password_reset(): ?array
{
    init_session();
    if (empty($_SESSION['pending_password_reset']) || !is_array($_SESSION['pending_password_reset'])) {
        return null;
    }
    $pending = $_SESSION['pending_password_reset'];
    $createdAt = (int) ($pending['created_at'] ?? 0);
    // Strict 5-minute expiration
    if (time() - $createdAt > 300) {
        clear_pending_password_reset();
        return null;
    }
    return $pending;
}

/**
 * Clears pending password reset state from session.
 */
function clear_pending_password_reset(): void
{
    init_session();
    unset($_SESSION['pending_password_reset']);
}
