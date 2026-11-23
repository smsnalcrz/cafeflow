<?php
/**
 * tools/import_mock_sql.php
 * Safely imports database/cafeflow_mock_data_1_year.sql into MariaDB.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';

$sqlFile = __DIR__ . '/../database/cafeflow_mock_data_1_year.sql';
if (!file_exists($sqlFile)) {
    die("Error: File $sqlFile does not exist. Run php tools/generate_1_year_mock_sql.php first.\n");
}

$fileSizeKb = round(filesize($sqlFile) / 1024, 1);
echo "Importing {$fileSizeKb} KB mock dataset from:\n  $sqlFile\n";
$start = microtime(true);

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($mysqli->connect_error) {
    die("MySQL Connection Error ({$mysqli->connect_errno}): {$mysqli->connect_error}\n");
}

$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 30);

$sql = file_get_contents($sqlFile);
if (!$sql) {
    die("Error reading SQL file.\n");
}

if ($mysqli->multi_query($sql)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
}

if ($mysqli->error) {
    die("MySQL Execution Error: " . $mysqli->error . "\n");
}

$mysqli->close();

$elapsed = round(microtime(true) - $start, 2);
echo "Import completed successfully in {$elapsed} seconds!\n";
