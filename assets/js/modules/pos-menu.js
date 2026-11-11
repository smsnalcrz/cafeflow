/**
 * CafeFlow POS - POS Menu & Size Picker Module
 * Manages cashier menu product catalog, category tabs, product search,
 * size options popover, and visual card badge synchronization.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, money, h, toast, emptyState } = window.CafeFlow.utils;
  const api = window.CafeFlow.api;
  const { SVG_ICONS, getCategorySvg } = window.CafeFlow.icons;
  const state = window.CafeFlow.state;

  let _sizePickerProduct = null;

  async function loadMenu() {
    try {
      const data = await api('fetch_menu.php');
      state.menu = data.products || [];
      state.menuCategories = data.categories || [];
      renderCategoryBar();
      renderMenu();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
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
      'aria-selected': state.activeCategory === 'all' ? 'true' : 'false',
      class: 'cat-pill' + (state.activeCategory === 'all' ? ' active' : ''),
      onclick: () => {
        state.activeCategory = 'all';
        renderCategoryBar();
        renderMenu();
        const active = bar.querySelector('.cat-pill.active');
        if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      }
    }, allIcon, h('span', { class: 'cat-text', text: 'All Items' }));
    bar.append(allPill);

    for (const cat of state.menuCategories) {
      const isSelected = state.activeCategory.toLowerCase() === cat.toLowerCase();
      const icon = h('span', { class: 'cat-icon' });
      icon.innerHTML = getCategorySvg(cat, 16);
      const pill = h('button', {
        type: 'button',
        role: 'tab',
        'aria-selected': isSelected ? 'true' : 'false',
        class: 'cat-pill' + (isSelected ? ' active' : ''),
        onclick: () => {
          state.activeCategory = cat;
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
    if (!grid) return;
    grid.textContent = '';
    state.cardBadges.clear();

    if (!state.menu.length) {
      grid.append(emptyState({
        icon: '☕',
        title: 'No Products in Menu',
        desc: 'Get started by registering your first beverage, pastry, or food item.',
        tip: '💡 Tip: You can set hot/iced options, cup sizes, and linked recipe ingredients.',
        actionText: '+ Add Your First Product',
        onAction: () => {
          if (window.CafeFlow.nav && window.CafeFlow.nav.showView) {
            window.CafeFlow.nav.showView('products');
          }
        }
      }));
      return;
    }

    // Filter by category and search keyword
    const filtered = state.menu.filter(p => {
      const matchCat = state.activeCategory === 'all' || (p.category && p.category.toLowerCase() === state.activeCategory.toLowerCase());
      const matchSearch = !state.searchQuery ||
        p.name.toLowerCase().includes(state.searchQuery) ||
        (p.category && p.category.toLowerCase().includes(state.searchQuery));
      return matchCat && matchSearch;
    });

    if (!filtered.length) {
      const isSearch = Boolean(state.searchQuery);
      grid.append(emptyState({
        icon: isSearch ? '🔍' : '☕',
        title: isSearch ? `No products match "${state.searchQuery}"` : `No items in "${state.activeCategory}"`,
        desc: isSearch ? 'Check your spelling or browse using the category chips above.' : 'No products have been assigned to this category yet.',
        tip: isSearch ? '💡 Tip: You can search by product name (e.g. Latte) or category (e.g. Croffle).' : null,
        actionText: isSearch ? '✕ Clear Search Filter' : 'Show All Products',
        onAction: () => {
          state.searchQuery = '';
          state.activeCategory = 'all';
          const ps = $('#productSearch');
          if (ps) ps.value = '';
          const sc = $('#searchClearBtn');
          if (sc) sc.hidden = true;
          renderCategoryBar();
          renderMenu();
        }
      }));
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
        const catLabel = p.category && state.activeCategory === 'all' ? p.category : 'Single size';
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
            if (window.CafeFlow.cart && window.CafeFlow.cart.addToCart) {
              window.CafeFlow.cart.addToCart(p, null, null, p.price);
            }
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
      state.cardBadges.set(p.product_id, badge);
    }

    syncCardBadges();
  }

  function syncCardBadges() {
    for (const [productId, badge] of state.cardBadges) {
      let total = 0;
      for (const [key, line] of state.cart) {
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

  function openSizePicker(p) {
    if (p.stock_state === 'out') {
      toast(`${p.name} is currently out of stock.`, 'warn');
      return;
    }
    _sizePickerProduct = p;
    const titleEl = $('#sizePopoverTitle');
    if (titleEl) titleEl.textContent = p.name;
    const sizeOpts = $('#sizeOptions');
    if (!sizeOpts) return;
    sizeOpts.innerHTML = '';

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
      const isLg = s.oz === 22 || s.code === '22oz';

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
        if (window.CafeFlow.cart && window.CafeFlow.cart.addToCart) {
          window.CafeFlow.cart.addToCart(p, s.oz, s.label, s.price);
        }
        closeSizePicker();
      });

      sizeOpts.appendChild(btn);
    });

    const bDrop = $('#sizeBackdrop');
    if (bDrop) bDrop.hidden = false;
    const pop = $('#sizePopover');
    if (pop) pop.hidden = false;
  }

  function closeSizePicker() {
    const bDrop = $('#sizeBackdrop');
    if (bDrop) bDrop.hidden = true;
    const pop = $('#sizePopover');
    if (pop) pop.hidden = true;
    _sizePickerProduct = null;
  }

  function initMenuEvents() {
    initCategoryBarScroll();

    const szClose = $('#sizePopoverClose');
    if (szClose) szClose.addEventListener('click', closeSizePicker);
    const szBdrop = $('#sizeBackdrop');
    if (szBdrop) szBdrop.addEventListener('click', closeSizePicker);

    const searchInput = $('#productSearch');
    const clearBtn = $('#searchClearBtn');
    if (searchInput && clearBtn) {
      searchInput.addEventListener('input', e => {
        state.searchQuery = e.target.value.trim().toLowerCase();
        clearBtn.hidden = !state.searchQuery;
        renderMenu();
      });
      clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        state.searchQuery = '';
        clearBtn.hidden = true;
        searchInput.focus();
        renderMenu();
      });
    }
  }

  window.CafeFlow.menu = {
    loadMenu,
    renderMenu,
    renderCategoryBar,
    syncCardBadges,
    openSizePicker,
    closeSizePicker,
    initMenuEvents
  };
})();
