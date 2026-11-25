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
