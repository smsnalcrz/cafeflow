<?php
/**
 * CafeFlow - shared bootstrap: PDO connection, JSON helpers, input validation.
 * Change the constants below only if your MySQL differs from the XAMPP defaults.
 */

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_PORT')) {
    $envPort = getenv('DB_PORT');
    $portCacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cafeflow_db_port.cache';
    $cachedPort = ($envPort === false || $envPort === '') && is_file($portCacheFile)
        ? trim((string) @file_get_contents($portCacheFile))
        : '';
    $resolvedPort = ($envPort !== false && $envPort !== '')
        ? $envPort
        : (in_array($cachedPort, ['3306', '3307'], true) ? $cachedPort : '3307');
    define('DB_PORT', $resolvedPort);
}
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'cafeflow');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : ''); // XAMPP default: empty password

date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');  // PHP warnings must never corrupt a JSON reply
ini_set('log_errors', '1');

if (!class_exists('ApiError')) {
    /** An error that is safe to show to the cashier (message + HTTP status). */
    class ApiError extends RuntimeException
    {
    }
}

if (!function_exists('ensure_schema')) {
    function ensure_schema(PDO $pdo): void
    {
        require_once __DIR__ . '/schema_migrations.php';
        run_schema_migrations($pdo);
    }
}

if (!function_exists('asset_url')) {
    /**
     * Generates a cache-busted relative URL for CSS/JS assets based on filemtime().
     */
    function asset_url(string $relativePath): string
    {
        $fullPath = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
        $ver = @filemtime($fullPath) ?: time();
        return $relativePath . '?v=' . $ver;
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $configuredPort = defined('DB_PORT') ? (int) DB_PORT : 3307;
            $portsToTry = array_values(array_unique(array_filter([$configuredPort, 3307, 3306])));

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $lastException = null;
            $connectedPort = null;
            foreach ($portsToTry as $port) {
                try {
                    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=utf8mb4';
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                    $connectedPort = $port;
                    break;
                } catch (PDOException $e) {
                    $lastException = $e;
                    // If connection refused (code 2002 / wrong port), try next candidate port
                    if ($e->getCode() === 2002 || str_contains($e->getMessage(), '2002')) {
                        continue;
                    }
                    throw $e;
                }
            }

            if ($pdo === null) {
                throw $lastException;
            }

            if ($connectedPort !== null && (string) $connectedPort !== (string) $configuredPort) {
                @file_put_contents(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cafeflow_db_port.cache', (string) $connectedPort);
            }

            $pdo->exec("SET time_zone = '+08:00'");
            // Separate runtime schema changes from ordinary web requests.
            // DDL migrations only run during CLI commands (migrations/testing) or when explicitly enabled.
            if (php_sapi_name() === 'cli' || defined('RUN_MIGRATIONS_ON_BOOT')) {
                ensure_schema($pdo);
            }
        }
        return $pdo;
    }
}

if (!function_exists('send_json')) {
    function send_json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('fail')) {
    function fail(int $status, string $message): void
    {
        throw new ApiError($message, $status);
    }
}

if (!function_exists('require_method')) {
    function require_method(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            if (!headers_sent()) {
                header('Allow: ' . $method);
            }
            fail(405, 'Method not allowed.');
        }
    }
}

if (!function_exists('read_json')) {
    function read_json(): array
    {
        $raw  = $GLOBALS['MOCK_INPUT_JSON'] ?? file_get_contents('php://input');
        $data = json_decode($raw === false || $raw === null ? '' : $raw, true);
        if (!is_array($data)) {
            fail(400, 'Invalid request.');
        }
        return $data;
    }
}

if (!function_exists('as_int')) {
    function as_int(mixed $value, int $min, int $max): ?int
    {
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $n === false ? null : $n;
    }
}

if (!function_exists('as_decimal')) {
    function as_decimal(mixed $value, float $min, float $max): ?string
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        $f = (float) $value;
        if (!is_finite($f) || $f < $min || $f > $max) {
            return null;
        }
        return number_format($f, 2, '.', '');
    }
}

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ApiError) {
        $status  = $e->getCode() ?: 400;
        $message = $e->getMessage();
    } else {
        error_log('[CafeFlow] ' . $e);
        $status  = 500;
        $message = 'Server error. Please try again.';
    }
    send_json(['success' => false, 'error' => $message], $status);
});
