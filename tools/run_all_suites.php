<?php
declare(strict_types=1);

$suites = [
    'test_reports_suite.php',
    'test_audit_suite.php',
    'test_2fa_suite.php',
    'test_waste_suite.php',
    'test_checkout_e2e.php',
    'test_remember_me_suite.php',
    'test_forgot_password_suite.php',
    'test_bug_hunt_fixes.php',
    'test_cancel_reasons.php',
    'test_security_and_edge_cases.php',
    'test_audit_resilience_and_mock_guard.php',
    'test_predictive_suite.php',
];

$allPassed = true;
echo "Running All 12 Test Suites..." . PHP_EOL;

foreach ($suites as $suite) {
    $path = __DIR__ . '/' . $suite;
    if (!file_exists($path)) {
        echo "[-] Skipping missing $suite" . PHP_EOL;
        continue;
    }
    $cmd = "php " . escapeshellarg($path);
    exec($cmd, $output, $code);
    if ($code === 0) {
        echo "[PASS] $suite" . PHP_EOL;
    } else {
        echo "[FAIL] $suite (Exit code: $code)" . PHP_EOL;
        echo implode(PHP_EOL, array_slice($output, -10)) . PHP_EOL;
        $allPassed = false;
    }
}

if ($allPassed) {
    echo PHP_EOL . ">>> ALL 12 TEST SUITES PASSED 100%! <<<" . PHP_EOL;
} else {
    echo PHP_EOL . ">>> SOME TEST SUITES FAILED! <<<" . PHP_EOL;
    exit(1);
}
