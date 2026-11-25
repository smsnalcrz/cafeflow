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
