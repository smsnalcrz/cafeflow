<?php
/**
 * CafeFlow - Verification Suite for Audit Resilience (Task 09),
 * Event Attribution (Task 11), and Mock Data Exclusion (Task 19).
 */
define('CAFEFLOW_TESTING', true);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/audit.php';

$pdo = db();
$pass = 0;
$fail = 0;

function assert_test(bool $cond, string $desc, ?string $detail = null): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo " [PASS] $desc\n";
    } else {
        $fail++;
        echo " [FAIL] $desc" . ($detail ? " ($detail)" : "") . "\n";
    }
}

echo "======================================================================\n";
echo "   CAFEFLOW AUDIT RESILIENCE & MOCK DATA EXCLUSION TEST SUITE         \n";
echo "======================================================================\n\n";

// --- 1. Audit Failure Fallback Outbox & Recovery (Task 09) ---
echo "--- 1. Testing Audit Failure Fallback Outbox & Recovery (Task 09) ---\n";
$fallbackPath = audit_fallback_filepath();
if (file_exists($fallbackPath)) {
    @unlink($fallbackPath);
}

// Create a disconnected/invalid mock PDO to simulate DB failure
class BrokenMockPdo extends PDO {
    public function __construct() {
        // do not call parent
    }
    public function prepare($statement, $options = []): PDOStatement {
        throw new PDOException("Simulated MariaDB server connection drop");
    }
}

$brokenPdo = new BrokenMockPdo();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'test_manager';
$_SESSION['user_role'] = 'manager';
$_SESSION['audit_tracking_id'] = 'aud_' . bin2hex(random_bytes(16));

// Call audit_log with broken PDO: must not throw, must write to fallback outbox
try {
    audit_log(
        'security',
        'test_failure_resilience',
        'Simulated critical audit event during DB outage',
        'system',
        '42',
        ['outage_test' => true],
        $brokenPdo
    );
    assert_test(true, "audit_log handles DB connection failure without uncaught exception");
} catch (Throwable $e) {
    assert_test(false, "audit_log threw uncaught exception on DB failure", $e->getMessage());
}

assert_test(file_exists($fallbackPath), "Durable fallback outbox file exists at $fallbackPath");
assert_test(audit_fallback_pending_count() === 1, "audit_fallback_pending_count() reports 1 pending record");

// Inspect fallback contents
$fbContent = file_get_contents($fallbackPath);
$fbJson = json_decode(trim($fbContent), true);
assert_test(is_array($fbJson) && ($fbJson['action'] ?? '') === 'test_failure_resilience', "Fallback record accurately preserved action and metadata");
assert_test(str_contains($fbJson['failure_error'] ?? '', 'Simulated MariaDB server connection drop'), "Fallback records diagnostic failure error");

// Now recover and flush fallback records with live PDO
$flushedCount = audit_flush_fallback_records($pdo);
assert_test($flushedCount === 1, "audit_flush_fallback_records() successfully flushed 1 record into database");
assert_test(audit_fallback_pending_count() === 0, "Fallback outbox pending count is now 0 after flush");

// Verify that the record actually landed in audit_logs
$checkStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = 'test_failure_resilience' ORDER BY log_id DESC LIMIT 1");
$checkStmt->execute();
$recoveredRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
assert_test($recoveredRow !== false, "Recovered record exists in audit_logs table");
assert_test($recoveredRow['description'] === 'Simulated critical audit event during DB outage', "Recovered record preserved exact description");

// Clean up test audit row
if ($recoveredRow) {
    $pdo->prepare("DELETE FROM audit_logs WHERE log_id = ?")->execute([$recoveredRow['log_id']]);
}

// --- 2. Testing Failed Login Audit Attribution (Task 11) ---
echo "\n--- 2. Testing Failed Login Audit Attribution (Task 11) ---\n";
$fakeIp = '192.168.10.99';
$fakeUser = 'non_existent_hacker_' . bin2hex(random_bytes(4));

record_login_attempt($fakeIp, $fakeUser, false, $pdo);

$failAuditStmt = $pdo->prepare("
    SELECT * FROM audit_logs 
     WHERE category = 'security' 
       AND action = 'auth_login_failed' 
       AND description LIKE ? 
     ORDER BY log_id DESC LIMIT 1
");
$failAuditStmt->execute(["%$fakeUser%"]);
$failAuditRow = $failAuditStmt->fetch(PDO::FETCH_ASSOC);

assert_test($failAuditRow !== false, "Failed login attempt recorded to security audit trail");
assert_test(str_contains($failAuditRow['description'] ?? '', $fakeUser), "Audit description includes targeted username");
$meta = json_decode($failAuditRow['metadata_json'] ?? '{}', true);
assert_test(($meta['ip'] ?? '') === $fakeIp, "Audit metadata includes attacker IP address");

// Clean up
$pdo->prepare("DELETE FROM login_attempts WHERE username = ?")->execute([$fakeUser]);
if ($failAuditRow) {
    $pdo->prepare("DELETE FROM audit_logs WHERE log_id = ?")->execute([$failAuditRow['log_id']]);
}

// --- 3. Testing Mock vs Genuine Data Exclusion (Task 19) ---
echo "\n--- 3. Testing Mock vs Genuine Data Exclusion (Task 19) ---\n";
// Insert a real and a mock test transaction separately to get exact IDs
$insReal = $pdo->prepare("
    INSERT INTO transactions (total_amount, amount_paid, change_due, status, payment_method, is_mock, `timestamp`)
    VALUES (999.00, 1000.00, 1.00, 'Completed', 'Cash', 0, NOW())
");
$insReal->execute();
$realTxId = (int)$pdo->lastInsertId();

$insMock = $pdo->prepare("
    INSERT INTO transactions (total_amount, amount_paid, change_due, status, payment_method, is_mock, `timestamp`)
    VALUES (8888.00, 8888.00, 0.00, 'Completed', 'Cash', 1, NOW())
");
$insMock->execute();
$mockTxId = (int)$pdo->lastInsertId();

// Simulate transactions_api.php listing default (exclude mock)
$listDefault = $pdo->query("SELECT transaction_id, total_amount FROM transactions WHERE transaction_id IN ($realTxId, $mockTxId) AND (is_mock = 0 OR is_mock IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
assert_test(count($listDefault) === 1 && (int)$listDefault[0]['transaction_id'] === $realTxId, "Default transaction query strictly excludes mock rows");

// Verify that include_mock returns both
$listAll = $pdo->query("SELECT transaction_id, total_amount FROM transactions WHERE transaction_id IN ($realTxId, $mockTxId)")->fetchAll(PDO::FETCH_ASSOC);
assert_test(count($listAll) === 2, "Explicit include_mock query returns both mock and genuine rows");

// Clean up test transactions
$pdo->prepare("DELETE FROM transactions WHERE transaction_id IN ($realTxId, $mockTxId)")->execute();

echo "\n======================================================================\n";
echo "   RESILIENCE & MOCK GUARD RUN COMPLETE: $pass PASSED, $fail FAILED   \n";
echo "======================================================================\n";
exit($fail > 0 ? 1 : 0);
