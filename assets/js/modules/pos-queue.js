/**
 * CafeFlow POS - Barista Queue & Kitchen Display Module
 * Handles automated polling of pending kitchen tickets, elapsed wait-time badges,
 * order completion with stock deduction, and order cancellation/voiding.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, h, toast, emptyState } = window.CafeFlow.utils;
  const api = window.CafeFlow.api;

  let polling = false;
  const doneIds = new Set();
  let lastOrders = [];

  let activeCancelTxId = null;
  let activeCancelStatus = null;

  function setConn(ok) {
    const el = $('#connState');
    if (!el) return;
    el.textContent = ok ? 'Live' : 'Reconnecting…';
    el.classList.toggle('down', !ok);
  }

  async function pollQueue() {
    if (polling) return;
    polling = true;
    try {
      const data = await api('fetch_queue.php');
      setConn(true);
      renderQueue(data.orders || []);
      const low = $('#lowBadge');
      if (low) {
        low.hidden = data.low_stock === 0;
        low.textContent = data.low_stock;
      }
    } catch (_) {
      setConn(false);
    } finally {
      polling = false;
    }
  }

  const ageLabel = m => m < 1 ? 'Just now' : m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';

  function buildTicket(o) {
    const completeBtn = h('button', { type: 'button', class: 'complete', text: 'Mark Complete' });
    completeBtn.addEventListener('click', () => completeOrder(o.transaction_id, completeBtn));

    const cancelBtn = h('button', {
      type: 'button',
      class: 'btn ghost small',
      style: 'margin-top: 6px; width: 100%; border-color: rgba(224, 82, 82, 0.3); color: var(--rose);',
      text: '✕ Cancel Order'
    });
    cancelBtn.addEventListener('click', () => openCancelOrderModal(o.transaction_id, 'Pending'));

    return h('article', { class: 'ticket', dataset: { id: o.transaction_id } },
      h('div', { class: 'ticket-inner' },
        h('header', {},
          h('strong', { class: 'ticket-no', text: '#' + o.transaction_id }),
          h('span', { class: 'pill-pay ' + o.payment_method.toLowerCase(), text: o.payment_method }),
          h('span', { class: 'ticket-age' })),
        h('ul', {}, o.items.map(i => h('li', {}, h('b', { text: i.quantity + '×' }), ' ' + i.name))),
        completeBtn,
        cancelBtn));
  }

  function renderQueue(raw) {
    lastOrders = raw;
    for (const id of Array.from(doneIds)) {
      if (!raw.some(o => o.transaction_id === id)) doneIds.delete(id);
    }
    const orders = raw.filter(o => !doneIds.has(o.transaction_id));
    const list = $('#queueList');
    if (!list) return;

    const present = new Map($$('.ticket', list).map(el => [Number(el.dataset.id), el]));
    const wanted = new Set(orders.map(o => o.transaction_id));

    for (const [id, el] of present) {
      if (!wanted.has(id)) el.remove();
    }
    for (const o of orders) {
      if (!present.has(o.transaction_id)) list.append(buildTicket(o));
    }

    const emptyEl = $('.cf-empty-state', list) || $('.empty', list);
    if (orders.length && emptyEl) emptyEl.remove();
    if (!orders.length && !emptyEl) {
      list.append(emptyState({
        icon: '',
        title: 'All Orders Served',
        desc: 'Preparation queue is clear. New customer tickets sync automatically.',
        tip: 'Wait time: Green < 5m, Amber < 10m, Red > 10m.',
        compact: true
      }));
    }

    for (const o of orders) {
      const el = $(`.ticket[data-id="${o.transaction_id}"]`, list);
      if (!el) continue;
      const mins = Math.floor(o.age_sec / 60);
      const ageEl = $('.ticket-age', el);
      if (ageEl) ageEl.textContent = ageLabel(mins);
      el.classList.remove('age-ok', 'age-warn', 'age-late');
      el.classList.add(mins < 5 ? 'age-ok' : mins < 10 ? 'age-warn' : 'age-late');
    }

    const countEl = $('#queueCount');
    if (countEl) countEl.textContent = orders.length;
    const badge = $('#queueBadge');
    if (badge) {
      badge.hidden = orders.length === 0;
      badge.textContent = orders.length;
    }
  }

  async function completeOrder(id, btn) {
    btn.disabled = true;
    btn.textContent = 'Completing…';
    try {
      const res = await api('complete_order.php', { transaction_id: id });
      doneIds.add(id);
      renderQueue(lastOrders);
      if (res.low_stock && res.low_stock.length) {
        toast('Low stock: ' + res.low_stock.join(', '), 'warn', 4500);
      }
      if (window.CafeFlow.menu && window.CafeFlow.menu.loadMenu) {
        window.CafeFlow.menu.loadMenu();
      }
      pollQueue();
    } catch (e) {
      toast(e.message, 'error', 4500);
      btn.disabled = false;
      btn.textContent = 'Mark Complete';
      pollQueue();
    }
  }

  const PENDING_REASONS = [
    {
      group: 'Unprepared (Return Stock to Inventory)',
      options: [
        { label: 'Customer changed mind (Not prepared)', val: 'Customer changed mind (Not prepared)', waste: false },
        { label: 'Cashier error / Wrong item or size', val: 'Cashier error / Wrong item or size', waste: false },
        { label: 'Duplicate order entered', val: 'Duplicate order entered', waste: false },
        { label: 'Customer walked out / Payment cancelled', val: 'Customer walked out / Payment cancelled', waste: false },
        { label: 'Other reason (No ingredients wasted)', val: 'Other reason (No materials wasted)', waste: false }
      ]
    },
    {
      group: 'Kitchen Spillage & Waste (Deduct & Log to Waste)',
      options: [
        { label: 'Accidental spillage / Dropped drink (Waste)', val: 'Accidental spillage / Dropped drink (Waste)', waste: true },
        { label: 'Preparation mistake / Burned espresso (Waste)', val: 'Preparation mistake / Burned espresso (Waste)', waste: true },
        { label: 'Curdled / spoiled milk during steaming (Waste)', val: 'Curdled / spoiled milk during steaming (Waste)', waste: true },
        { label: 'Machine or equipment issue during brew (Waste)', val: 'Machine or equipment issue during brew (Waste)', waste: true },
        { label: 'Other reason (Ingredients wasted / spilled)', val: 'Other reason (Ingredients wasted / spilled)', waste: true }
      ]
    }
  ];

  const COMPLETED_REASONS = [
    {
      group: 'Void Completed Order (Logs to Waste & Loss)',
      options: [
        { label: 'Customer return / Taste complaint (Waste)', val: 'Customer return / Taste complaint (Waste)', waste: true },
        { label: 'Order prepared incorrectly & remade (Waste)', val: 'Order prepared incorrectly & remade (Waste)', waste: true },
        { label: 'Customer requested refund after preparation (Waste)', val: 'Customer requested refund after preparation (Waste)', waste: true },
        { label: 'Defective cup or leaking beverage (Waste)', val: 'Defective cup or leaking beverage (Waste)', waste: true },
        { label: 'Other completed void reason (Waste)', val: 'Other completed void reason (Waste)', waste: true }
      ]
    }
  ];

  function updateCancelImpactBadge() {
    const select = $('#cancelReasonSelect');
    const badge = $('#cancelInventoryImpactBadge');
    const confirmBtn = $('#confirmCancelOrderBtn');
    if (!select || !badge) return;

    const selectedOpt = select.selectedOptions[0];
    const isWaste = selectedOpt ? selectedOpt.getAttribute('data-waste') === '1' : false;

    if (activeCancelStatus === 'Completed') {
      badge.innerHTML = `<span style="font-size:16px;">⚠</span> <div><b>Physical Ingredients Consumed:</b> Completed drinks cannot be poured back into supply. Consumed ingredients will be recorded in <b>Waste & Loss</b> reports.</div>`;
      badge.style.background = 'rgba(224, 82, 82, 0.12)';
      badge.style.border = '1px solid rgba(224, 82, 82, 0.35)';
      badge.style.color = 'var(--rose, #e05252)';
      if (confirmBtn) confirmBtn.textContent = 'Confirm Void & Log Waste';
    } else if (isWaste) {
      badge.innerHTML = `<span style="font-size:16px;">⚠</span> <div><b>Kitchen Spillage / Waste:</b> Ingredients were spoiled, dropped, or consumed. Stock will <b>REMAIN DEDUCTED</b> and logged to <b>Waste & Loss</b> reports.</div>`;
      badge.style.background = 'rgba(224, 82, 82, 0.12)';
      badge.style.border = '1px solid rgba(224, 82, 82, 0.35)';
      badge.style.color = 'var(--rose, #e05252)';
      if (confirmBtn) confirmBtn.textContent = 'Confirm Waste Cancellation';
    } else {
      badge.innerHTML = `<span style="font-size:16px;">✓</span> <div><b>Inventory Restored:</b> Order was cancelled before preparation. All reserved ingredients will be returned to <b>Inventory</b>. Zero waste recorded.</div>`;
      badge.style.background = 'rgba(46, 125, 50, 0.12)';
      badge.style.border = '1px solid rgba(46, 125, 50, 0.35)';
      badge.style.color = 'var(--green, #2e7d32)';
      if (confirmBtn) confirmBtn.textContent = 'Confirm Cancellation';
    }
  }

  function openCancelOrderModal(txId, status = 'Pending') {
    activeCancelTxId = txId;
    activeCancelStatus = status;
    const titleEl = $('#cancelOrderModalTitle');
    const descEl = $('#cancelOrderModalDesc');
    const reasonSelect = $('#cancelReasonSelect');
    const reasonInp = $('#cancelReasonInput');

    if (reasonInp) reasonInp.value = '';

    const groups = (status === 'Completed') ? COMPLETED_REASONS : PENDING_REASONS;
    if (reasonSelect) {
      reasonSelect.textContent = '';
      groups.forEach(g => {
        const og = document.createElement('optgroup');
        og.label = '── ' + g.group + ' ──';
        g.options.forEach(opt => {
          const o = document.createElement('option');
          o.value = opt.val;
          o.textContent = opt.label;
          o.setAttribute('data-waste', opt.waste ? '1' : '0');
          og.appendChild(o);
        });
        reasonSelect.appendChild(og);
      });
      reasonSelect.selectedIndex = 0;
    }

    if (status === 'Completed') {
      if (titleEl) titleEl.textContent = 'Void Completed Transaction #' + txId;
      if (descEl) descEl.textContent = 'Warning: This transaction was already completed. Voiding will record consumed materials to Waste & Loss and exclude the sale from revenue.';
    } else {
      if (titleEl) titleEl.textContent = 'Cancel Order #' + txId;
      if (descEl) descEl.textContent = 'Choose a cancellation reason to determine whether ingredients are returned to stock or logged as kitchen spillage.';
    }

    updateCancelImpactBadge();

    const modal = $('#cancelOrderModal');
    if (modal) {
      modal.hidden = false;
      if (reasonSelect) reasonSelect.focus();
    }
  }

  function closeCancelOrderModal() {
    const cm = $('#cancelOrderModal');
    if (cm) cm.hidden = true;
    activeCancelTxId = null;
    activeCancelStatus = null;
  }

  async function submitCancelOrder() {
    if (!activeCancelTxId) return;

    const select = $('#cancelReasonSelect');
    const selectedOpt = select ? select.selectedOptions[0] : null;
    const isWaste = selectedOpt ? (selectedOpt.getAttribute('data-waste') === '1') : false;
    const baseReason = select ? select.value : '';
    const notes = $('#cancelReasonInput')?.value?.trim();
    const finalReason = notes ? `${baseReason} - ${notes}` : baseReason;

    if (!finalReason || finalReason.length < 3) {
      toast('Please choose or enter a cancellation reason.', 'error', 3500);
      return;
    }

    const btn = $('#confirmCancelOrderBtn');
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Processing…';
    }

    try {
      const res = await api('cancel_order.php', {
        transaction_id: activeCancelTxId,
        reason: finalReason,
        is_waste: isWaste
      });
      closeCancelOrderModal();
      toast(res.message || 'Order updated successfully.', 'success', 3500);
      pollQueue();
      if (window.CafeFlow.menu && window.CafeFlow.menu.loadMenu) {
        window.CafeFlow.menu.loadMenu();
      }
      if (window.CafeFlow.state.currentView === 'report' && window.CafeFlow.reports && window.CafeFlow.reports.loadReportData) {
        window.CafeFlow.reports.loadReportData();
      }
    } catch (err) {
      toast(err.message, 'error', 4500);
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Confirm';
      }
    }
  }

  function initQueueEvents() {
    const cancelModalClose = $('#cancelOrderModalClose');
    if (cancelModalClose) cancelModalClose.addEventListener('click', closeCancelOrderModal);
    const cancelOrderDismiss = $('#dismissCancelOrderBtn') || $('#cancelOrderDismissBtn');
    if (cancelOrderDismiss) cancelOrderDismiss.addEventListener('click', closeCancelOrderModal);
    const confirmCancelBtn = $('#confirmCancelOrderBtn');
    if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', submitCancelOrder);
    const reasonSelect = $('#cancelReasonSelect');
    if (reasonSelect) reasonSelect.addEventListener('change', updateCancelImpactBadge);
  }

  window.CafeFlow.queue = {
    pollQueue,
    renderQueue,
    completeOrder,
    openCancelOrderModal,
    closeCancelOrderModal,
    submitCancelOrder,
    initQueueEvents
  };
})();
