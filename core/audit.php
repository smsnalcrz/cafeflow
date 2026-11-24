<?php
/**
 * CafeFlow - Central Audit Logging & Activity Trail Subsystem
 *
 * Provides thread-safe, non-blocking append-only audit logging for
 * user sessions, tab navigation, security events, catalog changes,
 * inventory adjustments, and order operations.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_metadata.php';
require_once __DIR__ . '/auth.php';

function audit_public_session_id(?string $value): ?string
{
    // Legacy rows may still exist while the data upgrade is pending.
    return $value !== null && preg_match('/^aud_[0-9a-f]{32}$/D', $value) ? $value : null;
}

/**
 * Records an immutable audit log entry into `audit_logs`.
 *
 * @param string      $category     One of: navigation, pos_order, inventory, catalog, account, security, system
 * @param string      $action       Action identifier (e.g. order_checkout, stock_restock, tab_view)
 * @param string      $description  Human-readable description of the action
 * @param string|null $targetType   Entity type affected (e.g. transaction, inventory_item, user, product)
 * @param string|null $targetId     Entity ID affected
 * @param array|null  $metadata     Arbitrary contextual key-value payload (automatically sanitized)
 * @param PDO|null    $existingPdo  Optional existing PDO instance
 * @param array|null  $actorUser    Optional user array if current_user() is unavailable
 */
if (!function_exists('audit_log')) {
    function audit_log(
        string $category,
        string $action,
        string $description,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $metadata = null,
        ?PDO $existingPdo = null,
        ?array $actorUser = null
    ): void {
        try {
            $validCategories = ['navigation', 'pos_order', 'inventory', 'catalog', 'account', 'security', 'system'];
            if (!in_array($category, $validCategories, true)) {
                $category = 'system';
            }

            $pdo = $existingPdo ?: db();
            $user = $actorUser ?: current_user();

            $userId = null;
            $username = 'system';
            $role = 'system';

            if ($user && !empty($user['user_id'])) {
                $userId = (int) $user['user_id'];
                $username = $user['username'] ?? 'unknown';
                $role = $user['role'] ?? 'unknown';
            } elseif (!empty($_SESSION['user_id'])) {
                $userId = (int) $_SESSION['user_id'];
                $username = $_SESSION['username'] ?? 'unknown';
                $role = $_SESSION['user_role'] ?? 'unknown';
            }

            $sessionId = $_SESSION['audit_tracking_id'] ?? null;
            $ip = function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

            $stmt = $pdo->prepare('
                INSERT INTO audit_logs (
                    session_id, user_id, username_snapshot, user_role_snapshot,
                    category, action, description, target_type, target_id,
                    metadata_json, ip_address, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))
            ');
            $stmt->execute([
                $sessionId,
                $userId,
                substr($username, 0, 50),
                substr($role, 0, 20),
                $category,
                substr($action, 0, 64),
                substr($description, 0, 255),
                $targetType ? substr($targetType, 0, 50) : null,
                $targetId !== null ? substr((string)$targetId, 0, 64) : null,
                audit_metadata_json($metadata),
                substr($ip, 0, 45)
            ]);
        } catch (Throwable $e) {
            // Task 09: Logging failure must never crash core POS operations,
            // but must be securely captured in an append-only durable fallback outbox
            $fallbackFile = audit_fallback_filepath();
            $fallbackPayload = [
                'session_id'         => $sessionId ?? null,
                'user_id'            => $userId ?? null,
                'username_snapshot'  => substr($username ?? 'unknown', 0, 50),
                'user_role_snapshot' => substr($role ?? 'unknown', 0, 20),
                'category'           => $category,
                'action'             => substr($action, 0, 64),
                'description'        => substr($description, 0, 255),
                'target_type'        => $targetType ? substr($targetType, 0, 50) : null,
                'target_id'          => $targetId !== null ? substr((string)$targetId, 0, 64) : null,
                'metadata_json'      => audit_metadata_json($metadata),
                'ip_address'         => substr($ip ?? '127.0.0.1', 0, 45),
                'created_at'         => date('Y-m-d H:i:s'),
                'failure_error'      => substr($e->getMessage(), 0, 255)
            ];
            @file_put_contents(
                $fallbackFile,
                json_encode($fallbackPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
            error_log('[CafeFlow Audit Log Fallback] Preserved audit record in fallback outbox: action=' . $action . ' error=' . $e->getMessage());
        }
    }
}

/**
 * Returns absolute path to the audit durable fallback outbox.
 */
function audit_fallback_filepath(): string
{
    return __DIR__ . '/../database/audit_fallback.jsonl';
}

/**
 * Recovers and flushes pending audit records from the fallback outbox into audit_logs.
 *
 * @param PDO|null $pdo Database connection handle
 * @return int Number of records successfully recovered
 */
function audit_flush_fallback_records(?PDO $pdo = null): int
{
    $fallbackFile = audit_fallback_filepath();
    if (!file_exists($fallbackFile) || filesize($fallbackFile) === 0) {
        return 0;
    }

    $db = $pdo ?: db();
    $fh = @fopen($fallbackFile, 'c+');
    if (!$fh) {
        return 0;
    }

    $flushed = 0;
    try {
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return 0;
        }

        $lines = [];
        rewind($fh);
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if (empty($lines)) {
            ftruncate($fh, 0);
            flock($fh, LOCK_UN);
            fclose($fh);
            return 0;
        }

        $stmt = $db->prepare('
            INSERT INTO audit_logs (
                session_id, user_id, username_snapshot, user_role_snapshot,
                category, action, description, target_type, target_id,
                metadata_json, ip_address, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $remaining = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            try {
                $stmt->execute([
                    $row['session_id'] ?? null,
                    $row['user_id'] ?? null,
                    $row['username_snapshot'] ?? 'system',
                    $row['user_role_snapshot'] ?? 'system',
                    $row['category'] ?? 'system',
                    $row['action'] ?? 'unknown',
                    $row['description'] ?? '',
                    $row['target_type'] ?? null,
                    $row['target_id'] ?? null,
                    $row['metadata_json'] ?? '{}',
                    $row['ip_address'] ?? '127.0.0.1',
                    $row['created_at'] ?? date('Y-m-d H:i:s')
                ]);
                $flushed++;
            } catch (Throwable $insErr) {
                // Keep unrecoverable rows in outbox for subsequent retry
                $remaining[] = $line;
            }
        }

        rewind($fh);
        ftruncate($fh, 0);
        if (!empty($remaining)) {
            fwrite($fh, implode("\n", $remaining) . "\n");
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        error_log('[CafeFlow Audit Flush Error] ' . $e->getMessage());
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    return $flushed;
}

/**
 * Returns count of unrecovered audit fallback outbox records.
 */
function audit_fallback_pending_count(): int
{
    $fallbackFile = audit_fallback_filepath();
    if (!file_exists($fallbackFile) || filesize($fallbackFile) === 0) {
        return 0;
    }
    $count = 0;
    $fh = @fopen($fallbackFile, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($fh);
    }
    return $count;
}
