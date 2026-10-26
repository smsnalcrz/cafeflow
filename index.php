<?php
// Cache-bust the CSS/JS by file modification time so the tablet never runs stale code.
$ver = static fn(string $f): int => (int) @filemtime(__DIR__ . '/' . $f);
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
  <link rel="stylesheet" href="style.css?v=<?= $ver('style.css') ?>">
</head>
<body>

  <!-- ===== Top navigation ===== -->
  <header class="topbar">
    <div class="brand">
      <img src="dk_cafe_logo.png" alt="DK Cafe" class="brand-logo">
    </div>
    <nav class="nav" aria-label="Main">
      <button type="button" class="nav-btn active" data-view="pos">
        🧾 POS <span class="badge" id="queueBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="products">
        🏷️ Products
      </button>
      <button type="button" class="nav-btn" data-view="inventory">
        📦 Inventory <span class="badge warn" id="lowBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="report">
        📊 Sales Report
      </button>
    </nav>
    <div class="clock" id="clock" aria-hidden="true"></div>
  </header>

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
      <div class="category-bar" id="categoryBar" role="tablist" aria-label="Product categories"></div>

      <!-- Products Grid -->
      <div class="menu-grid" id="menuGrid"></div>

      <div class="cart" aria-label="Current order">
        <div class="cart-head">
          <h2>Current Order</h2>
          <span class="muted" id="cartCount">0 items</span>
          <button type="button" class="btn ghost small" id="clearCart">✕ Clear</button>
        </div>
        <div class="cart-lines" id="cartLines"></div>
        <div class="cart-foot">
          <div class="total-row">
            <span>Total</span>
            <strong id="cartTotal">₱0.00</strong>
          </div>
          <div class="pay-row">
            <button type="button" class="pay cash"  data-method="Cash">💵 Checkout · Cash</button>
            <button type="button" class="pay gcash" data-method="GCash">📱 Checkout · GCash</button>
          </div>
        </div>
      </div>
    </section>

    <!-- Right 40%: Barista queue -->
    <section class="panel barista" aria-label="Barista queue">
      <div class="panel-head">
        <h2>Barista Queue</h2>
        <span class="pill" id="queueCount">0</span>
        <span class="conn" id="connState">Connecting…</span>
      </div>
      <div class="queue" id="queueList"></div>
    </section>
  </main>

  <!-- ===== Products Management modal ===== -->
  <div class="modal" id="productModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>🏷️ Manage Products</h2>
        <div class="modal-search-wrap">
          <input type="search" id="manageProductSearch" class="search-input" placeholder="🔍 Filter products list...">
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
            <input id="pCategory" list="categoryDatalist" maxlength="50" placeholder="e.g. Coffee" required>
            <datalist id="categoryDatalist"></datalist>
          </label>
        </div>

        <div class="sheet-grid-2">
          <label>Price (₱) *
            <input id="pPrice" inputmode="decimal" placeholder="0.00" required>
          </label>
          <label>Available Stock
            <input id="pStock" inputmode="decimal" placeholder="0">
          </label>
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
          <label class="check-row">
            <input type="checkbox" id="pTrackStock" checked>
            <span>Track stock quantity for this product</span>
          </label>
          <div class="link-sub" id="linkSubSection">
            <div class="link-head">
              <div class="link-title-group">
                <span class="link-title">Link to Raw Inventory Supply (Optional)</span>
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


  <!-- ===== Inventory modal ===== -->
  <div class="modal" id="inventoryModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>📦 Raw Supplies Inventory</h2>
        <div class="modal-actions">
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
          <button type="button" class="btn small primary" id="exportExcelBtn" title="Export transactions to Excel spreadsheet">📥 Export to Excel</button>
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

  <div class="toast" id="toast" role="status" aria-live="polite"></div>

  <script src="script.js?v=<?= $ver('script.js') ?>" defer></script>
</body>
</html>
