/**
 * CafeFlow POS - Raw Supplies Inventory Module
 * Handles inventory catalog table, low/out-of-stock badges, search filtering,
 * restock actions, add/edit ingredient drawer, and ingredient deletion.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, qtyFmt, h, toast, emptyState } = window.CafeFlow.utils;
  const api = window.CafeFlow.api;
  const state = window.CafeFlow.state;

  let sheet = { mode: null, item: null };

  const parseNum = text => {
    const s = String(text).trim().replace(/,/g, '');
    const n = Number(s);
    return s !== '' && Number.isFinite(n) ? n : null;
  };

  async function loadInventory() {
    try {
      const res = await api('inventory_api.php');
      state.inventory = res.items || [];
      renderInventory();
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function renderInventory() {
    const body = $('#invBody');
    if (!body) return;
    body.textContent = '';

    const searchInput = $('#inventorySearch');
    const clearBtn = $('#inventorySearchClearBtn');
    if (searchInput && searchInput.value !== state.inventorySearch) {
      searchInput.value = state.inventorySearch;
    }
    if (clearBtn) {
      clearBtn.hidden = !state.inventorySearch.trim();
    }

    if (!state.inventory.length) {
      body.append(h('tr', {},
        h('td', { colspan: '5' },
          emptyState({
            icon: '📦',
            title: 'No Raw Supplies Registered',
            desc: 'Track ingredients, cups, lids, and packaging to enable automatic recipe deductions.',
            tip: '💡 Tip: Set restock thresholds to trigger low-stock alerts automatically.',
            actionText: '+ Add First Supply Item',
            onAction: () => openSheet('add')
          })
        )
      ));
      return;
    }

    const q = state.inventorySearch.trim().toLowerCase();
    const filtered = !q ? state.inventory : state.inventory.filter(it => {
      const name = (it.name || '').toLowerCase();
      const unit = (it.unit_measure || '').toLowerCase();
      const st = it.current_stock <= 0 ? 'out' : it.low ? 'low' : 'ok';
      const statusLabel = { out: 'out of stock out', low: 'low stock low alert', ok: 'ok in stock good available' }[st] || '';
      return name.includes(q) || unit.includes(q) || statusLabel.includes(q);
    });

    if (!filtered.length) {
      body.append(h('tr', {},
        h('td', { colspan: '5' },
          emptyState({
            icon: '🔍',
            title: `No supplies match "${state.inventorySearch}"`,
            desc: 'Check your spelling or search by unit (e.g. g, ml, pcs) or status (low, out, ok).',
            actionText: '✕ Clear Search Filter',
            onAction: () => {
              state.inventorySearch = '';
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
      const st = it.current_stock <= 0 ? 'out' : it.low ? 'low' : 'ok';
      const label = { out: 'Out', low: 'Low', ok: 'OK' }[st];
      body.append(h('tr', {},
        h('td', { class: 'name', text: it.name }),
        h('td', { class: 'num', text: qtyFmt(it.current_stock) + ' ' + it.unit_measure }),
        h('td', { class: 'num', text: qtyFmt(it.restock_threshold) + ' ' + it.unit_measure }),
        h('td', {}, h('span', { class: 'tag ' + st, text: label })),
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
      if (window.CafeFlow.products && window.CafeFlow.products.loadSupplyList) {
        await window.CafeFlow.products.loadSupplyList();
      }
      if (window.CafeFlow.menu && window.CafeFlow.menu.loadMenu) {
        window.CafeFlow.menu.loadMenu();
      }
      if (window.CafeFlow.queue && window.CafeFlow.queue.pollQueue) {
        window.CafeFlow.queue.pollQueue();
      }
    } catch (e) {
      toast(e.message, 'error', 4000);
    }
  }

  function openSheet(mode, item = null) {
    sheet = { mode, item };
    const restock = mode === 'restock';
    const titleEl = $('#sheetTitle');
    if (titleEl) {
      titleEl.textContent = mode === 'add' ? 'Add inventory item'
                          : restock ? 'Restock ' + item.name
                          : 'Edit ' + item.name;
    }

    const visible = { name: !restock, unit: !restock, stock: true, threshold: !restock };
    $$('#itemForm [data-f]').forEach(label => {
      label.hidden = !visible[label.dataset.f];
    });

    const stockLabel = $('#fStockLabel');
    if (stockLabel) {
      stockLabel.textContent = restock ? 'Quantity received' : mode === 'add' ? 'Starting stock' : 'Current stock (after a count)';
    }

    const fName = $('#fName');
    if (fName) fName.value = item ? item.name : '';
    const fUnit = $('#fUnit');
    if (fUnit) fUnit.value = item ? item.unit_measure : '';
    const fStock = $('#fStock');
    if (fStock) fStock.value = !item || restock ? '' : item.current_stock;
    const fThreshold = $('#fThreshold');
    if (fThreshold) fThreshold.value = item ? item.restock_threshold : '';

    const err = $('#sheetError');
    if (err) err.hidden = true;
    const ish = $('#itemSheet');
    if (ish) ish.hidden = false;

    (restock ? fStock : fName)?.focus();
  }

  function closeSheet() {
    const ish = $('#itemSheet');
    if (ish) ish.hidden = true;
  }

  async function submitSheet(e) {
    e.preventDefault();
    const err = $('#sheetError');
    const show = msg => {
      if (err) {
        err.textContent = msg;
        err.hidden = false;
      }
    };

    const { mode, item } = sheet;
    const stock = parseNum($('#fStock')?.value);
    let payload;

    if (mode === 'restock') {
      if (stock === null || stock <= 0) return show('Enter the quantity received (more than 0).');
      payload = { action: 'restock', item_id: item.item_id, amount: stock };
    } else {
      const name = $('#fName')?.value.trim();
      const unit = $('#fUnit')?.value.trim();
      const threshold = parseNum($('#fThreshold')?.value);
      if (!name) return show('Name is required.');
      if (!unit) return show('Unit is required (for example g, ml or pcs).');
      if (stock === null || stock < 0) return show('Stock must be a number of 0 or more.');
      if (threshold === null || threshold < 0) return show('Restock alert must be a number of 0 or more.');
      payload = { action: mode === 'add' ? 'add' : 'update', name, unit, stock, threshold };
      if (mode === 'edit') payload.item_id = item.item_id;
    }

    const saveBtn = $('#itemForm button[type="submit"]');
    if (saveBtn) saveBtn.disabled = true;

    try {
      await api('inventory_api.php', payload);
      closeSheet();
      toast('Inventory updated');
      await loadInventory();
      if (window.CafeFlow.menu && window.CafeFlow.menu.loadMenu) {
        window.CafeFlow.menu.loadMenu();
      }
      if (window.CafeFlow.queue && window.CafeFlow.queue.pollQueue) {
        window.CafeFlow.queue.pollQueue();
      }
    } catch (ex) {
      show(ex.message);
    } finally {
      if (saveBtn) saveBtn.disabled = false;
    }
  }

  function initInventoryEvents() {
    const addBtn = $('#addNewSupplyBtn') || $('#addItem');
    if (addBtn) addBtn.addEventListener('click', () => openSheet('add'));

    const closeBtn = $('#sheetCancel') || $('#closeSheet');
    if (closeBtn) closeBtn.addEventListener('click', closeSheet);

    const sheet = $('#itemSheet') || $('#itemSheetBackdrop');
    if (sheet) {
      sheet.addEventListener('click', (e) => {
        if (e.target === sheet) closeSheet();
      });
    }

    const form = $('#itemForm');
    if (form) form.addEventListener('submit', submitSheet);

    const searchInput = $('#inventorySearch');
    const clearBtn = $('#inventorySearchClearBtn');
    if (searchInput && clearBtn) {
      searchInput.addEventListener('input', e => {
        state.inventorySearch = e.target.value.trim().toLowerCase();
        clearBtn.hidden = !state.inventorySearch;
        renderInventory();
      });
      clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        state.inventorySearch = '';
        clearBtn.hidden = true;
        searchInput.focus();
        renderInventory();
      });
    }
  }

  window.CafeFlow.inventory = {
    loadInventory,
    renderInventory,
    openSheet,
    closeSheet,
    deleteInventoryItem,
    submitSheet,
    initInventoryEvents
  };
})();
