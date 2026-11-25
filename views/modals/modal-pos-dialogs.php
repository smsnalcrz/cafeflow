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
    <div class="sheet-card" style="max-width: 480px;">
      <h3 id="cancelOrderModalTitle" style="color:var(--rose, #e05252); margin-top:0;">Cancel Order</h3>
      <p id="cancelOrderModalDesc" style="font-size:13.5px; color:var(--muted); margin: 6px 0 14px;">
        Choose a cancellation reason to determine whether ingredients should be returned to stock or logged as kitchen spillage.
      </p>

      <label for="cancelReasonSelect" style="display:flex; flex-direction:column; gap:6px; margin-bottom:12px; width:100%;">
        CANCELLATION REASON (REQUIRED)
        <select id="cancelReasonSelect" style="width:100%; box-sizing:border-box; cursor:pointer;">
          <!-- Dynamically populated by pos-queue.js -->
        </select>
      </label>

      <!-- Dynamic Inventory Impact Badge -->
      <div id="cancelInventoryImpactBadge" style="margin-bottom:12px; padding:10px 14px; border-radius:8px; font-size:12.5px; font-weight:600; line-height:1.4; display:flex; align-items:flex-start; gap:8px;">
      </div>

      <label for="cancelReasonInput" style="display:flex; flex-direction:column; gap:6px; margin-bottom:8px; width:100%;">
        ADDITIONAL DETAILS / NOTES (OPTIONAL)
        <input type="text" id="cancelReasonInput" placeholder="e.g. details on spill, wrong syrup used, or customer remarks..." maxlength="255" style="width:100%; box-sizing:border-box;">
      </label>

      <div class="sheet-actions" style="margin-top:16px;">
        <button type="button" class="btn ghost" id="dismissCancelOrderBtn">Keep Order</button>
        <button type="button" class="btn danger" id="confirmCancelOrderBtn">Confirm Cancellation</button>
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
