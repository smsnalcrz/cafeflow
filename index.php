<?php
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
  'assets/css/reports.css',
  'assets/css/waste.css',
  'assets/css/predictive.css',
  'assets/css/responsive.css',
  'assets/css/print.css',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="theme-color" content="#162318">
  <title>CafeFlow · DK Cafe</title>
  <script>
    try {
      localStorage.removeItem('cafeflow_theme');
      document.documentElement.removeAttribute('data-theme');
    } catch (e) {}
  </script>
  <?php foreach ($cssModules as $css): ?>
  <link rel="stylesheet" href="<?= $css ?>?v=<?= $ver($css) ?>">
  <?php endforeach; ?>
</head>
<body>

  <!-- ===== Top navigation ===== -->
  <header class="topbar">
    <div class="brand" role="banner" title="CafeFlow & DK Cafe">
      <div class="brand-lockup">
        <div class="brand-top-row">
          <div class="brand-emblem-wrap" title="CafeFlow POS">
            <img src="assets/images/cafeflow_logo.svg?v=<?= $ver('assets/images/cafeflow_logo.svg') ?>" alt="CafeFlow" class="brand-emblem-img">
          </div>
          <div class="brand-logo-wrap" title="DK Cafe">
            <img src="assets/images/dk_cafe_logo_cropped.png?v=<?= $ver('assets/images/dk_cafe_logo_cropped.png') ?>" alt="DK Cafe" class="brand-logo-dk">
          </div>
        </div>
        <div class="brand-pill-bar">
          <span class="brand-pill-item">CAFEFLOW</span>
          <span class="brand-pill-amp" aria-hidden="true">&amp;</span>
          <span class="brand-pill-item">DK CAFE</span>
        </div>
      </div>
    </div>
    <nav class="nav" aria-label="Main">
      <?php if ($currentRole === 'barista'): ?>
      <button type="button" class="nav-btn active" data-view="pos">
        <svg class="nav-icon" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
        <span>POS</span> <span class="badge" id="queueBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="products">
        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
        <span>Products</span>
      </button>
      <?php endif; ?>
      <button type="button" class="nav-btn" data-view="inventory">
        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
        <span>Inventory</span> <span class="badge warn" id="lowBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="report">
        <svg class="nav-icon" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
        <span>Sales Report</span>
      </button>
      <?php if ($currentRole === 'manager'): ?>
      <button type="button" class="nav-btn" data-view="waste" id="wasteNavBtn">
        <svg class="nav-icon" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
        <span>Waste Reports</span>
      </button>
      <button type="button" class="nav-btn" data-view="predictive" id="predictiveNavBtn">
        <svg class="nav-icon" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
        <span>Predictive Analytics</span>
      </button>
      <?php endif; ?>
    </nav>

    <!-- Profile Button in Topbar (Positioned between Nav and Clock) -->
    <div class="user-profile-wrap" id="userProfileWrap">
      <button type="button" class="profile-btn" id="profileBtn" aria-expanded="false" aria-haspopup="true" title="User Profile">
        <div class="profile-avatar <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>" id="topbarAvatar">
          <?= strtoupper(substr($currentUser['full_name'] ?: $currentUser['username'], 0, 1)) ?>
        </div>
        <div class="profile-meta">
          <span class="profile-name" id="topbarUserName"><?= htmlspecialchars($currentUser['full_name']) ?></span>
          <span class="profile-role-badge <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>">
            <?= ucfirst($currentRole) ?>
          </span>
        </div>
        <span class="profile-chevron" aria-hidden="true">▾</span>
      </button>

      <!-- Profile Dropdown Menu -->
      <div class="profile-dropdown" id="profileDropdown" hidden>
        <div class="profile-dropdown-header">
          <div class="dropdown-avatar-lg <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>">
            <?= strtoupper(substr($currentUser['full_name'] ?: $currentUser['username'], 0, 1)) ?>
          </div>
          <div class="dropdown-user-info">
            <strong class="dropdown-fullname"><?= htmlspecialchars($currentUser['full_name']) ?></strong>
            <span class="dropdown-username">@<?= htmlspecialchars($currentUser['username']) ?></span>
            <span class="dropdown-role-tag <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>">
              <?= ucfirst($currentRole) ?>
            </span>
          </div>
        </div>
        <div class="profile-dropdown-menu">
          <button type="button" class="dropdown-item" id="openSecurity2faBtn">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </span>
            <span>Account Security &amp; 2FA</span>
          </button>
          <?php if ($currentRole === 'manager'): ?>
          <button type="button" class="dropdown-item" id="openAccountsBtn">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </span>
            <span>Account Management</span>
          </button>
          <?php endif; ?>
          <a href="logout.php" class="dropdown-item danger" id="logoutLink" style="text-decoration: none;">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </span>
            <span>Sign Out</span>
          </a>
        </div>
      </div>
    </div>
    <div class="clock" id="clock" aria-hidden="true"></div>
  </header>

  <?php if ($currentRole === 'barista'): ?>
  <!-- ===== Single-screen dual mode ===== -->
  <main class="split">

    <!-- Left 60%: Cashier -->
    <section class="panel cashier" aria-label="Cashier">
      <div class="panel-head cashier-head">
        <h2>Menu</h2>
        <div class="menu-search-wrap">
          <input type="search" id="productSearch" class="search-input" placeholder="Search beverages, pastries, meals, or category..." autocomplete="off">
          <button type="button" class="search-clear" id="searchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <button type="button" class="btn small primary" id="openAddProductBtn">+ Add Product</button>
      </div>

      <!-- Category Filter Pills Bar -->
      <div class="category-nav-container">
        <button type="button" class="cat-arrow-btn prev" id="catScrollLeftBtn" aria-label="Previous categories" title="Scroll left" hidden>‹</button>
        <div class="category-bar" id="categoryBar" role="tablist" aria-label="Product categories"></div>
        <button type="button" class="cat-arrow-btn next" id="catScrollRightBtn" aria-label="Next categories" title="Scroll right" hidden>›</button>
      </div>

      <!-- Products Grid -->
      <div class="menu-grid" id="menuGrid"></div>
    </section>

    <!-- Right panel: Barista Queue (Upper) & Current Order (Lower) -->
    <section class="panel barista" aria-label="Barista queue and current order">
      <!-- Upper: Barista Queue -->
      <div class="barista-queue-wrap">
        <div class="panel-head">
          <h2>Barista Queue</h2>
          <span class="pill" id="queueCount">0</span>
          <span class="conn" id="connState">Connecting…</span>
        </div>
        <div class="queue" id="queueList"></div>
      </div>

      <!-- Lower: Current Order -->
      <div class="cart" aria-label="Current order">
        <div class="cart-head">
          <h2>Current Order</h2>
          <span class="muted" id="cartCount">0 items</span>
          <button type="button" class="btn ghost small" id="clearCart">Clear</button>
        </div>
        <div class="cart-lines" id="cartLines"></div>
        <div class="cart-foot">
          <div class="discount-selector-wrap" style="margin-bottom: 8px;">
            <label for="cartDiscountType" style="font-size: 11px; font-weight: 700; color: var(--muted); display: block; margin-bottom: 4px;">APPLY DISCOUNT</label>
            <select id="cartDiscountType" class="auth-input" style="padding: 6px 10px; font-size: 12.5px; height: 34px; width: 100%;">
              <option value="none">No Discount (Regular)</option>
              <option value="senior_pwd">Senior / PWD (20% Discount)</option>
              <option value="custom_percent">Custom Promo Discount (%)</option>
            </select>
            <div id="seniorIdWrap" style="margin-top: 6px;" hidden>
              <input type="text" id="cartSeniorIdNo" class="auth-input" placeholder="OSCA / PWD ID or Booklet #" style="padding: 6px 10px; font-size: 12px; height: 32px; width: 100%;">
            </div>
            <div id="customPercentWrap" style="margin-top: 6px;" hidden>
              <input type="number" id="cartDiscountRate" class="auth-input" placeholder="Discount % (1 to 50)" min="1" max="50" style="padding: 6px 10px; font-size: 12px; height: 32px; width: 100%;">
            </div>
          </div>
          <div class="discount-breakdown-wrap" id="cartDiscountSummary" style="font-size: 12px; color: var(--muted); margin-bottom: 6px;" hidden>
            <div style="display:flex; justify-content:space-between;"><span>Gross Subtotal:</span><span id="cartSubtotalDisplay">₱0.00</span></div>
            <div style="display:flex; justify-content:space-between; color: var(--emerald);"><span>Discount:</span><span id="cartDiscountDisplay">-₱0.00</span></div>
          </div>
          <div class="total-row">
            <span>Payable Total</span>
            <strong id="cartTotal">₱0.00</strong>
          </div>
          <div class="pay-row">
            <button type="button" class="pay cash" data-method="Cash">
              <svg class="pay-btn-svg" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
              <span>Checkout · Cash</span>
            </button>
            <button type="button" class="pay gcash" data-method="GCash">
              <svg class="pay-btn-svg" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
              <span>Checkout · GCash</span>
            </button>
          </div>
        </div>
      </div>
    </section>
  </main>

  <!-- ===== Mobile Sticky Order Dock (visible on mobile / small screens when cart has items) ===== -->
  <div class="mobile-order-bar" id="mobileOrderBar" hidden aria-label="Order summary dock">
    <button type="button" class="mob-order-info" id="mobOrderViewBtn" aria-label="View order items">
      <div class="mob-order-meta">
        <span class="mob-order-badge" id="mobOrderBadge">0</span>
        <span class="mob-order-label">Current Order</span>
      </div>
      <div class="mob-order-total-group">
        <strong class="mob-order-total" id="mobOrderTotal">₱0.00</strong>
        <span class="mob-order-chevron" aria-hidden="true">▲</span>
      </div>
    </button>
    <div class="mob-order-actions">
      <button type="button" class="mob-pay-btn mob-pay-cash" id="mobPayCashBtn">
        <svg class="pay-btn-svg" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle></svg>
        <span>Cash</span>
      </button>
      <button type="button" class="mob-pay-btn mob-pay-gcash" id="mobPayGcashBtn">
        <svg class="pay-btn-svg" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
        <span>GCash</span>
      </button>
    </div>
  </div>
  <?php else: ?>
  <!-- ===== Manager Dedicated Dashboard Workspace ===== -->
  <div class="manager-dashboard-container" id="managerDashboard">
    <div class="mgr-welcome-banner">
      <div>
        <h1>Welcome back, <?= htmlspecialchars($currentUser['full_name']) ?>!</h1>
        <p>Manager Portal &middot; Access raw inventory levels, sales revenue analytics, and account administration.</p>
      </div>
      <span class="mgr-banner-badge">Role: Manager (Administrative Access)</span>
    </div>

    <div class="mgr-cards-grid">
      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Raw Supplies Inventory</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardSupplyCount">--</div>
        <p class="muted" style="margin: 0 0 10px;">Monitor stock counts, restock raw materials, and inspect low-stock alerts.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenInventoryBtn">Manage Inventory</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Today's Sales Revenue</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardSalesToday">₱0.00</div>
        <p class="muted" style="margin: 0 0 10px;">Inspect historical transaction records, date spans, and export spreadsheets.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenReportBtn">View Sales Reports</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Account Administration</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardUserCount">--</div>
        <p class="muted" style="margin: 0 0 10px;">Create Barista/Manager accounts, approve registrations, and manage credentials.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenAccountsBtn">Manage Accounts</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Waste &amp; Loss Tracking</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardWasteToday">₱0.00 loss</div>
        <p class="muted" style="margin: 0 0 10px;">Monitor raw material waste, ingredient consumption, and income loss from cancelled orders.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenWasteBtn">View Waste Reports</button>
      </div>

      <div class="mgr-card mgr-card-featured">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Predictive Sales Analytics</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardPredictiveHighlight">Forecast Engine</div>
        <p class="muted" style="margin: 0 0 10px;">Seasonality-aware ML demand forecasting for daily, 7-day, monthly, and yearly revenue.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenPredictiveBtn">Launch Predictive Engine</button>
      </div>
    </div>

    <!-- ===== Manager Dashboard Charts (Option B) ===== -->
    <div class="mgr-charts-section" id="mgrChartsSection">
      <div class="mgr-charts-header">
        <h2 class="mgr-charts-title">Today's Sales Overview</h2>
        <span class="mgr-charts-subtitle" id="mgrChartsPeriodLabel">Loading...</span>
      </div>
      <div class="mgr-charts-grid">
        <div class="mgr-chart-card">
          <div class="mgr-chart-card-head">
            <span class="mgr-chart-card-title">Hourly Sales Trend (₱)</span>
          </div>
          <div class="mgr-chart-wrap">
            <canvas id="mgrLineChart" aria-label="Hourly sales trend chart"></canvas>
            <p class="mgr-chart-empty" id="mgrLineChartEmpty" hidden>No hourly data for today yet.</p>
          </div>
        </div>
        <div class="mgr-chart-card">
          <div class="mgr-chart-card-head">
            <span class="mgr-chart-card-title">Top Sellers Today (Units)</span>
          </div>
          <div class="mgr-chart-wrap">
            <canvas id="mgrBarChart" aria-label="Top selling products chart"></canvas>
            <p class="mgr-chart-empty" id="mgrBarChartEmpty" hidden>No product sales recorded today.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($currentRole === 'barista'): ?>
  <!-- ===== Products Management modal ===== -->
  <div class="modal" id="productModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>Manage Products</h2>
        <div class="modal-search-wrap">
          <input type="search" id="manageProductSearch" class="search-input" placeholder="Filter products by name or category (e.g. Espresso, Croffle)...">
          <button type="button" class="search-clear" id="manageProductSearchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn primary" id="addNewProductBtn">+ Add Product</button>
          <button type="button" class="btn ghost" data-close>✕ Close</button>
        </div>
      </div>
      <div class="modal-body">
        <table class="table">
          <thead>
            <tr>
              <th style="width: 54px;">Image</th>
              <th>Product Name</th>
              <th>Category</th>
              <th class="num">Price</th>
              <th class="num">Stock</th>
              <th>Status</th>
              <th class="act">Actions</th>
            </tr>
          </thead>
          <tbody id="prodTableBody"></tbody>
        </table>
      </div>
    </div><!-- /.modal-card -->

    <!-- Add / Edit Product Sheet (direct child of #productModal, outside .modal-card to avoid overflow:hidden clipping) -->
    <div class="sheet" id="productSheet" hidden>
      <form class="sheet-card" id="productForm" autocomplete="off" novalidate enctype="multipart/form-data">
        <h3 id="productSheetTitle">Add Product</h3>
        
        <div class="sheet-grid-2">
          <label>Name *
            <input id="pName" maxlength="100" placeholder="e.g. Spanish Latte" required>
          </label>
          <label>Category *
            <select id="pCategory" required>
              <option value="Coffee">Coffee</option>
              <option value="Milk Series">Milk Series</option>
              <option value="Milk Tea">Milk Tea</option>
              <option value="Fruit Tea / Lemonade">Fruit Tea / Lemonade</option>
              <option value="Croffle Classic">Croffle Classic</option>
              <option value="Croffle Premium">Croffle Premium</option>
              <option value="Sandwich">Sandwich</option>
              <option value="Pastry">Pastry</option>
              <option value="Snacks">Snacks</option>
            </select>
          </label>
        </div>

        <div class="product-pricing-box">
          <div class="pricing-header-row">
            <label class="check-row" style="margin-bottom: 0;">
              <input type="checkbox" id="pHasSizes" checked>
              <span id="pHasSizesLabel"><strong>Enable Cup Sizes</strong> (Flexible sizes & Hot/Cold options)</span>
            </label>
          </div>

          <!-- Dynamic Cup Sizes Builder Container -->
          <div id="pSizesContainer" class="size-builder-box">
            <div class="size-builder-toolbar">
              <span class="presets-label">Quick Presets:</span>
              <div class="presets-group">
                <button type="button" class="btn-preset-pill" data-preset="coffee">12oz Hot + 16oz Cold</button>
                <button type="button" class="btn-preset-pill" data-preset="cold">16oz + 22oz Cold</button>
                <button type="button" class="btn-preset-pill" data-preset="all">12oz Hot + 16oz + 22oz Cold</button>
              </div>
            </div>

            <!-- Header Labels for Desktop -->
            <div class="size-options-header">
              <span>Cup Size</span>
              <span>Temp</span>
              <span>Display Label</span>
              <span>Price (₱)</span>
              <span></span>
            </div>

            <!-- Dynamic size rows container -->
            <div id="sizeOptionsList" class="size-options-list"></div>

            <div class="size-builder-actions">
              <button type="button" class="btn ghost small" id="addSizeOptionBtn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Add Cup Size
              </button>
              <span class="size-builder-hint">Configure custom cup sizes and Hot / Cold temperature options</span>
            </div>
          </div>

          <!-- Base/Fixed Price for single-sized items or fallback -->
          <div id="pBasePriceWrap" style="margin-top: 10px;" hidden>
            <label id="pBasePriceLabel">Single / Fixed Price (₱) *
              <input id="pPrice" inputmode="decimal" placeholder="0.00" required>
            </label>
          </div>
          <input type="hidden" id="pStock" value="0">
        </div>

        <div class="image-upload-wrap">
          <label>Product Image (JPG, PNG, WebP)
            <input type="file" id="pImageInput" accept="image/jpeg,image/png,image/webp">
          </label>
          <div class="image-preview-box" id="imagePreviewBox" hidden>
            <img id="imagePreviewImg" alt="Product preview">
            <button type="button" class="btn ghost small" id="removeImageBtn">✕ Remove image</button>
          </div>
        </div>

        <div class="inventory-link-box">
          <div class="link-sub" id="linkSubSection">
            <div class="link-head">
              <div class="link-title-group">
                <span class="link-title">Raw Inventory Supply Link (Recipe)</span>
                <span class="supply-count-badge" id="supplySelectionCount">0 items selected</span>
              </div>
              <button type="button" class="btn-link-add" id="openQuickAddSupplyBtn" title="Add a new raw supply item to inventory">+ Add Item</button>
            </div>

            <!-- Quick Add Raw Supply Inline Card -->
            <div class="quick-add-supply-card" id="quickAddSupplyCard" hidden>
              <div class="quick-add-header">
                <span>Add New Inventory Item</span>
                <button type="button" class="quick-add-close" id="quickAddSupplyClose" title="Cancel adding item">✕</button>
              </div>
              <div class="sheet-grid-2">
                <label>Item Name *
                  <input type="text" id="quickSupplyName" maxlength="100" placeholder="e.g. Arabica Beans" autocomplete="off">
                </label>
                <label>Unit Measure *
                  <input type="text" id="quickSupplyUnit" maxlength="20" placeholder="e.g. g, ml, pcs, shots" autocomplete="off">
                </label>
              </div>
              <div class="sheet-grid-2">
                <label>Starting Stock
                  <input type="number" id="quickSupplyStock" inputmode="decimal" placeholder="0" min="0" step="any">
                </label>
                <label>Restock Alert At
                  <input type="number" id="quickSupplyThreshold" inputmode="decimal" placeholder="0" min="0" step="any">
                </label>
              </div>
              <p class="form-error" id="quickSupplyError" hidden></p>
              <div class="quick-add-actions">
                <button type="button" class="btn ghost small" id="quickSupplyCancel">Cancel</button>
                <button type="button" class="btn primary small" id="quickSupplySaveBtn">Add to Inventory</button>
              </div>
            </div>

            <!-- Inventory Search Bar -->
            <div class="supply-search-wrap">
              <div class="supply-search-input-box">
                <span class="supply-search-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </span>
                <input type="search" id="supplySearchInput" class="search-input supply-search-field" placeholder="Search raw inventory supplies by name or unit..." autocomplete="off">
                <button type="button" class="search-clear" id="supplySearchClearBtn" hidden title="Clear search">✕</button>
              </div>
            </div>

            <!-- Dedicated Scrollable List Container for Raw Supplies -->
            <div class="supply-list-container" id="supplyListContainer" role="region" aria-label="Raw inventory items list">
              <div class="supply-list" id="supplyList"></div>
            </div>

            <!-- Hidden input for legacy backward-compatibility -->
            <input type="hidden" id="pLinkedInventory" value="">
            <input type="hidden" id="pDeductAmount" value="0">
          </div>
        </div>

        <p class="form-error" id="productSheetError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="productSheetCancel">Cancel</button>
          <button type="submit" class="btn primary" id="saveProductBtn">Save Product</button>
        </div>
      </form>
    </div><!-- /#productSheet -->
  </div><!-- /#productModal -->
  <?php endif; ?>


  <!-- ===== Inventory modal ===== -->
  <div class="modal" id="inventoryModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>Raw Supplies Inventory</h2>
        <div class="modal-search-wrap">
          <input type="search" id="inventorySearch" class="search-input" placeholder="Search supplies by name, unit (g, ml, pcs), or status (low, ok)..." autocomplete="off">
          <button type="button" class="search-clear" id="inventorySearchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small" id="invLogWasteBtn" title="Log spilled, damaged, or expired stock" style="background: var(--red-light, #fce8e6); color: var(--red, #c5221f); border: 1px solid rgba(197, 34, 31, 0.25);">+ Log Waste</button>
          <button type="button" class="btn primary" id="addNewSupplyBtn">+ Add Supply</button>
          <a href="api/export_excel.php?type=inventory" class="btn small" id="exportInventoryExcelBtn" title="Export inventory & recipe audit to Excel (.xlsx)" style="background: var(--amber); color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; padding: 7px 12px; font-weight: 600; border-radius: var(--radius-sm); font-size: 13px;">Export Excel</a>
          <button type="button" class="btn ghost" data-close>✕ Close</button>
        </div>
      </div>
      <div class="modal-body">
        <table class="table">
          <thead>
            <tr>
              <th>Supply Item</th>
              <th class="num">In stock</th>
              <th class="num">Alert at</th>
              <th>Status</th>
              <th class="act"></th>
            </tr>
          </thead>
          <tbody id="invBody"></tbody>
        </table>
      </div>
    </div>

    <!-- Add / edit / restock sheet -->
    <div class="sheet" id="itemSheet" hidden>
      <form class="sheet-card" id="itemForm" autocomplete="off" novalidate>
        <h3 id="sheetTitle"></h3>
        <label data-f="name">Name <input id="fName" maxlength="100" placeholder="e.g. Coffee Beans"></label>
        <label data-f="unit">Unit <input id="fUnit" maxlength="20" placeholder="g, ml, pcs…"></label>
        <label data-f="stock"><span id="fStockLabel">Stock</span> <input id="fStock" inputmode="decimal" placeholder="0"></label>
        <label data-f="threshold">Restock alert at <input id="fThreshold" inputmode="decimal" placeholder="0"></label>
        <p class="form-error" id="sheetError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="sheetCancel">Cancel</button>
          <button type="submit" class="btn primary">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== Sales report modal ===== -->
  <div class="modal" id="reportModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>Sales Report & Transactions</h2>
        <div class="seg" id="rangeSeg" role="group" aria-label="Report period">
          <button type="button" class="on" data-range="today">Today</button>
          <button type="button" data-range="date">Specific Date</button>
          <button type="button" data-range="7days">7-Day Report</button>
          <button type="button" data-range="month">Specific Month</button>
          <?php if ($currentRole === 'manager'): ?>
          <button type="button" data-range="year" id="yearlyRangeBtn">Yearly</button>
          <?php endif; ?>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small" id="toggleCalBtn" title="Open or close visual calendar">Calendar</button>
          <button type="button" class="btn small primary" id="exportExcelBtn" title="Export transactions to Excel spreadsheet">Export Excel (.xlsx)</button>
          <button type="button" class="btn ghost" data-close>Close</button>
        </div>
      </div>

      <!-- Contextual toolbar for stepping, picking, and quick navigation -->
      <div class="report-toolbar" id="reportToolbar">
        <div class="toolbar-nav">
          <button type="button" class="btn-step" id="prevPeriodBtn" title="Previous period">◀</button>
          
          <div class="picker-group" id="todayPickerGroup">
            <span class="pill-badge" id="todayBadge">Today's Live Sales</span>
          </div>

          <div class="picker-group" id="datePickerGroup" hidden>
            <label for="reportDatePicker" class="picker-label">Date:</label>
            <input type="date" id="reportDatePicker" class="date-input">
            <button type="button" class="btn ghost small" id="jumpTodayBtn">Jump to Today</button>
          </div>

          <div class="picker-group" id="sevenDayPickerGroup" hidden>
            <label for="report7DayPicker" class="picker-label">7-Day Start:</label>
            <input type="date" id="report7DayPicker" class="date-input">
            <span class="pill-range" id="report7DayBadge">7-Day Span</span>
            <button type="button" class="btn ghost small" id="jumpLast7Btn">Last 7 Days</button>
          </div>

          <div class="picker-group" id="monthPickerGroup" hidden>
            <label for="reportMonthPicker" class="picker-label">Month:</label>
            <input type="month" id="reportMonthPicker" class="date-input">
            <button type="button" class="btn ghost small" id="jumpThisMonthBtn">This Month</button>
          </div>

          <div class="picker-group" id="yearPickerGroup" hidden>
            <label for="reportYearPicker" class="picker-label">Year:</label>
            <select id="reportYearPicker" class="year-select"></select>
            <button type="button" class="btn ghost small" id="jumpThisYearBtn">This Year</button>
          </div>

          <button type="button" class="btn-step" id="nextPeriodBtn" title="Next period">▶</button>
        </div>

        <div class="toolbar-status">
          <span class="status-period" id="reportStatusPeriod">Loading...</span>
        </div>
      </div>

      <!-- Interactive visual calendar drawer -->
      <div class="cal-drawer" id="reportCalDrawer" hidden>
        <div class="cal-card">
          <div class="cal-head">
            <button type="button" class="cal-nav-btn" id="calPrevMonth" title="Previous month">◀</button>
            <div class="cal-selects">
              <select id="calMonthSelect" aria-label="Select month" class="cal-select"></select>
              <select id="calYearSelect" aria-label="Select year" class="cal-select"></select>
            </div>
            <button type="button" class="cal-nav-btn" id="calNextMonth" title="Next month">▶</button>
            <span class="cal-tip" id="calModeTip">Tap a date to inspect report</span>
            <button type="button" class="btn ghost small cal-close-btn" id="calCloseBtn">Close Calendar</button>
          </div>

          <div class="cal-weekdays">
            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
          </div>

          <div class="cal-grid" id="calDaysGrid"></div>

          <div class="cal-foot">
            <span class="cal-foot-label">Quick select:</span>
            <div class="cal-presets">
              <button type="button" class="cal-pill" data-cal-action="today">Today</button>
              <button type="button" class="cal-pill" data-cal-action="yesterday">Yesterday</button>
              <button type="button" class="cal-pill" data-cal-action="last7">Last 7 Days</button>
              <button type="button" class="cal-pill" data-cal-action="thisMonth">This Month</button>
              <button type="button" class="cal-pill" data-cal-action="lastMonth">Last Month</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-body" id="reportBody"></div>
    </div>
  </div>

  <?php if ($currentRole === 'manager'): ?>
  <!-- ===== Dedicated Predictive Machine Learning Analytics Modal ===== -->
  <div class="modal" id="predictiveModal" hidden>
    <div class="modal-card predictive-modal-card">
      <div class="modal-head">
        <div class="modal-title-wrap">
          <h2>Predictive Machine Learning Analytics</h2>
          <p class="modal-subtitle">Seasonality-aware demand forecasting and revenue projections derived from historical transaction data.</p>
        </div>
        <div class="seg" id="predPeriodSeg" role="group" aria-label="Prediction period">
          <button type="button" class="on" data-pred-period="daily">Daily Prediction</button>
          <button type="button" data-pred-period="7days">7-Day Prediction</button>
          <button type="button" data-pred-period="month">Specific Month Prediction</button>
          <button type="button" data-pred-period="year">Yearly Prediction</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small primary" id="predExportExcelBtn" title="Export AI Forecast &amp; Demand Models to Excel (.xlsx)">Export Forecast (.xlsx)</button>
          <button type="button" class="btn small" id="predRecalculateBtn" title="Recalculate forecast from latest transactions">Recalculate</button>
          <button type="button" class="btn ghost" data-close>Close</button>
        </div>
      </div>

      <!-- Predictive Contextual Toolbar -->
      <div class="report-toolbar" id="predToolbar">
        <div class="toolbar-nav">
          <button type="button" class="btn-step" id="predPrevPeriodBtn" title="Previous period">◀</button>

          <div class="picker-group" id="predDailyPickerGroup">
            <label for="predDatePicker" class="picker-label">Target Date:</label>
            <input type="date" id="predDatePicker" class="date-input">
            <span class="pill-range" id="predDailyBadge">Next Day Forecast</span>
            <button type="button" class="btn ghost small" id="predJumpTomorrowBtn">Tomorrow</button>
          </div>

          <div class="picker-group" id="pred7DayPickerGroup" hidden>
            <label for="pred7DayPicker" class="picker-label">Start Date:</label>
            <input type="date" id="pred7DayPicker" class="date-input">
            <span class="pill-range" id="pred7DayBadge">7-Day Horizon</span>
            <button type="button" class="btn ghost small" id="predJumpNext7Btn">Upcoming 7 Days</button>
          </div>

          <div class="picker-group" id="predMonthPickerGroup" hidden>
            <label for="predMonthPicker" class="picker-label">Target Month:</label>
            <input type="month" id="predMonthPicker" class="date-input">
            <span class="pill-range" id="predMonthBadge">Monthly Cycle</span>
            <button type="button" class="btn ghost small" id="predJumpNextMonthBtn">Next Month</button>
          </div>

          <div class="picker-group" id="predYearPickerGroup" hidden>
            <label for="predYearPicker" class="picker-label">Target Year:</label>
            <select id="predYearPicker" class="year-select"></select>
            <span class="pill-range" id="predYearBadge">Annual Horizon</span>
            <button type="button" class="btn ghost small" id="predJumpNextYearBtn">Next Year</button>
          </div>

          <button type="button" class="btn-step" id="predNextPeriodBtn" title="Next period">▶</button>
        </div>

        <div class="toolbar-status">
          <span class="status-period" id="predStatusPeriod">Loading predictive model...</span>
        </div>
      </div>

      <div class="modal-body" id="predictiveBody"></div>
    </div>
  </div>

  <!-- ===== Dedicated Waste & Loss Reports Modal ===== -->
  <div class="modal" id="wasteModal" hidden>
    <div class="modal-card waste-modal-card">
      <div class="modal-head">
        <div class="modal-title-wrap">
          <h2>Waste &amp; Loss Reports</h2>
          <p class="modal-subtitle">Track raw ingredient consumption waste and estimated financial loss from cancelled orders.</p>
        </div>
        <div class="seg" id="wastePeriodSeg" role="group" aria-label="Waste reporting period">
          <button type="button" data-waste-period="hourly">Hourly</button>
          <button type="button" class="on" data-waste-period="daily">Daily</button>
          <button type="button" data-waste-period="weekly">Weekly</button>
          <button type="button" data-waste-period="monthly">Monthly</button>
          <button type="button" data-waste-period="yearly">Yearly</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small primary" id="wasteRefreshBtn" title="Refresh waste data">Refresh</button>
          <button type="button" class="btn small" id="wasteManualEntryBtn" title="Log manual waste entry">+ Log Manual Waste</button>
          <button type="button" class="btn ghost" data-close>Close</button>
        </div>
      </div>

      <!-- Waste Contextual Toolbar -->
      <div class="report-toolbar" id="wasteToolbar">
        <div class="toolbar-nav">
          <button type="button" class="btn-step" id="wastePrevPeriodBtn" title="Previous period">◀</button>

          <div class="picker-group" id="wasteHourlyPickerGroup" hidden>
            <label for="wasteHourlyDatePicker" class="picker-label">Date:</label>
            <input type="date" id="wasteHourlyDatePicker" class="date-input">
            <span class="pill-range">24-Hour Breakdown</span>
            <button type="button" class="btn ghost small" id="wasteJumpHourlyTodayBtn">Today</button>
          </div>

          <div class="picker-group" id="wasteDailyPickerGroup">
            <label for="wasteDailyDatePicker" class="picker-label">Date:</label>
            <input type="date" id="wasteDailyDatePicker" class="date-input">
            <span class="pill-range">Single Day</span>
            <button type="button" class="btn ghost small" id="wasteJumpDailyTodayBtn">Today</button>
          </div>

          <div class="picker-group" id="wasteWeeklyPickerGroup" hidden>
            <label for="wasteWeeklyDatePicker" class="picker-label">Start Date:</label>
            <input type="date" id="wasteWeeklyDatePicker" class="date-input">
            <span class="pill-range" id="wasteWeeklyBadge">7-Day Span</span>
            <button type="button" class="btn ghost small" id="wasteJumpThisWeekBtn">This Week</button>
          </div>

          <div class="picker-group" id="wasteMonthlyPickerGroup" hidden>
            <label for="wasteMonthPicker" class="picker-label">Month:</label>
            <input type="month" id="wasteMonthPicker" class="date-input">
            <span class="pill-range" id="wasteMonthlyBadge">Full Month</span>
            <button type="button" class="btn ghost small" id="wasteJumpThisMonthBtn">This Month</button>
          </div>

          <div class="picker-group" id="wasteYearlyPickerGroup" hidden>
            <label for="wasteYearSelect" class="picker-label">Year:</label>
            <select id="wasteYearSelect" class="year-select"></select>
            <span class="pill-range">Annual Window</span>
            <button type="button" class="btn ghost small" id="wasteJumpThisYearBtn">This Year</button>
          </div>

          <button type="button" class="btn-step" id="wasteNextPeriodBtn" title="Next period">▶</button>
        </div>

        <div class="toolbar-status">
          <span class="status-period" id="wasteStatusPeriod">Loading waste audit data...</span>
        </div>
      </div>

      <!-- View Navigation Tabs -->
      <div class="waste-view-tabs" id="wasteViewTabs">
        <button type="button" class="waste-tab active" data-view-tab="overview">Overview &amp; Charts</button>
        <button type="button" class="waste-tab" data-view-tab="materials">Material Waste Summary</button>
        <button type="button" class="waste-tab" data-view-tab="incomeloss">Income Loss Report</button>
        <button type="button" class="waste-tab" data-view-tab="log">Waste Event Logs</button>
      </div>

      <div class="modal-body" id="wasteBody"></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ===== E-Receipt modal ===== -->
  <div class="modal" id="receiptModal" hidden>
    <div class="modal-card receipt-card-wrap">
      <div class="modal-head receipt-head">
        <h2>Official E-Receipt</h2>
        <div class="modal-actions">
          <button type="button" class="btn ghost" id="closeReceiptBtn">Close</button>
        </div>
      </div>
      <div class="modal-body receipt-scroll" id="receiptModalBody"></div>
    </div>
  </div>

  <!-- ===== Cash Payment Tender Modal ===== -->
  <div class="sheet" id="payModal" hidden>
    <div class="sheet-card pay-dialog">
      <h3>Cash Payment</h3>
      <div class="pay-due">
        <span>Total Due:</span>
        <strong id="payDueAmount">₱0.00</strong>
      </div>
      <label>Amount Tendered (₱)
        <input type="number" id="payTenderedInput" inputmode="decimal" step="any" placeholder="0.00" autofocus>
      </label>
      <div class="quick-amounts" id="quickAmounts"></div>
      <div class="pay-change">
        <span>Change:</span>
        <strong id="payChangeAmount">₱0.00</strong>
      </div>
      <div class="sheet-actions">
        <button type="button" class="btn ghost" id="cancelPayBtn">Cancel</button>
        <button type="button" class="btn primary" id="confirmCashPayBtn">Confirm Cash & Print Order</button>
      </div>
    </div>
  </div>

  <!-- ===== GCash Payment Tender Modal ===== -->
  <div class="sheet" id="gcashModal" hidden>
    <div class="sheet-card pay-dialog">
      <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="#007dfe" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
        <h3 style="margin:0;">GCash Payment</h3>
      </div>
      <div class="pay-due">
        <span>Total Due:</span>
        <strong id="gcashDueAmount">₱0.00</strong>
      </div>
      <label>GCash Reference Number (Required)
        <input type="text" id="gcashRefInput" placeholder="e.g. 100234567891 (min 6 digits from customer receipt)" maxlength="30" autocomplete="off" style="font-family: monospace; font-size: 15px; font-weight: 700; letter-spacing: 1px;">
      </label>
      <div style="font-size:12px; color:var(--muted); margin-top:-6px; margin-bottom:12px;">
        Enter the reference number from the customer's GCash receipt for merchant verification.
      </div>
      <div class="sheet-actions">
        <button type="button" class="btn ghost" id="cancelGcashBtn">Cancel</button>
        <button type="button" class="btn primary" id="confirmGcashBtn" style="background:#007dfe; border-color:#007dfe;">Confirm GCash & Submit Order</button>
      </div>
    </div>
  </div>

  <!-- ===== Order Cancellation / Void Confirmation Modal ===== -->
  <div class="sheet" id="cancelOrderModal" hidden>
    <div class="sheet-card" style="max-width: 440px;">
      <h3 id="cancelOrderModalTitle" style="color:var(--rose, #e05252); margin-top:0;">Cancel Order</h3>
      <p id="cancelOrderModalDesc" style="font-size:13.5px; color:var(--muted); margin: 6px 0 14px;">
        Are you sure you want to cancel this order?
      </p>
      <label>Cancellation Reason (Required)
        <input type="text" id="cancelReasonInput" placeholder="e.g. Customer change of mind, incorrect drink size, duplicate order..." maxlength="255">
      </label>
      <div class="sheet-actions" style="margin-top:16px;">
        <button type="button" class="btn ghost" id="dismissCancelOrderBtn">Keep Order</button>
        <button type="button" class="btn danger" id="confirmCancelOrderBtn">Confirm</button>
      </div>
    </div>
  </div>

  <!-- ===== Universal Confirmation Dialog Modal ===== -->
  <div class="sheet confirm-modal-sheet" id="customConfirmModal" hidden style="display: none;">
    <div class="sheet-card confirm-dialog-card" id="confirmDialogCard" role="dialog" aria-modal="true" aria-labelledby="confirmDialogTitle">
      <div class="confirm-dialog-header">
        <div class="confirm-dialog-icon-wrap danger" id="confirmDialogIconWrap">
          <svg id="confirmDialogIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
        </div>
        <div class="confirm-dialog-title-wrap">
          <h3 id="confirmDialogTitle">Confirm Action</h3>
          <p class="confirm-dialog-subtitle" id="confirmDialogSubtitle">Confirmation Required</p>
        </div>
        <button type="button" class="btn-close-sheet" id="confirmDialogCloseBtn" title="Close dialog">✕</button>
      </div>
      <div class="confirm-dialog-body" id="confirmDialogBody">
        <p id="confirmDialogMessage" class="confirm-dialog-message">Are you sure you want to proceed?</p>
        <p id="confirmDialogHint" class="confirm-dialog-hint" hidden></p>
      </div>
      <div class="confirm-dialog-actions">
        <button type="button" class="btn ghost" id="confirmDialogCancelBtn">Cancel</button>
        <button type="button" class="btn danger" id="confirmDialogConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>

  <!-- ===== Shared Manual Waste Entry Modal ===== -->
  <div class="sheet" id="manualWasteModal" hidden>
    <form class="sheet-card manual-waste-sheet-card" id="manualWasteForm" autocomplete="off" onsubmit="return false;">
      <input type="hidden" id="manualWasteItemId" value="">

      <div class="manual-waste-header">
        <div class="manual-waste-title-group">
          <h3 class="manual-waste-title">Log Manual Waste</h3>
          <p class="manual-waste-subtitle">Record spilled, damaged, or expired stock to keep inventory and loss tracking accurate.</p>
        </div>
        <button type="button" class="manual-waste-close-btn" id="closeManualWasteBtn" aria-label="Close dialog" title="Close">✕</button>
      </div>

      <div class="manual-waste-grid">
        <div class="manual-waste-field full-width">
          <label class="manual-waste-label" for="manualWasteItemName">Material / Supply Name <span class="req">*</span></label>
          <div class="manual-waste-combobox" id="manualWasteCombobox">
            <div class="combobox-input-wrap">
              <input type="text" id="manualWasteItemName" class="manual-waste-input combobox-input" placeholder="Search or select raw supply item..." maxlength="100" autocomplete="off">
              <button type="button" class="combobox-toggle-btn" id="manualWasteComboboxToggle" aria-label="Toggle supply list" tabindex="-1">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </button>
            </div>
            
            <div class="combobox-menu" id="manualWasteDropdownMenu" hidden>
              <div class="combobox-menu-header">
                <span>Raw Supplies Inventory</span>
                <span class="combobox-count" id="comboboxItemCount">0 items</span>
              </div>
              <div class="combobox-list" id="manualWasteDropdownList" role="listbox" tabindex="-1"></div>
            </div>
          </div>
          <span id="manualWasteStockHint" class="manual-waste-stock-hint"></span>
        </div>

        <div class="manual-waste-field">
          <label class="manual-waste-label" for="manualWasteUnit">Unit <span class="req">*</span></label>
          <select id="manualWasteUnit" class="manual-waste-input">
            <option value="pcs">pcs</option>
            <option value="g">g</option>
            <option value="ml">ml</option>
          </select>
        </div>

        <div class="manual-waste-field">
          <label class="manual-waste-label" for="manualWasteAmount">Amount Wasted <span class="req">*</span></label>
          <input type="number" id="manualWasteAmount" class="manual-waste-input" placeholder="e.g. 50" step="0.01" min="0.01">
        </div>

        <div class="manual-waste-field">
          <label class="manual-waste-label" for="manualWasteCost">Estimated Cost (₱)</label>
          <input type="number" id="manualWasteCost" class="manual-waste-input" placeholder="Auto-calculated" step="0.01" min="0.01">
        </div>

        <div class="manual-waste-field" style="justify-content: flex-end;">
          <label class="manual-waste-checkbox-wrap">
            <input type="checkbox" id="manualWasteDeductCheck" checked>
            <span>Deduct from Stock</span>
          </label>
        </div>

        <div class="manual-waste-field full-width">
          <label class="manual-waste-label" for="manualWasteReason">Reason / Description</label>
          <input type="text" id="manualWasteReason" class="manual-waste-input" placeholder="e.g. Spilled during preparation, expired batch, cracked cup..." maxlength="255">
        </div>
      </div>

      <div class="manual-waste-actions">
        <div id="manualWasteMessage" class="manual-waste-inline-msg" hidden></div>
        <button type="button" class="btn ghost" id="manualWasteCancelBtn">Cancel</button>
        <button type="button" class="btn primary" id="manualWasteSubmitBtn">Log Waste Entry</button>
      </div>
    </form>
  </div>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <!-- ===== Size Picker Popover ===== -->
  <div class="size-backdrop" id="sizeBackdrop" hidden></div>
  <div class="size-popover" id="sizePopover" role="dialog" aria-modal="true" aria-label="Choose cup size" hidden>
    <div class="size-popover-header">
      <span class="size-popover-title" id="sizePopoverTitle">Choose Size</span>
      <button type="button" class="size-popover-close" id="sizePopoverClose" aria-label="Cancel">✕</button>
    </div>
    <div class="size-options" id="sizeOptions">
      <button type="button" class="size-btn" data-oz="16" data-label="Tall">
        <span class="size-cup size-cup--sm">
          <svg viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M7 8h26l-4 32H11L7 8z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
            <path d="M7 8h26" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </span>
        <span class="size-label">Tall</span>
        <span class="size-oz">16 oz</span>
        <span class="size-price-tag" id="sizePrice16oz">₱0.00</span>
      </button>
      <button type="button" class="size-btn" data-oz="22" data-label="Grande">
        <span class="size-cup size-cup--lg">
          <svg viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M5 6h30l-5 36H10L5 6z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
            <path d="M5 6h30" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
          </svg>
        </span>
        <span class="size-label">Grande</span>
        <span class="size-oz">22 oz</span>
        <span class="size-price-tag" id="sizePrice22oz">₱0.00</span>
      </button>
    </div>
  </div>

  <?php if ($currentRole === 'manager'): ?>
  <!-- ===== Accounts Management modal (Manager Only) ===== -->
  <div class="modal" id="accountsModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>Account Management</h2>
        <div class="modal-search-wrap">
          <input type="search" id="accountSearch" class="search-input" placeholder="Search accounts by name, username, email..." autocomplete="off">
          <button type="button" class="search-clear" id="accountSearchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn primary" id="addNewAccountBtn">+ New Account</button>
          <button type="button" class="btn ghost" data-close>✕ Close</button>
        </div>
      </div>

      <!-- Account Overview Stats Cards -->
      <div class="accounts-overview-grid">
        <div class="account-stat-card">
          <span class="stat-num" id="statTotalAccounts">0</span>
          <span class="stat-label">Total Accounts</span>
        </div>
        <div class="account-stat-card">
          <span class="stat-num" id="statBaristaAccounts">0</span>
          <span class="stat-label">Baristas</span>
        </div>
        <div class="account-stat-card">
          <span class="stat-num" id="statManagerAccounts">0</span>
          <span class="stat-label">Managers</span>
        </div>
        <div class="account-stat-card stat-active">
          <span class="stat-num" id="statActiveAccounts">0</span>
          <span class="stat-label">Active</span>
        </div>
        <div class="account-stat-card stat-pending">
          <span class="stat-num" id="statPendingAccounts">0</span>
          <span class="stat-label">Pending / Inactive</span>
        </div>
      </div>

      <!-- Filter Tabs -->
      <div class="accounts-filter-bar">
        <button type="button" class="acc-filter-tab active" data-filter="all">All Accounts</button>
        <button type="button" class="acc-filter-tab" data-filter="barista">Baristas</button>
        <button type="button" class="acc-filter-tab" data-filter="manager">Managers</button>
        <button type="button" class="acc-filter-tab" data-filter="active">Active</button>
        <button type="button" class="acc-filter-tab" data-filter="pending">Pending Approval</button>
      </div>

      <div class="modal-body">
        <div class="accounts-table-container">
          <table class="table">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>2FA</th>
                <th>Created</th>
                <th>Last Login</th>
                <th class="act">Actions</th>
              </tr>
            </thead>
            <tbody id="accountsTableBody">
              <!-- Rendered via JS -->
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /.modal-card -->

    <!-- Add / Edit Account Sheet -->
    <div class="sheet" id="accountSheet" hidden>
      <form class="sheet-card" id="accountForm" autocomplete="off" novalidate>
        <div class="sheet-head">
          <h3 id="accountSheetTitle">Create New Account</h3>
          <button type="button" class="btn-close-sheet" id="accountSheetCloseIcon" title="Close sheet">✕</button>
        </div>
        <input type="hidden" id="accUserId" value="">
        <input type="hidden" id="accCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="sheet-grid-2">
          <label>Full Name *
            <input type="text" id="accFullName" maxlength="100" placeholder="e.g. Maria Santos" required>
          </label>
          <label>Username *
            <input type="text" id="accUsername" maxlength="50" placeholder="e.g. maria_barista" required>
          </label>
        </div>

        <div class="sheet-grid-2" id="accStatusGroup">
          <label>Email Address
            <input type="email" id="accEmail" maxlength="100" placeholder="e.g. maria@cafeflow.com">
          </label>
          <label>Account Status *
            <select id="accStatus" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="pending">Pending Approval</option>
            </select>
          </label>
        </div>

        <div class="sheet-grid-full">
          <label>Role *
            <select id="accRole" required>
              <option value="barista">Barista (Full POS &amp; System Access)</option>
              <option value="manager">Manager (Inventory, Reports &amp; Accounts)</option>
            </select>
          </label>
        </div>

        <div id="accPasswordSection">
          <div class="sheet-grid-2">
            <label><span id="accPassLabel">Password *</span>
              <div class="pass-input-wrap">
                <input type="password" id="accPassword" minlength="6" placeholder="Min. 6 characters">
                <button type="button" class="btn-toggle-pass" data-target="accPassword" tabindex="-1">👁️</button>
              </div>
            </label>
            <label><span id="accPassConfirmLabel">Confirm Password *</span>
              <div class="pass-input-wrap">
                <input type="password" id="accConfirmPassword" minlength="6" placeholder="Repeat password">
                <button type="button" class="btn-toggle-pass" data-target="accConfirmPassword" tabindex="-1">👁️</button>
              </div>
            </label>
          </div>
          <small class="auth-hint" id="accPassHint" hidden style="margin-top: 4px; display: block; color: var(--muted); font-size: 12px;">Leave password blank if you do not wish to change it.</small>
        </div>

        <p class="form-error" id="accountSheetError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="accountSheetCancel">Cancel</button>
          <button type="submit" class="btn primary" id="accountSheetSaveBtn">Save Account</button>
        </div>
      </form>
    </div><!-- /#accountSheet -->

    <!-- Reset Password Sheet -->
    <div class="sheet" id="resetPassSheet" hidden>
      <form class="sheet-card" id="resetPassForm" autocomplete="off" novalidate>
        <div class="sheet-head">
          <h3>Reset Account Password</h3>
          <button type="button" class="btn-close-sheet" id="resetPassCloseIcon" title="Close sheet">✕</button>
        </div>
        <p class="muted" style="margin: 0; font-size: 13px;">Resetting password for: <strong id="resetTargetUsername" style="color: var(--espresso);">--</strong></p>
        <input type="hidden" id="resetUserId" value="">
        <input type="hidden" id="resetCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="sheet-grid-2">
          <label>New Password *
            <div class="pass-input-wrap">
              <input type="password" id="resetNewPass" minlength="6" placeholder="Min. 6 characters" required>
              <button type="button" class="btn-toggle-pass" data-target="resetNewPass" tabindex="-1">👁️</button>
            </div>
          </label>
          <label>Confirm Password *
            <div class="pass-input-wrap">
              <input type="password" id="resetConfirmPass" minlength="6" placeholder="Repeat password" required>
              <button type="button" class="btn-toggle-pass" data-target="resetConfirmPass" tabindex="-1">👁️</button>
            </div>
          </label>
        </div>

        <p class="form-error" id="resetPassError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="resetPassCancel">Cancel</button>
          <button type="submit" class="btn primary" id="resetPassSaveBtn">Set New Password</button>
        </div>
      </form>
    </div><!-- /#resetPassSheet -->

    <!-- Reset Employee 2FA Sheet -->
    <div class="sheet" id="reset2faSheet" hidden>
      <form class="sheet-card" id="reset2faForm" autocomplete="off" novalidate>
        <div class="sheet-head">
          <h3 style="color: var(--amber);">Reset Employee 2FA</h3>
          <button type="button" class="btn-close-sheet" id="reset2faCloseIcon" title="Close sheet">✕</button>
        </div>
        <p class="muted" style="margin: 0; font-size: 13px;">Resetting 2FA for: <strong id="reset2faTargetUsername" style="color: var(--espresso);">--</strong> (<span id="reset2faTargetFullName">--</span>)</p>
        <div style="background: var(--amber-light); border: 1px solid rgba(232,98,58,0.3); border-radius: var(--radius-sm); padding: 10px 12px; font-size: 12.5px; color: var(--espresso); line-height: 1.45;">
          ⚠️ <strong>Security Action:</strong> This will erase the employee's existing Google Authenticator secret and recovery codes. The employee will be required to re-enroll with Google Authenticator upon their next login.
        </div>
        <input type="hidden" id="reset2faUserId" value="">
        <input type="hidden" id="reset2faCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

        <label style="display: block;">Confirm Manager Password *
          <div class="pass-input-wrap">
            <input type="password" id="reset2faManagerPass" placeholder="Enter your manager password" required>
            <button type="button" class="btn-toggle-pass" data-target="reset2faManagerPass" tabindex="-1">👁️</button>
          </div>
        </label>

        <p class="form-error" id="reset2faError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="reset2faCancel">Cancel</button>
          <button type="submit" class="btn" id="reset2faSubmitBtn" style="background: var(--amber); color: #fff; font-weight: 800;">Confirm Reset 2FA</button>
        </div>
      </form>
    </div><!-- /#reset2faSheet -->

  </div><!-- /#accountsModal -->
  <?php endif; ?>

  <!-- ===== Account Security & Two-Factor Authentication Modal (All Personnel) ===== -->
  <div class="modal" id="security2faModal" hidden>
    <div class="modal-card modal-card-2fa" style="max-width: 540px; margin: auto; height: auto; max-height: calc(100vh - 100px); border-radius: var(--radius-xl); overflow: hidden; display: flex; flex-direction: column;">
      <div class="modal-head">
        <div style="display: flex; align-items: center; gap: 10px;">
          <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center;">
            <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
          <div>
            <h2 style="margin: 0; font-size: 19px;">Account Security</h2>
            <p style="margin: 0; font-size: 12px; color: var(--muted);">Two-Factor Authentication (Google Authenticator)</p>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn ghost" id="closeSecurity2faBtn">✕ Close</button>
        </div>
      </div>

      <div class="modal-body" style="padding: 24px 28px 28px;">
        <!-- Status View (When 2FA is Disabled or Enabled) -->
        <div id="sec2faStatusView">
          <div id="sec2faStatusCard" style="background: var(--cream); border: 1.5px solid var(--line); border-radius: var(--radius); padding: 18px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
              <span style="font-size: 14px; font-weight: 800; color: var(--espresso);">2FA Status</span>
              <span id="sec2faStatusBadge" class="status-badge" style="font-size: 12px;">--</span>
            </div>
            <p id="sec2faStatusDesc" style="font-size: 13.5px; color: var(--ink-mid); margin: 0; line-height: 1.45;"></p>
            <div id="sec2faMetaInfo" style="margin-top: 10px; font-size: 12px; color: var(--muted);" hidden></div>
          </div>

          <!-- Actions when Disabled -->
          <div id="sec2faDisabledActions">
            <button type="button" class="btn primary" id="start2faEnrollBtn" style="width: 100%; height: 44px; font-weight: 800;">
              Enable Two-Factor Authentication
            </button>
          </div>

          <!-- Actions when Enabled -->
          <div id="sec2faEnabledActions" hidden style="display: flex; flex-direction: column; gap: 10px;">
            <button type="button" class="btn" id="openRegenCodesBtn" style="width: 100%; height: 42px; background: var(--parchment-mid); color: var(--espresso); font-weight: 700; border: 1.5px solid var(--line);">
              Regenerate Recovery Codes
            </button>
            <button type="button" class="btn" id="openDisable2faBtn" style="width: 100%; height: 42px; background: var(--red-light); color: var(--red); font-weight: 700; border: 1.5px solid rgba(184,50,38,0.2);">
              Disable Two-Factor Authentication
            </button>
          </div>
        </div>

        <!-- Enrollment Flow Sheet / View -->
        <div id="sec2faEnrollView" hidden>
          <!-- Step 1: Password Check to Start -->
          <div id="sec2faEnrollStepPassword">
            <h3 style="font-size: 16px; margin: 0 0 8px; color: var(--espresso);">Verify Your Password</h3>
            <p style="font-size: 13px; color: var(--muted); margin: 0 0 16px;">Please confirm your current password to begin 2FA setup.</p>
            <form id="sec2faPasswordForm" novalidate>
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">Current Password</label>
                <div class="pass-input-wrap">
                  <input type="password" id="sec2faCurrentPass" class="search-input" style="width: 100%;" placeholder="Enter your password" required>
                  <button type="button" class="btn-toggle-pass" data-target="sec2faCurrentPass" tabindex="-1">👁️</button>
                </div>
              </div>
              <p class="form-error" id="sec2faPassError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
              <div style="display: flex; gap: 10px;">
                <button type="button" class="btn ghost" id="sec2faPassCancelBtn" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn primary" id="sec2faPassSubmitBtn" style="flex: 1;">Continue →</button>
              </div>
            </form>
          </div>

          <!-- Step 2: Show Setup Key & Verification Input -->
          <div id="sec2faEnrollStepKey" hidden>
            <h3 style="font-size: 16px; margin: 0 0 6px; color: var(--espresso);">Connect Google Authenticator</h3>
            <p style="font-size: 12.5px; color: var(--muted); margin: 0 0 12px; line-height: 1.4;">
              1. Open Google Authenticator on your mobile device.<br>
              2. Tap <strong>+</strong> and choose <strong>Enter a setup key</strong>.<br>
              3. Set Account: <strong id="sec2faAccountLabel">CafeFlow</strong>.<br>
              4. Enter the Setup Key below and select <strong>Time-based</strong>:
            </p>

            <div style="background: #F2EBE0; border: 1.5px dashed #2D6A4F; border-radius: var(--radius-sm); padding: 12px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
              <span id="sec2faSetupKeyDisplay" style="font-family: monospace; font-size: 16px; font-weight: 800; color: #1B4332; letter-spacing: 1.5px; user-select: all;">---- ---- ---- ----</span>
              <button type="button" class="btn small" id="sec2faCopyKeyBtn" style="background: #1B4332; color: #fff; font-size: 12px; padding: 6px 12px; border-radius: 6px; white-space: nowrap;">Copy Key</button>
            </div>

            <form id="sec2faConfirmForm" novalidate>
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; text-align: center;">Enter 6-Digit Authenticator Code</label>
                <input type="text" id="sec2faConfirmCodeInput" class="search-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" style="text-align: center; font-family: monospace; font-size: 24px; font-weight: 800; letter-spacing: 8px; height: 50px; width: 100%; box-sizing: border-box;" placeholder="······">
              </div>
              <p class="form-error" id="sec2faConfirmError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
              <div style="display: flex; gap: 10px;">
                <button type="button" class="btn ghost" id="sec2faConfirmCancelBtn" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn primary" id="sec2faConfirmSubmitBtn" style="flex: 1;">Activate 2FA</button>
              </div>
            </form>
          </div>

          <!-- Step 3: Show Recovery Codes on Success -->
          <div id="sec2faEnrollStepSuccess" hidden>
            <div class="auth-alert success" style="margin-bottom: 14px;">
              <span>✓ Two-Factor Authentication is now active!</span>
            </div>
            <h3 style="font-size: 15px; margin: 0 0 6px; color: var(--espresso);">Emergency Recovery Codes</h3>
            <p style="font-size: 12.5px; color: var(--muted); margin: 0 0 12px; line-height: 1.4;">
              Save these recovery codes immediately. If you lose access to your phone, each code can be used once to sign in.
            </p>
            <div class="recovery-codes-grid" id="sec2faRecoveryCodesGrid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px;"></div>
            <div style="display: flex; gap: 8px; margin-bottom: 16px;">
              <button type="button" class="btn ghost" id="sec2faCopyAllCodesBtn" style="flex: 1; font-size: 13px;">Copy All</button>
              <button type="button" class="btn ghost" id="sec2faDownloadCodesBtn" style="flex: 1; font-size: 13px;">Download (.txt)</button>
            </div>
            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--ink-mid); cursor: pointer; margin-bottom: 18px;">
              <input type="checkbox" id="sec2faAcknowledgeBox" style="margin-top: 2px;">
              <span>I have saved these emergency recovery codes in a secure location.</span>
            </label>
            <button type="button" class="btn primary" id="sec2faFinishEnrollBtn" disabled style="width: 100%; height: 44px; font-weight: 800;">
              Finish &amp; Close
            </button>
          </div>
        </div>

        <!-- Disable 2FA Sheet / View -->
        <div id="sec2faDisableView" hidden>
          <h3 style="font-size: 16px; margin: 0 0 8px; color: var(--red);">Disable Two-Factor Authentication</h3>
          <p style="font-size: 13px; color: var(--muted); margin: 0 0 16px;">This will remove Google Authenticator protection from your account. Enter your password and current 6-digit code to confirm:</p>
          <form id="sec2faDisableForm" novalidate>
            <div style="margin-bottom: 14px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">Current Password</label>
              <div class="pass-input-wrap">
                <input type="password" id="sec2faDisablePass" class="search-input" style="width: 100%;" placeholder="Enter your password" required>
                <button type="button" class="btn-toggle-pass" data-target="sec2faDisablePass" tabindex="-1">👁️</button>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">6-Digit Authenticator Code</label>
              <input type="text" id="sec2faDisableCode" class="search-input" maxlength="6" inputmode="numeric" style="text-align: center; font-family: monospace; font-size: 20px; font-weight: 800; letter-spacing: 6px; width: 100%; box-sizing: border-box;" placeholder="······" required>
            </div>
            <p class="form-error" id="sec2faDisableError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
            <div style="display: flex; gap: 10px;">
              <button type="button" class="btn ghost" id="sec2faDisableCancelBtn" style="flex: 1;">Cancel</button>
              <button type="submit" class="btn" id="sec2faDisableSubmitBtn" style="flex: 1; background: var(--red); color: #fff; font-weight: 700;">Disable 2FA</button>
            </div>
          </form>
        </div>

        <!-- Regenerate Recovery Codes Sheet / View -->
        <div id="sec2faRegenView" hidden>
          <h3 style="font-size: 16px; margin: 0 0 8px; color: var(--espresso);">Regenerate Emergency Recovery Codes</h3>
          <p style="font-size: 13px; color: var(--muted); margin: 0 0 16px;">This will invalidate all current recovery codes and generate 8 new codes. Enter your password and current 6-digit code to confirm:</p>
          <form id="sec2faRegenForm" novalidate>
            <div style="margin-bottom: 14px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">Current Password</label>
              <div class="pass-input-wrap">
                <input type="password" id="sec2faRegenPass" class="search-input" style="width: 100%;" placeholder="Enter your password" required>
                <button type="button" class="btn-toggle-pass" data-target="sec2faRegenPass" tabindex="-1">👁️</button>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">6-Digit Authenticator Code</label>
              <input type="text" id="sec2faRegenCode" class="search-input" maxlength="6" inputmode="numeric" style="text-align: center; font-family: monospace; font-size: 20px; font-weight: 800; letter-spacing: 6px; width: 100%; box-sizing: border-box;" placeholder="······" required>
            </div>
            <p class="form-error" id="sec2faRegenError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
            <div style="display: flex; gap: 10px;">
              <button type="button" class="btn ghost" id="sec2faRegenCancelBtn" style="flex: 1;">Cancel</button>
              <button type="submit" class="btn primary" id="sec2faRegenSubmitBtn" style="flex: 1;">Generate New Codes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

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
  <script src="assets/js/app.js?v=<?= $ver('assets/js/app.js') ?>" defer></script>
</body>
</html>
