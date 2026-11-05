/* CafeFlow - Front-end logic. Vanilla JS, no external libraries, works fully offline. */

'use strict';



(() => {

  const POLL_MS = 3000;   // barista queue refresh interval

  const MAX_QTY = 99;

  const currentUser = window.CafeFlowUser || { role: 'barista', username: 'guest' };



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



  /** fetch() wrapper: supports JSON and FormData, auto-attaches CSRF token, handles session expiration. */

  async function api(url, body) {

    const finalUrl = (url.startsWith('api/') || url.startsWith('http')) ? url : 'api/' + url;

    let init;

    const headers = {};

    if (currentUser && currentUser.csrf_token) {

      headers['X-CSRF-Token'] = currentUser.csrf_token;

    }



    if (body === undefined) {

      init = { cache: 'no-store', headers };

    } else if (body instanceof FormData) {

      init = { method: 'POST', body, cache: 'no-store', headers };

    } else {

      headers['Content-Type'] = 'application/json';

      init = {

        method: 'POST',

        headers,

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



    if (res.status === 401) {

      window.location.href = 'login.php?timeout=1';

      throw new Error('Your session has expired. Redirecting to sign in…');

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

  const urlCat = new URLSearchParams(window.location.search).get('cat');

  let activeCategory = urlCat ? urlCat : 'all';

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



  /* ------------------------------------------------------------------ *

   *  Bespoke SVG Icon System (Designed for OK Café / CafeFlow)

   * ------------------------------------------------------------------ */

  const SVG_ICONS = {

    // Categories

    all: `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/></svg>`,



    coffee: `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v7a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V8z"/><line x1="6" y1="2" x2="6" y2="4"/><line x1="10" y1="2" x2="10" y2="4"/><line x1="14" y1="2" x2="14" y2="4"/></svg>`,



    'croffle-classic': `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>`,



    'croffle-premium': `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="14" rx="4"/><path d="M3 12h18M3 17h18M8 7v14M13 7v14M18 7v14"/><path d="M12 1.5l1.3 2.6 2.8.4-2 2 .5 2.8-2.6-1.4-2.6 1.4.5-2.8-2-2 2.8-.4z" fill="currentColor"/></svg>`,



    'fruit-tea': `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 5h12l-1.8 15a2 2 0 0 1-2 1.8H9.8a2 2 0 0 1-2-1.8L6 5z"/><path d="M4 5h16"/><circle cx="16" cy="10" r="3"/><path d="M16 7v6M13 10h6"/><path d="M13 1.5l-2 7"/></svg>`,



    'milk-series': `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2h8v3H8z"/><path d="M7 5l-2.5 5v11a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1V10L17 5H7z"/><path d="M12 11c-1.5 2-2.5 3.5-2.5 5a2.5 2.5 0 0 0 5 0c0-1.5-1-3-2.5-5z" fill="currentColor" fill-opacity="0.25"/></svg>`,



    'milk-tea': `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1.5 13a2 2 0 0 1-2 1.8H9.5a2 2 0 0 1-2-1.8L6 7z"/><path d="M5 7h14"/><path d="M15 1.5l-3 9.5"/><circle cx="10" cy="17" r="1.3" fill="currentColor"/><circle cx="14" cy="17" r="1.3" fill="currentColor"/><circle cx="12" cy="14.5" r="1.3" fill="currentColor"/></svg>`,



    pastry: `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16c-1-3 0-7 3-9 4-3 10-3 13 1 2 3 2 7 0 9-3 2-6 2-9 1-2 1-4 1-5 0l-2-2z"/><path d="M7.5 10c2-1.5 5-1.5 7 0"/><path d="M6 13c1.5-1 3.5-1 5 0"/><path d="M13 13c1.5-.5 3 0 4 1"/></svg>`,



    sandwich: `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l14-6a2 2 0 0 1 2.6 1.1l1.4 3.3a2 2 0 0 1-1.1 2.6L6 18a2 2 0 0 1-2.6-1.1l-1.4-3.3A2 2 0 0 1 3 11z"/><path d="M5 12l14-6"/><path d="M6 15l14-6"/></svg>`,



    snacks: `<svg viewBox="0 0 24 24" width="{s}" height="{s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 10l2.5 11h9L19 10H5z"/><path d="M8 10V4.5a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1V10M11.5 10V2.5a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1V10M15 10V5a1 1 0 0 1 1-1h1a1 1 0 0 1 1 1V10"/></svg>`,



    // Temperature & Size Badges

    hot: `<svg class="chip-svg icon-hot" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 9h1a3 3 0 0 1 0 6h-1"/><path d="M3 9h15v6a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9z"/><path d="M7 2v3M11 2v3M15 2v3"/></svg>`,



    cold: `<svg class="chip-svg icon-cold" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M6 7h12l-1.5 13a2 2 0 0 1-2 1.8H9.5a2 2 0 0 1-2-1.8L6 7z"/><path d="M5 7h14"/><line x1="15" y1="2" x2="12" y2="13"/></svg>`,



    food: `<svg class="chip-svg icon-food" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12h8M12 8v8"/></svg>`,



    // UI & Action

    cart: `<svg class="cart-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1.5"/><circle cx="19" cy="21" r="1.5"/><path d="M2 3h3.5l2.4 12.2a2 2 0 0 0 2 1.6h8.8a2 2 0 0 0 2-1.6L22 7H6.5"/></svg>`

  };



  function getCategoryKey(cat) {

    if (!cat) return 'coffee';

    const c = cat.toLowerCase();

    if (c === 'all' || c === 'all items') return 'all';

    if (c.includes('croffle classic')) return 'croffle-classic';

    if (c.includes('croffle premium')) return 'croffle-premium';

    if (c.includes('croffle') || c.includes('waffle')) return 'croffle-classic';

    if (c.includes('fruit') || c.includes('lemonade') || c.includes('juice') || c.includes('soda')) return 'fruit-tea';

    if (c.includes('milk tea') || c.includes('boba')) return 'milk-tea';

    if (c.includes('milk series') || c.includes('milk')) return 'milk-series';

    if (c.includes('pastry') || c.includes('bakery') || c.includes('croissant')) return 'pastry';

    if (c.includes('sandwich') || c.includes('toast') || c.includes('burger')) return 'sandwich';

    if (c.includes('snack') || c.includes('fries')) return 'snacks';

    if (c.includes('coffee') || c.includes('espresso') || c.includes('latte')) return 'coffee';

    return 'coffee';

  }



  function getCategorySvg(cat, size = 16) {

    const key = getCategoryKey(cat);

    const tmpl = SVG_ICONS[key] || SVG_ICONS.coffee;

    return tmpl.replace(/{s}/g, String(size));

  }



  function getCategoryIcon(cat) {

    return getCategorySvg(cat, 26);

  }



  function updateCatNavArrows() {

    const bar = $('#categoryBar');

    const leftBtn = $('#catScrollLeftBtn');

    const rightBtn = $('#catScrollRightBtn');

    if (!bar || !leftBtn || !rightBtn) return;

    const hasOverflow = bar.scrollWidth > bar.clientWidth + 4;

    leftBtn.hidden = !hasOverflow;

    rightBtn.hidden = !hasOverflow;

    if (hasOverflow) {

      leftBtn.disabled = bar.scrollLeft <= 4;

      rightBtn.disabled = Math.ceil(bar.scrollLeft + bar.clientWidth) >= bar.scrollWidth - 4;

    }

  }



  function initCategoryBarScroll() {

    const bar = $('#categoryBar');

    const leftBtn = $('#catScrollLeftBtn');

    const rightBtn = $('#catScrollRightBtn');

    if (!bar) return;



    if (leftBtn) {

      leftBtn.addEventListener('click', () => {

        bar.scrollBy({ left: -200, behavior: 'smooth' });

        setTimeout(updateCatNavArrows, 250);

      });

    }

    if (rightBtn) {

      rightBtn.addEventListener('click', () => {

        bar.scrollBy({ left: 200, behavior: 'smooth' });

        setTimeout(updateCatNavArrows, 250);

      });

    }



    bar.addEventListener('scroll', updateCatNavArrows, { passive: true });

    window.addEventListener('resize', updateCatNavArrows);



    // Mouse wheel horizontal scrolling

    bar.addEventListener('wheel', e => {

      if (e.deltaY !== 0 && bar.scrollWidth > bar.clientWidth) {

        e.preventDefault();

        bar.scrollLeft += e.deltaY;

        updateCatNavArrows();

      }

    }, { passive: false });



    // Drag-to-scroll support for mouse & touch

    let isDown = false;

    let startX = 0;

    let startScrollLeft = 0;

    bar.addEventListener('mousedown', e => {

      isDown = true;

      startX = e.pageX - bar.offsetLeft;

      startScrollLeft = bar.scrollLeft;

    });

    window.addEventListener('mouseup', () => {

      if (isDown) {

        isDown = false;

        bar.classList.remove('grabbing');

      }

    });

    bar.addEventListener('mousemove', e => {

      if (!isDown) return;

      const x = e.pageX - bar.offsetLeft;

      const walk = x - startX;

      if (Math.abs(walk) > 4) {

        bar.classList.add('grabbing');

        bar.scrollLeft = startScrollLeft - walk;

        updateCatNavArrows();

      }

    });

  }



  function renderCategoryBar() {

    const bar = $('#categoryBar');

    if (!bar) return;

    bar.textContent = '';



    const allIcon = h('span', { class: 'cat-icon' });

    allIcon.innerHTML = getCategorySvg('all', 16);

    const allPill = h('button', {

      type: 'button',

      role: 'tab',

      'aria-selected': activeCategory === 'all' ? 'true' : 'false',

      class: 'cat-pill' + (activeCategory === 'all' ? ' active' : ''),

      onclick: () => {

        activeCategory = 'all';

        renderCategoryBar();

        renderMenu();

        const active = bar.querySelector('.cat-pill.active');

        if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

      }

    }, allIcon, h('span', { class: 'cat-text', text: 'All Items' }));

    bar.append(allPill);



    for (const cat of menuCategories) {

      const isSelected = activeCategory.toLowerCase() === cat.toLowerCase();

      const icon = h('span', { class: 'cat-icon' });

      icon.innerHTML = getCategorySvg(cat, 16);

      const pill = h('button', {

        type: 'button',

        role: 'tab',

        'aria-selected': isSelected ? 'true' : 'false',

        class: 'cat-pill' + (isSelected ? ' active' : ''),

        onclick: () => {

          activeCategory = cat;

          renderCategoryBar();

          renderMenu();

          const active = bar.querySelector('.cat-pill.active');

          if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

        }

      }, icon, h('span', { class: 'cat-text', text: cat }));

      bar.append(pill);

    }



    setTimeout(updateCatNavArrows, 50);

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

      const cartIconWrap = h('span', { class: 'cart-icon-wrap' });

      cartIconWrap.innerHTML = SVG_ICONS.cart;

      const cartPill = h('div', { class: 'item-cart-pill', 'aria-hidden': 'true' },

        cartIconWrap,

        badge

      );



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

              const phCircle = h('div', { class: 'placeholder-circle' });

              phCircle.innerHTML = getCategorySvg(p.category, 26);

              this.parentElement.append(phCircle, cartPill);

            }

          }),

          cartPill

        );

      } else {

        const phCircle = h('div', { class: 'placeholder-circle' });

        phCircle.innerHTML = getCategorySvg(p.category, 26);

        imgNode = h('div', { class: 'item-img-wrap item-placeholder' },

          phCircle,

          cartPill

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



      // Sizes chips row with custom SVG icons

      const sizeChipsWrap = h('div', { class: 'item-sizes-row' });

      if (p.has_sizes && Array.isArray(p.sizes) && p.sizes.length > 0) {

        p.sizes.forEach(s => {

          const isExplicitCold = (s.temp && s.temp.toLowerCase() === 'cold') ||

                                (s.label && s.label.toLowerCase().includes('cold'));

          const isHot = !isExplicitCold && (

                        (s.temp && s.temp.toLowerCase() === 'hot') ||

                        (s.label && s.label.toLowerCase().includes('hot')) ||

                        s.oz === 12

          );

          const iconSpan = h('span', { class: 'chip-icon' });

          iconSpan.innerHTML = isHot ? SVG_ICONS.hot : SVG_ICONS.cold;

          const labelSpan = h('span', { class: 'chip-label', text: s.oz ? `${s.oz}oz` : (s.label || s.code) });

          sizeChipsWrap.append(h('span', {

            class: 'size-chip ' + (isHot ? 'chip-hot' : 'chip-cold'),

          }, iconSpan, labelSpan));

        });

      } else {

        const catLabel = p.category && activeCategory === 'all' ? p.category : 'Single size';

        const foodIcon = h('span', { class: 'chip-icon' });

        foodIcon.innerHTML = SVG_ICONS.food;

        const foodLabel = h('span', { class: 'chip-label', text: catLabel });

        sizeChipsWrap.append(h('span', {

          class: 'size-chip chip-food',

        }, foodIcon, foodLabel));

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

        imgNode,

        h('div', { class: 'item-info' },

          h('div', { class: 'item-header-row' },

            h('span', { class: 'item-name', text: p.name }),

            h('span', { class: 'item-price', text: priceText })

          ),

          h('div', { class: 'item-sub-row' },

            sizeChipsWrap,

            tag

          )

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

      badge.textContent = total;

      badge.hidden = total === 0;

      const cartPill = badge.closest('.item-cart-pill');

      if (cartPill) {

        cartPill.classList.toggle('has-items', total > 0);

      }

      badge.closest('.item')?.classList.toggle('in-cart-item', total > 0);

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

          { code: '16oz', label: '16oz Cold', oz: 16, price: Number(p.price_16oz || p.price), available: true, stock_state: 'ok' },

          { code: '22oz', label: '22oz Cold', oz: 22, price: Number(p.price_22oz || (Number(p.price) + 20)), available: true, stock_state: 'ok' }

        ];



    const sizes = Array.isArray(p.sizes) && p.sizes.length > 0 ? p.sizes : fallbackSizes;



    sizes.forEach(s => {

      const isOut = s.stock_state === 'out' || s.available === false;

      const isExplicitCold = (s.temp && s.temp.toLowerCase() === 'cold') ||

                            (s.label && s.label.toLowerCase().includes('cold')) ||

                            (s.code && s.code.toLowerCase().includes('cold'));

      const isHot = !isExplicitCold && (

                    (s.temp && s.temp.toLowerCase() === 'hot') ||

                    (s.label && s.label.toLowerCase().includes('hot')) ||

                    s.oz === 12 || s.code === '12oz'

      );

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

    const bDrop = $('#sizeBackdrop');

    if (bDrop) bDrop.hidden = true;

    const pop = $('#sizePopover');

    if (pop) pop.hidden = true;

    _sizePickerProduct = null;

  }



  const szClose = $('#sizePopoverClose');

  if (szClose) szClose.addEventListener('click', closeSizePicker);

  const szBdrop = $('#sizeBackdrop');

  if (szBdrop) szBdrop.addEventListener('click', closeSizePicker);



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



    const grossCents = lines.reduce((sum, l) => sum + lineCents(l), 0);

    const count = lines.reduce((sum, l) => sum + l.qty, 0);

    const calc = getCartCalculations();



    const summaryBox = $('#cartDiscountSummary');

    if (summaryBox) {

      if (calc.discType !== 'none') {

        summaryBox.hidden = false;

        $('#cartSubtotalDisplay').textContent = money(calc.gross);

        $('#cartDiscountDisplay').textContent = '-' + money(calc.discount);

        $('#cartTaxDisplay').textContent = money(calc.tax);

        $('#cartTaxLabel').textContent = (calc.discType === 'senior_pwd') ? 'VAT (Exempt):' : '12% VAT:';

      } else {

        summaryBox.hidden = true;

      }

    }



    $('#cartTotal').textContent = money(calc.payable);

    $('#cartCount').textContent = count + (count === 1 ? ' item' : ' items');



    const locked = lines.length === 0 || submitting;

    $('#clearCart').disabled = locked;

    $$('.pay').forEach(b => { b.disabled = locked; });

    syncCardBadges();

    syncMobOrderBar();

  }



  function getCartCalculations() {

    const lines = Array.from(cart.values());

    const grossCents = lines.reduce((sum, l) => sum + lineCents(l), 0);

    const gross = grossCents / 100;

    

    const discType = $('#cartDiscountType') ? $('#cartDiscountType').value : 'none';

    let discount = 0;

    let tax = 0;

    let payable = gross;



    if (discType === 'senior_pwd') {

      const vatExempt = Math.round((gross / 1.12) * 100) / 100;

      discount = Math.round((vatExempt * 0.20) * 100) / 100;

      tax = 0.00;

      payable = Math.round((vatExempt - discount) * 100) / 100;

    } else if (discType === 'custom_percent') {

      const rate = parseFloat($('#cartDiscountRate')?.value) || 0;

      discount = Math.round((gross * (rate / 100)) * 100) / 100;

      const base = Math.max(0, gross - discount);

      tax = Math.round((base - (base / 1.12)) * 100) / 100;

      payable = base;

    } else {

      discount = 0;

      tax = Math.round((gross - (gross / 1.12)) * 100) / 100;

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

    if (cart.size === 0) return;

    if (calc.discType === 'senior_pwd' && !calc.seniorId) {

      toast('Please enter Senior Citizen / PWD ID or Booklet Number before checkout.', 'error', 3500);

      $('#cartSeniorIdNo')?.focus();

      return;

    }

    const total = calc.payable;



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



  function openGcashModal() {

    const calc = getCartCalculations();

    if (cart.size === 0 || submitting) return;

    if (calc.discType === 'senior_pwd' && !calc.seniorId) {

      toast('Please enter Senior Citizen / PWD ID or Booklet Number before proceeding.', 'error', 3500);

      $('#cartSeniorIdNo')?.focus();

      return;

    }

    $('#gcashDueAmount').textContent = money(calc.payable);

    const refInp = $('#gcashRefInput');

    if (refInp) {

      refInp.value = '';

      $('#gcashModal').hidden = false;

      refInp.focus();

    }

  }



  function closeGcashModal() {

    const gm = $('#gcashModal');

    if (gm) gm.hidden = true;

  }



  let activeCancelTxId = null;

  let activeCancelStatus = null;



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

    btn.disabled = true;

    btn.textContent = 'Processing…';



    try {

      const res = await api('cancel_order.php', {

        transaction_id: activeCancelTxId,

        reason: reason

      });

      closeCancelOrderModal();

      toast(res.message || 'Order updated successfully.', 'success', 3500);

      pollQueue();

      loadMenu();

      if (currentView === 'reports') loadReportData();

    } catch (err) {

      toast(err.message, 'error', 4500);

    } finally {

      btn.disabled = false;

      btn.textContent = 'Confirm';

    }

  }



  function closePayModal() {

    const pm = $('#payModal');

    if (pm) pm.hidden = true;

  }



  async function checkout(method, amountPaid = null, extra = {}) {

    if (submitting || cart.size === 0) return;

    const calc = getCartCalculations();

    if (calc.discType === 'senior_pwd' && !calc.seniorId) {

      toast('Please enter Senior Citizen / PWD ID or Booklet Number.', 'error', 3500);

      $('#cartSeniorIdNo')?.focus();

      return;

    }



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

        amount_paid: amountPaid !== null ? Number(amountPaid) : undefined,

        discount_type: calc.discType,

        discount_id_no: calc.discType === 'senior_pwd' ? calc.seniorId : undefined,

        discount_rate: calc.discType === 'custom_percent' ? calc.discRate : undefined,

        ...extra

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

   *  Navigation: POS / Products / Inventory / Sales Report / Accounts

   * ------------------------------------------------------------------ */

  function showView(view) {

    if (currentUser.role === 'manager' && (view === 'pos' || view === 'products')) {

      toast('Access restricted: Manager role does not have access to POS or Products.', 'error', 3500);

      return;

    }

    if (currentUser.role !== 'manager' && view === 'accounts') {

      toast('Access restricted: Only Managers have access to Account Management.', 'error', 3500);

      return;

    }



    currentView = view;

    $$('.nav-btn').forEach(b => b.classList.toggle('active', b.dataset.view === view));

    const pModal = $('#productModal');

    if (pModal) pModal.hidden = view !== 'products';

    const invModal = $('#inventoryModal');

    if (invModal) invModal.hidden = view !== 'inventory';

    const repModal = $('#reportModal');

    if (repModal) repModal.hidden = view !== 'report';

    const accModal = $('#accountsModal');

    if (accModal) accModal.hidden = view !== 'accounts';

    const recModal = $('#receiptModal');

    if (recModal) recModal.hidden = true;

    closePayModal();

    syncMobOrderBar();



    if (view !== 'products' && typeof closeProductSheet === 'function') closeProductSheet();

    if (view !== 'inventory' && typeof closeSheet === 'function') closeSheet();

    if (view !== 'accounts' && typeof closeAccountSheet === 'function') closeAccountSheet();

    if (view !== 'accounts' && typeof closeResetPassSheet === 'function') closeResetPassSheet();

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

    if (view === 'accounts' && currentUser.role === 'manager') loadAccounts();

    if (view === 'dashboard' && currentUser.role === 'manager') loadManagerDashboard();

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

    const ps = $('#productSheet');

    if (ps) ps.hidden = true;

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

    const ish = $('#itemSheet');

    if (ish) ish.hidden = true;

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

   *  Chart.js helpers — shared palette matching CafeFlow CSS tokens

   * ------------------------------------------------------------------ */

  const CF_ORANGE      = '#e8623a';

  const CF_ORANGE_MID  = '#f07348';

  const CF_GREEN       = '#2a9b52';

  const CF_ESPRESSO    = '#162318';

  const CF_MUTED       = '#5a7a63';

  const CF_LINE        = '#cde5d4';



  // Fill all 24 hours with zero so the line chart never has gaps

  function buildHourlyDataset(byHour) {

    const totals = new Array(24).fill(0);

    const orders = new Array(24).fill(0);

    (byHour || []).forEach(function(h) {

      totals[h.hour] = h.total;

      orders[h.hour] = h.orders;

    });

    return { totals, orders };

  }



  // Global chart instance references so we can destroy before re-creating

  var _reportLineChart     = null;

  var _reportBarChart      = null;

  var _reportDonutChart    = null;

  var _reportAllLineChart  = null;

  var _reportAllBarChart   = null;

  var _reportAllDonutChart = null;

  var _mgrLineChart        = null;

  var _mgrBarChart         = null;



  // Active chart tab state for the report selector

  var _activeChartTab = 'trend';



  function destroyChart(ref) {

    if (ref) { try { ref.destroy(); } catch (e) {} }

    return null;

  }



  function buildChartDefaults() {

    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    Chart.defaults.color = CF_MUTED;

  }

  buildChartDefaults();



  function renderLineChart(canvas, data) {

    if (typeof Chart === 'undefined' || !canvas) return null;

    var ctx = canvas.getContext('2d');

    var grad = ctx.createLinearGradient(0, 0, 0, 240);

    grad.addColorStop(0, 'rgba(232,98,58,0.22)');

    grad.addColorStop(1, 'rgba(232,98,58,0.0)');

    return new Chart(ctx, {

      type: 'line',

      data: {

        labels: data.labels,

        datasets: [{

          label: 'Sales (\u20b1)',

          data: data.totals,

          borderColor: CF_ORANGE,

          backgroundColor: grad,

          borderWidth: 2.5,

          pointBackgroundColor: CF_ESPRESSO,

          pointBorderColor: CF_ORANGE,

          pointRadius: 4,

          pointHoverRadius: 6,

          tension: 0.4,

          fill: true,

          yAxisID: 'y'

        }, {

          label: 'Orders',

          data: data.orders,

          borderColor: CF_GREEN,

          backgroundColor: 'transparent',

          borderWidth: 2,

          pointBackgroundColor: CF_GREEN,

          pointRadius: 3,

          pointHoverRadius: 5,

          tension: 0.4,

          fill: false,

          borderDash: [5, 3],

          yAxisID: 'y2'

        }]

      },

      options: {

        responsive: true,

        maintainAspectRatio: false,

        interaction: { mode: 'index', intersect: false },

        plugins: {

          legend: { position: 'top', labels: { boxWidth: 12, padding: 16, font: { size: 12 } } },

          tooltip: {

            backgroundColor: CF_ESPRESSO,

            titleColor: '#fff',

            bodyColor: 'rgba(255,255,255,0.8)',

            padding: 10,

            cornerRadius: 8,

            callbacks: {

              label: function(c) {

                return c.dataset.yAxisID === 'y'

                  ? '  \u20b1' + Number(c.parsed.y).toLocaleString('en-PH', { minimumFractionDigits: 2 })

                  : '  ' + c.parsed.y + ' orders';

              }

            }

          }

        },

        scales: {

          x: { grid: { color: CF_LINE }, ticks: { font: { size: 11 }, maxRotation: 45 } },

          y: {

            position: 'left', grid: { color: CF_LINE },

            ticks: { font: { size: 11 }, callback: function(v) { return '\u20b1' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 0 }); } }

          },

          y2: {

            position: 'right', grid: { drawOnChartArea: false },

            ticks: { font: { size: 11 }, stepSize: 1, precision: 0 }

          }

        }

      }

    });

  }



  function renderBarChart(canvas, data) {

    if (typeof Chart === 'undefined' || !canvas) return null;

    var ctx = canvas.getContext('2d');

    var n = data.labels.length;

    var barColors = data.labels.map(function(_, i) {

      var alpha = 1 - (i / Math.max(n, 1)) * 0.4;

      return 'rgba(42,155,82,' + alpha + ')';

    });

    return new Chart(ctx, {

      type: 'bar',

      data: {

        labels: data.labels,

        datasets: [{

          label: 'Units Sold',

          data: data.qtys,

          backgroundColor: barColors,

          borderColor: barColors.map(function(c) { return c.replace(/[\d.]+\)$/, '1)'); }),

          borderWidth: 1,

          borderRadius: 6,

          borderSkipped: false

        }]

      },

      options: {

        indexAxis: 'y',

        responsive: true,

        maintainAspectRatio: false,

        plugins: {

          legend: { display: false },

          tooltip: {

            backgroundColor: CF_ESPRESSO,

            titleColor: '#fff',

            bodyColor: 'rgba(255,255,255,0.8)',

            padding: 10,

            cornerRadius: 8,

            callbacks: { label: function(c) { return '  ' + c.parsed.x + ' units sold'; } }

          }

        },

        scales: {

          x: { grid: { color: CF_LINE }, ticks: { font: { size: 11 }, precision: 0 } },

          y: {

            grid: { display: false },

            ticks: {

              font: { size: 11 },

              callback: function(val) {

                var lbl = this.getLabelForValue(val);

                return lbl && lbl.length > 22 ? lbl.slice(0, 20) + '\u2026' : lbl;

              }

            }

          }

        }

      }

    });

  }

  /* ------------------------------------------------------------------ *

   *  Sales Report Renderer

   * ------------------------------------------------------------------ */

  const stat = (label, value, big = false) =>

    h('div', { class: 'stat' + (big ? ' big' : '') },

      h('span', { class: 'stat-label', text: label }),

      h('strong', { class: 'stat-value', text: value }));





  function renderDonutChart(canvas, data) {

    if (typeof Chart === 'undefined' || !canvas) return null;

    var ctx = canvas.getContext('2d');



    // Color mapper: GCash is blue (#007DFE), Cash is green (#2a9b52)

    function getPaymentColor(label, idx) {

      var l = String(label || '').toLowerCase();

      if (l === 'cash') return '#2a9b52';      // Green for Cash

      if (l === 'gcash') return '#007DFE';     // Blue for GCash

      var fallbacks = ['#2a9b52', '#007DFE', '#8b5cf6', '#e8623a', '#d97706'];

      return fallbacks[idx % fallbacks.length];

    }



    var sliceColors = data.labels.map(function(lbl, i) {

      return getPaymentColor(lbl, i);

    });



    var totalVal = data.values.reduce(function(a, b) { return a + b; }, 0);



    function formatMoneyShort(val) {

      var num = Number(val || 0);

      var dec = (num % 1 !== 0) ? 2 : 0;

      return '\u20b1' + num.toLocaleString('en-PH', { minimumFractionDigits: dec, maximumFractionDigits: dec });

    }



    // Chart.js plugin to draw amount and label perfectly centered in the donut hole with hover feedback

    var centerTextPlugin = {

      id: 'donutCenterDynamicText',

      afterDraw: function(chart) {

        var meta = chart.getDatasetMeta(0);

        if (!meta || !meta.data || !meta.data.length) return;



        var arc = meta.data[0];

        var centerX = (arc && arc.x != null) ? arc.x : (chart.chartArea.left + chart.chartArea.right) / 2;

        var centerY = (arc && arc.y != null) ? arc.y : (chart.chartArea.top + chart.chartArea.bottom) / 2;

        var innerRadius = (arc && arc.innerRadius) ? arc.innerRadius : (Math.min(chart.chartArea.right - chart.chartArea.left, chart.chartArea.bottom - chart.chartArea.top) * 0.31);



        var c = chart.ctx;

        c.save();



        var hIdx = chart._hoveredSliceIndex;

        var displayAmount, displaySub, displayColor;



        if (hIdx != null && data.values[hIdx] != null) {

          displayAmount = formatMoneyShort(data.values[hIdx]);

          var pct = totalVal > 0 ? Math.round(data.values[hIdx] / totalVal * 100) : 0;

          displaySub    = String(data.labels[hIdx] || '').toUpperCase() + ' (' + pct + '%)';

          displayColor  = sliceColors[hIdx] || CF_ESPRESSO;

        } else {

          displayAmount = formatMoneyShort(totalVal);

          displaySub    = 'TOTAL SALES';

          displayColor  = CF_ESPRESSO;

        }



        c.textAlign = 'center';

        c.textBaseline = 'middle';



        // Responsive font sizes scaled to the donut hole

        var amountSize = Math.max(13, Math.min(22, Math.round(innerRadius * 0.38)));

        var subSize    = Math.max(9, Math.min(11, Math.round(amountSize * 0.52)));



        // 1. Amount

        c.font = '800 ' + amountSize + 'px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

        c.fillStyle = displayColor;

        c.fillText(displayAmount, centerX, centerY - Math.round(subSize * 0.7));



        // 2. Sub-label

        c.font = '700 ' + subSize + 'px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

        c.fillStyle = (hIdx != null) ? displayColor : CF_MUTED;

        c.fillText(displaySub, centerX, centerY + Math.round(amountSize * 0.75));



        c.restore();

      }

    };



    var chartInstance = new Chart(ctx, {

      type: 'doughnut',

      plugins: [centerTextPlugin],

      data: {

        labels: data.labels,

        datasets: [{

          data: data.values,

          backgroundColor: sliceColors,

          borderColor: '#ffffff',

          borderWidth: 3,

          hoverOffset: 8

        }]

      },

      options: {

        responsive: true,

        maintainAspectRatio: false,

        cutout: '62%',

        onHover: function(event, elements, ch) {

          var prev = ch._hoveredSliceIndex;

          if (elements && elements.length > 0) {

            ch._hoveredSliceIndex = elements[0].index;

            ch.canvas.style.cursor = 'pointer';

          } else {

            ch._hoveredSliceIndex = null;

            ch.canvas.style.cursor = 'default';

          }

          if (prev !== ch._hoveredSliceIndex) {

            ch.draw();

          }

        },

        plugins: {

          legend: {

            position: 'right',

            onHover: function(event, legendItem, legend) {

              var ch = legend.chart;

              if (ch && ch._hoveredSliceIndex !== legendItem.index) {

                ch._hoveredSliceIndex = legendItem.index;

                ch.canvas.style.cursor = 'pointer';

                ch.draw();

              }

            },

            onLeave: function(event, legendItem, legend) {

              var ch = legend.chart;

              if (ch && ch._hoveredSliceIndex != null) {

                ch._hoveredSliceIndex = null;

                ch.canvas.style.cursor = 'default';

                ch.draw();

              }

            },

            labels: {

              boxWidth: 14,

              boxHeight: 14,

              borderRadius: 3,

              useBorderRadius: true,

              padding: 16,

              font: { size: 12, weight: '600' },

              generateLabels: function(ch) {

                var ds = ch.data.datasets[0];

                return ch.data.labels.map(function(lbl, i) {

                  var total = ds.data.reduce(function(a, b) { return a + b; }, 0);

                  var pct = total > 0 ? Math.round(ds.data[i] / total * 100) : 0;

                  return {

                    text: lbl + ' (' + pct + '%)',

                    fillStyle: sliceColors[i] || '#ccc',

                    strokeStyle: '#ffffff',

                    lineWidth: 1,

                    hidden: false,

                    index: i

                  };

                });

              }

            }

          },

          tooltip: {

            enabled: false // Dynamic center text in donut hole acts as the indicator

          }

        }

      }

    });



    // Reset when mouse leaves canvas

    canvas.addEventListener('mouseleave', function() {

      if (chartInstance && chartInstance._hoveredSliceIndex != null) {

        chartInstance._hoveredSliceIndex = null;

        chartInstance.canvas.style.cursor = 'default';

        chartInstance.draw();

      }

    });



    return chartInstance;

  }

  

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



    // Destroy any previous report charts to free canvas contexts

    _reportLineChart     = destroyChart(_reportLineChart);

    _reportBarChart      = destroyChart(_reportBarChart);

    _reportDonutChart    = destroyChart(_reportDonutChart);

    _reportAllLineChart  = destroyChart(_reportAllLineChart);

    _reportAllBarChart   = destroyChart(_reportAllBarChart);

    _reportAllDonutChart = destroyChart(_reportAllDonutChart);



    // ---- Manager-only Chart Selector (Sales Trend / Top Products / Payment Breakdown / Compare All) ----

    if (currentUser.role === 'manager') {

      var hasHourly  = r.by_hour && r.by_hour.length > 0;

      var hasItems   = r.top_items && r.top_items.length > 0;

      var isMultiDay = r.by_day && r.by_day.length > 1;

      var trendLabels, trendTotals, trendOrders, trendTitle;

      if (!isMultiDay && hasHourly) {

        var ds = buildHourlyDataset(r.by_hour);

        var lastActiveHour = r.by_hour.length ? Math.max.apply(null, r.by_hour.map(function(x) { return x.hour; })) : 22;

        var sliceEnd = Math.max(lastActiveHour + 1, 12);

        trendLabels = Array.from({ length: sliceEnd - 6 }, function(_, i) { return formatHour(i + 6); });

        trendTotals = ds.totals.slice(6, sliceEnd);

        trendOrders = ds.orders.slice(6, sliceEnd);

        trendTitle  = 'Sales Trend by Hour';

      } else if (isMultiDay) {

        trendLabels = r.by_day.map(function(d) { return dayLabel(d.day); }).reverse();

        trendTotals = r.by_day.map(function(d) { return d.total; }).reverse();

        trendOrders = r.by_day.map(function(d) { return d.orders; }).reverse();

        trendTitle  = 'Sales Trend by Day';

      }

      var hasTrend = trendLabels && trendLabels.length > 0;



      // Build payment breakdown data for donut

      var payLabels = [], payValues = [];

      if ((r.summary.cash || 0) > 0)  { payLabels.push('Cash');  payValues.push(r.summary.cash); }

      if ((r.summary.gcash || 0) > 0) { payLabels.push('GCash'); payValues.push(r.summary.gcash); }

      var hasPayData = payLabels.length > 0;



      // Helper: build a single chart panel element

      function makeChartPanel(id, title, extraClass) {

        var panelBody = h('div', { class: 'chart-panel-body' });

        var panel = h('div', { class: 'chart-panel' + (extraClass ? ' ' + extraClass : ''), id: id },

          h('div', { class: 'chart-panel-head' },

            h('span', { class: 'chart-panel-title', text: title })

          ),

          panelBody

        );

        return { panel: panel, body: panelBody };

      }



      // Build all four panels

      var trendPanel  = makeChartPanel('chartPanelTrend',   trendTitle || 'Sales Trend', 'chart-line');

      var prodPanel   = makeChartPanel('chartPanelProducts', 'Top Products (Units Sold)', 'chart-bar');

      var payPanel    = makeChartPanel('chartPanelPayment',  'Payment Breakdown', 'chart-donut');

      var allGrid     = h('div', { class: 'chart-panels-all-grid', id: 'chartPanelAll', hidden: true });



      // --- Trend panel content ---

      if (hasTrend) {

        var lineCanvas = h('canvas', { 'aria-label': 'Sales trend chart' });

        trendPanel.body.append(lineCanvas);

        (function(c, l, t, o) {

          requestAnimationFrame(function() { _reportLineChart = renderLineChart(c, { labels: l, totals: t, orders: o }); });

        })(lineCanvas, trendLabels, trendTotals, trendOrders);

      } else {

        trendPanel.body.append(h('p', { class: 'chart-panel-empty', text: 'No trend data for this period.' }));

      }



      // --- Top Products panel content ---

      if (hasItems) {

        var barCanvas = h('canvas', { 'aria-label': 'Top products chart' });

        prodPanel.body.style.height = Math.max(260, r.top_items.length * 38) + 'px';

        prodPanel.body.append(barCanvas);

        (function(c, lbs, qs) {

          requestAnimationFrame(function() { _reportBarChart = renderBarChart(c, { labels: lbs, qtys: qs }); });

        })(barCanvas, r.top_items.map(function(t) { return t.name; }), r.top_items.map(function(t) { return t.qty; }));

      } else {

        prodPanel.body.append(h('p', { class: 'chart-panel-empty', text: 'No item sales recorded in this period.' }));

      }



      // --- Payment Breakdown donut panel content ---

      if (hasPayData) {

        var donutWrap = h('div', { class: 'donut-wrap' });

        var donutCanvas = h('canvas', { 'aria-label': 'Payment breakdown donut chart' });

        donutWrap.append(donutCanvas);

        payPanel.body.append(donutWrap);

        (function(c, lbs, vals) {

          requestAnimationFrame(function() { _reportDonutChart = renderDonutChart(c, { labels: lbs, values: vals, isRevenue: true }); });

        })(donutCanvas, payLabels, payValues);

      } else {

        payPanel.body.append(h('p', { class: 'chart-panel-empty', text: 'No payment data for this period.' }));

      }



      // --- Compare All grid: clone 3 mini-panels ---

      function makeAllMini(srcTitle, extraClass) {

        var miniBody = h('div', { class: 'chart-panel-body' });

        var mini = h('div', { class: 'chart-panel' + (extraClass ? ' ' + extraClass : '') },

          h('div', { class: 'chart-panel-head' }, h('span', { class: 'chart-panel-title', text: srcTitle })),

          miniBody

        );

        return { panel: mini, body: miniBody };

      }

      var allTrend = makeAllMini(trendTitle || 'Sales Trend', 'chart-line');

      var allProd  = makeAllMini('Top Products', 'chart-bar');

      var allPay   = makeAllMini('Payment Breakdown', 'chart-donut');

      allGrid.append(allTrend.panel, allProd.panel, allPay.panel);



      // Render chart selector tabs

      var tabs = [

        { id: 'trend',    label: '📈 Sales Trend' },

        { id: 'products', label: '🏆 Top Products' },

        { id: 'payment',  label: '💳 Payment Breakdown' },

        { id: 'all',      label: '⊞ Compare All' }

      ];

      var tabEls = tabs.map(function(tab) {

        return h('button', {

          type: 'button',

          class: 'chart-tab' + (tab.id === _activeChartTab ? ' active' : ''),

          dataset: { tab: tab.id },

          text: tab.label

        });

      });

      var tabsWrap = h('div', { class: 'chart-selector-tabs' }, ...tabEls);

      var selectorRow = h('div', { class: 'chart-selector-wrap' },

        h('span', { class: 'chart-selector-label', text: 'Chart View:' }),

        tabsWrap

      );



      var panelsWrap = h('div', { class: 'chart-panels-wrap' });



      function showChartTab(tabId) {

        _activeChartTab = tabId;

        // Update tab button active states

        tabEls.forEach(function(btn) {

          btn.classList.toggle('active', btn.dataset.tab === tabId);

        });

        // Show/hide individual panels

        trendPanel.panel.hidden  = (tabId !== 'trend');

        prodPanel.panel.hidden   = (tabId !== 'products');

        payPanel.panel.hidden    = (tabId !== 'payment');

        allGrid.hidden           = (tabId !== 'all');

        // Render Compare All charts lazily on first show

        if (tabId === 'all') {

          if (!allTrend.body._chartBuilt) {

            allTrend.body._chartBuilt = true;

            if (hasTrend) {

              var c1 = h('canvas', { 'aria-label': 'Sales trend mini' });

              allTrend.body.append(c1);

              (function(c, l, t, o) {

                requestAnimationFrame(function() { _reportAllLineChart = renderLineChart(c, { labels: l, totals: t, orders: o }); });

              })(c1, trendLabels, trendTotals, trendOrders);

            } else {

              allTrend.body.append(h('p', { class: 'chart-panel-empty', text: 'No trend data.' }));

            }

          }

          if (!allProd.body._chartBuilt) {

            allProd.body._chartBuilt = true;

            if (hasItems) {

              allProd.body.style.height = Math.max(200, Math.min(r.top_items.length, 5) * 38) + 'px';

              var c2 = h('canvas', { 'aria-label': 'Top products mini' });

              allProd.body.append(c2);

              (function(c, lbs, qs) {

                requestAnimationFrame(function() { _reportAllBarChart = renderBarChart(c, { labels: lbs, qtys: qs }); });

              })(c2, r.top_items.slice(0,5).map(function(t) { return t.name; }), r.top_items.slice(0,5).map(function(t) { return t.qty; }));

            } else {

              allProd.body.append(h('p', { class: 'chart-panel-empty', text: 'No item data.' }));

            }

          }

          if (!allPay.body._chartBuilt) {

            allPay.body._chartBuilt = true;

            if (hasPayData) {

              var dWrap2 = h('div', { class: 'donut-wrap' });

              var dCvs2 = h('canvas', { 'aria-label': 'Payment donut mini' });

              dWrap2.append(dCvs2);

              allPay.body.append(dWrap2);

              (function(c, lbs, vals) {

                requestAnimationFrame(function() { _reportAllDonutChart = renderDonutChart(c, { labels: lbs, values: vals, isRevenue: true }); });

              })(dCvs2, payLabels, payValues);

            } else {

              allPay.body.append(h('p', { class: 'chart-panel-empty', text: 'No payment data.' }));

            }

          }

        }

      }



      // Wire up tab click events

      tabEls.forEach(function(btn) {

        btn.addEventListener('click', function() { showChartTab(btn.dataset.tab); });

      });



      panelsWrap.append(trendPanel.panel, prodPanel.panel, payPanel.panel, allGrid);

      body.append(selectorRow, panelsWrap);



      // Show whichever tab was last active (default: trend)

      showChartTab(_activeChartTab);

    }

    // ---- End Manager Chart Selector Section ----



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

   *  Account Management Module

   * ------------------------------------------------------------------ */

  let accountsList = [];

  let accountsOverview = { total: 0, baristas: 0, managers: 0, active: 0, pending: 0, inactive: 0 };

  let accountFilter = 'all';

  let accountSearchQuery = '';



  async function loadAccounts() {

    try {

      const data = await api('accounts_api.php');

      accountsList = data.items || [];

      accountsOverview = data.overview || {};

      renderAccountStats();

      renderAccountsTable();

    } catch (e) {

      toast(e.message, 'error', 4000);

    }

  }



  function renderAccountStats() {

    if ($('#statTotalAccounts')) $('#statTotalAccounts').textContent = accountsOverview.total || 0;

    if ($('#statBaristaAccounts')) $('#statBaristaAccounts').textContent = accountsOverview.baristas || 0;

    if ($('#statManagerAccounts')) $('#statManagerAccounts').textContent = accountsOverview.managers || 0;

    if ($('#statActiveAccounts')) $('#statActiveAccounts').textContent = accountsOverview.active || 0;

    if ($('#statPendingAccounts')) $('#statPendingAccounts').textContent = (accountsOverview.pending || 0) + (accountsOverview.inactive || 0);



    // Also update manager dashboard card if present

    if ($('#mgrCardUserCount')) {

      $('#mgrCardUserCount').textContent = `${accountsOverview.total || 0} accounts`;

    }

  }



  function renderAccountsTable() {

    const tbody = $('#accountsTableBody');

    if (!tbody) return;

    tbody.textContent = '';



    const q = accountSearchQuery.toLowerCase().trim();

    const filtered = accountsList.filter(acc => {

      // Filter by tab

      if (accountFilter === 'barista' && acc.role !== 'barista') return false;

      if (accountFilter === 'manager' && acc.role !== 'manager') return false;

      if (accountFilter === 'active' && acc.status !== 'active') return false;

      if (accountFilter === 'pending' && acc.status !== 'pending') return false;



      // Filter by search query

      if (q) {

        const matchName = (acc.full_name || '').toLowerCase().includes(q);

        const matchUser = (acc.username || '').toLowerCase().includes(q);

        const matchEmail = (acc.email || '').toLowerCase().includes(q);

        return matchName || matchUser || matchEmail;

      }

      return true;

    });



    if (filtered.length === 0) {

      const tr = h('tr', {},

        h('td', { colspan: '8', style: 'text-align: center; padding: 32px; color: var(--muted);' },

          h('p', { style: 'font-size: 15px; margin: 0;' }, 'No accounts match your current filter.')

        )

      );

      tbody.append(tr);

      return;

    }



    for (const acc of filtered) {

      const isSelf = Boolean(acc.is_self);

      const isManager = (acc.role === 'manager');

      const isCurrentUserMgr = (currentUser.role === 'manager');



      // Status Badge

      const statusBadge = h('span', { class: `status-badge ${acc.status}` },

        h('span', { class: 'status-dot' }),

        acc.status.toUpperCase()

      );



      // Role Badge

      const roleBadge = h('span', { class: `role-badge role-${acc.role}` },

        acc.role === 'barista' ? '☕ Barista' : '🛡️ Manager'

      );



      // Actions Container

      const actWrap = h('div', { style: 'display: flex; gap: 6px; align-items: center; justify-content: flex-end;' });



      // Edit Button

      const editBtn = h('button', {

        type: 'button',

        class: 'acc-act-btn',

        title: 'Edit account details',

        onclick: () => openEditAccountSheet(acc)

      }, '✏️ Edit');

      actWrap.append(editBtn);



      // Reset Password Button

      const resetPassBtn = h('button', {

        type: 'button',

        class: 'acc-act-btn',

        title: 'Reset account password',

        onclick: () => openResetPasswordSheet(acc)

      }, '🔑 Reset');

      actWrap.append(resetPassBtn);



      // Status Action (Approve / Deactivate / Activate)

      if (acc.status === 'pending') {

        const approveBtn = h('button', {

          type: 'button',

          class: 'acc-act-btn btn-activate',

          title: 'Approve and activate this account',

          onclick: () => toggleAccountStatus(acc.user_id, 'active', `Approve and activate account for @${acc.username}?`)

        }, '✓ Approve');

        actWrap.append(approveBtn);

      } else if (acc.status === 'active') {

        const deactBtn = h('button', {

          type: 'button',

          class: 'acc-act-btn btn-deactivate',

          title: 'Deactivate account',

          onclick: () => toggleAccountStatus(acc.user_id, 'inactive', `Are you sure you want to deactivate the account @${acc.username}?`)

        }, 'Deactivate');



        // Manager cannot deactivate self

        if (isCurrentUserMgr && isSelf) {

          deactBtn.disabled = true;

          deactBtn.title = 'Security Rule: You cannot deactivate your own Manager account.';

        }

        // Protect last active manager

        if (isManager && accountsOverview.managers <= 1) {

          deactBtn.disabled = true;

          deactBtn.title = 'Security Rule: Cannot deactivate the last active Manager account.';

        }

        actWrap.append(deactBtn);

      } else {

        const actBtn = h('button', {

          type: 'button',

          class: 'acc-act-btn btn-activate',

          title: 'Activate account',

          onclick: () => toggleAccountStatus(acc.user_id, 'active', `Reactivate account for @${acc.username}?`)

        }, 'Activate');

        actWrap.append(actBtn);

      }



      const formattedCreated = acc.created_at ? acc.created_at.replace('T', ' ').slice(0, 16) : '--';

      const loginText = acc.last_login ? acc.last_login.replace('T', ' ').slice(0, 16) : 'Never';



      const tr = h('tr', {},

        h('td', { style: 'font-weight: 700;' }, acc.full_name + (isSelf ? ' (You)' : '')),

        h('td', { style: 'font-family: monospace; font-size: 13.5px;' }, '@' + acc.username),

        h('td', { style: 'color: var(--muted); font-size: 13px;' }, acc.email || '—'),

        h('td', {}, roleBadge),

        h('td', {}, statusBadge),

        h('td', { style: 'font-size: 12.5px; color: var(--muted); white-space: nowrap;' }, formattedCreated),

        h('td', { style: 'font-size: 12.5px; color: acc.last_login ? "inherit" : "var(--muted)"; white-space: nowrap;' }, loginText),

        h('td', { class: 'act' }, actWrap)

      );



      tbody.append(tr);

    }

  }



  function openCreateAccountSheet() {

    $('#accountSheetTitle').textContent = 'Create New Account';

    $('#accUserId').value = '';

    $('#accFullName').value = '';

    $('#accUsername').value = '';

    $('#accUsername').disabled = false;

    $('#accEmail').value = '';

    $('#accRole').value = 'barista';

    $('#accRole').disabled = false;

    $('#accStatus').value = 'active';

    $('#accStatus').disabled = false;

    $('#accPassword').value = '';

    $('#accPassword').required = true;

    $('#accConfirmPassword').value = '';

    $('#accConfirmPassword').required = true;

    $('#accPassLabel').textContent = 'Password *';

    $('#accPassConfirmLabel').textContent = 'Confirm Password *';

    $('#accPassHint').hidden = true;

    $('#accountSheetError').hidden = true;

    $('#accountSheet').hidden = false;

    $('#accFullName').focus();

  }



  function openEditAccountSheet(acc) {

    $('#accountSheetTitle').textContent = `Edit Account: @${acc.username}`;

    $('#accUserId').value = acc.user_id;

    $('#accFullName').value = acc.full_name;

    $('#accUsername').value = acc.username;

    $('#accUsername').disabled = true; // Username cannot be changed

    $('#accEmail').value = acc.email || '';

    $('#accRole').value = acc.role;

    $('#accStatus').value = acc.status;



    // Security Rules for Manager:

    // Manager cannot change their own role or deactivate themselves

    const isCurrentUserMgr = (currentUser.role === 'manager');

    const isSelf = Boolean(acc.is_self);

    if (isCurrentUserMgr && isSelf) {

      $('#accRole').disabled = true;

      $('#accRole').title = 'Security Rule: Managers cannot alter their own role.';

      $('#accStatus').disabled = true;

      $('#accStatus').title = 'Security Rule: You cannot deactivate your own account.';

    } else {

      $('#accRole').disabled = false;

      $('#accRole').title = '';

      $('#accStatus').disabled = false;

      $('#accStatus').title = '';

    }



    $('#accPassword').value = '';

    $('#accPassword').required = false;

    $('#accConfirmPassword').value = '';

    $('#accConfirmPassword').required = false;

    $('#accPassLabel').textContent = 'New Password (Optional)';

    $('#accPassConfirmLabel').textContent = 'Confirm New Password';

    $('#accPassHint').hidden = false;

    $('#accountSheetError').hidden = true;

    $('#accountSheet').hidden = false;

    $('#accFullName').focus();

  }



  function closeAccountSheet() {

    const ash = $('#accountSheet');

    if (ash) ash.hidden = true;

    const asErr = $('#accountSheetError');

    if (asErr) asErr.hidden = true;

  }



  function openResetPasswordSheet(acc) {

    $('#resetUserId').value = acc.user_id;

    $('#resetTargetUsername').textContent = `@${acc.username} (${acc.full_name})`;

    $('#resetNewPass').value = '';

    $('#resetConfirmPass').value = '';

    $('#resetPassError').hidden = true;

    $('#resetPassSheet').hidden = false;

    $('#resetNewPass').focus();

  }



  function closeResetPassSheet() {

    const rsh = $('#resetPassSheet');

    if (rsh) rsh.hidden = true;

    const rshErr = $('#resetPassError');

    if (rshErr) rshErr.hidden = true;

  }



  async function toggleAccountStatus(userId, newStatus, promptMsg) {

    if (!confirm(promptMsg)) return;

    try {

      const res = await api('accounts_api.php', {

        action: 'toggle_status',

        user_id: userId,

        status: newStatus,

        csrf_token: currentUser.csrf_token

      });

      toast(res.message || 'Status updated.', 'success', 3000);

      loadAccounts();

    } catch (e) {

      toast(e.message, 'error', 4500);

    }

  }



  /* ------------------------------------------------------------------ *

   *  Manager Home Dashboard Loader

   * ------------------------------------------------------------------ */

  async function loadManagerDashboard() {

    try {

      // 1. Load accounts overview

      const accData = await api('accounts_api.php');

      if ($('#mgrCardUserCount')) {

        $('#mgrCardUserCount').textContent = (accData.overview && accData.overview.total ? accData.overview.total : 0) + ' accounts';

      }



      // 2. Load inventory items count

      const invData = await api('inventory_api.php');

      if ($('#mgrCardSupplyCount')) {

        const totalItems = (invData.items || []).length;

        const lowCount = (invData.items || []).filter(function(i) { return i.low; }).length;

        $('#mgrCardSupplyCount').textContent = totalItems + ' items' + (lowCount > 0 ? ' (' + lowCount + ' low)' : '');

      }



      // 3. Load today's sales + render dashboard charts (Option B)

      const todayIso = toIsoDate(new Date());

      const repData = await api('report.php?range=date&date=' + encodeURIComponent(todayIso));

      if ($('#mgrCardSalesToday')) {

        const totalSales = (repData.summary && repData.summary.total) || 0;

        $('#mgrCardSalesToday').textContent = money(totalSales);

      }



      // Update dashboard chart subtitle

      const periodLabel = $('#mgrChartsPeriodLabel');

      if (periodLabel) periodLabel.textContent = repData.label || todayIso;



      // ---- Dashboard Line Chart: Hourly Sales Trend ----

      const lineCanvasEl = $('#mgrLineChart');

      const lineEmptyEl  = $('#mgrLineChartEmpty');

      if (lineCanvasEl) {

        _mgrLineChart = destroyChart(_mgrLineChart);

        const byHour = repData.by_hour || [];

        if (byHour.length > 0) {

          lineCanvasEl.hidden = false;

          if (lineEmptyEl) lineEmptyEl.hidden = true;

          const ds = buildHourlyDataset(byHour);

          const lastActive = Math.max.apply(null, byHour.map(function(x) { return x.hour; }));

          const sliceEnd   = Math.max(lastActive + 1, 12);

          const labels  = Array.from({ length: sliceEnd - 6 }, function(_, i) { return formatHour(i + 6); });

          const totals  = ds.totals.slice(6, sliceEnd);

          const orders  = ds.orders.slice(6, sliceEnd);

          _mgrLineChart = renderLineChart(lineCanvasEl, { labels: labels, totals: totals, orders: orders });

        } else {

          lineCanvasEl.hidden = true;

          if (lineEmptyEl) lineEmptyEl.hidden = false;

        }

      }



      // ---- Dashboard Bar Chart: Top Sellers ----

      const barCanvasEl = $('#mgrBarChart');

      const barEmptyEl  = $('#mgrBarChartEmpty');

      if (barCanvasEl) {

        _mgrBarChart = destroyChart(_mgrBarChart);

        const topItems = repData.top_items || [];

        if (topItems.length > 0) {

          barCanvasEl.hidden = false;

          if (barEmptyEl) barEmptyEl.hidden = true;

          const barWrap = barCanvasEl.closest('.mgr-chart-wrap');

          if (barWrap) barWrap.style.height = Math.max(180, topItems.length * 36) + 'px';

          _mgrBarChart = renderBarChart(barCanvasEl, {

            labels: topItems.map(function(t) { return t.name; }),

            qtys:   topItems.map(function(t) { return t.qty; })

          });

        } else {

          barCanvasEl.hidden = true;

          if (barEmptyEl) barEmptyEl.hidden = false;

        }

      }



    } catch (_) {

      // Ignore background stats refresh errors

    }

  }

  /* ------------------------------------------------------------------ *

   *  Wire Everything Up

   * ------------------------------------------------------------------ */

  // Top nav

  $$('.nav-btn').forEach(b => b.addEventListener('click', () => {

    if (currentUser.role === 'manager' && currentView === b.dataset.view) {

      showView('dashboard');

    } else {

      showView(b.dataset.view);

    }

  }));

  $$('[data-close]').forEach(b => b.addEventListener('click', () => {

    if (currentUser.role === 'manager') {

      showView('dashboard');

    } else {

      showView('pos');

    }

  }));



  // Brand logo click returns to POS (or Dashboard for Manager) and clears filters

  const brandEl = $('.brand');

  if (brandEl) {

    brandEl.addEventListener('click', () => {

      if (currentUser.role === 'manager') {

        showView('dashboard');

      } else {

        showView('pos');

        activeCategory = 'all';

        searchQuery = '';

        if (searchInput) searchInput.value = '';

        if (clearBtn) clearBtn.hidden = true;

        renderCategoryBar();

        renderMenu();

      }

    });

  }



  // Search input on POS

  const searchInput = $('#productSearch');

  const clearBtn = $('#searchClearBtn');

  if (searchInput && clearBtn) {

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

  }



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

  const openAddBtn = $('#openAddProductBtn');

  if (openAddBtn) {

    openAddBtn.addEventListener('click', () => {

      showView('products');

      openProductSheet('add');

    });

  }

  const addNewProdBtn = $('#addNewProductBtn');

  if (addNewProdBtn) addNewProdBtn.addEventListener('click', () => openProductSheet('add'));



  // Product Form & Image upload

  const pSheetCancel = $('#productSheetCancel');

  if (pSheetCancel) pSheetCancel.addEventListener('click', closeProductSheet);

  const pSheet = $('#productSheet');

  if (pSheet) pSheet.addEventListener('click', e => { if (e.target === e.currentTarget) closeProductSheet(); });

  const pForm = $('#productForm');

  if (pForm) pForm.addEventListener('submit', submitProductForm);



  const pImgInp = $('#pImageInput');

  if (pImgInp) {

    pImgInp.addEventListener('change', e => {

      const file = e.target.files && e.target.files[0];

      if (file) {

        removeCurrentImage = false;

        const reader = new FileReader();

        reader.onload = ev => {

          const prevImg = $('#imagePreviewImg');

          if (prevImg) prevImg.src = ev.target.result;

          const prevBox = $('#imagePreviewBox');

          if (prevBox) prevBox.hidden = false;

        };

        reader.readAsDataURL(file);

      }

    });

  }



  const remImgBtn = $('#removeImageBtn');

  if (remImgBtn) {

    remImgBtn.addEventListener('click', () => {

      removeCurrentImage = true;

      if (pImgInp) pImgInp.value = '';

      const prevBox = $('#imagePreviewBox');

      if (prevBox) prevBox.hidden = true;

    });

  }



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



  const openQuickAdd = $('#openQuickAddSupplyBtn');

  if (openQuickAdd) {

    openQuickAdd.addEventListener('click', () => {

      const card = $('#quickAddSupplyCard');

      if (!card) return;

      card.hidden = !card.hidden;

      if (!card.hidden) {

        if ($('#quickSupplyName')) $('#quickSupplyName').value = '';

        if ($('#quickSupplyUnit')) $('#quickSupplyUnit').value = '';

        if ($('#quickSupplyStock')) $('#quickSupplyStock').value = '0';

        if ($('#quickSupplyThreshold')) $('#quickSupplyThreshold').value = '0';

        if ($('#quickSupplyError')) $('#quickSupplyError').hidden = true;

        if ($('#quickSupplyName')) $('#quickSupplyName').focus();

      }

    });

  }



  const quickClose = $('#quickAddSupplyClose');

  if (quickClose) quickClose.addEventListener('click', () => {

    const card = $('#quickAddSupplyCard');

    if (card) card.hidden = true;

  });



  const quickCancel = $('#quickSupplyCancel');

  if (quickCancel) quickCancel.addEventListener('click', () => {

    const card = $('#quickAddSupplyCard');

    if (card) card.hidden = true;

  });



  const quickSave = $('#quickSupplySaveBtn');

  if (quickSave) quickSave.addEventListener('click', submitQuickAddSupply);



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

  const clrCartBtn = $('#clearCart');

  if (clrCartBtn) clrCartBtn.addEventListener('click', () => { cart.clear(); renderCart(); });



  // Pay buttons

  const payCashBtn = $('.pay.cash');

  if (payCashBtn) payCashBtn.addEventListener('click', openPayModal);

  const payGcashBtn = $('.pay.gcash');

  if (payGcashBtn) payGcashBtn.addEventListener('click', openGcashModal);

  const mobGcashBtn = $('#mobPayGcashBtn');

  if (mobGcashBtn) mobGcashBtn.addEventListener('click', openGcashModal);



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

      const calc = getCartCalculations();

      checkout('GCash', calc.payable, { reference_number: ref });

      closeGcashModal();

    });

  }



  // Cancel Order Modal Buttons

  const dismissCancelBtn = $('#dismissCancelOrderBtn');

  if (dismissCancelBtn) dismissCancelBtn.addEventListener('click', closeCancelOrderModal);

  const confirmCancelBtn = $('#confirmCancelOrderBtn');

  if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', submitCancelOrder);



  // Cart Discount Selectors

  const discSelect = $('#cartDiscountType');

  if (discSelect) {

    discSelect.addEventListener('change', () => {

      const val = discSelect.value;

      const sWrap = $('#seniorIdWrap');

      const cWrap = $('#customPercentWrap');

      if (sWrap) sWrap.hidden = (val !== 'senior_pwd');

      if (cWrap) cWrap.hidden = (val !== 'custom_percent');

      renderCart();

    });

  }

  const discRateInput = $('#cartDiscountRate');

  if (discRateInput) discRateInput.addEventListener('input', renderCart);

  const seniorIdInput = $('#cartSeniorIdNo');

  if (seniorIdInput) seniorIdInput.addEventListener('input', renderCart);



  const cancelPayBtn = $('#cancelPayBtn');

  if (cancelPayBtn) cancelPayBtn.addEventListener('click', closePayModal);

  const confirmCashPayBtn = $('#confirmCashPayBtn');

  if (confirmCashPayBtn) {

    confirmCashPayBtn.addEventListener('click', () => {

      const payInp = $('#payTenderedInput');

      const tendered = payInp ? (parseFloat(payInp.value) || 0) : 0;

      checkout('Cash', tendered);

    });

  }



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

  const closeReceiptBtn = $('#closeReceiptBtn');

  if (closeReceiptBtn) {

    closeReceiptBtn.addEventListener('click', () => {

      const recModal = $('#receiptModal');

      if (recModal) recModal.hidden = true;

    });

  }



  /* ------------------------------------------------------------------ *

   *  Profile Dropdown & Topbar Navigation

   * ------------------------------------------------------------------ */

  const profileBtn = $('#profileBtn');

  const profileDropdown = $('#profileDropdown');

  if (profileBtn && profileDropdown) {

    profileBtn.addEventListener('click', (e) => {

      e.stopPropagation();

      const isHidden = profileDropdown.hidden;

      profileDropdown.hidden = !isHidden;

      profileBtn.classList.toggle('active', isHidden);

      profileBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');

    });



    document.addEventListener('click', (e) => {

      if (!profileDropdown.hidden && !e.target.closest('#userProfileWrap')) {

        profileDropdown.hidden = true;

        profileBtn.classList.remove('active');

        profileBtn.setAttribute('aria-expanded', 'false');

      }

    });



    document.addEventListener('keydown', (e) => {

      if (e.key === 'Escape') {

        if (!profileDropdown.hidden) {

          profileDropdown.hidden = true;

          profileBtn.classList.remove('active');

          profileBtn.setAttribute('aria-expanded', 'false');

          return;

        }

        if (currentView && currentView !== 'pos' && currentView !== 'dashboard') {

          showView(currentUser.role === 'manager' ? 'dashboard' : 'pos');

        }

      }

    });

  }



  const openAccountsBtn = $('#openAccountsBtn');

  if (openAccountsBtn) {

    openAccountsBtn.addEventListener('click', () => {

      if (profileDropdown) profileDropdown.hidden = true;

      if (profileBtn) profileBtn.classList.remove('active');

      showView('accounts');

    });

  }



  /* ------------------------------------------------------------------ *

   *  Manager Dashboard Shortcuts

   * ------------------------------------------------------------------ */

  const mgrOpenInventoryBtn = $('#mgrOpenInventoryBtn');

  if (mgrOpenInventoryBtn) {

    mgrOpenInventoryBtn.addEventListener('click', () => showView('inventory'));

  }

  const mgrOpenReportBtn = $('#mgrOpenReportBtn');

  if (mgrOpenReportBtn) {

    mgrOpenReportBtn.addEventListener('click', () => showView('report'));

  }

  const mgrOpenAccountsBtn = $('#mgrOpenAccountsBtn');

  if (mgrOpenAccountsBtn) {

    mgrOpenAccountsBtn.addEventListener('click', () => showView('accounts'));

  }



  /* ------------------------------------------------------------------ *

   *  Account Management Form & Search Handlers

   * ------------------------------------------------------------------ */

  const accSearch = $('#accountSearch');

  const accSearchClear = $('#accountSearchClearBtn');

  if (accSearch) {

    accSearch.addEventListener('input', () => {

      accountSearchQuery = accSearch.value;

      if (accSearchClear) accSearchClear.hidden = !accSearch.value.trim();

      renderAccountsTable();

    });

  }

  if (accSearchClear) {

    accSearchClear.addEventListener('click', () => {

      if (accSearch) accSearch.value = '';

      accountSearchQuery = '';

      accSearchClear.hidden = true;

      renderAccountsTable();

      accSearch?.focus();

    });

  }



  // Filter Tabs

  $$('.acc-filter-tab').forEach(tab => {

    tab.addEventListener('click', () => {

      $$('.acc-filter-tab').forEach(t => t.classList.remove('active'));

      tab.classList.add('active');

      accountFilter = tab.dataset.filter || 'all';

      renderAccountsTable();

    });

  });



  // Open New Account Sheet

  const addNewAccBtn = $('#addNewAccountBtn');

  if (addNewAccBtn) addNewAccBtn.addEventListener('click', openCreateAccountSheet);



  // Close Sheets

  const accCancelBtn = $('#accountSheetCancel');

  if (accCancelBtn) accCancelBtn.addEventListener('click', closeAccountSheet);

  const accSheet = $('#accountSheet');

  if (accSheet) {

    accSheet.addEventListener('click', (e) => {

      if (e.target === accSheet) closeAccountSheet();

    });

  }



  const resetCancelBtn = $('#resetPassCancel');

  if (resetCancelBtn) resetCancelBtn.addEventListener('click', closeResetPassSheet);

  const resetSheet = $('#resetPassSheet');

  if (resetSheet) {

    resetSheet.addEventListener('click', (e) => {

      if (e.target === resetSheet) closeResetPassSheet();

    });

  }



  // Account Form Save

  const accForm = $('#accountForm');

  if (accForm) {

    accForm.addEventListener('submit', async (e) => {

      e.preventDefault();

      const errBox = $('#accountSheetError');

      errBox.hidden = true;

      errBox.textContent = '';



      const userId = $('#accUserId').value;

      const isEdit = Boolean(userId);

      const fullName = $('#accFullName').value.trim();

      const username = $('#accUsername').value.trim();

      const email = $('#accEmail').value.trim();

      const role = $('#accRole').value;

      const status = $('#accStatus').value;

      const pass = $('#accPassword').value;

      const confirmPass = $('#accConfirmPassword').value;



      if (!fullName) {

        errBox.textContent = 'Full Name is required.';

        errBox.hidden = false;

        return;

      }

      if (!isEdit && !username) {

        errBox.textContent = 'Username is required.';

        errBox.hidden = false;

        return;

      }

      if (!isEdit && (!pass || pass.length < 6)) {

        errBox.textContent = 'Password must be at least 6 characters.';

        errBox.hidden = false;

        return;

      }

      if (pass && pass !== confirmPass) {

        errBox.textContent = 'Passwords do not match.';

        errBox.hidden = false;

        return;

      }



      const saveBtn = $('#accountSheetSaveBtn');

      saveBtn.disabled = true;

      saveBtn.textContent = 'Saving…';



      try {

        const payload = isEdit ? {

          action: 'update',

          user_id: parseInt(userId, 10),

          full_name: fullName,

          email: email,

          role: role,

          status: status,

          password: pass,

          csrf_token: currentUser.csrf_token

        } : {

          action: 'create',

          full_name: fullName,

          username: username,

          email: email,

          role: role,

          status: status,

          password: pass,

          csrf_token: currentUser.csrf_token

        };



        const res = await api('accounts_api.php', payload);

        toast(res.message || 'Account saved.', 'success', 3000);

        closeAccountSheet();

        loadAccounts();

      } catch (err) {

        errBox.textContent = err.message;

        errBox.hidden = false;

      } finally {

        saveBtn.disabled = false;

        saveBtn.textContent = 'Save Account';

      }

    });

  }



  // Reset Password Form Submit

  const resetForm = $('#resetPassForm');

  if (resetForm) {

    resetForm.addEventListener('submit', async (e) => {

      e.preventDefault();

      const errBox = $('#resetPassError');

      errBox.hidden = true;

      errBox.textContent = '';



      const userId = $('#resetUserId').value;

      const newPass = $('#resetNewPass').value;

      const confirmPass = $('#resetConfirmPass').value;



      if (newPass.length < 6) {

        errBox.textContent = 'New password must be at least 6 characters.';

        errBox.hidden = false;

        return;

      }

      if (newPass !== confirmPass) {

        errBox.textContent = 'Passwords do not match.';

        errBox.hidden = false;

        return;

      }



      const saveBtn = $('#resetPassSaveBtn');

      saveBtn.disabled = true;

      saveBtn.textContent = 'Resetting…';



      try {

        const res = await api('accounts_api.php', {

          action: 'reset_password',

          user_id: parseInt(userId, 10),

          new_password: newPass,

          confirm_password: confirmPass,

          csrf_token: currentUser.csrf_token

        });

        toast(res.message || 'Password reset successfully.', 'success', 3000);

        closeResetPassSheet();

      } catch (err) {

        errBox.textContent = err.message;

        errBox.hidden = false;

      } finally {

        saveBtn.disabled = false;

        saveBtn.textContent = 'Set New Password';

      }

    });

  }



  // Password Show/Hide Toggle Buttons

  $$('.btn-toggle-pass').forEach(btn => {

    btn.addEventListener('click', () => {

      const targetId = btn.getAttribute('data-target');

      const input = document.getElementById(targetId);

      if (!input) return;

      if (input.type === 'password') {

        input.type = 'text';

        btn.textContent = '🙈';

      } else {

        input.type = 'password';

        btn.textContent = '👁️';

      }

    });

  });



  // Init

  initCategoryBarScroll();

  tickClock();

  setInterval(tickClock, 15000);



  if (currentUser.role === 'manager') {

    // Manager Restricted Mode: do NOT poll POS queue or fetch menu

    loadManagerDashboard();



    // Check URL param or default to dashboard

    const urlParams = new URLSearchParams(window.location.search);

    const reqView = urlParams.get('view');

    if (reqView === 'inventory' || reqView === 'report' || reqView === 'accounts') {

      showView(reqView);

    } else {

      showView('dashboard');

    }

  } else {

    // Barista Mode: full POS system access

    renderCart();

    loadMenu();

    pollQueue();

    setInterval(pollQueue, POLL_MS);



    const urlParams = new URLSearchParams(window.location.search);

    const reqView = urlParams.get('view');

    if (reqView && ['pos', 'products', 'inventory', 'report'].includes(reqView)) {

      showView(reqView);

    }

  }

})();

