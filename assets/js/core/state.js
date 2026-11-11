/**
 * CafeFlow POS - Central Application State Store
 * Holds shared reactive data structures (menu, cart, discounts, orders queue, inventory).
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const urlCat = new URLSearchParams(window.location.search).get('cat');

  window.CafeFlow.state = {
    // Menu & Catalog
    menu: [],
    menuCategories: [],
    activeCategory: urlCat ? urlCat : 'all',
    searchQuery: '',
    cardBadges: new Map(),

    // Cart & Pricing
    cart: new Map(),
    activeDiscount: null,
    customDiscountAmount: 0,
    submitting: false,

    // Orders Queue
    queueOrders: [],
    queueFilter: 'all',

    // View & Navigation
    currentView: 'pos',

    // Back-office Data
    inventory: [],
    inventorySearch: '',
    products: [],
    productsSearch: '',
    accountsList: []
  };
})();
