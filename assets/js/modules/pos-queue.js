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
        icon: '✨',
        title: 'All Orders Served',
        desc: 'Preparation queue is clear. New customer tickets sync automatically.',
        tip: '⚡ Wait time: Green < 5m, Amber < 10m, Red > 10m.',
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

  function openCancelOrderModal(txId, status = 'Pending') {
    activeCancelTxId = txId;
    activeCancelStatus = status;
    const titleEl = $('#cancelOrderModalTitle');
    const descEl = $('#cancelOrderModalDesc');
    const confirmBtn = $('#confirmCancelOrderBtn');
    const reasonInp = $('#cancelReasonInput');

    if (reasonInp) reasonInp.value = '';
    if (status === 'Completed') {
      if (titleEl) titleEl.textContent = 'Void Completed Transaction #' + txId;
      if (descEl) descEl.textContent = 'Warning: This will void the completed transaction, reverse all ingredient deductions back into inventory, and exclude the sale from revenue.';
      if (confirmBtn) confirmBtn.textContent = 'Confirm Void & Reverse Inventory';
    } else {
      if (titleEl) titleEl.textContent = 'Cancel Order #' + txId;
      if (descEl) descEl.textContent = 'Are you sure you want to cancel this pending order? It will be removed from the kitchen queue without deducting stock.';
      if (confirmBtn) confirmBtn.textContent = 'Confirm Cancellation';
    }

    const modal = $('#cancelOrderModal');
    if (modal) {
      modal.hidden = false;
      if (reasonInp) reasonInp.focus();
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
    const reason = $('#cancelReasonInput')?.value?.trim();
    if (!reason || reason.length < 3) {
      toast('Please enter a cancellation reason (minimum 3 characters).', 'error', 3500);
      $('#cancelReasonInput')?.focus();
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
        reason: reason
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
