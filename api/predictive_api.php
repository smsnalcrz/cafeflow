<?php
/**
 * CafeFlow POS — Predictive Machine Learning Analytics API v3.0
 * -------------------------------------------------------------
 * Simplified, executive forecasting engine for DK Cafe:
 *   1. Predicted Sales (Expected vs. Actual)
 *   2. Products Sold (Expected demand & actual units sold)
 *   3. Materials / Ingredients Consumed (Recipe-driven raw ingredient burn)
 *   4. Peak Demand Intelligence (Peak hours, peak days, peak months)
 *   5. Model Evaluation (Outlier winsorization, hold-out accuracy audit)
 *
 * RBAC: Strictly restricted to Store Managers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/recipe_engine.php';
require_once __DIR__ . '/../core/predictive_engine.php';

require_login();
require_method('GET');

$currentUser = current_user();
if (!$currentUser || $currentUser['role'] !== 'manager') {
    fail(403, 'Access denied: Predictive Machine Learning Analytics is restricted to Store Managers.');
}

$perfStart = microtime(true);
$pdo = db();

$mode = strtolower(trim($_GET['mode'] ?? 'daily'));
if (!in_array($mode, ['daily', '7days', 'month', 'year'], true)) {
    $mode = 'daily';
}

try {
    $report = predictive_generate_report($pdo, $mode, $_GET);
    $report['execution_time_ms'] = round((microtime(true) - $perfStart) * 1000.0, 2);
    send_json($report);
} catch (Throwable $e) {
    fail(500, 'Predictive forecasting error: ' . $e->getMessage());
}
