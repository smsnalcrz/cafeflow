  <script>
    window.CafeFlowUser = <?= json_encode([
      'user_id'    => (int) $currentUser['user_id'],
      'full_name'  => $currentUser['full_name'],
      'username'   => $currentUser['username'],
      'email'      => $currentUser['email'] ?? '',
      'role'       => $currentRole,
      'status'     => $currentUser['status'],
      'csrf_token' => $csrfToken
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="assets/js/vendor/chart.umd.min.js"></script>
  <script src="assets/js/core/config.js?v=<?= $ver('assets/js/core/config.js') ?>" defer></script>
  <script src="assets/js/core/utils.js?v=<?= $ver('assets/js/core/utils.js') ?>" defer></script>
  <script src="assets/js/core/theme.js?v=<?= $ver('assets/js/core/theme.js') ?>" defer></script>
  <script src="assets/js/core/state.js?v=<?= $ver('assets/js/core/state.js') ?>" defer></script>
  <script src="assets/js/core/api.js?v=<?= $ver('assets/js/core/api.js') ?>" defer></script>
  <script src="assets/js/modules/icons.js?v=<?= $ver('assets/js/modules/icons.js') ?>" defer></script>
  <script src="assets/js/modules/receipt.js?v=<?= $ver('assets/js/modules/receipt.js') ?>" defer></script>
  <script src="assets/js/modules/pos-menu.js?v=<?= $ver('assets/js/modules/pos-menu.js') ?>" defer></script>
  <script src="assets/js/modules/pos-cart.js?v=<?= $ver('assets/js/modules/pos-cart.js') ?>" defer></script>
  <script src="assets/js/modules/pos-queue.js?v=<?= $ver('assets/js/modules/pos-queue.js') ?>" defer></script>
  <script src="assets/js/modules/navigation.js?v=<?= $ver('assets/js/modules/navigation.js') ?>" defer></script>
  <script src="assets/js/modules/products.js?v=<?= $ver('assets/js/modules/products.js') ?>" defer></script>
  <script src="assets/js/modules/inventory.js?v=<?= $ver('assets/js/modules/inventory.js') ?>" defer></script>
  <script src="assets/js/modules/reports.js?v=<?= $ver('assets/js/modules/reports.js') ?>" defer></script>
  <script src="assets/js/modules/waste.js?v=<?= $ver('assets/js/modules/waste.js') ?>" defer></script>
  <script src="assets/js/modules/accounts.js?v=<?= $ver('assets/js/modules/accounts.js') ?>" defer></script>
  <script src="assets/js/modules/security-2fa.js?v=<?= $ver('assets/js/modules/security-2fa.js') ?>" defer></script>
  <script src="assets/js/modules/dashboard.js?v=<?= $ver('assets/js/modules/dashboard.js') ?>" defer></script>
  <script src="assets/js/modules/predictive.js?v=<?= $ver('assets/js/modules/predictive.js') ?>" defer></script>
  <script src="assets/js/modules/audit.js?v=<?= $ver('assets/js/modules/audit.js') ?>" defer></script>
  <script src="assets/js/app.js?v=<?= $ver('assets/js/app.js') ?>" defer></script>
</body>
</html>
