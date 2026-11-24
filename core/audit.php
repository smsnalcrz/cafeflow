<?php
/**
 * CafeFlow - Central Audit Logging & Activity Trail Subsystem
 *
 * Provides thread-safe, non-blocking append-only audit logging for
 * user sessions, tab navigation, security events, catalog changes,
 * inventory adjustments, and order operations.
 */
require_once __DIR__ . '/db.php';
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

            // Redact any sensitive passwords or secrets from metadata
            if (is_array($metadata)) {
                unset(
                    $metadata['password'],
                    $metadata['new_password'],
                    $metadata['confirm_password'],
                    $metadata['secret'],
                    $metadata['two_factor_secret'],
                    $metadata['recovery_code'],
                    $metadata['recovery_codes'],
                    $metadata['csrf_token']
                );
            }

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
                $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                substr($ip, 0, 45)
            ]);
        } catch (Throwable $e) {
            // Logging failure must never crash core POS operations
            error_log('[CafeFlow Audit Log Failure] ' . $e->getMessage());
        }
    }
}
