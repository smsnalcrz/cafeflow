/**
 * CafeFlow POS - E-Receipt Module
 * Handles loading, rendering, and printing electronic receipts for transactions.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, h, money, toast } = window.CafeFlow.utils;
  const api = window.CafeFlow.api;

  /**
   * Fetch transaction receipt details by transaction ID and display in receipt modal.
   * @param {number|string} transactionId
   */
  async function viewReceipt(transactionId) {
    try {
      const data = await api(`transactions_api.php?transaction_id=${transactionId}`);
      renderReceipt(data);
      const modal = $('#receiptModal');
      if (modal) modal.hidden = false;
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  /**
   * Render receipt DOM structure inside #receiptModalBody.
   * @param {object} data
   */
  function renderReceipt(data) {
    const { business, receipt } = data;
    const body = $('#receiptModalBody');
    if (!body) return;
    body.textContent = '';

    const paper = h('div', { class: 'receipt-paper' },
      // Store Logo & Info
      h('div', { class: 'receipt-logo' },
        h('h2', { text: `☕ ${business.name}` }),
        h('p', { text: business.system }),
        h('p', { text: business.address }),
        h('p', { text: business.contact }),
        business.tax_id ? h('p', { text: business.tax_id }) : null
      ),
      h('hr', { class: 'receipt-divider' }),

      // Receipt Meta
      h('div', { class: 'receipt-meta-grid' },
        h('span', { text: 'Receipt No:' }), h('strong', { text: receipt.receipt_no }),
        h('span', { text: 'Date:' }), h('strong', { text: receipt.formatted_date }),
        h('span', { text: 'Time:' }), h('strong', { text: receipt.formatted_time }),
        h('span', { text: 'Status:' }), h('strong', { text: receipt.status }),
        h('span', { text: 'Payment:' }), h('strong', { text: receipt.payment_method })
      ),
      h('hr', { class: 'receipt-divider' }),

      // Items Table
      h('table', { class: 'receipt-items-table' },
        h('thead', {}, h('tr', {},
          h('th', { text: 'Item' }),
          h('th', { class: 'num', text: 'Qty' }),
          h('th', { class: 'num', text: 'Price' }),
          h('th', { class: 'num', text: 'Total' })
        )),
        h('tbody', {},
          receipt.items.map(it => h('tr', {},
            h('td', { text: it.name }),
            h('td', { class: 'num', text: String(it.quantity) }),
            h('td', { class: 'num', text: money(it.unit_price) }),
            h('td', { class: 'num', text: money(it.line_total) })
          ))
        )
      ),
      h('hr', { class: 'receipt-divider' }),

      // Totals
      h('div', { class: 'receipt-totals' },
        h('div', { class: 'receipt-total-row' },
          h('span', { text: 'Subtotal:' }),
          h('strong', { text: money(receipt.subtotal) })
        ),
        receipt.discount > 0 ? h('div', { class: 'receipt-total-row' },
          h('span', { text: 'Discount:' }),
          h('strong', { text: '-' + money(receipt.discount) })
        ) : null,
        h('div', { class: 'receipt-total-row grand' },
          h('span', { text: 'TOTAL PAID:' }),
          h('strong', { text: money(receipt.total_amount) })
        ),
        h('div', { class: 'receipt-total-row' },
          h('span', { text: `${receipt.payment_method} Tendered:` }),
          h('strong', { text: money(receipt.amount_paid) })
        ),
        receipt.change_due > 0 ? h('div', { class: 'receipt-total-row' },
          h('span', { text: 'Change Due:' }),
          h('strong', { style: 'color:var(--green);', text: money(receipt.change_due) })
        ) : null
      ),

      // Barcode & Footer
      h('div', { class: 'receipt-barcode', text: `*${receipt.receipt_no.replace('#', '')}*` }),
      h('div', { class: 'receipt-footer' },
        h('p', { text: 'Thank you for your visit!' }),
        h('p', { text: 'Please keep this e-receipt for your records.' })
      )
    );

    body.append(paper);
  }

  /**
   * Wire receipt modal close handlers.
   */
  function initReceiptEvents() {
    const closeReceiptBtn = $('#closeReceiptBtn');
    if (closeReceiptBtn) {
      closeReceiptBtn.addEventListener('click', () => {
        const modal = $('#receiptModal');
        if (modal) modal.hidden = true;
      });
    }

    const receiptModal = $('#receiptModal');
    if (receiptModal) {
      receiptModal.addEventListener('click', (e) => {
        if (e.target === receiptModal) receiptModal.hidden = true;
      });
    }
  }

  // Global helper
  window.viewReceipt = viewReceipt;

  // Export to namespace
  window.CafeFlow.receipt = {
    viewReceipt,
    renderReceipt,
    initReceiptEvents
  };
})();
