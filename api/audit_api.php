<?php
/**
 * CafeFlow - Manager Audit Log & Activity Trail API
 *
 * Implements strict RBAC (manager only), high-speed multi-faceted filtering,
 * pagination, KPI session metrics, and CSV compliance export.
 * Note: Historical logs are immutable; mutations (POST/PUT/DELETE) are rejected with 405.
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/audit.php';
require_once __DIR__ . '/../core/csv.php';

$currentUser = require_role('manager'); // Barista role receives HTTP 403 automatically
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: GET');
    }
    echo json_encode([
        'success' => false,
        'error'   => 'Method not allowed. Audit logs are append-only and strictly immutable.',
        'code'    => 'METHOD_NOT_ALLOWED'
    ]);
    if (!defined('CAFEFLOW_TESTING')) {
        exit;
    }
    return;
}

// -----------------------------------------------------------------------------
// Helper: Format Relative Time
// -----------------------------------------------------------------------------
if (!function_exists('audit_format_relative_time')) {
    function audit_format_relative_time(string $datetimeStr): string
    {
        $timestamp = strtotime($datetimeStr);
        if (!$timestamp) return $datetimeStr;
        $diff = time() - $timestamp;

        if ($diff < 10) return 'just now';
        if ($diff < 60) return $diff . ' secs ago';
        $minutes = (int)($diff / 60);
        if ($minutes < 60) return $minutes . ' min' . ($minutes > 1 ? 's' : '') . ' ago';
        $hours = (int)($diff / 3600);
        if ($hours < 24) return $hours . ' hr' . ($hours > 1 ? 's' : '') . ' ago';
        $days = (int)($diff / 86400);
        if ($days < 7) return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        return date('M j, Y g:i A', $timestamp);
    }
}

// -----------------------------------------------------------------------------
// Filters & Parameters Extraction
// -----------------------------------------------------------------------------
$filters = [];
foreach (['user_id', 'category', 'action', 'date_from', 'date_to', 'search', 'export', 'limit', 'offset'] as $key) {
    $value = $_GET[$key] ?? '';
    if (!is_string($value) && !is_int($value)) {
        send_json(['success' => false, 'error' => "Invalid $key filter."], 422);
    }
    $filters[$key] = trim((string)$value);
}
$category = $filters['category'];
$action = $filters['action'];
$search = $filters['search'];
$dateFrom = $filters['date_from'];
$dateTo = $filters['date_to'];
$exportCsv = strtolower($filters['export']) === 'csv';
$integer = static function (string $key, int $min, int $max, ?int $default) use ($filters): ?int {
    if ($filters[$key] === '') return $default;
    $value = filter_var($filters[$key], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    if ($value === false) send_json(['success' => false, 'error' => "Invalid $key filter."], 422);
    return $value;
};
$userId = $integer('user_id', 1, 4294967295, null);
$limit = $integer('limit', 1, 200, 50);
$offset = $integer('offset', 0, PHP_INT_MAX, 0);
if (!in_array($category, ['', 'all', 'navigation', 'pos_order', 'inventory', 'catalog', 'account', 'security', 'system'], true)) {
    send_json(['success' => false, 'error' => 'Unknown audit category.'], 422);
}
if (!preg_match('/^.{0,200}$/usD', $search) || !preg_match('/^.{0,64}$/usD', $action)) {
    send_json(['success' => false, 'error' => 'Use up to 200 characters for search and 64 for action.'], 422);
}
foreach ([$dateFrom, $dateTo] as $date) {
    if ($date !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
        || (int)$parts[1] < 1000 || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]))) {
        send_json(['success' => false, 'error' => 'Enter a valid date in YYYY-MM-DD format.'], 422);
    }
}
if ($dateTo !== '' && $dateFrom > $dateTo) {
    send_json(['success' => false, 'error' => 'Start date must be on or before end date.'], 422);
}

// Build WHERE Clause
$where = ['1=1'];
$params = [];

if ($userId !== null && $userId > 0) {
    $where[] = 'l.user_id = :user_id';
    $params[':user_id'] = $userId;
}

if ($category && $category !== 'all') {
    $where[] = 'l.category = :category';
    $params[':category'] = $category;
}

if ($action !== '' && $action !== 'all') {
    $where[] = 'l.action = :action';
    $params[':action'] = $action;
}

if ($dateFrom) {
    $where[] = 'l.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo) {
    $where[] = 'l.created_at <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59.999';
}

if ($search !== '') {
    $where[] = '(
        l.description LIKE :kw1 
        OR l.username_snapshot LIKE :kw2 
        OR l.action LIKE :kw3 
        OR l.target_id LIKE :kw4 
        OR l.ip_address LIKE :kw5
    )';
    $kw = '%' . $search . '%';
    $params[':kw1'] = $kw;
    $params[':kw2'] = $kw;
    $params[':kw3'] = $kw;
    $params[':kw4'] = $kw;
    $params[':kw5'] = $kw;
}

$whereClause = implode(' AND ', $where);

// -----------------------------------------------------------------------------
// CSV Export Mode
// -----------------------------------------------------------------------------
if ($exportCsv) {
    $filename = 'cafeflow_audit_log_' . date('Ymd_His') . '.csv';
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // CSV Header row
    fputcsv($out, [
        'Log ID',
        'Timestamp (Asia/Manila)',
        'Session ID',
        'User ID',
        'Username',
        'Role',
        'Category',
        'Action',
        'Description',
        'Target Type',
        'Target ID',
        'IP Address',
        'Metadata'
    ]);

    $csvStmt = $pdo->prepare("
        SELECT l.log_id, l.created_at, l.session_id, l.user_id, l.username_snapshot,
               l.user_role_snapshot, l.category, l.action, l.description,
               l.target_type, l.target_id, l.ip_address, l.metadata_json
          FROM audit_logs l
         WHERE {$whereClause}
         ORDER BY l.created_at DESC, l.log_id DESC
         LIMIT 5000
    ");
    $csvStmt->execute($params);

    while ($row = $csvStmt->fetch(PDO::FETCH_ASSOC)) {
        write_spreadsheet_csv_row($out, [
            $row['log_id'],
            $row['created_at'],
            audit_public_session_id($row['session_id']) ?? '',
            $row['user_id'] ?? 'SYSTEM',
            $row['username_snapshot'],
            strtoupper($row['user_role_snapshot']),
            $row['category'],
            $row['action'],
            $row['description'],
            $row['target_type'] ?? '',
            $row['target_id'] ?? '',
            $row['ip_address'],
            $row['metadata_json'] ?? ''
        ]);
    }

    fclose($out);
    if (!defined('CAFEFLOW_TESTING')) {
        exit;
    }
    return;
}

// -----------------------------------------------------------------------------
// JSON Mode: Fetch Logs and Session Metrics
// -----------------------------------------------------------------------------
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// 1. Total matching count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs l WHERE {$whereClause}");
$countStmt->execute($params);
$totalFiltered = (int) $countStmt->fetchColumn();

// 2. Paginated rows with active session duration join
$sql = "
    SELECT l.log_id, l.session_id, l.user_id, l.username_snapshot, l.user_role_snapshot,
           l.category, l.action, l.description, l.target_type, l.target_id,
           l.metadata_json, l.ip_address, l.created_at,
           s.duration_seconds AS session_duration_seconds,
           s.login_at AS session_login_at,
           s.logout_at AS session_logout_at
      FROM audit_logs l
      LEFT JOIN user_sessions s ON l.session_id = s.session_id
     WHERE {$whereClause}
     ORDER BY l.created_at DESC, l.log_id DESC
     LIMIT :lim OFFSET :off
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$rawLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logs = [];
foreach ($rawLogs as $r) {
    $meta = null;
    if (!empty($r['metadata_json'])) {
        $meta = json_decode($r['metadata_json'], true);
    }

    $durSec = (int) ($r['session_duration_seconds'] ?? 0);
    $durFormatted = null;
    if ($durSec > 0) {
        $hrs = (int)($durSec / 3600);
        $mins = (int)(($durSec % 3600) / 60);
        $durFormatted = $hrs > 0 ? "{$hrs}h {$mins}m" : "{$mins}m";
    }

    $logs[] = [
        'log_id'            => (int) $r['log_id'],
        'session_id'        => audit_public_session_id($r['session_id']),
        'user_id'           => $r['user_id'] !== null ? (int) $r['user_id'] : null,
        'username'          => $r['username_snapshot'],
        'role'              => strtolower($r['user_role_snapshot']),
        'category'          => $r['category'],
        'action'            => $r['action'],
        'description'       => $r['description'],
        'target_type'       => $r['target_type'],
        'target_id'         => $r['target_id'],
        'metadata'          => $meta,
        'ip_address'        => $r['ip_address'],
        'created_at'        => $r['created_at'],
        'relative_time'     => audit_format_relative_time($r['created_at']),
        'session_duration'  => $durFormatted,
        'duration_seconds'  => $durSec
    ];
}

// -----------------------------------------------------------------------------
// Live Session & KPI Metrics
// -----------------------------------------------------------------------------
// Online users: active heartbeat within last 120 seconds and no logout
$onlineStmt = $pdo->query("
    SELECT s.user_id, u.username, u.full_name, u.role, s.ip_address, s.login_at, s.last_heartbeat_at,
           TIMESTAMPDIFF(SECOND, s.login_at, NOW(3)) AS current_duration_seconds
      FROM user_sessions s
      LEFT JOIN users u ON s.user_id = u.user_id
     WHERE s.logout_at IS NULL
       AND s.last_heartbeat_at >= DATE_SUB(NOW(3), INTERVAL 120 SECOND)
     ORDER BY s.last_heartbeat_at DESC
");
$onlineUsersRaw = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);

$onlineUsers = [];
foreach ($onlineUsersRaw as $ou) {
    $cSec = (int)$ou['current_duration_seconds'];
    $h = (int)($cSec / 3600);
    $m = (int)(($cSec % 3600) / 60);
    $onlineUsers[] = [
        'user_id'          => (int) $ou['user_id'],
        'username'         => $ou['username'] ?? 'unknown',
        'full_name'        => $ou['full_name'] ?? 'Staff Member',
        'role'             => strtolower($ou['role'] ?? 'barista'),
        'ip_address'       => $ou['ip_address'],
        'login_at'         => $ou['login_at'],
        'duration_formatted' => $h > 0 ? "{$h}h {$m}m" : "{$m}m",
        'duration_seconds'   => $cSec
    ];
}

// Sessions today
$todaySessionsStmt = $pdo->query("SELECT COUNT(*) FROM user_sessions WHERE DATE(login_at) = CURDATE()");
$totalSessionsToday = (int) $todaySessionsStmt->fetchColumn();

// Actions today
$todayActionsStmt = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at) = CURDATE()");
$totalActionsToday = (int) $todayActionsStmt->fetchColumn();

// Average session duration today (seconds)
$avgDurStmt = $pdo->query("
    SELECT AVG(duration_seconds) 
      FROM user_sessions 
     WHERE DATE(login_at) = CURDATE() 
       AND duration_seconds > 0
");
$avgDurationSec = (int) ($avgDurStmt->fetchColumn() ?: 0);
$avgHours = (int)($avgDurationSec / 3600);
$avgMins = (int)(($avgDurationSec % 3600) / 60);
$avgDurationFormatted = $avgHours > 0 ? "{$avgHours}h {$avgMins}m" : "{$avgMins}m";

// Category Breakdown today
$catStmt = $pdo->query("
    SELECT category, COUNT(*) as cnt
      FROM audit_logs
     WHERE DATE(created_at) = CURDATE()
     GROUP BY category
");
$catBreakdown = [];
while ($cr = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $catBreakdown[$cr['category']] = (int) $cr['cnt'];
}

// Available staff list for filter dropdown
$staffStmt = $pdo->query("SELECT user_id, username, full_name, role FROM users ORDER BY role ASC, full_name ASC");
$staffMembers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'pagination' => [
        'total'  => $totalFiltered,
        'limit'  => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $totalFiltered
    ],
    'metrics' => [
        'online_count'         => count($onlineUsers),
        'online_users'         => $onlineUsers,
        'sessions_today'       => $totalSessionsToday,
        'actions_today'        => $totalActionsToday,
        'avg_duration_today'   => $avgDurationFormatted,
        'avg_duration_seconds' => $avgDurationSec,
        'category_breakdown'   => $catBreakdown
    ],
    'staff_options' => array_map(function($s) {
        return [
            'user_id'   => (int)$s['user_id'],
            'username'  => $s['username'],
            'full_name' => $s['full_name'],
            'role'      => strtolower($s['role'])
        ];
    }, $staffMembers),
    'logs' => $logs
]);
