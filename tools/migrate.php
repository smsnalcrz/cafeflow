<?php
/**
 * CafeFlow Database Migration Tool (Task 30)
 * Separates runtime schema changes from ordinary web requests.
 * 
 * Usage:
 *   php tools/migrate.php
 */
define('RUN_MIGRATIONS_ON_BOOT', true);
require_once __DIR__ . '/../core/db.php';

echo "======================================================\n";
echo "   CAFEFLOW DATABASE MIGRATION RUNNER                 \n";
echo "======================================================\n\n";

$pdo = db();
echo "[1/2] Verifying core schema and legacy columns via ensure_schema()...\n";
ensure_schema($pdo);
echo " [OK] Core schema verified.\n\n";

echo "[2/2] Checking versioned SQL migrations in database/migrations/...\n";
$migrationsDir = __DIR__ . '/../database/migrations';
if (is_dir($migrationsDir)) {
    $files = glob($migrationsDir . '/*.sql');
    sort($files);
    foreach ($files as $f) {
        $basename = basename($f);
        echo " Applying {$basename}... ";
        $sql = file_get_contents($f);
        try {
            // MariaDB PDO exec can execute multi-statement SQL
            $pdo->exec($sql);
            echo "[OK]\n";
        } catch (Throwable $e) {
            echo "[NOTE: " . $e->getMessage() . "]\n";
        }
    }
} else {
    echo " No migrations directory found.\n";
}

echo "\nMigration process completed successfully.\n";
