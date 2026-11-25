<?php
/**
 * CafeFlow POS — Main Application Entry Point
 * Modularized into view partials under `views/layouts/`, `views/workspaces/`, and `views/modals/`.
 *
 * Referenced UI hooks & scripts in partials:
 * - Topbar & Theme: id="themeToggleBtn", id="dropdownThemeToggle", assets/js/core/theme.js
 * - Waste Horizons: data-waste-period="monthly", id="wasteMonthlyPickerGroup"
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

// Enforce authentication: unauthenticated visits redirect to login.php
$currentUser = require_login();
$currentRole = strtolower($currentUser['role'] ?? 'barista');
$csrfToken = get_csrf_token();

// Cache-bust the CSS/JS by file modification time so the tablet never runs stale code.
$ver = static fn(string $f): int => (int) @filemtime(__DIR__ . '/' . $f);

$cssModules = [
  'assets/css/variables.css',
  'assets/css/base.css',
  'assets/css/layout.css',
  'assets/css/topbar.css',
  'assets/css/auth.css',
  'assets/css/pos.css',
  'assets/css/cart.css',
  'assets/css/queue.css',
  'assets/css/modals.css',
  'assets/css/inventory.css',
  'assets/css/products.css',
  'assets/css/analytics-common.css',
  'assets/css/reports.css',
  'assets/css/waste.css',
  'assets/css/predictive.css',
  'assets/css/audit.css',
  'assets/css/responsive.css',
  'assets/css/print.css',
];

require __DIR__ . '/views/layouts/head.php';
require __DIR__ . '/views/layouts/topbar.php';

if ($currentRole === 'barista') {
  require __DIR__ . '/views/workspaces/pos.php';
  require __DIR__ . '/views/modals/modal-product.php';
} else {
  require __DIR__ . '/views/workspaces/dashboard.php';
}

require __DIR__ . '/views/modals/modal-inventory.php';
require __DIR__ . '/views/workspaces/modal-reports.php';

if ($currentRole === 'manager') {
  require __DIR__ . '/views/workspaces/modal-predictive.php';
}

require __DIR__ . '/views/workspaces/modal-waste.php';
require __DIR__ . '/views/modals/modal-pos-dialogs.php';

if ($currentRole === 'manager') {
  require __DIR__ . '/views/modals/modal-accounts.php';
}

require __DIR__ . '/views/modals/modal-security-2fa.php';

if ($currentRole === 'manager') {
  require __DIR__ . '/views/workspaces/modal-audit.php';
}

require __DIR__ . '/views/layouts/footer.php';
