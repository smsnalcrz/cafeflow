/**
 * CafeFlow POS - Bespoke SVG Icon System
 * Scalable vector icons for categories, drink temperatures, food items, and UI controls.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
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

  window.CafeFlow.icons = {
    SVG_ICONS,
    getCategoryKey,
    getCategorySvg,
    getCategoryIcon
  };
})();
