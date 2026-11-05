<?php
/**
 * CafeFlow - Core Authentication & RBAC Engine
 *
 * Centralized session handling, password authentication, role-based access
 * control (Barista vs Manager), CSRF token defense, and rate-limited brute force protection.
 */
require_once __DIR__ . '/db.php';

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
        session_start();
    }
}

/**
 * Returns current authenticated user record or null if not logged in.
 */
function current_user(): ?array
{
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

    static $cachedUser = null;
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
 * Logs in a user, regenerates session ID to prevent fixation, sets security tokens.
 */
function login_user(array $user): void
{
    init_session();
    session_regenerate_id(true);

    $_SESSION['user_id']    = (int) $user['user_id'];
    $_SESSION['user_role']  = strtolower($user['role']);
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['user_email'] = $user['email'] ?? '';
    $_SESSION['logged_at']     = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

    try {
        $pdo = db();
        $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?');
        $stmt->execute([(int) $user['user_id']]);
    } catch (Throwable $e) {
        error_log('[CafeFlow Auth] ' . $e->getMessage());
    }
}

/**
 * Destroys session and cleans up cookies.
 */
function logout_user(): void
{
    init_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
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
    session_destroy();
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
