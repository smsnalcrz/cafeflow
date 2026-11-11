/**
 * CafeFlow POS - Product Management & Recipe Module
 * Handles product catalog table, multi-ingredient recipe linking,
 * dynamic cup size pricing matrix, image upload, and add/edit drawer.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, money, qtyFmt, escapeHtml, h, toast } = window.CafeFlow.utils;
  const api = window.CafeFlow.api;
  const state = window.CafeFlow.state;
  const loadMenu = () => window.CafeFlow.menu ? window.CafeFlow.menu.loadMenu() : null;
  const pollQueue = () => window.CafeFlow.queue ? window.CafeFlow.queue.pollQueue() : null;

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

  function initProductEvents() {
    const manageSearchInp = $('#manageProductSearch');
    const manageSearchClr = $('#manageProductSearchClearBtn');
    if (manageSearchInp && manageSearchClr) {
      manageSearchInp.addEventListener('input', e => {
        const val = e.target.value.trim();
        manageSearchClr.hidden = !val;
        renderManageProducts();
      });
      manageSearchClr.addEventListener('click', () => {
        manageSearchInp.value = '';
        manageSearchClr.hidden = true;
        manageSearchInp.focus();
        renderManageProducts();
      });
    }

    const openAddBtn = $('#openAddProductBtn');
    if (openAddBtn) {
      openAddBtn.addEventListener('click', () => {
        if (window.showView) window.showView('products');
        openProductSheet('add');
      });
    }
    const addNewProdBtn = $('#addNewProductBtn');
    if (addNewProdBtn) addNewProdBtn.addEventListener('click', () => openProductSheet('add'));

    const pSheetCancel = $('#productSheetCancel') || $('#cancelProductSheet');
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
  }

  window.CafeFlow.products = {
    loadManageProducts,
    renderManageProducts,
    openProductSheet,
    closeProductSheet,
    deleteProduct,
    loadSupplyList,
    initProductEvents
  };
})();
