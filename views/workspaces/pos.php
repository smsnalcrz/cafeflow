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
