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
        🧾 POS <span class="badge" id="queueBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="products">
        🏷️ Products
      </button>
      <?php endif; ?>
      <button type="button" class="nav-btn" data-view="inventory">
        📦 Inventory <span class="badge warn" id="lowBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="report">
        📊 Sales Report
      </button>

      <!-- Profile Button in Topbar (Positioned between Sales Report and Clock) -->
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
            <?php if ($currentRole === 'manager'): ?>
            <button type="button" class="dropdown-item" id="openAccountsBtn">
              <span class="dropdown-item-icon">👥</span>
              <span>Account Management</span>
            </button>
            <?php endif; ?>
            <a href="logout.php" class="dropdown-item danger" id="logoutLink" style="text-decoration: none;">
              <span class="dropdown-item-icon">🚪</span>
              <span>Sign Out</span>
            </a>
          </div>
        </div>
      </div>
    </nav>
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
          <input type="search" id="productSearch" class="search-input" placeholder="🔍 Search product or category..." autocomplete="off">
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
          <button type="button" class="btn ghost small" id="clearCart">✕ Clear</button>
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
            <button type="button" class="pay cash"  data-method="Cash">💵 Checkout · Cash</button>
            <button type="button" class="pay gcash" data-method="GCash">📱 Checkout · GCash</button>
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
      <button type="button" class="mob-pay-btn mob-pay-cash" id="mobPayCashBtn">💵 Cash</button>
      <button type="button" class="mob-pay-btn mob-pay-gcash" id="mobPayGcashBtn">📱 GCash</button>
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
          <span class="mgr-card-icon">📦</span>
        </div>
        <div class="mgr-card-value" id="mgrCardSupplyCount">--</div>
        <p class="muted" style="margin: 0 0 10px;">Monitor stock counts, restock raw materials, and inspect low-stock alerts.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenInventoryBtn">Manage Inventory</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Today's Sales Revenue</span>
          <span class="mgr-card-icon">📊</span>
        </div>
        <div class="mgr-card-value" id="mgrCardSalesToday">₱0.00</div>
        <p class="muted" style="margin: 0 0 10px;">Inspect historical transaction records, date spans, and export spreadsheets.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenReportBtn">View Sales Reports</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Account Administration</span>
          <span class="mgr-card-icon">👥</span>
        </div>
        <div class="mgr-card-value" id="mgrCardUserCount">--</div>
        <p class="muted" style="margin: 0 0 10px;">Create Barista/Manager accounts, approve registrations, and manage credentials.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenAccountsBtn">Manage Accounts</button>
      </div>
    </div>

    <!-- ===== Manager Dashboard Charts (Option B) ===== -->
    <div class="mgr-charts-section" id="mgrChartsSection">
      <div class="mgr-charts-header">
        <h2 class="mgr-charts-title">📈 Today's Sales Overview</h2>
        <span class="mgr-charts-subtitle" id="mgrChartsPeriodLabel">Loading...</span>
      </div>
      <div class="mgr-charts-grid">
        <div class="mgr-chart-card">
          <div class="mgr-chart-card-head">
            <span class="mgr-chart-card-title">Hourly Sales Trend (₱)</span>
          </div>
          <div class="mgr-chart-wrap">
            <canvas id="mgrLineChart" aria-label="Hourly sales trend chart"></canvas>
            <p class="mgr-chart-empty" id="mgrLineChartEmpty" hidden>☕ No hourly data for today yet.</p>
          </div>
        </div>
        <div class="mgr-chart-card">
          <div class="mgr-chart-card-head">
            <span class="mgr-chart-card-title">Top Sellers Today (Units)</span>
          </div>
          <div class="mgr-chart-wrap">
            <canvas id="mgrBarChart" aria-label="Top selling products chart"></canvas>
            <p class="mgr-chart-empty" id="mgrBarChartEmpty" hidden>☕ No product sales recorded today.</p>
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
        <h2>🏷️ Manage Products</h2>
        <div class="modal-search-wrap">
          <input type="search" id="manageProductSearch" class="search-input" placeholder="🔍 Filter products list...">
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
                <button type="button" class="btn-preset-pill" data-preset="coffee">☕ 12oz Hot + 16oz Cold</button>
                <button type="button" class="btn-preset-pill" data-preset="cold">❄️ 16oz + 22oz Cold</button>
                <button type="button" class="btn-preset-pill" data-preset="all">🥤 12oz Hot + 16oz + 22oz Cold</button>
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
              <span class="size-builder-hint">Configure custom cup sizes and Hot ♨️ / Cold ❄️</span>
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
                <span>📦 Add New Inventory Item</span>
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
                <button type="button" class="btn primary small" id="quickSupplySaveBtn">✓ Add to Inventory</button>
              </div>
            </div>

            <!-- Inventory Search Bar -->
            <div class="supply-search-wrap">
              <div class="supply-search-input-box">
                <span class="supply-search-icon" aria-hidden="true">🔍</span>
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
        <h2>📦 Raw Supplies Inventory</h2>
        <div class="modal-search-wrap">
          <input type="search" id="inventorySearch" class="search-input" placeholder="🔍 Search supplies or status..." autocomplete="off">
          <button type="button" class="search-clear" id="inventorySearchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn primary" id="addNewSupplyBtn">+ Add Supply</button>
          <a href="api/export_excel.php?type=inventory" class="btn small" id="exportInventoryExcelBtn" title="Export inventory & recipe audit to Excel (.xlsx)" style="background: var(--amber); color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; padding: 7px 12px; font-weight: 600; border-radius: var(--radius-sm); font-size: 13px;">📥 Export Excel</a>
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
        <h2>📊 Sales Report & Transactions</h2>
        <div class="seg" id="rangeSeg" role="group" aria-label="Report period">
          <button type="button" class="on" data-range="today">Today</button>
          <button type="button" data-range="date">📅 Specific Date</button>
          <button type="button" data-range="7days">📆 7-Day Report</button>
          <button type="button" data-range="month">🗓️ Specific Month</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small" id="toggleCalBtn" title="Open or close visual calendar">📅 Calendar</button>
          <button type="button" class="btn small primary" id="exportExcelBtn" title="Export transactions to Excel spreadsheet">📥 Export Excel (.xlsx)</button>
          <button type="button" class="btn ghost" data-close>✕ Close</button>
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
            <button type="button" class="btn ghost small cal-close-btn" id="calCloseBtn">✕ Close Calendar</button>
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

  <!-- ===== E-Receipt modal ===== -->
  <div class="modal" id="receiptModal" hidden>
    <div class="modal-card receipt-card-wrap">
      <div class="modal-head receipt-head">
        <h2>🧾 Official E-Receipt</h2>
        <div class="modal-actions">
          <button type="button" class="btn ghost" id="closeReceiptBtn">✕ Close</button>
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
        <span style="font-size:24px;">📱</span>
        <h3 style="margin:0;">GCash Payment</h3>
      </div>
      <div class="pay-due">
        <span>Total Due:</span>
        <strong id="gcashDueAmount">₱0.00</strong>
      </div>
      <label>GCash Reference Number (Required)
        <input type="text" id="gcashRefInput" placeholder="e.g. 100234567891" maxlength="30" autocomplete="off" style="font-family: monospace; font-size: 15px; font-weight: 700; letter-spacing: 1px;">
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
        <input type="text" id="cancelReasonInput" placeholder="e.g. Customer changed mind, Out of stock, Entry error" maxlength="255">
      </label>
      <div class="sheet-actions" style="margin-top:16px;">
        <button type="button" class="btn ghost" id="dismissCancelOrderBtn">Keep Order</button>
        <button type="button" class="btn danger" id="confirmCancelOrderBtn">Confirm</button>
      </div>
    </div>
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
        <h2>👥 Account Management</h2>
        <div class="modal-search-wrap">
          <input type="search" id="accountSearch" class="search-input" placeholder="🔍 Search accounts by name, username, email..." autocomplete="off">
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
        <h3 id="accountSheetTitle">Create New Account</h3>
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

        <div class="sheet-grid-2">
          <label>Email Address
            <input type="email" id="accEmail" maxlength="100" placeholder="e.g. maria@cafeflow.com">
          </label>
          <label>Role *
            <select id="accRole" required>
              <option value="barista">Barista (Full POS & System Access)</option>
              <option value="manager">Manager (Inventory, Reports & Accounts)</option>
            </select>
          </label>
        </div>

        <div class="sheet-grid-2" id="accStatusGroup">
          <label>Account Status *
            <select id="accStatus" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="pending">Pending Approval</option>
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
          <small class="auth-hint" id="accPassHint" hidden>Leave password blank if you do not wish to change it.</small>
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
        <h3>Reset Account Password</h3>
        <p class="muted" style="margin-bottom: 12px;">Resetting password for: <strong id="resetTargetUsername">--</strong></p>
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

  </div><!-- /#accountsModal -->
  <?php endif; ?>

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
  <script src="assets/js/script.js?v=<?= $ver('assets/js/script.js') ?>" defer></script>
</body>
</html>
