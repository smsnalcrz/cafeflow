<?php
/**
 * CafeFlow - Navigation & Client Event Audit Beacon API
 *
 * Lightweight asynchronous ingest endpoint for browser sendBeacon and fetch calls.
 * Records tab visits, view dwell times, and client view transitions into audit_logs.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/audit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Authentication required.',
        'code'    => 'UNAUTHENTICATED'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input', false, null, 0, 8193);
if (strlen($rawInput) > 8192) send_json(['success' => false, 'error' => 'Navigation event is too large.'], 413);
$data = json_decode($rawInput, true, 16) ?? $_POST;
if (!is_array($data)) send_json(['success' => false, 'error' => 'Invalid navigation event.'], 422);

// Verify CSRF token
$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!is_string($csrfToken) || !validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid or missing CSRF token.',
        'code'    => 'INVALID_CSRF'
    ]);
    exit;
}

$views = ['dashboard', 'pos', 'products', 'inventory', 'report', 'accounts', 'predictive', 'waste', 'security_2fa', 'audit'];
$fields = $data['metadata'] ?? null;
$from = $fields['from_view'] ?? null;
$to = $fields['to_view'] ?? null;
$duration = filter_var($fields['duration_prev_ms'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if (!in_array($from, $views, true) || !in_array($to, $views, true) || $duration === false) {
    send_json(['success' => false, 'error' => 'Invalid view transition or dwell time.'], 422);
}

// Enforce 2-second minimum dwell filter on tab transitions if duration_prev_ms is reported
if ($duration < 2000) {
    echo json_encode(['success' => true, 'skipped' => 'sub_dwell_threshold']);
    exit;
}

audit_log('navigation', 'tab_view', "Switched from $from to $to", 'view', $to,
    ['from_view' => $from, 'to_view' => $to, 'duration_prev_ms' => $duration], null, $user);

echo json_encode(['success' => true]);
