<?php
/**
 * CafeFlow - Centralized API Middleware Pipeline
 * Provides a unified wrapper for method verification, authentication/RBAC,
 * CSRF validation, and PDO connection handling across API endpoints.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

if (!function_exists('api_endpoint')) {
    /**
     * Executes an API endpoint callback inside a standardized security pipeline.
     *
     * @param array{
     *   methods?: string[],
     *   roles?: string[],
     *   csrf?: bool
     * } $options
     * @param callable(PDO, array): void $handler
     */
    function api_endpoint(array $options, callable $handler): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $allowedMethods = $options['methods'] ?? ['GET'];

        if (!in_array($method, $allowedMethods, true)) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowedMethods));
            }
            fail(405, 'Method not allowed.');
        }

        $roles = $options['roles'] ?? ['barista', 'manager'];
        $user  = require_role($roles);

        $needsCsrf = $options['csrf'] ?? in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($needsCsrf) {
            verify_csrf_request();
        }

        $handler(db(), $user);
    }
}
