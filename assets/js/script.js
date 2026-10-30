/* CafeFlow - Front-end logic. Vanilla JS, no external libraries, works fully offline. */
'use strict';

(() => {
  const POLL_MS = 3000;   // barista queue refresh interval
  const MAX_QTY = 99;

  /* ------------------------------------------------------------------ *
   *  Small helpers
   * ------------------------------------------------------------------ */
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const money = n => '₱' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const qtyFmt = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
  const escapeHtml = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  /** Build a DOM element. Text is always inserted safely as text, never as HTML. */
  function h(tag, props = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [key, val] of Object.entries(props)) {
      if (val == null || val === false) continue;
      if (key === 'class') el.className = val;
      else if (key === 'text') el.textContent = val;
      else if (key === 'dataset') Object.assign(el.dataset, val);
      else if (key.startsWith('on')) el.addEventListener(key.slice(2), val);
      else el.setAttribute(key, val === true ? '' : val);
    }
    for (const kid of kids.flat()) if (kid != null && kid !== false) el.append(kid);
    return el;
  }

  /** fetch() wrapper: supports JSON and FormData, throws friendly Error on failure. */
  async function api(url, body) {
    const finalUrl = (url.startsWith('api/') || url.startsWith('http')) ? url : 'api/' + url;
    let init;
    if (body === undefined) {
      init = { cache: 'no-store' };
    } else if (body instanceof FormData) {
      init = { method: 'POST', body, cache: 'no-store' };
    } else {
      init = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        cache: 'no-store'
      };
    }

    let res;
    try {
      res = await fetch(finalUrl, init);
    } catch (_) {
      throw new Error('Cannot reach the server. Is XAMPP running?');
    }
    let data = null;
    try { data = await res.json(); } catch (_) { /* non-JSON reply */ }

    if (!res.ok || !data || data.success !== true) {
      throw new Error((data && data.error) || 'Something went wrong. Please try again.');
    }
    return data;
  }

  let toastTimer;
  function toast(title, kind = 'success', ms = 2600, sub = '') {
    const el = $('#toast');
    el.textContent = '';
    el.append(h('strong', { text: title }));
    if (sub) el.append(h('span', { text: sub }));
    el.className = 'toast show ' + kind;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  function tickClock() {
    $('#clock').textContent = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
  }

  /* ------------------------------------------------------------------ *
   *  Date & Time helpers
   * ------------------------------------------------------------------ */
  const pad2 = n => String(n).padStart(2, '0');
  const toIsoDate = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
  const toIsoMonth = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`;
  const parseIsoDate = str => {
    const [y, m, d] = str.split('-').map(Number);
    return new Date(y, m - 1, d);
  };
  const addDays = (d, n) => {
    const res = new Date(d);
    res.setDate(res.getDate() + n);
    return res;
  };
  const addMonths = (year, monthIndex, n) => {
    const res = new Date(year, monthIndex + n, 1);
    return { year: res.getFullYear(), month: res.getMonth() };
  };
  const formatHour = h => {
    const ampm = h >= 12 ? 'PM' : 'AM';
    const displayH = h % 12 || 12;
    return `${displayH}:00 ${ampm}`;
  };
  const dayLabel = iso => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
  };

  /* ------------------------------------------------------------------ *
   *  Menu (cashier grid with Search & Category Filters)
   * ------------------------------------------------------------------ */
  let menu = [];
  let menuCategories = [];
  let activeCategory = 'all';
  let searchQuery = '';
  const cardBadges = new Map(); // product_id -> badge element

  async function loadMenu() {
    try {
      const data = await api('fetch_menu.php');
      menu = data.products || [];
      menuCategories = data.categories || [];
      renderCategoryBar();
      renderMenu();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function getCategoryIcon(cat) {
    if (!cat) return '☕';
    const c = cat.toLowerCase();
    if (c.includes('croffle') || c.includes('waffle')) return '🧇';
    if (c.includes('sandwich') || c.includes('toast') || c.includes('burger')) return '🥪';
    if (c.includes('pastry') || c.includes('bakery') || c.includes('croissant')) return '🥐';
    if (c.includes('snack') || c.includes('fries') || c.includes('finger')) return '🍟';
    if (c.includes('boba') || c.includes('milk tea')) return '🧋';
    if (c.includes('fruit tea') || c.includes('lemonade') || c.includes('juice') || c.includes('tea')) return '🍋';
    if (c.includes('milk') || c.includes('matcha') || c.includes('chocolate')) return '🥛';
    if (c.includes('meal') || c.includes('rice') || c.includes('dish')) return '🍛';
    return '☕';
  }

  function renderCategoryBar() {
    const bar = $('#categoryBar');
    if (!bar) return;
    bar.textContent = '';

    const allPill = h('button', {
      type: 'button',
      role: 'tab',
      'aria-selected': activeCategory === 'all' ? 'true' : 'false',
      class: 'cat-pill' + (activeCategory === 'all' ? ' active' : ''),
      text: 'All Items',
      onclick: () => {
        activeCategory = 'all';
        renderCategoryBar();
        renderMenu();
      }
    });
    bar.append(allPill);

    for (const cat of menuCategories) {
      const isSelected = activeCategory.toLowerCase() === cat.toLowerCase();
      const pill = h('button', {
        type: 'button',
        role: 'tab',
        'aria-selected': isSelected ? 'true' : 'false',
        class: 'cat-pill' + (isSelected ? ' active' : ''),
        text: cat,
        onclick: () => {
          activeCategory = cat;
          renderCategoryBar();
          renderMenu();
        }
      });
      bar.append(pill);
    }
  }

  function renderMenu() {
    const grid = $('#menuGrid');
    grid.textContent = '';
    cardBadges.clear();

    if (!menu.length) {
      grid.append(h('div', { class: 'empty' },
        h('p', { text: 'No products in the menu yet.' }),
        h('button', {
          type: 'button',
          class: 'btn primary small',
          style: 'margin-top: 8px;',
          text: '+ Add Your First Product',
          onclick: () => showView('products')
        })
      ));
      return;
    }

    // Filter by category and search keyword
    const filtered = menu.filter(p => {
      const matchCat = activeCategory === 'all' || (p.category && p.category.toLowerCase() === activeCategory.toLowerCase());
      const matchSearch = !searchQuery ||
        p.name.toLowerCase().includes(searchQuery) ||
        (p.category && p.category.toLowerCase().includes(searchQuery));
      return matchCat && matchSearch;
    });

    if (!filtered.length) {
      grid.append(h('div', { class: 'empty' },
        h('p', { text: `No products match "${searchQuery}".` }),
        h('button', {
          type: 'button',
          class: 'btn ghost small',
          style: 'margin-top: 8px;',
          text: 'Clear Search',
          onclick: () => {
            searchQuery = '';
            $('#productSearch').value = '';
            $('#searchClearBtn').hidden = true;
            renderMenu();
          }
        })
      ));
      return;
    }

    for (const p of filtered) {
      const badge = h('span', { class: 'in-cart', hidden: true });

      // Image or placeholder
      let imgNode;
      if (p.image_url) {
        imgNode = h('div', { class: 'item-img-wrap' },
          h('img', {
            src: p.image_url,
            alt: p.name,
            class: 'item-img',
            onerror: function () {
              this.parentElement.className = 'item-img-wrap item-placeholder';
              this.parentElement.textContent = '';
              this.parentElement.append(h('span', { class: 'placeholder-icon', text: getCategoryIcon(p.category) }));
            }
          })
        );
      } else {
        imgNode = h('div', { class: 'item-img-wrap item-placeholder' },
          h('span', { class: 'placeholder-icon', text: getCategoryIcon(p.category) })
        );
      }

      const isOut = p.stock_state === 'out';
      const tag = isOut ? h('span', { class: 'tag out', text: 'Out of stock' })
                : p.stock_state === 'partial' ? (p.partial_label ? h('span', { class: 'tag low', text: p.partial_label }) : null)
                : p.stock_state === 'low' ? h('span', { class: 'tag low', text: 'Low stock' })
                : null;

      let priceText;
      if (p.has_sizes && Array.isArray(p.sizes) && p.sizes.length > 0) {
        const prices = p.sizes.map(s => Number(s.price)).filter(n => !isNaN(n));
        const minP = Math.min(...prices);
        const maxP = Math.max(...prices);
        priceText = minP !== maxP ? `${money(minP)} – ${money(maxP)}` : money(minP);
      } else if (p.has_sizes) {
        priceText = (p.price_16oz && p.price_22oz && p.price_16oz !== p.price_22oz)
          ? `${money(p.price_16oz)} – ${money(p.price_22oz)}`
          : money(p.price_16oz || p.price);
      } else {
        priceText = money(p.price);
      }

      const itemBtn = h('button', {
        type: 'button',
        class: 'item' + (isOut ? ' is-out-of-stock' : ''),
        'aria-disabled': isOut ? 'true' : 'false',
        title: isOut ? `${p.name} is currently out of stock` : '',
        onclick: () => {
          if (isOut) {
            toast(`${p.name} is currently out of stock.`, 'warn');
            return;
          }
          if (p.has_sizes) {
            openSizePicker(p);
          } else {
            addToCart(p, null, null, p.price);
          }
        }
      },
        badge,
        imgNode,
        h('span', { class: 'item-cat', text: p.category || 'General' }),
        h('span', { class: 'item-name', text: p.name }),
        h('div', { class: 'item-foot' },
          h('span', { class: 'item-price', text: priceText }),
          tag
        )
      );

      grid.append(itemBtn);
      cardBadges.set(p.product_id, badge);
    }

    syncCardBadges();
  }

  function syncCardBadges() {
    for (const [productId, badge] of cardBadges) {
      // Sum qty across all sizes for this product (keys are `productId:oz` or `productId:nosize`)
      let total = 0;
      for (const [key, line] of cart) {
        if (line.id === productId) total += line.qty;
      }
      badge.hidden = total === 0;
      if (total > 0) badge.textContent = total;
    }
  }

  /* ------------------------------------------------------------------ *
   *  Size Picker Popover
   * ------------------------------------------------------------------ */
  let _sizePickerProduct = null;

  function openSizePicker(p) {
    if (p.stock_state === 'out') {
      toast(`${p.name} is currently out of stock.`, 'warn');
      return;
    }
    _sizePickerProduct = p;
    $('#sizePopoverTitle').textContent = p.name;
    const sizeOpts = $('#sizeOptions');
    sizeOpts.innerHTML = '';

    // If p.sizes is present, render each available size dynamically
    const isCoffee = (p.category || '').toLowerCase() === 'coffee';
    const fallbackSizes = isCoffee
      ? [
          { code: '12oz', label: '12oz Hot', oz: 12, price: Number(p.price_12oz || p.price), available: true, stock_state: 'ok' },
          { code: '16oz', label: '16oz Cold', oz: 16, price: Number(p.price_16oz || p.price), available: true, stock_state: 'ok' }
        ]
      : [
          { code: '16oz', label: 'Tall', oz: 16, price: Number(p.price_16oz || p.price), available: true, stock_state: 'ok' },
          { code: '22oz', label: 'Grande', oz: 22, price: Number(p.price_22oz || (Number(p.price) + 20)), available: true, stock_state: 'ok' }
        ];

    const sizes = Array.isArray(p.sizes) && p.sizes.length > 0 ? p.sizes : fallbackSizes;

    sizes.forEach(s => {
      const isOut = s.stock_state === 'out' || s.available === false;
      const isHot = (s.temp && s.temp.toLowerCase() === 'hot') ||
                    (s.label && s.label.toLowerCase().includes('hot')) ||
                    s.oz === 12 || s.code === '12oz';
      const isLg  = s.oz === 22 || s.code === '22oz';

      const cupClass = isHot ? 'hot' : (isLg ? 'lg' : 'sm');
      let cupSvg;
      if (isHot) {
        cupSvg = `<svg viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M14 6c0-2 2-3 2-4m5 4c0-2 2-3 2-4m5 4c0-2 2-3 2-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          <path d="M9 13h22l-3.5 25a2 2 0 0 1-2 1.8H14.5a2 2 0 0 1-2-1.8L9 13z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
          <path d="M7 13h26" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
        </svg>`;
      } else if (isLg) {
        cupSvg = `<svg viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M5 6h30l-5 36H10L5 6z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
          <path d="M5 6h30" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
        </svg>`;
      } else {
        cupSvg = `<svg viewBox="0 0 40 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M7 8h26l-4 32H11L7 8z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/>
          <path d="M7 8h26" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
        </svg>`;
      }

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'size-btn' + (isOut ? ' is-out-of-stock' : '');
      btn.disabled = isOut;
      btn.setAttribute('aria-disabled', isOut ? 'true' : 'false');
      btn.title = isOut ? `${p.name} (${s.label} · ${s.oz}oz) is out of stock` : '';
      btn.innerHTML = `
        <span class="size-cup size-cup--${cupClass}">
          ${cupSvg}
        </span>
        <span class="size-label">${s.label}</span>
        <span class="size-oz">${s.oz} oz</span>
        <span class="size-price-tag ${isOut ? 'is-out' : ''}">${isOut ? 'Out of stock' : money(s.price)}</span>
      `;

      btn.addEventListener('click', () => {
        if (isOut) {
          toast(`${p.name} (${s.label} · ${s.oz}oz) is currently out of stock.`, 'warn');
          return;
        }
        addToCart(p, s.oz, s.label, s.price);
        closeSizePicker();
      });

      sizeOpts.appendChild(btn);
    });

    $('#sizeBackdrop').hidden = false;
    $('#sizePopover').hidden = false;
  }

  function closeSizePicker() {
    $('#sizeBackdrop').hidden = true;
    $('#sizePopover').hidden = true;
    _sizePickerProduct = null;
  }

  $('#sizePopoverClose').addEventListener('click', closeSizePicker);
  $('#sizeBackdrop').addEventListener('click', closeSizePicker);

  /* ------------------------------------------------------------------ *
   *  Cart & Checkout (with Cash Tender dialog)
   * ------------------------------------------------------------------ */
  const cart = new Map();     // `${product_id}:${oz|nosize}` -> { id, key, name, price, qty, oz, sizeLabel, sizeCode }
  let submitting = false;

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
    const line = cart.get(key);
    if (line) {
      line.qty = Math.min(line.qty + 1, MAX_QTY);
    } else {
      cart.set(key, {
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
    list.scrollTop = list.scrollHeight;
  }

  function changeQty(key, delta) {
    const line = cart.get(key);
    if (!line) return;
    if (delta > 0) {
      const prod = menu.find(m => m.product_id === line.id);
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
    if (line.qty <= 0) cart.delete(key);
    renderCart();
  }

  let currentView = 'pos';

  function syncMobOrderBar() {
    const mobBar = $('#mobileOrderBar');
    if (!mobBar) return;
    const lines = Array.from(cart.values());
    const count = lines.reduce((sum, l) => sum + l.qty, 0);
    const cents = lines.reduce((sum, l) => sum + lineCents(l), 0);
    if (count > 0 && currentView === 'pos') {
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
    const lines = Array.from(cart.values());
    const list = $('#cartLines');
    list.textContent = '';

    if (!lines.length) {
      list.append(h('p', { class: 'empty', text: '👆 Tap a menu item to start an order.' }));
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

    const cents = lines.reduce((sum, l) => sum + lineCents(l), 0);
    const count = lines.reduce((sum, l) => sum + l.qty, 0);
    $('#cartTotal').textContent = money(cents / 100);
    $('#cartCount').textContent = count + (count === 1 ? ' item' : ' items');

    const locked = lines.length === 0 || submitting;
    $('#clearCart').disabled = locked;
    $$('.pay').forEach(b => { b.disabled = locked; });
    syncCardBadges();
    syncMobOrderBar();
  }

  function openPayModal() {
    const lines = Array.from(cart.values());
    if (!lines.length) return;
    const total = lines.reduce((sum, l) => sum + lineCents(l), 0) / 100;

    $('#payDueAmount').textContent = money(total);
    const input = $('#payTenderedInput');
    input.value = total.toFixed(2);
    updatePayChange(total, total);

    // Build quick amounts
    const quickBox = $('#quickAmounts');
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
          input.value = p.toFixed(2);
          updatePayChange(p, total);
          $$('.quick-amt-btn', quickBox).forEach(b => b.classList.remove('active'));
          e.currentTarget.classList.add('active');
        }
      }));
    });

    input.oninput = () => {
      const val = parseFloat(input.value) || 0;
      updatePayChange(val, total);
      $$('.quick-amt-btn', quickBox).forEach(b => {
        const btnVal = parseFloat(b.textContent.replace(/[^0-9.]/g, '')) || 0;
        b.classList.toggle('active', Math.abs(btnVal - val) < 0.01);
      });
    };

    $('#payModal').hidden = false;
    input.focus();
    input.select();
  }

  function updatePayChange(tendered, total) {
    const change = Math.max(0, tendered - total);
    $('#payChangeAmount').textContent = money(change);
    $('#confirmCashPayBtn').disabled = tendered < total;
  }

  function closePayModal() {
    $('#payModal').hidden = true;
  }

  async function checkout(method, amountPaid = null) {
    if (submitting || cart.size === 0) return;
    submitting = true;
    renderCart();

    try {
      const items = Array.from(cart.values()).map(l => ({
        product_id: l.id,
        quantity: l.qty,
        size_oz: l.oz || null,
        size_label: l.sizeLabel || null,
        size_code: l.sizeCode || null
      }));
      const payload = {
        payment_method: method,
        items,
        amount_paid: amountPaid !== null ? Number(amountPaid) : undefined
      };

      const res = await api('checkout.php', payload);
      cart.clear();
      closePayModal();

      const subMsg = `#${res.transaction_id} · ${money(res.total)} · ${method}` +
        (res.change_due && parseFloat(res.change_due) > 0 ? ` · Change: ${money(res.change_due)}` : '');

      toast('Order Sent to Queue', 'success', 2600, subMsg);
      pollQueue();
      loadMenu(); // update stock indicators
    } catch (e) {
      toast(e.message, 'error', 4500);
    } finally {
      submitting = false;
      renderCart();
    }
  }

  /* ------------------------------------------------------------------ *
   *  Barista Queue (polled)
   * ------------------------------------------------------------------ */
  let polling = false;
  const doneIds = new Set();
  let lastOrders = [];

  function setConn(ok) {
    const el = $('#connState');
    el.textContent = ok ? 'Live' : 'Reconnecting…';
    el.classList.toggle('down', !ok);
  }

  async function pollQueue() {
    if (polling) return;
    polling = true;
    try {
      const data = await api('fetch_queue.php');
      setConn(true);
      renderQueue(data.orders);
      const low = $('#lowBadge');
      low.hidden = data.low_stock === 0;
      low.textContent = data.low_stock;
    } catch (_) {
      setConn(false);
    } finally {
      polling = false;
    }
  }

  const ageLabel = m => m < 1 ? 'Just now' : m < 60 ? m + ' min' : Math.floor(m / 60) + 'h ' + (m % 60) + 'm';

  function buildTicket(o) {
    const btn = h('button', { type: 'button', class: 'complete', text: 'Mark Complete' });
    btn.addEventListener('click', () => completeOrder(o.transaction_id, btn));
    return h('article', { class: 'ticket', dataset: { id: o.transaction_id } },
      h('div', { class: 'ticket-inner' },
        h('header', {},
          h('strong', { class: 'ticket-no', text: '#' + o.transaction_id }),
          h('span', { class: 'pill-pay ' + o.payment_method.toLowerCase(), text: o.payment_method }),
          h('span', { class: 'ticket-age' })),
        h('ul', {}, o.items.map(i => h('li', {}, h('b', { text: i.quantity + '×' }), ' ' + i.name))),
        btn));
  }

  function renderQueue(raw) {
    lastOrders = raw;
    for (const id of Array.from(doneIds)) {
      if (!raw.some(o => o.transaction_id === id)) doneIds.delete(id);
    }
    const orders = raw.filter(o => !doneIds.has(o.transaction_id));
    const list = $('#queueList');

    const present = new Map($$('.ticket', list).map(el => [Number(el.dataset.id), el]));
    const wanted = new Set(orders.map(o => o.transaction_id));
    for (const [id, el] of present) if (!wanted.has(id)) el.remove();
    for (const o of orders) if (!present.has(o.transaction_id)) list.append(buildTicket(o));

    const emptyEl = $('.empty', list);
    if (orders.length && emptyEl) emptyEl.remove();
    if (!orders.length && !emptyEl) {
      list.append(h('p', { class: 'empty', text: '✅ No pending orders. New tickets appear here automatically.' }));
    }

    for (const o of orders) {
      const el = $(`.ticket[data-id="${o.transaction_id}"]`, list);
      if (!el) continue;
      const mins = Math.floor(o.age_sec / 60);
      $('.ticket-age', el).textContent = ageLabel(mins);
      el.classList.remove('age-ok', 'age-warn', 'age-late');
      el.classList.add(mins < 5 ? 'age-ok' : mins < 10 ? 'age-warn' : 'age-late');
    }

    $('#queueCount').textContent = orders.length;
    const badge = $('#queueBadge');
    badge.hidden = orders.length === 0;
    badge.textContent = orders.length;
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
      loadMenu();
      pollQueue();
    } catch (e) {
      toast(e.message, 'error', 4500);
      btn.disabled = false;
      btn.textContent = 'Mark Complete';
      pollQueue();
    }
  }

  /* ------------------------------------------------------------------ *
   *  Navigation: POS / Products / Inventory / Sales Report
   * ------------------------------------------------------------------ */
  function showView(view) {
    currentView = view;
    $$('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));
    $('#productModal').hidden = view !== 'products';
    $('#inventoryModal').hidden = view !== 'inventory';
    $('#reportModal').hidden = view !== 'report';
    $('#receiptModal').hidden = true;
    closePayModal();
    syncMobOrderBar();

    if (view !== 'products') closeProductSheet();
    if (view !== 'inventory') closeSheet();
    if (view !== 'report') {
      calDrawerOpen = false;
      const drawer = $('#reportCalDrawer');
      if (drawer) drawer.hidden = true;
    }

    if (view === 'products') {
      const pSearch = $('#manageProductSearch');
      const pClear = $('#manageProductSearchClearBtn');
      if (pClear && pSearch) pClear.hidden = !pSearch.value.trim();
      loadManageProducts();
    }
    if (view === 'inventory') {
      const invSearch = $('#inventorySearch');
      const invClear = $('#inventorySearchClearBtn');
      if (invSearch) invSearch.value = inventorySearchQuery;
      if (invClear) invClear.hidden = !inventorySearchQuery.trim();
      loadInventory();
    }
    if (view === 'report') loadReport();
  }

  /* ------------------------------------------------------------------ *
   *  Product Management modal
   * ------------------------------------------------------------------ */
  let manageProducts = [];
  let currentProductSheet = { mode: null, product: null };
  let removeCurrentImage = false;

  async function loadManageProducts() {
    try {
      const data = await api('products_api.php');
      manageProducts = data.products || [];

      // Sync any categories from database into the select dropdown
      const catSelect = $('#pCategory');
      if (catSelect && data.categories) {
        const existingValues = new Set(Array.from(catSelect.options).map(o => o.value.toLowerCase()));
        data.categories.forEach(cat => {
          if (cat && !existingValues.has(cat.toLowerCase())) {
            const opt = document.createElement('option');
            opt.value = cat;
            opt.textContent = cat;
            catSelect.appendChild(opt);
            existingValues.add(cat.toLowerCase());
          }
        });
      }

      renderManageProducts();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function renderManageProducts() {
    const tbody = $('#prodTableBody');
    tbody.textContent = '';

    const filterInput = $('#manageProductSearch');
    const filterClear = $('#manageProductSearchClearBtn');
    const filterText = (filterInput ? filterInput.value : '').trim().toLowerCase();
    if (filterClear) filterClear.hidden = !filterText;

    const list = manageProducts.filter(p =>
      !filterText ||
      p.name.toLowerCase().includes(filterText) ||
      (p.category && p.category.toLowerCase().includes(filterText))
    );

    if (!list.length) {
      tbody.append(h('tr', {},
        h('td', { colspan: '7', class: 'empty', text: manageProducts.length ? 'No products match your filter.' : 'No products registered yet. Click "+ Add Product".' })
      ));
      return;
    }

    for (const p of list) {
      const imgCell = p.image_url
        ? h('img', {
            src: p.image_url,
            class: 'table-thumb',
            alt: p.name,
            onerror: function () {
              this.replaceWith(h('div', { class: 'table-thumb-ph', text: '☕' }));
            }
          })
        : h('div', { class: 'table-thumb-ph', text: '☕' });

      const state = p.stock_state || 'ok';
      const stateLabel = { out: 'Out of Stock', low: 'Low Stock', ok: 'In Stock', partial: 'Partial Stock' }[state] || 'In Stock';

      let stockDisplay = '';
      if (p.ingredients && p.ingredients.length > 1) {
        stockDisplay = `${p.ingredients.length} raw supplies linked`;
      } else if (p.ingredients && p.ingredients.length === 1) {
        const ing = p.ingredients[0];
        stockDisplay = `${qtyFmt(ing.deduction_amount)} ${ing.unit_measure || 'g'} / serving`;
      } else if (p.linked_inventory_id) {
        stockDisplay = `${qtyFmt(p.deduction_amount)} ${p.unit_measure || 'g'} / serving`;
      } else {
        stockDisplay = 'Raw Inventory Recipe';
      }

      const priceCell = p.has_sizes
        ? h('div', { class: 'price-size-group' },
            h('span', { class: 'size-tag' }, document.createTextNode('16oz: '), h('b', { text: money(p.price_16oz) })),
            h('span', { class: 'size-tag' }, document.createTextNode('22oz: '), h('b', { text: money(p.price_22oz) }))
          )
        : money(p.price);

      tbody.append(h('tr', {},
        h('td', {}, imgCell),
        h('td', { class: 'name', text: p.name }),
        h('td', {}, h('span', { class: 'cat-pill', style: 'cursor:default;', text: p.category || 'General' })),
        h('td', { class: 'num' }, priceCell),
        h('td', { class: 'num', text: stockDisplay }),
        h('td', {}, h('span', { class: 'tag ' + state, text: stateLabel })),
        h('td', { class: 'act' },
          h('button', { type: 'button', class: 'btn small primary', text: 'Edit', onclick: () => openProductSheet('edit', p) }),
          h('button', { type: 'button', class: 'btn small ghost', text: 'Delete', onclick: () => deleteProduct(p.product_id, p.name) })
        )
      ));
    }
  }

  /* ------------------------------------------------------------------ *
   *  Raw Inventory Supply Linking & Multi-Item Selection
   * ------------------------------------------------------------------ */
  let cachedInventoryItems = [];
  const selectedSupplies = new Map(); // itemId (number) => deduction_amount (number or string)
  let supplySearchQuery = '';

  async function loadSupplyList(initialSelections = new Map()) {
    try {
      const invData = await api('inventory_api.php');
      cachedInventoryItems = invData.items || [];
    } catch (_) {
      cachedInventoryItems = [];
    }
    selectedSupplies.clear();
    for (const [id, amt] of initialSelections.entries()) {
      selectedSupplies.set(Number(id), amt);
    }
    renderSupplyList();
    updateSupplySelectionCount();
  }

  function updateSupplySelectionCount() {
    const countBadge = $('#supplySelectionCount');
    if (!countBadge) return;
    const count = selectedSupplies.size;
    countBadge.textContent = `${count} ${count === 1 ? 'item' : 'items'} selected`;
    countBadge.classList.toggle('has-selected', count > 0);
  }

  function renderSupplyList() {
    const listEl = $('#supplyList');
    if (!listEl) return;
    listEl.textContent = '';

    if (!cachedInventoryItems.length) {
      listEl.append(h('div', { class: 'supply-empty-msg' },
        h('p', { text: 'No inventory items available.' }),
        h('button', {
          type: 'button',
          class: 'btn primary small',
          style: 'margin-top: 8px;',
          text: '+ Add Your First Supply Item',
          onclick: () => {
            const card = $('#quickAddSupplyCard');
            if (card) {
              card.hidden = false;
              $('#quickSupplyName').focus();
            }
          }
        })
      ));
      return;
    }

    const query = supplySearchQuery.trim().toLowerCase();
    const filtered = cachedInventoryItems.filter(it => {
      if (!query) return true;
      return it.name.toLowerCase().includes(query) ||
             it.unit_measure.toLowerCase().includes(query) ||
             String(it.item_id) === query;
    });

    if (!filtered.length) {
      listEl.append(h('div', { class: 'supply-empty-msg' },
        h('p', { text: 'No matching items found.' }),
        h('button', {
          type: 'button',
          class: 'btn ghost small',
          style: 'margin-top: 8px;',
          text: 'Clear Search',
          onclick: () => {
            supplySearchQuery = '';
            const sInp = $('#supplySearchInput');
            if (sInp) sInp.value = '';
            const sClr = $('#supplySearchClearBtn');
            if (sClr) sClr.hidden = true;
            renderSupplyList();
          }
        })
      ));
      return;
    }

    for (const it of filtered) {
      const isSelected = selectedSupplies.has(it.item_id);
      const state = it.current_stock <= 0 ? 'out' : it.low ? 'low' : 'ok';
      const stateLabel = { out: 'Out of Stock', low: 'Low Stock', ok: 'In Stock' }[state];

      const chk = h('input', {
        type: 'checkbox',
        class: 'supply-checkbox-custom',
        checked: isSelected,
        title: `Select ${it.name}`,
        onclick: e => e.stopPropagation(),
        onchange: e => {
          if (e.target.checked) {
            selectedSupplies.set(it.item_id, 1);
          } else {
            selectedSupplies.delete(it.item_id);
          }
          renderSupplyList();
          updateSupplySelectionCount();
        }
      });

      const leftCol = h('div', { class: 'supply-item-left' },
        chk,
        h('div', { class: 'supply-meta' },
          h('span', { class: 'supply-name', text: it.name }),
          h('div', { class: 'supply-sub' },
            h('span', { text: 'Stock: ' }),
            h('span', { class: 'supply-stock-num', text: `${qtyFmt(it.current_stock)} ${it.unit_measure}` }),
            h('span', { class: 'tag ' + state, style: 'font-size: 10px; padding: 1px 6px;', text: stateLabel })
          )
        )
      );

      let rightCol;
      if (isSelected) {
        const currentDeduct = selectedSupplies.get(it.item_id) || 1;
        const deductInp = h('input', {
          type: 'number',
          step: 'any',
          min: '0.01',
          class: 'supply-deduct-inp',
          value: String(currentDeduct),
          title: 'Amount deducted per order',
          onclick: e => e.stopPropagation(),
          oninput: e => {
            const val = parseFloat(e.target.value);
            selectedSupplies.set(it.item_id, !isNaN(val) && val > 0 ? val : e.target.value);
          }
        });

        rightCol = h('div', { class: 'supply-item-right', onclick: e => e.stopPropagation() },
          h('div', { class: 'supply-deduct-box' },
            h('span', { class: 'supply-deduct-lbl', text: 'Deduct:' }),
            deductInp,
            h('span', { class: 'supply-unit-lbl', text: `${it.unit_measure} / order` })
          )
        );
      } else {
        rightCol = h('div', { class: 'supply-item-right' },
          h('span', { style: 'font-size: 12px; color: var(--muted);', text: 'Tap to select' })
        );
      }

      const row = h('div', {
        class: 'supply-item' + (isSelected ? ' selected' : ''),
        dataset: { id: it.item_id },
        onclick: () => {
          if (selectedSupplies.has(it.item_id)) {
            selectedSupplies.delete(it.item_id);
          } else {
            selectedSupplies.set(it.item_id, 1);
          }
          renderSupplyList();
          updateSupplySelectionCount();
        }
      }, leftCol, rightCol);

      listEl.append(row);
    }
  }

  /* ------------------------------------------------------------------ *
   *  Dynamic Cup Size Options Management
   * ------------------------------------------------------------------ */
  let productSheetSizes = []; // [{ oz: 12, temp: 'Hot', label: '12oz Hot', price: '100.00' }, ...]

  function renderSizeOptionsList() {
    const list = $('#sizeOptionsList');
    if (!list) return;
    list.innerHTML = '';

    if (productSheetSizes.length === 0) {
      list.innerHTML = `
        <div style="text-align:center; padding:14px 8px; color:var(--muted); font-size:12.5px; border:1px dashed var(--line); border-radius:var(--radius-xs); background:#ffffff;">
          No cup sizes added. Click <strong>+ Add Cup Size</strong> or choose a preset above.
        </div>
      `;
      return;
    }

    productSheetSizes.forEach((item, index) => {
      const row = document.createElement('div');
      row.className = 'size-option-row';

      const isHot = (item.temp || '').toLowerCase() === 'hot';

      row.innerHTML = `
        <!-- Cup Size (oz) -->
        <select class="size-oz-select" data-index="${index}" title="Select cup size in fluid ounces">
          <option value="12" ${item.oz === 12 ? 'selected' : ''}>12 oz</option>
          <option value="16" ${item.oz === 16 ? 'selected' : ''}>16 oz</option>
          <option value="22" ${item.oz === 22 ? 'selected' : ''}>22 oz</option>
          <option value="8"  ${item.oz === 8 ? 'selected' : ''}>8 oz</option>
          <option value="20" ${item.oz === 20 ? 'selected' : ''}>20 oz</option>
          <option value="24" ${item.oz === 24 ? 'selected' : ''}>24 oz</option>
        </select>

        <!-- Temperature -->
        <select class="size-temp-select ${isHot ? 'temp-hot' : 'temp-cold'}" data-index="${index}" title="Select Hot or Cold">
          <option value="Cold" ${!isHot ? 'selected' : ''}>❄️ Cold</option>
          <option value="Hot"  ${isHot ? 'selected' : ''}>♨️ Hot</option>
        </select>

        <!-- Display Label -->
        <input type="text" class="size-label-input" data-index="${index}" value="${item.label ? escapeHtml(item.label) : ''}" placeholder="${item.oz}oz ${item.temp}" title="Display name on POS and receipts">

        <!-- Price (₱) -->
        <div class="size-price-input-wrap">
          <span class="currency-prefix">₱</span>
          <input type="number" step="0.01" min="0" class="size-price-input" data-index="${index}" value="${item.price !== null && item.price !== undefined ? item.price : ''}" placeholder="0.00" inputmode="decimal" required>
        </div>

        <!-- Remove Button -->
        <button type="button" class="btn-remove-size" data-index="${index}" title="Remove this size option">✕</button>
      `;

      const ozSel = row.querySelector('.size-oz-select');
      const tempSel = row.querySelector('.size-temp-select');
      const labelInp = row.querySelector('.size-label-input');
      const priceInp = row.querySelector('.size-price-input');
      const removeBtn = row.querySelector('.btn-remove-size');

      ozSel.addEventListener('change', e => {
        const newOz = parseInt(e.target.value, 10);
        const oldDefaultLabel = `${item.oz}oz ${item.temp}`;
        item.oz = newOz;
        if (!item.label || item.label === oldDefaultLabel) {
          item.label = `${newOz}oz ${item.temp}`;
          labelInp.value = item.label;
        }
        labelInp.placeholder = `${newOz}oz ${item.temp}`;
      });

      tempSel.addEventListener('change', e => {
        const newTemp = e.target.value;
        const oldDefaultLabel = `${item.oz}oz ${item.temp}`;
        item.temp = newTemp;
        tempSel.className = `size-temp-select ${newTemp === 'Hot' ? 'temp-hot' : 'temp-cold'}`;
        if (!item.label || item.label === oldDefaultLabel) {
          item.label = `${item.oz}oz ${newTemp}`;
          labelInp.value = item.label;
        }
        labelInp.placeholder = `${item.oz}oz ${newTemp}`;
      });

      labelInp.addEventListener('input', e => {
        item.label = e.target.value;
      });

      priceInp.addEventListener('input', e => {
        item.price = e.target.value;
      });

      removeBtn.addEventListener('click', () => {
        productSheetSizes.splice(index, 1);
        renderSizeOptionsList();
      });

      list.appendChild(row);
    });
  }

  function addSizeOptionRow(oz = 16, temp = 'Cold', label = '', price = '') {
    const finalLabel = label || `${oz}oz ${temp}`;
    productSheetSizes.push({
      oz: Number(oz),
      temp: temp,
      label: finalLabel,
      price: price
    });
    renderSizeOptionsList();
  }

  function applySizePreset(presetKey) {
    const existingPrices = {};
    productSheetSizes.forEach(s => {
      if (s.price !== '') existingPrices[`${s.oz}_${s.temp}`] = s.price;
      if (s.price !== '') existingPrices[s.oz] = s.price;
    });

    if (presetKey === 'coffee') {
      productSheetSizes = [
        { oz: 12, temp: 'Hot',  label: '12oz Hot',  price: existingPrices['12_Hot'] || existingPrices[12] || '' },
        { oz: 16, temp: 'Cold', label: '16oz Cold', price: existingPrices['16_Cold'] || existingPrices[16] || '' }
      ];
    } else if (presetKey === 'cold') {
      productSheetSizes = [
        { oz: 16, temp: 'Cold', label: '16oz Cold', price: existingPrices['16_Cold'] || existingPrices[16] || '' },
        { oz: 22, temp: 'Cold', label: '22oz Cold', price: existingPrices['22_Cold'] || existingPrices[22] || '' }
      ];
    } else if (presetKey === 'all') {
      productSheetSizes = [
        { oz: 12, temp: 'Hot',  label: '12oz Hot',  price: existingPrices['12_Hot'] || existingPrices[12] || '' },
        { oz: 16, temp: 'Cold', label: '16oz Cold', price: existingPrices['16_Cold'] || existingPrices[16] || '' },
        { oz: 22, temp: 'Cold', label: '22oz Cold', price: existingPrices['22_Cold'] || existingPrices[22] || '' }
      ];
    }
    renderSizeOptionsList();
  }

  function updateProductSizeFields(cat, forceReset = false) {
    const c = (cat || '').toLowerCase();
    const isFood = c.includes('croffle') || c.includes('pastry') || c.includes('sandwich') ||
                   c.includes('snack') || c.includes('meal') || c.includes('dessert') || c.includes('bakery');
    const isCoffee = c === 'coffee';
    const isBev = !isFood;

    const hasSizesCheck = $('#pHasSizes');
    const hasSizesLabel = $('#pHasSizesLabel');
    const sBox = $('#pSizesContainer');
    const bWrap = $('#pBasePriceWrap');

    if (hasSizesCheck) hasSizesCheck.checked = isBev;
    if (sBox) sBox.hidden = !isBev;
    if (bWrap) bWrap.hidden = isBev;

    if (hasSizesLabel) {
      hasSizesLabel.innerHTML = '<strong>Enable Cup Sizes</strong> (Flexible sizes & Hot/Cold options)';
    }

    if (forceReset || productSheetSizes.length === 0) {
      if (isCoffee) {
        applySizePreset('coffee');
      } else if (isBev) {
        applySizePreset('cold');
      } else {
        productSheetSizes = [];
        renderSizeOptionsList();
      }
    }
  }

  async function openProductSheet(mode, prod = null) {
    currentProductSheet = { mode, product: prod };
    removeCurrentImage = false;
    $('#productModal').hidden = false;
    const qCard = $('#quickAddSupplyCard');
    if (qCard) qCard.hidden = true;

    // Reset search
    supplySearchQuery = '';
    const sInput = $('#supplySearchInput');
    if (sInput) sInput.value = '';
    const sClear = $('#supplySearchClearBtn');
    if (sClear) sClear.hidden = true;

    // Build initial selections map
    const initialMap = new Map();
    if (prod) {
      if (prod.ingredients && prod.ingredients.length > 0) {
        prod.ingredients.forEach(ing => {
          initialMap.set(Number(ing.item_id), ing.deduction_amount || 1);
        });
      } else if (prod.linked_inventory_id) {
        initialMap.set(Number(prod.linked_inventory_id), prod.deduction_amount || 1);
      }
    }

    await loadSupplyList(initialMap);

    $('#productSheetTitle').textContent = mode === 'add' ? 'Add New Product' : 'Edit ' + prod.name;
    $('#pName').value = prod ? prod.name : '';
    
    // Category dropdown selection
    const targetCat = prod && prod.category ? prod.category : 'Coffee';
    const catSelect = $('#pCategory');
    if (catSelect) {
      let matched = false;
      for (const opt of catSelect.options) {
        if (opt.value.toLowerCase() === targetCat.toLowerCase()) {
          opt.selected = true;
          matched = true;
          break;
        }
      }
      if (!matched && targetCat) {
        const customOpt = document.createElement('option');
        customOpt.value = targetCat;
        customOpt.textContent = targetCat;
        customOpt.selected = true;
        catSelect.appendChild(customOpt);
      }
    }

    // Cup sizes handling
    const isFood = ['croffle', 'pastry', 'sandwich', 'snack', 'meal', 'dessert', 'bakery'].some(f => targetCat.toLowerCase().includes(f));
    const isBeverage = !isFood;
    const hasSizes = prod && typeof prod.has_sizes !== 'undefined'
      ? Boolean(Number(prod.has_sizes))
      : isBeverage;

    const hasSizesCheck = $('#pHasSizes');
    if (hasSizesCheck) hasSizesCheck.checked = hasSizes;
    const sBox = $('#pSizesContainer');
    if (sBox) sBox.hidden = !hasSizes;
    const bWrap = $('#pBasePriceWrap');
    if (bWrap) bWrap.hidden = hasSizes;

    // Populate productSheetSizes
    productSheetSizes = [];
    if (prod && Array.isArray(prod.sizes) && prod.sizes.length > 0) {
      prod.sizes.forEach(s => {
        const isHot = (s.temp && s.temp.toLowerCase() === 'hot') ||
                      (s.label && s.label.toLowerCase().includes('hot')) ||
                      s.oz === 12 || s.code === '12oz';
        productSheetSizes.push({
          oz: Number(s.oz || (s.code ? parseInt(s.code, 10) : 16)),
          temp: isHot ? 'Hot' : 'Cold',
          label: s.label || `${s.oz}oz ${isHot ? 'Hot' : 'Cold'}`,
          price: s.price !== null && s.price !== undefined ? Number(s.price).toFixed(2) : ''
        });
      });
    } else if (prod && (prod.price_12oz || prod.price_16oz || prod.price_22oz)) {
      if (prod.price_12oz) {
        productSheetSizes.push({ oz: 12, temp: 'Hot', label: '12oz Hot', price: Number(prod.price_12oz).toFixed(2) });
      }
      if (prod.price_16oz) {
        productSheetSizes.push({ oz: 16, temp: 'Cold', label: '16oz Cold', price: Number(prod.price_16oz).toFixed(2) });
      }
      if (prod.price_22oz) {
        productSheetSizes.push({ oz: 22, temp: 'Cold', label: '22oz Cold', price: Number(prod.price_22oz).toFixed(2) });
      }
    } else {
      const isCoffee = targetCat.toLowerCase() === 'coffee';
      if (isCoffee) {
        productSheetSizes = [
          { oz: 12, temp: 'Hot',  label: '12oz Hot',  price: prod && prod.price ? Number(prod.price).toFixed(2) : '' },
          { oz: 16, temp: 'Cold', label: '16oz Cold', price: prod && prod.price ? Number(prod.price).toFixed(2) : '' }
        ];
      } else if (isBeverage) {
        productSheetSizes = [
          { oz: 16, temp: 'Cold', label: '16oz Cold', price: prod && prod.price ? Number(prod.price).toFixed(2) : '' },
          { oz: 22, temp: 'Cold', label: '22oz Cold', price: prod && prod.price ? (Number(prod.price) + 20).toFixed(2) : '' }
        ];
      }
    }

    renderSizeOptionsList();

    if ($('#pPrice')) $('#pPrice').value = prod ? Number(prod.price).toFixed(2) : '';
    if ($('#pStock')) $('#pStock').value = '0';

    const linkSub = $('#linkSubSection');
    if (linkSub) linkSub.hidden = false;
    $('#pImageInput').value = '';

    // Image preview
    const previewBox = $('#imagePreviewBox');
    if (prod && prod.image_url) {
      $('#imagePreviewImg').src = prod.image_url;
      previewBox.hidden = false;
    } else {
      previewBox.hidden = true;
    }

    $('#productSheetError').hidden = true;
    $('#productSheet').hidden = false;
    $('#pName').focus();
  }

  function closeProductSheet() {
    $('#productSheet').hidden = true;
    const qCard = $('#quickAddSupplyCard');
    if (qCard) qCard.hidden = true;
  }

  async function submitQuickAddSupply() {
    const errEl = $('#quickSupplyError');
    const showErr = msg => { errEl.textContent = msg; errEl.hidden = false; };
    errEl.hidden = true;

    const name = ($('#quickSupplyName').value || '').trim();
    const unit = ($('#quickSupplyUnit').value || '').trim();
    const stock = parseFloat($('#quickSupplyStock').value) || 0;
    const threshold = parseFloat($('#quickSupplyThreshold').value) || 0;

    if (!name) return showErr('Supply item name is required.');
    if (!unit) return showErr('Unit of measure is required (e.g. g, ml, pcs, shots).');
    if (isNaN(stock) || stock < 0) return showErr('Starting stock must be a number of 0 or more.');
    if (isNaN(threshold) || threshold < 0) return showErr('Restock alert must be a number of 0 or more.');

    // Duplicate check against current inventory items (case-insensitive)
    const duplicate = cachedInventoryItems.some(
      it => it.name.trim().toLowerCase() === name.toLowerCase()
    );
    if (duplicate) {
      return showErr(`"${name}" is already in the inventory table. Please select it from the list.`);
    }

    const saveBtn = $('#quickSupplySaveBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    try {
      const res = await api('inventory_api.php', {
        action: 'add',
        name,
        unit,
        stock,
        threshold
      });

      $('#quickAddSupplyCard').hidden = true;

      const newItemId = res.item_id || null;
      if (newItemId) {
        selectedSupplies.set(Number(newItemId), 1);
      }
      await loadSupplyList(selectedSupplies);

      // Keep main inventory modal in sync
      loadInventory();

      toast(`Added "${name}" to inventory & selected`, 'success', 3000);
    } catch (ex) {
      showErr(ex.message);
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = '✓ Add to Inventory';
    }
  }

  async function submitProductForm(e) {
    e.preventDefault();
    const err = $('#productSheetError');
    const showErr = msg => { err.textContent = msg; err.hidden = false; };

    const name = $('#pName').value.trim();
    const category = $('#pCategory').value.trim() || 'General';
    const hasSizes = $('#pHasSizes') ? $('#pHasSizes').checked : false;

    let price = 0;
    const sizesData = [];

    if (hasSizes) {
      if (productSheetSizes.length === 0) {
        return showErr('Please add at least one cup size option, or uncheck "Enable Cup Sizes".');
      }

      for (let i = 0; i < productSheetSizes.length; i++) {
        const item = productSheetSizes[i];
        const pVal = parseFloat(item.price);
        if (isNaN(pVal) || pVal < 0) {
          return showErr(`Please enter a valid price for ${item.label || (item.oz + 'oz')} (₱0.00 or more).`);
        }
        const isHot = (item.temp || '').toLowerCase() === 'hot';
        const sCode = isHot ? `${item.oz}oz_hot` : `${item.oz}oz`;
        sizesData.push({
          code: sCode,
          oz: item.oz,
          temp: item.temp || 'Cold',
          label: item.label || `${item.oz}oz ${item.temp || 'Cold'}`,
          price: pVal
        });
      }

      // Base price is the minimum size price
      const minP = Math.min(...sizesData.map(s => s.price));
      price = minP;
    } else {
      price = parseFloat($('#pPrice').value);
      if (isNaN(price) || price < 0) return showErr('Please enter a valid price (₱0.00 or more).');
    }

    if (!name) return showErr('Product name is required.');

    const ingredientsPayload = [];
    for (const [itemId, deductAmt] of selectedSupplies.entries()) {
      const amt = parseFloat(deductAmt);
      if (isNaN(amt) || amt <= 0) {
        const found = cachedInventoryItems.find(it => it.item_id === itemId);
        return showErr(`Deduction amount for "${found ? found.name : 'ingredient'}" must be greater than 0.`);
      }
      ingredientsPayload.push({
        item_id: Number(itemId),
        deduction_amount: amt
      });
    }

    const formData = new FormData();
    formData.append('action', currentProductSheet.mode === 'add' ? 'add' : 'update');
    if (currentProductSheet.mode === 'edit') {
      formData.append('product_id', currentProductSheet.product.product_id);
    }
    formData.append('name', name);
    formData.append('category', category);
    formData.append('has_sizes', hasSizes ? '1' : '0');
    formData.append('price', price.toFixed(2));
    if (hasSizes) {
      formData.append('sizes_json', JSON.stringify(sizesData));
      const s12 = sizesData.find(s => s.oz === 12);
      const s16 = sizesData.find(s => s.oz === 16);
      const s22 = sizesData.find(s => s.oz === 22);
      if (s12) formData.append('price_12oz', s12.price.toFixed(2));
      if (s16) formData.append('price_16oz', s16.price.toFixed(2));
      if (s22) formData.append('price_22oz', s22.price.toFixed(2));
    }
    formData.append('stock_quantity', '0.00');
    formData.append('track_stock', '1');
    formData.append('ingredients_json', JSON.stringify(ingredientsPayload));

    if (ingredientsPayload.length > 0) {
      formData.append('linked_inventory_id', String(ingredientsPayload[0].item_id));
      formData.append('deduction_amount', String(ingredientsPayload[0].deduction_amount));
    } else {
      formData.append('linked_inventory_id', '');
      formData.append('deduction_amount', '0.00');
    }

    const fileInput = $('#pImageInput');
    if (fileInput.files && fileInput.files[0]) {
      formData.append('image', fileInput.files[0]);
    }
    if (removeCurrentImage) {
      formData.append('remove_image', '1');
    }

    const saveBtn = $('#saveProductBtn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    try {
      await api('products_api.php', formData);
      closeProductSheet();
      toast(currentProductSheet.mode === 'add' ? 'Product created' : 'Product updated');
      await loadManageProducts();
      await loadMenu();
    } catch (ex) {
      showErr(ex.message);
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save Product';
    }
  }

  async function deleteProduct(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}"? This will remove it from the menu.`)) {
      return;
    }
    try {
      await api('products_api.php', { action: 'delete', product_id: id });
      toast(`Deleted "${name}"`);
      await loadManageProducts();
      await loadMenu();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  /* ------------------------------------------------------------------ *
   *  Raw Supplies Inventory modal
   * ------------------------------------------------------------------ */
  let inventory = [];
  let inventorySearchQuery = '';

  async function loadInventory() {
    try {
      inventory = (await api('inventory_api.php')).items;
      renderInventory();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function renderInventory() {
    const body = $('#invBody');
    body.textContent = '';

    const searchInput = $('#inventorySearch');
    const clearBtn = $('#inventorySearchClearBtn');
    if (searchInput && searchInput.value !== inventorySearchQuery) {
      searchInput.value = inventorySearchQuery;
    }
    if (clearBtn) {
      clearBtn.hidden = !inventorySearchQuery.trim();
    }

    if (!inventory.length) {
      body.append(h('tr', {}, h('td', { colspan: '5', class: 'empty', text: 'No raw supplies yet. Click "+ Add Supply" to add your first raw material.' })));
      return;
    }

    const q = inventorySearchQuery.trim().toLowerCase();
    const filtered = !q ? inventory : inventory.filter(it => {
      const name = (it.name || '').toLowerCase();
      const unit = (it.unit_measure || '').toLowerCase();
      const state = it.current_stock <= 0 ? 'out' : it.low ? 'low' : 'ok';
      const statusLabel = { out: 'out of stock out', low: 'low stock low alert', ok: 'ok in stock good available' }[state] || '';

      return name.includes(q) || unit.includes(q) || statusLabel.includes(q);
    });

    if (!filtered.length) {
      body.append(h('tr', {},
        h('td', { colspan: '5', class: 'empty' },
          h('p', { text: `No raw supplies match "${inventorySearchQuery}".` }),
          h('button', {
            type: 'button',
            class: 'btn ghost small',
            style: 'margin-top: 8px;',
            text: 'Clear Search',
            onclick: () => {
              inventorySearchQuery = '';
              if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
              }
              if (clearBtn) clearBtn.hidden = true;
              renderInventory();
            }
          })
        )
      ));
      return;
    }

    for (const it of filtered) {
      const state = it.current_stock <= 0 ? 'out' : it.low ? 'low' : 'ok';
      const label = { out: 'Out', low: 'Low', ok: 'OK' }[state];
      body.append(h('tr', {},
        h('td', { class: 'name', text: it.name }),
        h('td', { class: 'num', text: qtyFmt(it.current_stock) + ' ' + it.unit_measure }),
        h('td', { class: 'num', text: qtyFmt(it.restock_threshold) + ' ' + it.unit_measure }),
        h('td', {}, h('span', { class: 'tag ' + state, text: label })),
        h('td', { class: 'act' },
          h('button', { type: 'button', class: 'btn small primary', text: 'Restock', onclick: () => openSheet('restock', it) }),
          h('button', { type: 'button', class: 'btn small ghost', text: 'Edit', onclick: () => openSheet('edit', it) }),
          h('button', { type: 'button', class: 'btn small ghost btn-delete', text: 'Delete', onclick: () => deleteInventoryItem(it.item_id, it.name) })
        )
      ));
    }
  }

  async function deleteInventoryItem(id, name) {
    if (!confirm(`Are you sure you want to delete "${name}" from inventory? Any linked products will become unlinked.`)) {
      return;
    }
    try {
      await api('inventory_api.php', { action: 'delete', item_id: id });
      toast(`Deleted "${name}" from inventory`);
      await loadInventory();
      await loadSupplyList();
      loadMenu();
      pollQueue();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  let sheet = { mode: null, item: null };

  function openSheet(mode, item = null) {
    sheet = { mode, item };
    const restock = mode === 'restock';
    $('#sheetTitle').textContent = mode === 'add' ? 'Add inventory item'
                                 : restock ? 'Restock ' + item.name
                                 : 'Edit ' + item.name;
    const visible = { name: !restock, unit: !restock, stock: true, threshold: !restock };
    $$('#itemForm [data-f]').forEach(label => { label.hidden = !visible[label.dataset.f]; });
    $('#fStockLabel').textContent = restock ? 'Quantity received' : mode === 'add' ? 'Starting stock' : 'Current stock (after a count)';
    $('#fName').value = item ? item.name : '';
    $('#fUnit').value = item ? item.unit_measure : '';
    $('#fStock').value = !item || restock ? '' : item.current_stock;
    $('#fThreshold').value = item ? item.restock_threshold : '';
    $('#sheetError').hidden = true;
    $('#itemSheet').hidden = false;
    (restock ? $('#fStock') : $('#fName')).focus();
  }

  function closeSheet() {
    $('#itemSheet').hidden = true;
  }

  const parseNum = text => {
    const s = String(text).trim().replace(/,/g, '');
    const n = Number(s);
    return s !== '' && Number.isFinite(n) ? n : null;
  };

  async function submitSheet(e) {
    e.preventDefault();
    const err = $('#sheetError');
    const show = msg => { err.textContent = msg; err.hidden = false; };

    const { mode, item } = sheet;
    const stock = parseNum($('#fStock').value);
    let payload;

    if (mode === 'restock') {
      if (stock === null || stock <= 0) return show('Enter the quantity received (more than 0).');
      payload = { action: 'restock', item_id: item.item_id, amount: stock };
    } else {
      const name = $('#fName').value.trim();
      const unit = $('#fUnit').value.trim();
      const threshold = parseNum($('#fThreshold').value);
      if (!name) return show('Name is required.');
      if (!unit) return show('Unit is required (for example g, ml or pcs).');
      if (stock === null || stock < 0) return show('Stock must be a number of 0 or more.');
      if (threshold === null || threshold < 0) return show('Restock alert must be a number of 0 or more.');
      payload = { action: mode === 'add' ? 'add' : 'update', name, unit, stock, threshold };
      if (mode === 'edit') payload.item_id = item.item_id;
    }

    const saveBtn = $('#itemForm button[type="submit"]');
    saveBtn.disabled = true;
    try {
      await api('inventory_api.php', payload);
      closeSheet();
      toast('Inventory updated');
      await loadInventory();
      loadMenu();
      pollQueue();
    } catch (ex) {
      show(ex.message);
    } finally {
      saveBtn.disabled = false;
    }
  }

  /* ------------------------------------------------------------------ *
   *  Sales Report & Calendar State
   * ------------------------------------------------------------------ */
  const now = new Date();
  let reportRange = 'today'; // 'today' | 'date' | '7days' | 'month'
  let selectedDate = toIsoDate(now);
  let selected7DayStart = toIsoDate(addDays(now, -6));
  let selectedMonth = toIsoMonth(now);

  let calYear = now.getFullYear();
  let calMonth = now.getMonth();
  let calDrawerOpen = false;

  const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  function syncToolbarUI() {
    $$('#rangeSeg button').forEach(b => {
      b.classList.toggle('on', b.dataset.range === reportRange);
    });

    $('#todayPickerGroup').hidden = reportRange !== 'today';
    $('#datePickerGroup').hidden = reportRange !== 'date';
    $('#sevenDayPickerGroup').hidden = reportRange !== '7days';
    $('#monthPickerGroup').hidden = reportRange !== 'month';

    $('#reportDatePicker').value = selectedDate;
    $('#report7DayPicker').value = selected7DayStart;
    $('#reportMonthPicker').value = selectedMonth;

    const start7 = parseIsoDate(selected7DayStart);
    const end7 = addDays(start7, 6);
    $('#report7DayBadge').textContent = `${dayLabel(selected7DayStart)} – ${dayLabel(toIsoDate(end7))}`;

    const calBtn = $('#toggleCalBtn');
    if (calBtn) {
      calBtn.classList.toggle('primary', calDrawerOpen);
      calBtn.classList.toggle('ghost', !calDrawerOpen);
    }
    $('#reportCalDrawer').hidden = !calDrawerOpen;

    const tips = {
      today: 'Click any date below to inspect past reports',
      date: 'Click any date to view single-day report',
      '7days': 'Click any date to start a 7-day report',
      month: 'Navigate or click any date in that month'
    };
    $('#calModeTip').textContent = tips[reportRange] || 'Select report date';
  }

  function getReportUrl() {
    if (reportRange === 'today') return 'report.php?range=today';
    if (reportRange === 'date') return `report.php?range=date&date=${encodeURIComponent(selectedDate)}`;
    if (reportRange === '7days') return `report.php?range=7days&start=${encodeURIComponent(selected7DayStart)}`;
    if (reportRange === 'month') return `report.php?range=month&month=${encodeURIComponent(selectedMonth)}`;
    return 'report.php?range=today';
  }

  async function loadReport() {
    syncToolbarUI();
    renderCalendar();
    try {
      const data = await api(getReportUrl());
      renderReport(data);
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function stepPeriod(delta) {
    if (reportRange === 'date') {
      selectedDate = toIsoDate(addDays(parseIsoDate(selectedDate), delta));
      calYear = parseIsoDate(selectedDate).getFullYear();
      calMonth = parseIsoDate(selectedDate).getMonth();
    } else if (reportRange === '7days') {
      selected7DayStart = toIsoDate(addDays(parseIsoDate(selected7DayStart), delta * 7));
      calYear = parseIsoDate(selected7DayStart).getFullYear();
      calMonth = parseIsoDate(selected7DayStart).getMonth();
    } else if (reportRange === 'month') {
      const [y, m] = selectedMonth.split('-').map(Number);
      const res = addMonths(y, m - 1, delta);
      selectedMonth = `${res.year}-${pad2(res.month + 1)}`;
      calYear = res.year;
      calMonth = res.month;
    } else if (reportRange === 'today') {
      reportRange = 'date';
      selectedDate = toIsoDate(addDays(new Date(), delta));
      calYear = parseIsoDate(selectedDate).getFullYear();
      calMonth = parseIsoDate(selectedDate).getMonth();
    }
    loadReport();
  }

  /* ------------------------------------------------------------------ *
   *  Visual Calendar rendering
   * ------------------------------------------------------------------ */
  function populateCalDropdowns() {
    const monthSelect = $('#calMonthSelect');
    const yearSelect = $('#calYearSelect');
    if (!monthSelect || !yearSelect || monthSelect.children.length > 0) return;

    MONTH_NAMES.forEach((name, idx) => {
      monthSelect.append(h('option', { value: String(idx), text: name }));
    });

    const curY = new Date().getFullYear();
    for (let y = curY - 3; y <= curY + 2; y++) {
      yearSelect.append(h('option', { value: String(y), text: String(y) }));
    }

    monthSelect.addEventListener('change', () => {
      calMonth = Number(monthSelect.value);
      renderCalendar();
    });
    yearSelect.addEventListener('change', () => {
      calYear = Number(yearSelect.value);
      renderCalendar();
    });
  }

  function renderCalendar() {
    populateCalDropdowns();
    $('#calMonthSelect').value = String(calMonth);
    $('#calYearSelect').value = String(calYear);

    const grid = $('#calDaysGrid');
    if (!grid) return;
    grid.textContent = '';

    const todayIso = toIsoDate(new Date());
    const start7Iso = selected7DayStart;
    const end7Iso = toIsoDate(addDays(parseIsoDate(selected7DayStart), 6));

    const firstDayIndex = new Date(calYear, calMonth, 1).getDay();
    const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
    const daysInPrevMonth = new Date(calYear, calMonth, 0).getDate();

    for (let i = firstDayIndex - 1; i >= 0; i--) {
      const dayNum = daysInPrevMonth - i;
      const prevDate = new Date(calYear, calMonth - 1, dayNum);
      grid.append(createCalDayButton(dayNum, toIsoDate(prevDate), true));
    }

    for (let d = 1; d <= daysInMonth; d++) {
      const iso = `${calYear}-${pad2(calMonth + 1)}-${pad2(d)}`;
      grid.append(createCalDayButton(d, iso, false));
    }

    const totalCells = firstDayIndex + daysInMonth;
    const nextPadding = (7 - (totalCells % 7)) % 7;
    for (let d = 1; d <= nextPadding; d++) {
      const nextDate = new Date(calYear, calMonth + 1, d);
      grid.append(createCalDayButton(d, toIsoDate(nextDate), true));
    }

    function createCalDayButton(dayNumber, iso, isOtherMonth) {
      const btn = h('button', {
        type: 'button',
        class: 'cal-day' + (isOtherMonth ? ' other-month' : ''),
        dataset: { date: iso },
        text: String(dayNumber)
      });

      if (iso === todayIso) btn.classList.add('today');

      if (reportRange === 'date' && iso === selectedDate) {
        btn.classList.add('selected');
      } else if (reportRange === 'today' && iso === todayIso) {
        btn.classList.add('selected');
      } else if (reportRange === '7days') {
        if (iso === start7Iso) btn.classList.add('selected', 'range-start');
        else if (iso === end7Iso) btn.classList.add('selected', 'range-end');
        else if (iso > start7Iso && iso < end7Iso) btn.classList.add('in-range');
      } else if (reportRange === 'month' && !isOtherMonth && iso.startsWith(selectedMonth)) {
        btn.classList.add('in-range');
      }

      btn.addEventListener('click', () => {
        if (reportRange === 'today') reportRange = 'date';
        if (reportRange === 'date') selectedDate = iso;
        else if (reportRange === '7days') selected7DayStart = iso;
        else if (reportRange === 'month') {
          const [y, m] = iso.split('-');
          selectedMonth = `${y}-${m}`;
        }
        loadReport();
      });

      if (reportRange === '7days') {
        btn.addEventListener('mouseenter', () => preview7Days(iso));
        btn.addEventListener('mouseleave', () => clear7DayPreview());
      }

      return btn;
    }
  }

  function preview7Days(startIso) {
    const endIso = toIsoDate(addDays(parseIsoDate(startIso), 6));
    $$('#calDaysGrid .cal-day').forEach(b => {
      const d = b.dataset.date;
      b.classList.toggle('range-preview', d >= startIso && d <= endIso);
    });
  }

  function clear7DayPreview() {
    $$('#calDaysGrid .cal-day').forEach(b => b.classList.remove('range-preview'));
  }

  /* ------------------------------------------------------------------ *
   *  Sales Report Renderer
   * ------------------------------------------------------------------ */
  const stat = (label, value, big = false) =>
    h('div', { class: 'stat' + (big ? ' big' : '') },
      h('span', { class: 'stat-label', text: label }),
      h('strong', { class: 'stat-value', text: value }));

  async function renderReport(r) {
    const s = r.summary;
    const body = $('#reportBody');
    body.textContent = '';

    $('#reportStatusPeriod').textContent = r.label;

    body.append(h('div', { class: 'report-banner' },
      h('h3', { text: r.label }),
      h('span', { class: 'report-banner-meta', text: `${r.start_date} to ${r.end_date}` })
    ));

    body.append(h('div', { class: 'stats' },
      stat('Total sales', money(s.total), true),
      stat('Orders', String(s.orders)),
      stat('Average order', money(s.orders ? s.total / s.orders : 0)),
      stat('Cash sales', money(s.cash)),
      stat('GCash sales', money(s.gcash)),
      stat('Pending queue', String(s.pending))));

    // Top items
    const maxQty = Math.max(1, ...r.top_items.map(t => t.qty));
    const top = r.top_items.length
      ? r.top_items.map(t => h('div', { class: 'bar-row' },
          h('span', { class: 'bar-name', text: t.name }),
          h('span', { class: 'bar-qty', text: String(t.qty) }),
          h('div', { class: 'bar' }, h('span', { style: `width:${Math.round(t.qty / maxQty * 100)}%` }))))
      : [h('p', { class: 'report-empty', text: '☕ No item sales recorded in this period.' })];

    // Daily breakdown or Hourly breakdown
    let breakdownSection;
    if (r.by_day && r.by_day.length > 1) {
      breakdownSection = h('section', {},
        h('h3', { text: `Daily Breakdown (${r.by_day.length} days)` }),
        h('table', { class: 'table' },
          h('thead', {}, h('tr', {},
            h('th', { text: 'Day' }),
            h('th', { class: 'num', text: 'Orders' }),
            h('th', { class: 'num', text: 'Cash' }),
            h('th', { class: 'num', text: 'GCash' }),
            h('th', { class: 'num', text: 'Total' })
          )),
          h('tbody', {},
            r.by_day.map(d => h('tr', {},
              h('td', { text: dayLabel(d.day) }),
              h('td', { class: 'num', text: String(d.orders) }),
              h('td', { class: 'num', text: money(d.cash || 0) }),
              h('td', { class: 'num', text: money(d.gcash || 0) }),
              h('td', { class: 'num', text: money(d.total) })
            )),
            h('tr', { class: 'table-total-row' },
              h('td', { text: 'Total' }),
              h('td', { class: 'num', text: String(s.orders) }),
              h('td', { class: 'num', text: money(s.cash) }),
              h('td', { class: 'num', text: money(s.gcash) }),
              h('td', { class: 'num', text: money(s.total) })
            )
          )
        )
      );
    } else if (r.by_hour && r.by_hour.length > 0) {
      breakdownSection = h('section', {},
        h('h3', { text: 'Sales by Hour of Day' }),
        h('table', { class: 'table' },
          h('thead', {}, h('tr', {},
            h('th', { text: 'Hour' }),
            h('th', { class: 'num', text: 'Orders' }),
            h('th', { class: 'num', text: 'Sales' })
          )),
          h('tbody', {},
            r.by_hour.map(hr => h('tr', {},
              h('td', { text: formatHour(hr.hour) }),
              h('td', { class: 'num', text: String(hr.orders) }),
              h('td', { class: 'num', text: money(hr.total) })
            ))
          )
        )
      );
    } else if (r.by_day && r.by_day.length === 1) {
      const d = r.by_day[0];
      breakdownSection = h('section', {},
        h('h3', { text: 'Day Summary' }),
        h('table', { class: 'table' },
          h('tbody', {},
            h('tr', {}, h('td', { text: 'Date' }), h('td', { class: 'num', text: dayLabel(d.day) })),
            h('tr', {}, h('td', { text: 'Orders' }), h('td', { class: 'num', text: String(d.orders) })),
            h('tr', {}, h('td', { text: 'Cash' }), h('td', { class: 'num', text: money(d.cash || 0) })),
            h('tr', {}, h('td', { text: 'GCash' }), h('td', { class: 'num', text: money(d.gcash || 0) })),
            h('tr', {}, h('td', { text: 'Total Sales' }), h('td', { class: 'num', text: money(d.total) }))
          )
        )
      );
    } else {
      breakdownSection = h('section', {},
        h('h3', { text: 'Sales Breakdown' }),
        h('p', { class: 'report-empty', text: 'No order activity during this period.' })
      );
    }

    body.append(h('div', { class: 'report-cols' },
      h('section', {}, h('h3', { text: 'Top Sellers (Cups & Pieces)' }), top),
      breakdownSection
    ));

    // Daily Transaction Records & E-Receipt Section
    const txSection = h('section', { class: 'tx-section' });
    body.append(txSection);

    try {
      const txData = await api(`transactions_api.php?date=${encodeURIComponent(r.start_date)}`);
      renderTransactionRecords(txSection, txData, r);
    } catch (_) {
      txSection.append(h('p', { class: 'muted', text: 'Could not load individual transaction records.' }));
    }
  }

  /* ------------------------------------------------------------------ *
   *  Daily Transaction Records List
   * ------------------------------------------------------------------ */
  function renderTransactionRecords(container, txData, r) {
    container.textContent = '';
    const records = txData.transactions || [];

    const head = h('div', { class: 'tx-section-head' },
      h('h3', { text: `📋 Transaction History (${records.length} order${records.length === 1 ? '' : 's'})` }),
      h('button', {
        type: 'button',
        class: 'btn small primary',
        text: '📥 Export to Excel (.xlsx)',
        onclick: () => exportTransactions(r.start_date, r.end_date)
      })
    );
    container.append(head);

    if (!records.length) {
      container.append(h('p', { class: 'report-empty', text: 'No transaction records found for this date.' }));
      return;
    }

    const table = h('table', { class: 'tx-table' },
      h('thead', {}, h('tr', {},
        h('th', { text: 'Receipt #' }),
        h('th', { text: 'Time' }),
        h('th', { text: 'Items Ordered' }),
        h('th', { text: 'Payment' }),
        h('th', { text: 'Status' }),
        h('th', { class: 'num', text: 'Total' }),
        h('th', { class: 'act', text: 'Receipt' })
      )),
      h('tbody', {},
        records.map(tx => {
          const statusClass = tx.status.toLowerCase();
          return h('tr', {},
            h('td', { class: 'tx-no', text: tx.receipt_no }),
            h('td', { text: tx.formatted_time }),
            h('td', { class: 'tx-items-summary', title: tx.items_summary, text: tx.items_summary || 'No items' }),
            h('td', {}, h('span', { class: 'pill-pay ' + tx.payment_method.toLowerCase(), text: tx.payment_method })),
            h('td', {}, h('span', { class: 'status-tag ' + statusClass, text: tx.status })),
            h('td', { class: 'num', style: 'font-weight:700;', text: money(tx.total_amount) }),
            h('td', { class: 'act' },
              h('button', {
                type: 'button',
                class: 'tx-view-btn',
                text: '🧾 View Receipt',
                onclick: () => viewReceipt(tx.transaction_id)
              })
            )
          );
        })
      )
    );

    container.append(table);
  }

  /* ------------------------------------------------------------------ *
   *  E-Receipt Modal
   * ------------------------------------------------------------------ */
  async function viewReceipt(transactionId) {
    try {
      const data = await api(`transactions_api.php?transaction_id=${transactionId}`);
      renderReceipt(data);
      $('#receiptModal').hidden = false;
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function renderReceipt(data) {
    const { business, receipt } = data;
    const body = $('#receiptModalBody');
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
        h('div', { class: 'receipt-total-row' },
          h('span', { text: '12% VAT (Included):' }),
          h('strong', { text: money(receipt.total_amount * 0.12 / 1.12) })
        ),
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

  /* ------------------------------------------------------------------ *
   *  Excel Export Functionality
   * ------------------------------------------------------------------ */
  function exportTransactions(start, end) {
    let url;
    if (start && end && start !== end) {
      url = `api/export_excel.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
    } else if (start) {
      url = `api/export_excel.php?date=${encodeURIComponent(start)}`;
    } else {
      url = 'api/export_excel.php?all=1';
    }

    toast('Preparing Excel export (.xlsx)…', 'success', 2000);
    // Trigger download
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', '');
    document.body.appendChild(link);
    link.click();
    link.remove();
  }

  /* ------------------------------------------------------------------ *
   *  Wire Everything Up
   * ------------------------------------------------------------------ */
  // Top nav
  $$('.nav-btn').forEach(b => b.addEventListener('click', () => showView(b.dataset.view)));
  $$('[data-close]').forEach(b => b.addEventListener('click', () => showView('pos')));

  // Brand logo click returns to POS and clears filters
  const brandEl = $('.brand');
  if (brandEl) {
    brandEl.addEventListener('click', () => {
      showView('pos');
      activeCategory = 'all';
      searchQuery = '';
      if (searchInput) searchInput.value = '';
      if (clearBtn) clearBtn.hidden = true;
      renderCategoryBar();
      renderMenu();
    });
  }

  // Search input on POS
  const searchInput = $('#productSearch');
  const clearBtn = $('#searchClearBtn');
  searchInput.addEventListener('input', e => {
    searchQuery = e.target.value.trim().toLowerCase();
    clearBtn.hidden = !searchQuery;
    renderMenu();
  });
  clearBtn.addEventListener('click', () => {
    searchInput.value = '';
    searchQuery = '';
    clearBtn.hidden = true;
    searchInput.focus();
    renderMenu();
  });

  // Manage products search
  const manageSearchInp = $('#manageProductSearch');
  const manageSearchClr = $('#manageProductSearchClearBtn');
  if (manageSearchInp) {
    manageSearchInp.addEventListener('input', () => {
      if (manageSearchClr) manageSearchClr.hidden = !manageSearchInp.value.trim();
      renderManageProducts();
    });
  }
  if (manageSearchClr) {
    manageSearchClr.addEventListener('click', () => {
      if (manageSearchInp) {
        manageSearchInp.value = '';
        manageSearchInp.focus();
      }
      manageSearchClr.hidden = true;
      renderManageProducts();
    });
  }

  // Add product shortcuts
  $('#openAddProductBtn').addEventListener('click', () => {
    showView('products');
    openProductSheet('add');
  });
  $('#addNewProductBtn').addEventListener('click', () => openProductSheet('add'));

  // Product Form & Image upload
  $('#productSheetCancel').addEventListener('click', closeProductSheet);
  $('#productSheet').addEventListener('click', e => { if (e.target === e.currentTarget) closeProductSheet(); });
  $('#productForm').addEventListener('submit', submitProductForm);

  $('#pImageInput').addEventListener('change', e => {
    const file = e.target.files && e.target.files[0];
    if (file) {
      removeCurrentImage = false;
      const reader = new FileReader();
      reader.onload = ev => {
        $('#imagePreviewImg').src = ev.target.result;
        $('#imagePreviewBox').hidden = false;
      };
      reader.readAsDataURL(file);
    }
  });

  $('#removeImageBtn').addEventListener('click', () => {
    removeCurrentImage = true;
    $('#pImageInput').value = '';
    $('#imagePreviewBox').hidden = true;
  });

  const hasSizesCheckbox = $('#pHasSizes');
  if (hasSizesCheckbox) {
    hasSizesCheckbox.addEventListener('change', e => {
      const isChecked = e.target.checked;
      const sBox = $('#pSizesContainer');
      const bWrap = $('#pBasePriceWrap');
      if (sBox) sBox.hidden = !isChecked;
      if (bWrap) bWrap.hidden = isChecked;
      if (isChecked && productSheetSizes.length === 0) {
        const cat = $('#pCategory') ? $('#pCategory').value : 'Coffee';
        updateProductSizeFields(cat, true);
      }
    });
  }

  const addSizeBtn = $('#addSizeOptionBtn');
  if (addSizeBtn) {
    addSizeBtn.addEventListener('click', () => {
      addSizeOptionRow(16, 'Cold', '', '');
    });
  }

  // Preset buttons
  $$('.btn-preset-pill').forEach(btn => {
    btn.addEventListener('click', () => {
      const preset = btn.getAttribute('data-preset');
      applySizePreset(preset);
    });
  });

  const categorySelect = $('#pCategory');
  if (categorySelect) {
    categorySelect.addEventListener('change', e => {
      if (currentProductSheet && currentProductSheet.mode === 'add') {
        updateProductSizeFields(e.target.value || '', true);
      }
    });
  }

  // Raw inventory supplies search filtering
  const supplySearchInp = $('#supplySearchInput');
  const supplySearchClear = $('#supplySearchClearBtn');
  if (supplySearchInp) {
    supplySearchInp.addEventListener('input', e => {
      supplySearchQuery = e.target.value.trim().toLowerCase();
      if (supplySearchClear) supplySearchClear.hidden = !supplySearchQuery;
      renderSupplyList();
    });
  }
  if (supplySearchClear) {
    supplySearchClear.addEventListener('click', () => {
      supplySearchQuery = '';
      if (supplySearchInp) supplySearchInp.value = '';
      supplySearchClear.hidden = true;
      if (supplySearchInp) supplySearchInp.focus();
      renderSupplyList();
    });
  }

  $('#openQuickAddSupplyBtn').addEventListener('click', () => {
    const card = $('#quickAddSupplyCard');
    card.hidden = !card.hidden;
    if (!card.hidden) {
      $('#quickSupplyName').value = '';
      $('#quickSupplyUnit').value = '';
      $('#quickSupplyStock').value = '0';
      $('#quickSupplyThreshold').value = '0';
      $('#quickSupplyError').hidden = true;
      $('#quickSupplyName').focus();
    }
  });

  $('#quickAddSupplyClose').addEventListener('click', () => {
    $('#quickAddSupplyCard').hidden = true;
  });

  $('#quickSupplyCancel').addEventListener('click', () => {
    $('#quickAddSupplyCard').hidden = true;
  });

  $('#quickSupplySaveBtn').addEventListener('click', submitQuickAddSupply);

  ['#quickSupplyName', '#quickSupplyUnit', '#quickSupplyStock', '#quickSupplyThreshold'].forEach(sel => {
    const el = $(sel);
    if (el) {
      el.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          submitQuickAddSupply();
        }
      });
    }
  });

  // Cart operations
  $('#clearCart').addEventListener('click', () => { cart.clear(); renderCart(); });

  // Pay buttons
  $('.pay.cash').addEventListener('click', openPayModal);
  $('.pay.gcash').addEventListener('click', () => {
    const total = Array.from(cart.values()).reduce((sum, l) => sum + lineCents(l), 0) / 100;
    checkout('GCash', total);
  });

  $('#cancelPayBtn').addEventListener('click', closePayModal);
  $('#confirmCashPayBtn').addEventListener('click', () => {
    const tendered = parseFloat($('#payTenderedInput').value) || 0;
    checkout('Cash', tendered);
  });

  // Mobile sticky order dock actions
  const mobViewBtn = $('#mobOrderViewBtn');
  if (mobViewBtn) {
    mobViewBtn.addEventListener('click', () => {
      const cartEl = $('.cart');
      if (cartEl) cartEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  const mobCashBtn = $('#mobPayCashBtn');
  if (mobCashBtn) {
    mobCashBtn.addEventListener('click', openPayModal);
  }
  const mobGcashBtn = $('#mobPayGcashBtn');
  if (mobGcashBtn) {
    mobGcashBtn.addEventListener('click', () => {
      const total = Array.from(cart.values()).reduce((sum, l) => sum + lineCents(l), 0) / 100;
      checkout('GCash', total);
    });
  }

  // Inventory modal
  const invSearchInput = $('#inventorySearch');
  const invClearBtn = $('#inventorySearchClearBtn');
  if (invSearchInput) {
    invSearchInput.addEventListener('input', e => {
      inventorySearchQuery = e.target.value;
      if (invClearBtn) invClearBtn.hidden = !inventorySearchQuery.trim();
      renderInventory();
    });
  }
  if (invClearBtn) {
    invClearBtn.addEventListener('click', () => {
      inventorySearchQuery = '';
      if (invSearchInput) {
        invSearchInput.value = '';
        invSearchInput.focus();
      }
      invClearBtn.hidden = true;
      renderInventory();
    });
  }
  const addNewSupplyBtn = $('#addNewSupplyBtn');
  if (addNewSupplyBtn) {
    addNewSupplyBtn.addEventListener('click', () => openSheet('add'));
  }

  $('#sheetCancel').addEventListener('click', closeSheet);
  $('#itemSheet').addEventListener('click', e => { if (e.target === e.currentTarget) closeSheet(); });
  $('#itemForm').addEventListener('submit', submitSheet);

  // Report Period Segment buttons
  $$('#rangeSeg button').forEach(b => b.addEventListener('click', () => {
    reportRange = b.dataset.range;
    if (reportRange === 'date') {
      calYear = parseIsoDate(selectedDate).getFullYear();
      calMonth = parseIsoDate(selectedDate).getMonth();
    } else if (reportRange === '7days') {
      calYear = parseIsoDate(selected7DayStart).getFullYear();
      calMonth = parseIsoDate(selected7DayStart).getMonth();
    } else if (reportRange === 'month') {
      const [y, m] = selectedMonth.split('-').map(Number);
      calYear = y;
      calMonth = m - 1;
    }
    loadReport();
  }));

  // Period steppers
  $('#prevPeriodBtn').addEventListener('click', () => stepPeriod(-1));
  $('#nextPeriodBtn').addEventListener('click', () => stepPeriod(1));

  // Date inputs
  $('#reportDatePicker').addEventListener('change', e => {
    if (e.target.value) {
      selectedDate = e.target.value;
      calYear = parseIsoDate(selectedDate).getFullYear();
      calMonth = parseIsoDate(selectedDate).getMonth();
      loadReport();
    }
  });

  $('#report7DayPicker').addEventListener('change', e => {
    if (e.target.value) {
      selected7DayStart = e.target.value;
      calYear = parseIsoDate(selected7DayStart).getFullYear();
      calMonth = parseIsoDate(selected7DayStart).getMonth();
      loadReport();
    }
  });

  $('#reportMonthPicker').addEventListener('change', e => {
    if (e.target.value) {
      selectedMonth = e.target.value;
      const [y, m] = selectedMonth.split('-').map(Number);
      calYear = y;
      calMonth = m - 1;
      loadReport();
    }
  });

  // Jump buttons
  $('#jumpTodayBtn').addEventListener('click', () => {
    selectedDate = toIsoDate(new Date());
    calYear = new Date().getFullYear();
    calMonth = new Date().getMonth();
    loadReport();
  });

  $('#jumpLast7Btn').addEventListener('click', () => {
    selected7DayStart = toIsoDate(addDays(new Date(), -6));
    calYear = new Date().getFullYear();
    calMonth = new Date().getMonth();
    loadReport();
  });

  $('#jumpThisMonthBtn').addEventListener('click', () => {
    selectedMonth = toIsoMonth(new Date());
    calYear = new Date().getFullYear();
    calMonth = new Date().getMonth();
    loadReport();
  });

  // Calendar drawer toggle
  $('#toggleCalBtn').addEventListener('click', () => {
    calDrawerOpen = !calDrawerOpen;
    syncToolbarUI();
    if (calDrawerOpen) renderCalendar();
  });

  $('#calCloseBtn').addEventListener('click', () => {
    calDrawerOpen = false;
    syncToolbarUI();
  });

  $('#calPrevMonth').addEventListener('click', () => {
    calMonth--;
    if (calMonth < 0) {
      calMonth = 11;
      calYear--;
    }
    renderCalendar();
  });

  $('#calNextMonth').addEventListener('click', () => {
    calMonth++;
    if (calMonth > 11) {
      calMonth = 0;
      calYear++;
    }
    renderCalendar();
  });

  // Calendar presets
  $$('.cal-pill').forEach(btn => {
    btn.addEventListener('click', () => {
      const act = btn.dataset.calAction;
      const today = new Date();
      if (act === 'today') {
        reportRange = 'today';
        selectedDate = toIsoDate(today);
      } else if (act === 'yesterday') {
        reportRange = 'date';
        selectedDate = toIsoDate(addDays(today, -1));
      } else if (act === 'last7') {
        reportRange = '7days';
        selected7DayStart = toIsoDate(addDays(today, -6));
      } else if (act === 'thisMonth') {
        reportRange = 'month';
        selectedMonth = toIsoMonth(today);
      } else if (act === 'lastMonth') {
        reportRange = 'month';
        const prev = addMonths(today.getFullYear(), today.getMonth(), -1);
        selectedMonth = `${prev.year}-${pad2(prev.month + 1)}`;
      }
      calYear = parseIsoDate(selectedDate || selected7DayStart || today).getFullYear();
      calMonth = parseIsoDate(selectedDate || selected7DayStart || today).getMonth();
      loadReport();
    });
  });

  // Excel export from report
  $('#exportExcelBtn').addEventListener('click', () => {
    if (reportRange === '7days') {
      const end7 = toIsoDate(addDays(parseIsoDate(selected7DayStart), 6));
      exportTransactions(selected7DayStart, end7);
    } else if (reportRange === 'month') {
      const [y, m] = selectedMonth.split('-').map(Number);
      const lastD = new Date(y, m, 0).getDate();
      exportTransactions(`${selectedMonth}-01`, `${selectedMonth}-${pad2(lastD)}`);
    } else if (reportRange === 'date') {
      exportTransactions(selectedDate, selectedDate);
    } else {
      exportTransactions(toIsoDate(new Date()), toIsoDate(new Date()));
    }
  });

  // Receipt modal close
  $('#closeReceiptBtn').addEventListener('click', () => {
    $('#receiptModal').hidden = true;
  });

  // Init
  renderCart();
  tickClock();
  setInterval(tickClock, 15000);
  loadMenu();
  pollQueue();
  setInterval(pollQueue, POLL_MS);
})();
