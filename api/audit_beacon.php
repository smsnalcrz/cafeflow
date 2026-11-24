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

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: $_POST;

// Verify CSRF token
$csrfToken = $data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid or missing CSRF token.',
        'code'    => 'INVALID_CSRF'
    ]);
    exit;
}

$category = trim($data['category'] ?? 'navigation');
$action = trim($data['action'] ?? 'tab_view');
$description = trim($data['description'] ?? 'Navigated to view');
$targetType = !empty($data['target_type']) ? trim($data['target_type']) : 'view';
$targetId = !empty($data['target_id']) ? trim($data['target_id']) : null;
$metadata = !empty($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null;

// Enforce 2-second minimum dwell filter on tab transitions if duration_prev_ms is reported
if (isset($metadata['duration_prev_ms']) && (int)$metadata['duration_prev_ms'] < 2000) {
    echo json_encode(['success' => true, 'skipped' => 'sub_dwell_threshold']);
    exit;
}

audit_log($category, $action, $description, $targetType, $targetId, $metadata, null, $user);

echo json_encode(['success' => true]);
