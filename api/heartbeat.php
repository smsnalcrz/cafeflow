<?php
/**
 * CafeFlow - Client Heartbeat API
 *
 * Pings active session lifecycle, keeps duration_seconds updated,
 * and maintains live online status.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Session expired or invalid. Please sign in again.',
        'code'    => 'UNAUTHENTICATED'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: [];

// Verify CSRF if provided in body or header
$token = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if ($token !== null && !validate_csrf_token($token)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid CSRF token.',
        'code'    => 'INVALID_CSRF'
    ]);
    exit;
}

$sessId = $_SESSION['audit_tracking_id'] ?? null;
if (!$sessId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'No active session found.'
    ]);
    exit;
}

try {
    $pdo = db();
    $ip = get_client_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Unknown', 0, 255);

    // Recover a missing tracking row, and leave closed sessions untouched.
    $stmt = $pdo->prepare('
        INSERT INTO user_sessions (
            session_id, user_id, ip_address, user_agent, auth_method, login_at, last_heartbeat_at, duration_seconds
        ) VALUES (?, ?, ?, ?, "password", NOW(3), NOW(3), 0)
        ON DUPLICATE KEY UPDATE
            last_heartbeat_at = IF(logout_at IS NULL, NOW(3), last_heartbeat_at),
            duration_seconds = IF(logout_at IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, login_at, NOW(3))), duration_seconds),
            ip_address = IF(logout_at IS NULL, VALUES(ip_address), ip_address),
            user_agent = IF(logout_at IS NULL, VALUES(user_agent), user_agent)
    ');
    $stmt->execute([$sessId, (int)$user['user_id'], $ip, $ua]);

    // Retrieve active duration
    $durStmt = $pdo->prepare('SELECT duration_seconds FROM user_sessions WHERE session_id = ? AND logout_at IS NULL');
    $durStmt->execute([$sessId]);
    $sessInfo = $durStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sessInfo) {
        logout_user('revoked');
        send_json(['success' => false, 'error' => 'Session expired or invalid.', 'code' => 'UNAUTHENTICATED'], 401);
    }

    echo json_encode([
        'success'        => true,
        'session_id'     => $sessId,
        'active_seconds' => (int)($sessInfo['duration_seconds'] ?? 0),
        'server_time'    => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Unable to update session heartbeat.'
    ]);
}
