<?php
/**
 * tools/import_mock_sql.php
 * Safely streams and imports database/cafeflow_mock_data_realistic.sql
 * (or database/cafeflow_mock_data_1_year.sql) into MariaDB without exceeding
 * max_allowed_packet limits.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';

$customFile = $argv[1] ?? null;
if ($customFile && file_exists($customFile)) {
    $sqlFile = $customFile;
} else {
    // Prefer realistic mock data file, fallback to 1_year
    $candRealistic = __DIR__ . '/../database/cafeflow_mock_data_realistic.sql';
    $cand1Year = __DIR__ . '/../database/cafeflow_mock_data_1_year.sql';
    $sqlFile = file_exists($candRealistic) ? $candRealistic : $cand1Year;
}

if (!file_exists($sqlFile)) {
    die("Error: File $sqlFile does not exist. Run php database/seed_dk_realistic_data.php first.\n");
}

$fileSizeKb = round(filesize($sqlFile) / 1024, 1);
echo "Importing {$fileSizeKb} KB realistic mock dataset from:\n  " . basename($sqlFile) . "\n";
$start = microtime(true);

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($mysqli->connect_error) {
    die("MySQL Connection Error ({$mysqli->connect_errno}): {$mysqli->connect_error}\n");
}

$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 30);
$mysqli->set_charset('utf8mb4');

$handle = fopen($sqlFile, 'r');
if (!$handle) {
    die("Error opening SQL file: $sqlFile\n");
}

$mysqli->query("SET FOREIGN_KEY_CHECKS = 0");
$mysqli->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
$mysqli->query("SET time_zone = '+08:00'");

$buffer = '';
$stmtCount = 0;

while (($line = fgets($handle)) !== false) {
    $trimmed = trim($line);

    // Skip comments and blank lines outside buffer
    if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*'))) {
        continue;
    }

    $buffer .= $line;

    // A statement ends when a line ends with ';'
    if (str_ends_with($trimmed, ';')) {
        $stmtSql = trim($buffer);
        // Strip trailing semicolon for single statement execution
        if (str_ends_with($stmtSql, ';')) {
            $stmtSql = substr($stmtSql, 0, -1);
        }

        if ($stmtSql !== '') {
            $stmtCount++;
            if (!$mysqli->query($stmtSql)) {
                $err = $mysqli->error;
                fclose($handle);
                $mysqli->close();
                die("\nMySQL Execution Error on statement #{$stmtCount}: {$err}\nStatement preview: " . substr($stmtSql, 0, 120) . "...\n");
            }
            if ($stmtCount % 25 === 0) {
                echo "  - Processed {$stmtCount} statements...\n";
            }
        }
        $buffer = '';
    }
}

fclose($handle);

$mysqli->query("SET FOREIGN_KEY_CHECKS = 1");
$mysqli->close();

$elapsed = round(microtime(true) - $start, 2);
echo "Import completed successfully! ({$stmtCount} statements executed in {$elapsed}s)\n";
