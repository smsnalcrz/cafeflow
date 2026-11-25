<?php
/**
 * export_excel.php - Exports CafeFlow transactions & analytics into Enterprise Multi-Sheet Excel (.xlsx).
 *
 * Supported Query Parameters:
 *   GET ?date=YYYY-MM-DD
 *   GET ?start=YYYY-MM-DD&end=YYYY-MM-DD
 *   GET ?all=1
 *   GET ?type=sales|predictive|inventory
 */
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';

// RBAC: Both Barista and Manager can export sales reports; Predictive is strictly Manager-only
$currentUser = require_role(['barista', 'manager']);
require_method('GET');

$pdo = db();
$tz = new DateTimeZone('Asia/Manila');

$dateParam  = $_GET['date']  ?? null;
$startParam = $_GET['start'] ?? null;
$endParam   = $_GET['end']   ?? null;
$allParam   = !empty($_GET['all']);
$typeParam  = strtolower($_GET['type'] ?? 'sales');

if ($typeParam === 'predictive' && strtolower((string)($currentUser['role'] ?? '')) !== 'manager') {
    fail(403, 'Access denied: Predictive Machine Learning Analytics exports are restricted to Store Managers.');
}

function is_valid_iso_date(?string $str): bool {
    if (!$str || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str, $m)) {
        return false;
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    return $y >= 2020 && $y <= 2035 && checkdate($mo, $d, $y);
}

/**
 * Locate verified Python executable that contains openpyxl and pymysql.
 */
function get_python_binary(): string {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $candidates = [
        'C:\\Users\\USER\\AppData\\Local\\Programs\\Python\\Python314\\python.exe',
        'python',
        'py -3',
        'py',
    ];

    foreach ($candidates as $cand) {
        $testCmd = (str_contains($cand, ' ') && !str_starts_with($cand, '"') ? '"' . $cand . '"' : escapeshellcmd($cand))
                 . ' -c "import openpyxl, pymysql; print(\'OK\')" 2>&1';
        $out = [];
        $rc = -1;
        @exec($testCmd, $out, $rc);
        if ($rc === 0 && !empty($out) && trim($out[0]) === 'OK') {
            $resolved = $cand;
            return $cand;
        }
    }

    $resolved = 'python';
    return $resolved;
}

$scriptPath = __DIR__ . '/../core/excel_generator.py';

if (!file_exists($scriptPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Excel generator engine script is missing.']);
    exit;
}

// Prepare date range & filename suffix
$periodLabel = '';
$filenameSuffix = '';

if ($allParam) {
    $periodLabel = 'All Available Transactions';
    $filenameSuffix = 'All_' . date('Ymd_His');
} elseif (is_valid_iso_date($startParam) && is_valid_iso_date($endParam)) {
    $periodLabel = "$startParam to $endParam";
    $filenameSuffix = "{$startParam}_to_{$endParam}";
} elseif (is_valid_iso_date($dateParam)) {
    $periodLabel = $dateParam;
    $filenameSuffix = $dateParam;
    $startParam = null;
    $endParam = null;
} else {
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    $dateParam = $today;
    $startParam = null;
    $endParam = null;
    $periodLabel = $today;
    $filenameSuffix = $today;
}

$tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cf_xlsx_' . uniqid() . '.xlsx';
$cliArgs = escapeshellarg($scriptPath) . ' --output ' . escapeshellarg($tempFile);

if ($typeParam === 'inventory') {
    $cliArgs .= ' --type inventory';
    $filename = 'DK_Cafe_Inventory_Stock_Report_' . date('Ymd_His') . '.xlsx';
} elseif ($typeParam === 'predictive') {
    $cliArgs .= ' --type predictive';
    if ($allParam) {
        $cliArgs .= ' --all';
    } elseif ($startParam && $endParam) {
        $cliArgs .= ' --start ' . escapeshellarg($startParam) . ' --end ' . escapeshellarg($endParam);
    } elseif ($dateParam) {
        $cliArgs .= ' --date ' . escapeshellarg($dateParam);
    } else {
        $cliArgs .= ' --all';
    }
    // Predictive models calibrate from the full historical operating baseline
    $cliArgs .= ' --include-mock';
    $filename = 'DK_Cafe_Executive_Predictive_Forecast_' . $filenameSuffix . '.xlsx';
} else {
    // Default: Sales Audit & Management Workbook (all 7 sheets)
    $cliArgs .= ' --type sales';
    if ($allParam) {
        $cliArgs .= ' --all';
    } elseif ($startParam && $endParam) {
        $cliArgs .= ' --start ' . escapeshellarg($startParam) . ' --end ' . escapeshellarg($endParam);
    } elseif ($dateParam) {
        $cliArgs .= ' --date ' . escapeshellarg($dateParam);
    }
    if (!empty($_GET['include_mock']) && ($_GET['include_mock'] === '1' || $_GET['include_mock'] === 'true')) {
        $cliArgs .= ' --include-mock';
    }
    $filename = 'DK_Cafe_Executive_Sales_Audit_' . $filenameSuffix . '.xlsx';
}

$pythonBin = get_python_binary();
$pyExecutable = (str_contains($pythonBin, ' ') && !str_starts_with($pythonBin, '"')) ? '"' . $pythonBin . '"' : escapeshellcmd($pythonBin);
$cmd = "$pyExecutable $cliArgs 2>&1";

$cmdOutput = [];
$returnCode = 1;
@exec($cmd, $cmdOutput, $returnCode);

if ($returnCode === 0 && file_exists($tempFile) && filesize($tempFile) > 0) {
    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tempFile));
        header('Cache-Control: max-age=0, no-cache, must-revalidate');
        header('Pragma: public');
    }
    readfile($tempFile);
    @unlink($tempFile);
    exit;
}

// Log error details for store administrators
error_log("CafeFlow Excel Generator Execution Failure (code $returnCode): " . implode("\n", $cmdOutput));

// If legacy format was explicitly requested, allow single-sheet fallback
if (!empty($_GET['legacy'])) {
    require_once __DIR__ . '/../core/legacy_excel_fallback.php';
    exit;
}

// Return clean, informative error instead of silently outputting a crippled 1-sheet file
http_response_code(500);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => false,
    'error'   => 'Unable to generate Executive 7-Sheet Excel workbook. Please check server logs or contact support.',
    'details' => !empty($cmdOutput) ? end($cmdOutput) : 'Unknown Python process error',
]);
if (file_exists($tempFile)) {
    @unlink($tempFile);
}
exit;
