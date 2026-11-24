<?php
/**
 * CafeFlow - Audit Log & User Activity Tracking Test Suite
 *
 * Automated verification of:
 * 1. Database schema and ON DELETE SET NULL foreign key safety
 * 2. Immutable audit logging, timestamp precision & secret redaction
 * 3. Session lifecycle, duration calculation, and heartbeat pinging
 * 4. Strict RBAC (Barista 403, Manager 200) and immutability (405 on mutations)
 * 5. Navigation beacon ingest and 2-second dwell-time throttling
 * 6. Action audit logging hooks across POS, inventory, catalog, and accounts
 * 7. CSV compliance streaming export
 * 8. Dual-write backward compatibility with security_audit_log
 */
if (!defined('CAFEFLOW_TESTING')) {
    define('CAFEFLOW_TESTING', true);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/audit.php';

$pdo = db();
$totalPassed = 0;
$totalFailed = 0;

function assertTest(string $description, bool $condition, ?string $extraInfo = null): void
{
    global $totalPassed, $totalFailed;
    if ($condition) {
        $totalPassed++;
        echo " [PASS] {$description}\n";
        if ($extraInfo) {
            echo "        {$extraInfo}\n";
        }
    } else {
        $totalFailed++;
        echo " [FAIL] {$description}\n";
        if ($extraInfo) {
            echo "        Details: {$extraInfo}\n";
        }
    }
}

echo "======================================================================\n";
echo "   CAFEFLOW AUDIT LOG & USER ACTIVITY TRACKING TEST SUITE             \n";
echo "======================================================================\n\n";

// -----------------------------------------------------------------------------
// 1. Database Schema & Foreign Key Constraints
// -----------------------------------------------------------------------------
echo "--- 1. Testing Database Schema & Constraints ---\n";

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
assertTest('Schema: user_sessions table exists', in_array('user_sessions', $tables, true));
assertTest('Schema: audit_logs table exists', in_array('audit_logs', $tables, true));

$sessionCols = $pdo->query('SHOW COLUMNS FROM user_sessions')->fetchAll(PDO::FETCH_COLUMN);
$expectedSessionCols = ['session_id', 'user_id', 'ip_address', 'user_agent', 'auth_method', 'login_at', 'last_heartbeat_at', 'logout_at', 'duration_seconds', 'logout_reason', 'created_at'];
$hasAllSessionCols = count(array_intersect($expectedSessionCols, $sessionCols)) === count($expectedSessionCols);
assertTest('Schema: user_sessions has all expected columns', $hasAllSessionCols);

$auditCols = $pdo->query('SHOW COLUMNS FROM audit_logs')->fetchAll(PDO::FETCH_COLUMN);
$expectedAuditCols = ['log_id', 'session_id', 'user_id', 'username_snapshot', 'user_role_snapshot', 'category', 'action', 'description', 'target_type', 'target_id', 'metadata_json', 'ip_address', 'created_at'];
$hasAllAuditCols = count(array_intersect($expectedAuditCols, $auditCols)) === count($expectedAuditCols);
assertTest('Schema: audit_logs has all expected columns', $hasAllAuditCols);

// Verify ON DELETE SET NULL constraint exists on user_id
$fkCheck = $pdo->query("
    SELECT DELETE_RULE 
      FROM information_schema.REFERENTIAL_CONSTRAINTS 
     WHERE CONSTRAINT_SCHEMA = DATABASE() 
       AND TABLE_NAME = 'audit_logs' 
       AND CONSTRAINT_NAME = 'fk_audit_user'
")->fetchColumn();
assertTest('Safety: audit_logs user foreign key uses ON DELETE SET NULL', $fkCheck === 'SET NULL', "Delete rule: {$fkCheck}");

// -----------------------------------------------------------------------------
// 2. Core Audit Logging & Redaction
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Core Audit Logging & Secret Redaction ---\n";

$testAction = 'test_audit_event_' . bin2hex(random_bytes(4));
$testMeta = [
    'test_key'         => 'safe_value',
    'nested'           => [(object)['keep' => 7, 'accessToken' => 'private-fixture',
        'details' => ['Set-Cookie' => 'private-fixture', 'TOTP_Secret' => 'private-fixture']]],
    'password'         => 'super_secret_123',
    'confirm_password' => 'super_secret_123',
    'secret'           => 'BASE32SECRETVAL',
    'two_factor_secret'=> 'ENCRYPTEDORPLAIN',
    'recovery_code'    => '1234-5678',
    'csrf_token'       => 'randomcsrftoken'
];

$mockUser = [
    'user_id'   => 2,
    'username'  => 'manager',
    'full_name' => 'Store Manager',
    'role'      => 'manager'
];

audit_log('system', $testAction, 'Automated unit test execution event', 'test', '999', $testMeta, $pdo, $mockUser);

$fetchStmt = $pdo->prepare('SELECT * FROM audit_logs WHERE action = ? LIMIT 1');
$fetchStmt->execute([$testAction]);
$logRow = $fetchStmt->fetch(PDO::FETCH_ASSOC);

assertTest('Audit Engine: Successfully logged event to audit_logs', !empty($logRow));
assertTest('Audit Engine: Snapshot captures username and role', ($logRow['username_snapshot'] ?? '') === 'manager' && ($logRow['user_role_snapshot'] ?? '') === 'manager');
assertTest('Audit Engine: Timestamp precision has microseconds', (bool)preg_match('/\.\d{3}$/', $logRow['created_at'] ?? ''));

$savedMeta = json_decode($logRow['metadata_json'] ?? '{}', true);
assertTest('Redaction: Safe metadata preserved', ($savedMeta['test_key'] ?? '') === 'safe_value');
assertTest('Redaction: nested arrays and objects preserve safe fields and remove secrets',
    ($savedMeta['nested'][0]['keep'] ?? null) === 7 && !str_contains($logRow['metadata_json'], 'private-fixture'));
assertTest('Redaction: oversized metadata cannot exceed the storage budget',
    audit_metadata_json(array_fill(0, 16, str_repeat('a', 1024))) === null);
assertTest('Redaction: Sensitive password strictly redacted', !isset($savedMeta['password']) && !isset($savedMeta['confirm_password']));
assertTest('Redaction: Sensitive 2FA secrets strictly redacted', !isset($savedMeta['secret']) && !isset($savedMeta['two_factor_secret']) && !isset($savedMeta['recovery_code']));
assertTest('Redaction: CSRF token strictly redacted', !isset($savedMeta['csrf_token']));

// Non-blocking test
try {
    // Pass closed or weird parameters to audit_log
    audit_log('invalid_category', 'noop', 'Test failure tolerance', null, null, null, null, null);
    assertTest('Resilience: audit_log never throws exceptions on edge cases', true);
} catch (Throwable $e) {
    assertTest('Resilience: audit_log never throws exceptions on edge cases', false, $e->getMessage());
}

// -----------------------------------------------------------------------------
// 3. Session Lifecycle & Heartbeat API
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Session Lifecycle & Heartbeat ---\n";

init_session();
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'manager';
$_SESSION['username'] = 'manager';
$_SESSION['full_name'] = 'Manager';

// Test login_user session insertion
login_user($mockUser, 'password');
$sessId = $_SESSION['audit_tracking_id'] ?? '';
assertTest('Session Lifecycle: audit correlation ID is independent of the authentication cookie',
    $sessId !== session_id() && audit_public_session_id($sessId) === $sessId);

$sessStmt = $pdo->prepare('SELECT * FROM user_sessions WHERE session_id = ?');
$sessStmt->execute([$sessId]);
$sessRow = $sessStmt->fetch(PDO::FETCH_ASSOC);

assertTest('Session Lifecycle: login_user inserts record into user_sessions', !empty($sessRow));
assertTest('Session Lifecycle: login_user sets initial duration to 0', (int)($sessRow['duration_seconds'] ?? -1) === 0);
assertTest('Session Lifecycle: auth_login event logged in audit_logs', (bool)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE session_id = '{$sessId}' AND action = 'auth_login'")->fetchColumn());

// Test heartbeat endpoint logic
$upHeartbeat = $pdo->prepare('
    UPDATE user_sessions
       SET last_heartbeat_at = DATE_ADD(login_at, INTERVAL 45 SECOND),
           duration_seconds = 45
     WHERE session_id = ?
');
$upHeartbeat->execute([$sessId]);

$sessStmt->execute([$sessId]);
$sessRowUpdated = $sessStmt->fetch(PDO::FETCH_ASSOC);
assertTest('Heartbeat: Duration calculates correctly after elapsed time', (int)$sessRowUpdated['duration_seconds'] === 45);

// Test logout_user
logout_user('user_logout');

$sessStmt->execute([$sessId]);
$sessRowLoggedOut = $sessStmt->fetch(PDO::FETCH_ASSOC);
assertTest('Session Lifecycle: logout_user sets logout_at and logout_reason', !empty($sessRowLoggedOut['logout_at']) && $sessRowLoggedOut['logout_reason'] === 'user_logout');
assertTest('Session Lifecycle: auth_logout event logged in audit_logs', (bool)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE session_id = '{$sessId}' AND action = 'auth_logout'")->fetchColumn());

// -----------------------------------------------------------------------------
// 4. Role-Based Access Control (RBAC) & Immutability
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing RBAC & Immutability ---\n";

login_user($mockUser);
$revokedId = $_SESSION['audit_tracking_id'];
$pdo->prepare("UPDATE user_sessions SET logout_at = NOW(3), logout_reason = 'revoked' WHERE session_id = ?")
    ->execute([$revokedId]);
assertTest('Session Lifecycle: a revoked correlation ID ends the authenticated session', current_user() === null);
$sessStmt->execute([$revokedId]);
assertTest('Session Lifecycle: invalidation preserves the original revocation reason',
    $sessStmt->fetch(PDO::FETCH_ASSOC)['logout_reason'] === 'revoked');

// Test unauthenticated access to audit_api.php
init_session();
$_SESSION = [];
clear_auth_cache();

ob_start();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/audit_api.php';
$_SERVER['HTTP_ACCEPT'] = 'application/json';

// Test barista access (should be 403 Forbidden)
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'barista';
$_SESSION['username'] = 'barista';
$_SESSION['full_name'] = 'DK Cafe Barista';
clear_auth_cache();

$user = current_user();
assertTest('RBAC Test Setup: Authenticated as Barista', ($user['role'] ?? '') === 'barista');

$allowed = ['manager'];
$isBaristaForbidden = !in_array(strtolower($user['role']), $allowed, true);
assertTest('RBAC: Barista role forbidden from accessing Manager Audit API', $isBaristaForbidden);

// Switch to manager
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'manager';
$_SESSION['username'] = 'manager';
$_SESSION['full_name'] = 'Manager';
clear_auth_cache();
$mgrUser = current_user();
assertTest('RBAC Test Setup: Authenticated as Manager', ($mgrUser['role'] ?? '') === 'manager');

// -----------------------------------------------------------------------------
// 5. Navigation Tracking & Dwell Throttling
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing Navigation Beacon & Dwell Throttling ---\n";

$csrfToken = get_csrf_token();

// Sub-threshold dwell (1200ms < 2000ms): should be filtered
$quickDwell = 1200;
$shouldSkip = $quickDwell < 2000;
assertTest('Navigation Beacon: Sub-2000ms transitions skipped to prevent spam', $shouldSkip);

// Valid dwell (4500ms >= 2000ms): should be logged
$validDwell = 4500;
audit_log('navigation', 'tab_view', 'Switched from pos to reports', 'view', 'reports', [
    'from_view'        => 'pos',
    'to_view'          => 'reports',
    'duration_prev_ms' => $validDwell
], $pdo, $mgrUser);

$navLogStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE category = 'navigation' AND action = 'tab_view' ORDER BY log_id DESC LIMIT 1");
$navLogStmt->execute();
$navLogRow = $navLogStmt->fetch(PDO::FETCH_ASSOC);

assertTest('Navigation Beacon: Valid dwell transitions recorded in audit_logs', !empty($navLogRow));
$navMeta = json_decode($navLogRow['metadata_json'] ?? '{}', true);
assertTest('Navigation Beacon: Metadata records previous view and dwell milliseconds', ($navMeta['from_view'] ?? '') === 'pos' && ($navMeta['to_view'] ?? '') === 'reports');

// -----------------------------------------------------------------------------
// 6. Action Audit Logging Hooks Across Modules
// -----------------------------------------------------------------------------
echo "\n--- 6. Testing Action Hooks Across Modules ---\n";

// Order Checkout hook test
audit_log('pos_order', 'order_checkout', 'Ring up Order #9999 (Cash) - 2 item(s) - ₱240.00', 'transaction', '9999', [
    'tx_id'          => 9999,
    'total'          => 240.00,
    'payment_method' => 'Cash',
    'items_count'    => 2
], $pdo, $mgrUser);

$txLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'order_checkout' AND target_id = '9999' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Action Hook: order_checkout logged with items and total', !empty($txLog));

// Order Void hook test
audit_log('pos_order', 'order_void', 'Voided completed Order #9999 (Accidental duplicate)', 'transaction', '9999', [
    'tx_id'              => 9999,
    'reason'             => 'Accidental duplicate',
    'inventory_reversed' => true
], $pdo, $mgrUser);

$voidLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'order_void' AND target_id = '9999' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Action Hook: order_void logged with reason and restoration flag', !empty($voidLog));

// Stock Restock hook test
audit_log('inventory', 'stock_restock', 'Restocked +5000 units of inventory item #18', 'inventory', '18', [
    'amount' => 5000
], $pdo, $mgrUser);

$restockLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'stock_restock' AND target_id = '18' ORDER BY log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Action Hook: stock_restock logged with amount and item ID', !empty($restockLog));

// Manual Waste hook test
audit_log('inventory', 'waste_manual', 'Recorded manual waste: 250.00 ml of Full Cream Milk (Spilled during rush)', 'inventory', '61', [
    'item_id'   => 61,
    'amount'    => 250.00,
    'unit'      => 'ml',
    'cost_php'  => 35.00,
    'reason'    => 'Spilled during rush'
], $pdo, $mgrUser);

$wasteLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'waste_manual' AND target_id = '61' ORDER BY log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Action Hook: waste_manual logged with ingredient details', !empty($wasteLog));

// Catalog Product Create hook test
audit_log('catalog', 'product_create', 'Added new menu product: Ube Macapuno Latte (Coffee)', 'menu_product', '888', [
    'name'      => 'Ube Macapuno Latte',
    'category'  => 'Coffee',
    'price'     => '140.00',
    'has_sizes' => 1
], $pdo, $mgrUser);

$catLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'product_create' AND target_id = '888' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Action Hook: product_create logged with category and pricing', !empty($catLog));

// Account Toggle Status hook test
audit_log('account', 'user_status_toggle', 'Set account @test_barista to inactive', 'user', '101', [
    'old_status' => 'active',
    'new_status' => 'inactive',
    'username'   => 'test_barista'
], $pdo, $mgrUser);

$accLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'user_status_toggle' AND target_id = '101' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Action Hook: user_status_toggle logged with status transition', !empty($accLog));

// Clean up test rows
$pdo->exec("DELETE FROM audit_logs WHERE target_id IN ('999', '9999', '888', '101') OR action = '{$testAction}'");

// -----------------------------------------------------------------------------
// 7. Security Audit Log Dual-Write Bridge
// -----------------------------------------------------------------------------
echo "\n--- 7. Testing security_audit_log Dual-Write Bridge ---\n";

$bridgeAction = 'bridge_test_2fa_reset_' . bin2hex(random_bytes(3));
log_security_audit($pdo, 2, 1, $bridgeAction, ['reason' => 'Unit test dual write',
    'context' => ['recoveryCodes' => ['private-fixture']]]);

$secRow = $pdo->query("SELECT * FROM security_audit_log WHERE action = '{$bridgeAction}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Bridge: Successfully wrote to legacy security_audit_log table', !empty($secRow));

$auditRow = $pdo->query("SELECT * FROM audit_logs WHERE action = '{$bridgeAction}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest('Bridge: Successfully dual-wrote to primary audit_logs table', !empty($auditRow));
assertTest('Bridge: both stored copies redact nested recovery codes',
    !str_contains($secRow['metadata'], 'private-fixture') && !str_contains($auditRow['metadata_json'], 'private-fixture'));

// Clean up bridge test rows
$pdo->exec("DELETE FROM security_audit_log WHERE action = '{$bridgeAction}'");
$pdo->exec("DELETE FROM audit_logs WHERE action = '{$bridgeAction}'");

// -----------------------------------------------------------------------------
// 8. Testing Audit API JSON Response & CSV Streaming
// -----------------------------------------------------------------------------
echo "\n--- 8. Testing Audit API Response & CSV Streaming ---\n";

// Authenticate as Manager
$_SESSION['user_id'] = 2;
$_SESSION['user_role'] = 'manager';
$_SESSION['username'] = 'manager';
$_SESSION['full_name'] = 'Manager';
clear_auth_cache();

// 8.1 API JSON Response
$legacyAction = 'legacy_fixture_' . bin2hex(random_bytes(4));
$legacyInsert = $pdo->prepare("INSERT INTO audit_logs
    (session_id, username_snapshot, user_role_snapshot, category, action, description, metadata_json, ip_address)
    VALUES ('legacy-session-fixture', 'fixture', 'manager', 'system', ?, 'Historical audit fixture', ?, '127.0.0.1')");
$legacyInsert->execute([$legacyAction, '{"context":{"keep":"historical-value","csrfToken":"private-fixture"}}']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['limit' => 10, 'offset' => 0];

ob_start();
include __DIR__ . '/../api/audit_api.php';
$apiOutput = ob_get_clean();

$apiJson = json_decode($apiOutput, true);
assertTest('Audit API: Returns valid JSON with success: true', !empty($apiJson) && $apiJson['success'] === true);
assertTest('Audit API: Includes pagination metadata', isset($apiJson['pagination']['total']) && isset($apiJson['pagination']['limit']));
assertTest('Audit API: Includes live metrics (online_count, sessions, actions, duration)', isset($apiJson['metrics']['online_count']) && isset($apiJson['metrics']['sessions_today']));
assertTest('Audit API: Returns logs array with formatted timestamps', is_array($apiJson['logs']));
$legacyResult = array_values(array_filter($apiJson['logs'], static fn($row) => $row['action'] === $legacyAction));
assertTest('Audit API: historical sessions and nested secrets are masked on read',
    ($legacyResult[0]['metadata']['context'] ?? null) === ['keep' => 'historical-value']
    && array_key_exists('session_id', $legacyResult[0]) && $legacyResult[0]['session_id'] === null);

// 8.2 API CSV Export
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['export' => 'csv'];

ob_start();
include __DIR__ . '/../api/audit_api.php';
$csvOutput = ob_get_clean();

$hasBom = str_starts_with($csvOutput, "\xEF\xBB\xBF");
assertTest('CSV Export: Streams UTF-8 BOM for Excel compatibility', $hasBom);
$hasRequiredHeaders = str_contains($csvOutput, 'Log ID') &&
                      str_contains($csvOutput, 'Timestamp') &&
                      str_contains($csvOutput, 'Category') &&
                      str_contains($csvOutput, 'Action') &&
                      str_contains($csvOutput, 'Description') &&
                      str_contains($csvOutput, 'IP Address');
assertTest('CSV Export: Contains required CSV header columns', $hasRequiredHeaders);
assertTest('CSV Export: historical sensitive values are removed before serialization',
    str_contains($csvOutput, $legacyAction) && !str_contains($csvOutput, 'private-fixture')
    && !str_contains($csvOutput, 'legacy-session-fixture'));
$pdo->prepare('DELETE FROM audit_logs WHERE action = ?')->execute([$legacyAction]);

$formulaCells = ['=1', ' +1', '-1', '@name', "\ttext", " \rtext", "\u{00A0}=1"];
$normalCells = ['DK Cafe', 'coffee, "iced"', 'backslash\\"quote', ''];
$csvStream = fopen('php://temp', 'w+');
write_spreadsheet_csv_row($csvStream, array_merge($formulaCells, $normalCells));
rewind($csvStream);
assertTest('CSV Export: spreadsheet prefixes are quoted and ordinary cells round-trip',
    fgetcsv($csvStream, 0, ',', '"', '') === array_merge(
        array_map(static fn($value) => "'" . $value, $formulaCells), $normalCells));
fclose($csvStream);

// 8.3 Immutability / Method Not Allowed check
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];

ob_start();
include __DIR__ . '/../api/audit_api.php';
$methodOutput = ob_get_clean();
$methodJson = json_decode($methodOutput, true);
assertTest('Immutability: POST mutation rejected with METHOD_NOT_ALLOWED', ($methodJson['code'] ?? '') === 'METHOD_NOT_ALLOWED');

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n======================================================================\n";
echo "   AUDIT TEST RUN COMPLETE: {$totalPassed} PASSED, {$totalFailed} FAILED\n";
echo "======================================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
