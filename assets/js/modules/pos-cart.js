/**
 * CafeFlow POS - POS Cart & Checkout Module
 * Handles line items, quantities, 20% Senior/PWD & custom discounts,
 * cash tender calculator, GCash payment modal, and order checkout dispatch.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, money, h, toast, emptyState } = window.CafeFlow.utils;
  const { MAX_QTY } = window.CafeFlow.config;
  const api = window.CafeFlow.api;
  const state = window.CafeFlow.state;

  const lineCents = l => Math.round(l.price * 100) * l.qty;

  function addToCart(p, oz = null, sizeLabel = null, customPrice = null) {
    if (oz && Array.isArray(p.sizes)) {
      const sObj = p.sizes.find(s => (s.oz === oz || s.code === (oz + 'oz')) && (!sizeLabel || s.label === sizeLabel));
      if (sObj && (sObj.stock_state === 'out' || sObj.available === false)) {
        toast(`${p.name} (${sObj.label || sizeLabel || ''} · ${oz}oz) is currently out of stock.`, 'warn');
        return;
      }
    } else if (p.stock_state === 'out') {
      toast(`${p.name} is currently out of stock.`, 'warn');
      return;
    }

    const sCode = oz ? oz + 'oz' : null;
    const key = oz ? `${p.product_id}:${oz}:${sizeLabel || ''}` : `${p.product_id}:nosize`;
    const price = customPrice !== null ? Number(customPrice) : Number(p.price);

    const line = state.cart.get(key);
    if (line) {
      line.qty = Math.min(line.qty + 1, MAX_QTY);
    } else {
      state.cart.set(key, {
        id: p.product_id,
        key,
        name: p.name,
        price,
        qty: 1,
        oz: oz || null,
        sizeLabel: sizeLabel || null,
        sizeCode: sCode
      });
    }

    renderCart();
    const list = $('#cartLines');
    if (list) list.scrollTop = list.scrollHeight;
  }

  function changeQty(key, delta) {
    const line = state.cart.get(key);
    if (!line) return;

    if (delta > 0) {
      const prod = state.menu.find(m => m.product_id === line.id);
      if (prod) {
        if (line.oz && Array.isArray(prod.sizes)) {
          const sObj = prod.sizes.find(s => s.oz === line.oz || s.code === (line.oz + 'oz'));
          if (sObj && (sObj.stock_state === 'out' || sObj.available === false)) {
            toast(`${line.name} (${sObj.label || line.sizeLabel || ''} · ${line.oz}oz) is currently out of stock.`, 'warn');
            return;
          }
        } else if (prod.stock_state === 'out') {
          toast(`${line.name} is currently out of stock.`, 'warn');
          return;
        }
      }
    }

    line.qty = Math.min(line.qty + delta, MAX_QTY);
    if (line.qty <= 0) state.cart.delete(key);
    renderCart();
  }

  function syncMobOrderBar() {
    const mobBar = $('#mobileOrderBar');
    if (!mobBar) return;
    const lines = Array.from(state.cart.values());
    const count = lines.reduce((sum, l) => sum + l.qty, 0);
    const cents = lines.reduce((sum, l) => sum + lineCents(l), 0);

    if (count > 0 && state.currentView === 'pos') {
      mobBar.hidden = false;
      document.body.classList.add('has-mob-dock');
      const badge = $('#mobOrderBadge');
      if (badge) badge.textContent = String(count);
      const mobTotal = $('#mobOrderTotal');
      if (mobTotal) mobTotal.textContent = money(cents / 100);
    } else {
      mobBar.hidden = true;
      document.body.classList.remove('has-mob-dock');
    }
  }

  function renderCart() {
    const lines = Array.from(state.cart.values());
    const list = $('#cartLines');
    if (!list) return;
    list.textContent = '';

    if (!lines.length) {
      list.append(emptyState({
        icon: '🛒',
        title: 'Order is Empty',
        desc: 'Tap any beverage or pastry from the menu to begin.',
        tip: '💡 20% Senior & PWD discount available below.',
        compact: true
      }));
    }

    for (const l of lines) {
      let pillText = '';
      if (l.sizeLabel) {
        if (l.oz && !l.sizeLabel.toLowerCase().includes(String(l.oz))) {
          pillText = `${l.sizeLabel} · ${l.oz}oz`;
        } else {
          pillText = l.sizeLabel;
        }
      } else if (l.oz) {
        pillText = `${l.oz}oz`;
      }

      const sizePill = pillText
        ? h('span', { class: 'line-size-pill', text: pillText })
        : null;

      list.append(h('div', { class: 'line' },
        h('span', { class: 'line-name' }, document.createTextNode(l.name), sizePill),
        h('span', { class: 'stepper' },
          h('button', { type: 'button', 'aria-label': 'One less ' + l.name, text: '−', onclick: () => changeQty(l.key, -1) }),
          h('b', { text: String(l.qty) }),
          h('button', { type: 'button', 'aria-label': 'One more ' + l.name, text: '+', onclick: () => changeQty(l.key, 1) })),
        h('span', { class: 'line-total', text: money(lineCents(l) / 100) })));
    }

    const count = lines.reduce((sum, l) => sum + l.qty, 0);
    const calc = getCartCalculations();

    const summaryBox = $('#cartDiscountSummary');
    if (summaryBox) {
      if (calc.discType !== 'none') {
        summaryBox.hidden = false;
        const subDisplay = $('#cartSubtotalDisplay');
        if (subDisplay) subDisplay.textContent = money(calc.gross);
        const discDisplay = $('#cartDiscountDisplay');
        if (discDisplay) discDisplay.textContent = '-' + money(calc.discount);
      } else {
        summaryBox.hidden = true;
      }
    }

    const totalEl = $('#cartTotal');
    if (totalEl) totalEl.textContent = money(calc.payable);
    const countEl = $('#cartCount');
    if (countEl) countEl.textContent = count + (count === 1 ? ' item' : ' items');

    const locked = lines.length === 0 || state.submitting;
    const clearBtn = $('#clearCart');
    if (clearBtn) clearBtn.disabled = locked;

    $$('.pay').forEach(b => { b.disabled = locked; });

    if (window.CafeFlow.menu && window.CafeFlow.menu.syncCardBadges) {
      window.CafeFlow.menu.syncCardBadges();
    }
    syncMobOrderBar();
  }

  function getCartCalculations() {
    const lines = Array.from(state.cart.values());
    const grossCents = lines.reduce((sum, l) => sum + lineCents(l), 0);
    const gross = grossCents / 100;

    const discType = $('#cartDiscountType') ? $('#cartDiscountType').value : 'none';
    let discount = 0;
    let tax = 0;
    let payable = gross;

    if (discType === 'senior_pwd') {
      discount = Math.round((gross * 0.20) * 100) / 100;
      tax = 0.00;
      payable = Math.round((gross - discount) * 100) / 100;
    } else if (discType === 'custom_percent') {
      const rate = parseFloat($('#cartDiscountRate')?.value) || 0;
      discount = Math.round((gross * (rate / 100)) * 100) / 100;
      tax = 0.00;
      payable = Math.max(0, Math.round((gross - discount) * 100) / 100);
    } else {
      discount = 0.00;
      tax = 0.00;
      payable = gross;
    }

    return {
      gross,
      discount,
      tax,
      payable,
      discType,
      seniorId: $('#cartSeniorIdNo')?.value?.trim() || '',
      discRate: parseFloat($('#cartDiscountRate')?.value) || 0
    };
  }

  function openPayModal() {
    const calc = getCartCalculations();
    if (state.cart.size === 0) return;
    if (calc.discType === 'senior_pwd' && !calc.seniorId) {
      toast('Please enter Senior Citizen / PWD ID or Booklet Number before checkout.', 'error', 3500);
      $('#cartSeniorIdNo')?.focus();
      return;
    }
    const total = calc.payable;

    const dueEl = $('#payDueAmount');
    if (dueEl) dueEl.textContent = money(total);
    const input = $('#payTenderedInput');
    if (input) {
      input.value = total.toFixed(2);
      updatePayChange(total, total);
    }

    // Build quick amounts
    const quickBox = $('#quickAmounts');
    if (quickBox) {
      quickBox.textContent = '';
      const presets = [total];
      [50, 100, 200, 500, 1000].forEach(p => {
        if (p >= total && !presets.includes(p)) presets.push(p);
      });
      presets.sort((a, b) => a - b);

      presets.forEach(p => {
        quickBox.append(h('button', {
          type: 'button',
          class: 'quick-amt-btn' + (Math.abs(p - total) < 0.01 ? ' active' : ''),
          text: p === total ? 'Exact (' + money(p) + ')' : money(p),
          onclick: (e) => {
            if (input) {
              input.value = p.toFixed(2);
              updatePayChange(p, total);
            }
            $$('.quick-amt-btn', quickBox).forEach(b => b.classList.remove('active'));
            e.currentTarget.classList.add('active');
          }
        }));
      });

      if (input) {
        input.oninput = () => {
          const val = parseFloat(input.value) || 0;
          updatePayChange(val, total);
          $$('.quick-amt-btn', quickBox).forEach(b => {
            const btnVal = parseFloat(b.textContent.replace(/[^0-9.]/g, '')) || 0;
            b.classList.toggle('active', Math.abs(btnVal - val) < 0.01);
          });
        };
      }
    }

    const pm = $('#payModal');
    if (pm) pm.hidden = false;
    if (input) {
      input.focus();
      input.select();
    }
  }

  function updatePayChange(tendered, total) {
    const change = Math.max(0, tendered - total);
    const changeEl = $('#payChangeAmount');
    if (changeEl) changeEl.textContent = money(change);
    const confirmBtn = $('#confirmCashPayBtn');
    if (confirmBtn) confirmBtn.disabled = tendered < total;
  }

  function closePayModal() {
    const pm = $('#payModal');
    if (pm) pm.hidden = true;
  }

  function openGcashModal() {
    const calc = getCartCalculations();
    if (state.cart.size === 0 || state.submitting) return;
    if (calc.discType === 'senior_pwd' && !calc.seniorId) {
      toast('Please enter Senior Citizen / PWD ID or Booklet Number before proceeding.', 'error', 3500);
      $('#cartSeniorIdNo')?.focus();
      return;
    }
    const dueEl = $('#gcashDueAmount');
    if (dueEl) dueEl.textContent = money(calc.payable);
    const refInp = $('#gcashRefInput');
    if (refInp) {
      refInp.value = '';
      const gm = $('#gcashModal');
      if (gm) gm.hidden = false;
      refInp.focus();
    }
  }

  function closeGcashModal() {
    const gm = $('#gcashModal');
    if (gm) gm.hidden = true;
  }

  async function checkout(method, amountPaid = null, extra = {}) {
    if (state.submitting || state.cart.size === 0) return;
    const calc = getCartCalculations();
    if (calc.discType === 'senior_pwd' && !calc.seniorId) {
      toast('Please enter Senior Citizen / PWD ID or Booklet Number.', 'error', 3500);
      $('#cartSeniorIdNo')?.focus();
      return;
    }

    state.submitting = true;
    renderCart();

    try {
      const items = Array.from(state.cart.values()).map(l => ({
        product_id: l.id,
        quantity: l.qty,
        size_oz: l.oz || null,
        size_label: l.sizeLabel || null,
        size_code: l.sizeCode || null
      }));
      const payload = {
        payment_method: method,
        items,
        amount_paid: amountPaid !== null ? Number(amountPaid) : undefined,
        discount_type: calc.discType,
        discount_id_no: calc.discType === 'senior_pwd' ? calc.seniorId : undefined,
        discount_rate: calc.discType === 'custom_percent' ? calc.discRate : undefined,
        ...extra
      };

      const res = await api('checkout.php', payload);
      state.cart.clear();
      closePayModal();

      const subMsg = `#${res.transaction_id} · ${money(res.total)} · ${method}` +
        (res.change_due && parseFloat(res.change_due) > 0 ? ` · Change: ${money(res.change_due)}` : '');

      toast('Order Sent to Queue', 'success', 2600, subMsg);

      if (window.CafeFlow.queue && window.CafeFlow.queue.pollQueue) {
        window.CafeFlow.queue.pollQueue();
      }
      if (window.CafeFlow.menu && window.CafeFlow.menu.loadMenu) {
        window.CafeFlow.menu.loadMenu();
      }
    } catch (e) {
      toast(e.message, 'error', 4500);
    } finally {
      state.submitting = false;
      renderCart();
    }
  }

  function initCartEvents() {
    const clearBtn = $('#clearCart');
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        state.cart.clear();
        renderCart();
      });
    }

    const discSelect = $('#cartDiscountType');
    if (discSelect) {
      discSelect.addEventListener('change', () => {
        const val = discSelect.value;
        const sWrap = $('#seniorIdWrap') || $('#cartSeniorIdRow');
        const cWrap = $('#customPercentWrap') || $('#cartCustomRateRow');
        if (sWrap) sWrap.hidden = (val !== 'senior_pwd');
        if (cWrap) cWrap.hidden = (val !== 'custom_percent');
        renderCart();
      });
    }

    const rateInp = $('#cartDiscountRate');
    if (rateInp) rateInp.addEventListener('input', renderCart);
    const seniorInp = $('#cartSeniorIdNo');
    if (seniorInp) seniorInp.addEventListener('input', renderCart);

    // Payment triggers - Desktop & Mobile
    const payCashBtn = $('.pay.cash') || $('#payCashBtn');
    if (payCashBtn) payCashBtn.addEventListener('click', openPayModal);
    const payGcashBtn = $('.pay.gcash') || $('#payGcashBtn');
    if (payGcashBtn) payGcashBtn.addEventListener('click', openGcashModal);

    const mobGcashBtn = $('#mobPayGcashBtn');
    if (mobGcashBtn) mobGcashBtn.addEventListener('click', openGcashModal);
    const mobCashBtn = $('#mobPayCashBtn');
    if (mobCashBtn) mobCashBtn.addEventListener('click', openPayModal);

    const mobViewBtn = $('#mobOrderViewBtn');
    if (mobViewBtn) {
      mobViewBtn.addEventListener('click', () => {
        const cartEl = $('.cart');
        if (cartEl) cartEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }

    // Cash Modal
    const cancelPayBtn = $('#cancelPayBtn');
    if (cancelPayBtn) cancelPayBtn.addEventListener('click', closePayModal);
    const confirmCashBtn = $('#confirmCashPayBtn');
    if (confirmCashBtn) {
      confirmCashBtn.addEventListener('click', () => {
        const input = $('#payTenderedInput');
        const val = parseFloat(input?.value) || 0;
        checkout('Cash', val);
      });
    }

    // GCash Modal
    const cancelGcashBtn = $('#cancelGcashBtn');
    if (cancelGcashBtn) cancelGcashBtn.addEventListener('click', closeGcashModal);
    const confirmGcashBtn = $('#confirmGcashBtn');
    if (confirmGcashBtn) {
      confirmGcashBtn.addEventListener('click', () => {
        const refInp = $('#gcashRefInput');
        const ref = refInp ? refInp.value.trim() : '';
        if (!ref || ref.length < 6) {
          toast('Please enter a valid GCash Reference Number (minimum 6 characters).', 'error', 3500);
          refInp?.focus();
          return;
        }
        closeGcashModal();
        const calc = getCartCalculations();
        checkout('GCash', calc.payable, { reference_number: ref });
      });
    }
  }

  window.CafeFlow.cart = {
    addToCart,
    changeQty,
    renderCart,
    getCartCalculations,
    syncMobOrderBar,
    openPayModal,
    closePayModal,
    openGcashModal,
    closeGcashModal,
    checkout,
    initCartEvents
  };
})();
