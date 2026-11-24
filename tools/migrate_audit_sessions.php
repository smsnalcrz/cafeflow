<?php
declare(strict_types=1);

// Run offline with DB_NAME explicitly set, after taking a protected backup.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!getenv('DB_NAME')) {
    fwrite(STDERR, "Set DB_NAME to the installation to upgrade.\n");
    exit(1);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/audit_metadata.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("CREATE TEMPORARY TABLE audit_id_map (
        old_id VARCHAR(64) PRIMARY KEY, new_id VARCHAR(36) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->beginTransaction();
    $map = $pdo->prepare("INSERT INTO audit_id_map
        SELECT session_id, CONCAT('aud_', LEFT(SHA2(CONCAT(?, session_id), 256), 32))
        FROM (SELECT session_id FROM user_sessions UNION SELECT session_id FROM audit_logs) ids
        WHERE session_id IS NOT NULL AND BINARY session_id NOT REGEXP '^aud_[0-9a-f]{32}$'");
    $map->execute([bin2hex(random_bytes(32))]);
    $logs = $pdo->exec("UPDATE audit_logs l JOIN audit_id_map m ON m.old_id = l.session_id
                        SET l.session_id = m.new_id");
    $sessions = $pdo->exec("UPDATE user_sessions s JOIN audit_id_map m ON m.old_id = s.session_id
        SET s.session_id = m.new_id,
            s.logout_reason = IF(s.logout_at IS NULL, 'revoked', s.logout_reason),
            s.logout_at = COALESCE(s.logout_at, s.last_heartbeat_at)");
    $redacted = 0;
    foreach ([['audit_logs', 'log_id', 'metadata_json'], ['security_audit_log', 'audit_id', 'metadata']] as [$table, $id, $column]) {
        $cursor = 0;
        $read = $pdo->prepare("SELECT $id, IF(OCTET_LENGTH($column) <= 65536, $column, NULL) AS payload FROM $table
            WHERE $id > ? AND $column IS NOT NULL ORDER BY $id LIMIT 100 FOR UPDATE");
        $write = $pdo->prepare("UPDATE $table SET $column = ? WHERE $id = ?");
        do {
            $read->execute([$cursor]);
            $rows = $read->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cursor = $row[$id];
                $clean = audit_stored_metadata($row['payload']);
                if ($clean !== null && json_encode(json_decode($clean)) === json_encode(json_decode($row['payload']))) continue;
                $write->execute([$clean, $cursor]);
                $redacted++;
            }
        } while (count($rows) === 100);
    }
    $pdo->commit();
    echo "Converted $logs events and $sessions sessions. Sanitized $redacted metadata records.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Audit session conversion failed (code " . $e->getCode() . ").\n");
    exit(1);
}
