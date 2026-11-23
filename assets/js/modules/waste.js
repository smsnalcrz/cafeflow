/**
 * CafeFlow POS - Waste & Loss Tracking Module
 * Provides comprehensive audit of raw ingredient & packaging waste from cancelled orders,
 * income loss analytics, material breakdown, Chart.js visualizations, and granular waste logs.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, money, escapeHtml, h, toast, emptyState, pad2, toIsoDate, parseIsoDate, addDays } = window.CafeFlow.utils;
  const currentUser = (window.CafeFlow.config && window.CafeFlow.config.currentUser) || window.currentUser || { role: 'manager' };
  const api = window.CafeFlow.api;

  let _wastePeriod = 'daily'; // 'hourly' | 'daily' | 'weekly' | 'yearly'
  let _activeViewTab = 'overview'; // 'overview' | 'materials' | 'incomeloss' | 'log'

  const now = new Date();
  let _selectedDate = toIsoDate(now);
  let _selectedWeekStart = toIsoDate(addDays(now, -6));
  let _selectedYear = now.getFullYear();

  let _cachedData = null;
  let _wasteTrendChart = null;
  let _wasteMaterialChart = null;
  let _wasteProductChart = null;
  let _manualWasteOpen = false;

  function destroyWasteCharts() {
    if (_wasteTrendChart) {
      _wasteTrendChart.destroy();
      _wasteTrendChart = null;
    }
    if (_wasteMaterialChart) {
      _wasteMaterialChart.destroy();
      _wasteMaterialChart = null;
    }
    if (_wasteProductChart) {
      _wasteProductChart.destroy();
      _wasteProductChart = null;
    }
  }

  /* ------------------------------------------------------------------ *
   *  Manual Waste Entry Modal (uncontrollable waste: breakage, expiry, etc.)
   * ------------------------------------------------------------------ */
  let _inventoryCache = null;

  async function getInventoryList() {
    if (_inventoryCache && _inventoryCache.length > 0) return _inventoryCache;
    if (window.CafeFlow.state && Array.isArray(window.CafeFlow.state.inventory) && window.CafeFlow.state.inventory.length > 0) {
      _inventoryCache = window.CafeFlow.state.inventory;
      return _inventoryCache;
    }
    try {
      const res = await api('inventory_api.php?status=all');
      _inventoryCache = res.items || [];
      return _inventoryCache;
    } catch {
      return [];
    }
  }

  function estimateIngredientCost(name, unit, amount) {
    if (!amount || amount <= 0) return 0;
    const n = (name || '').toLowerCase();
    const u = (unit || '').toLowerCase();

    // Normalize bulk units to base units
    let effAmount = amount;
    let effUnit = u;
    if (u === 'kg') {
      effAmount = amount * 1000;
      effUnit = 'g';
    } else if (u === 'l' || u === 'liter' || u === 'liters') {
      effAmount = amount * 1000;
      effUnit = 'ml';
    }

    let rate = null;
    if (n.includes('matcha')) rate = 0.90;
    else if (n.includes('espresso') || n.includes('bean')) rate = (effUnit === 'ml') ? 0.50 : 0.70;
    else if (n.includes('milk') || n.includes('arla') || n.includes('emborg')) rate = 0.09;
    else if (n.includes('cream') || n.includes('whipping')) rate = 0.18;
    else if (n.includes('syrup') || n.includes('sauce') || n.includes('puree')) rate = 0.30;
    else if (n.includes('powder')) rate = 0.60;
    else if (n.includes('ice')) rate = 0.015;
    else if (n.includes('croissant') || n.includes('croffle')) rate = 45.00;
    else if (n.includes('fries')) rate = 0.16;
    else if (n.includes('bread') || n.includes('roll')) rate = 10.00;
    else if (n.includes('egg')) rate = 9.00;
    else if (n.includes('cheese')) rate = 7.00;
    else if (n.includes('ham') || n.includes('spam') || n.includes('chicken')) rate = 0.50;
    else if (n.includes('spread') || n.includes('nutella') || n.includes('biscoff')) rate = 0.60;
    else if (n.includes('tea')) rate = 0.05;
    else if (n.includes('soda')) rate = 0.04;
    else if (n.includes('juice')) rate = 0.15;
    else if (n.includes('boba') || n.includes('pearl') || n.includes('nata')) rate = 0.18;
    else if (n.includes('22oz cup')) rate = 3.80;
    else if (n.includes('16oz cup')) rate = 3.00;
    else if (n.includes('12oz') || n.includes('hot cup')) rate = 3.50;
    else if (n.includes('lid')) rate = 1.20;
    else if (n.includes('straw')) rate = 0.50;

    if (rate === null) {
      if (effUnit === 'g') rate = 0.30;
      else if (effUnit === 'ml') rate = 0.15;
      else if (effUnit === 'pcs' || effUnit === 'pc') rate = 3.00;
      else rate = 1.00;
    }
    return Math.round(effAmount * rate * 100) / 100;
  }

  function updateEstimatedCost() {
    const name = $('#manualWasteItemName')?.value?.trim();
    const unit = $('#manualWasteUnit')?.value;
    const amount = parseFloat($('#manualWasteAmount')?.value || '0');
    const costInput = $('#manualWasteCost');

    if (costInput && !costInput.dataset.manuallyEdited) {
      if (amount > 0) {
        const est = estimateIngredientCost(name, unit, amount);
        costInput.value = est > 0 ? est.toFixed(2) : '';
      } else {
        costInput.value = '';
      }
    }
  }

  function onItemNameSelected(name, items) {
    if (!name) return;
    const clean = name.trim().toLowerCase();
    const match = (items || []).find(it => it.name.toLowerCase() === clean);
    const unitSelect = $('#manualWasteUnit');
    const itemIdInput = $('#manualWasteItemId');
    const hint = $('#manualWasteStockHint');

    if (match) {
      if (itemIdInput) itemIdInput.value = match.item_id;
      if (unitSelect) {
        const u = match.unit_measure || 'pcs';
        let optExists = Array.from(unitSelect.options).some(o => o.value.toLowerCase() === u.toLowerCase());
        if (!optExists) {
          const newOpt = document.createElement('option');
          newOpt.value = u;
          newOpt.textContent = u;
          unitSelect.appendChild(newOpt);
        }
        unitSelect.value = u;
      }
      if (hint) {
        const stockNum = Number(match.current_stock);
        const threshNum = Number(match.restock_threshold || 0);
        hint.textContent = `In Stock: ${stockNum.toLocaleString()} ${match.unit_measure}`;
        hint.style.color = (stockNum <= threshNum) ? 'var(--amber, #b54516)' : 'var(--muted)';
      }
    } else {
      if (itemIdInput) itemIdInput.value = '';
      if (hint) {
        hint.textContent = 'Custom material (not tracked in live inventory stock)';
        hint.style.color = 'var(--muted)';
      }
    }
    updateEstimatedCost();
  }

  /* ------------------------------------------------------------------ *
   *  Custom Combobox State & Functions
   * ------------------------------------------------------------------ */
  let _comboboxItems = [];
  let _filteredComboboxItems = [];
  let _highlightedComboboxIndex = -1;

  function renderComboboxList(filterText = '') {
    const listEl = $('#manualWasteDropdownList');
    const countEl = $('#comboboxItemCount');
    if (!listEl) return;

    listEl.innerHTML = '';
    const cleanFilter = (filterText || '').trim().toLowerCase();

    _filteredComboboxItems = cleanFilter
      ? _comboboxItems.filter(it => (it.name || '').toLowerCase().includes(cleanFilter))
      : _comboboxItems.slice();

    if (countEl) {
      countEl.textContent = cleanFilter
        ? `${_filteredComboboxItems.length} of ${_comboboxItems.length}`
        : `${_comboboxItems.length} items`;
    }

    if (_filteredComboboxItems.length === 0) {
      const emptyDiv = document.createElement('div');
      emptyDiv.className = 'combobox-empty-item';
      emptyDiv.innerHTML = `<strong>No matching supplies found.</strong><br><span style="font-size: 11.5px; opacity: 0.85;">Click to log "${escapeHtml(cleanFilter || 'custom')}" as unlisted supply</span>`;
      emptyDiv.addEventListener('mousedown', (e) => {
        e.preventDefault();
        onItemNameSelected(cleanFilter || filterText, _comboboxItems);
        closeCombobox();
        $('#manualWasteAmount')?.focus();
      });
      listEl.appendChild(emptyDiv);
      return;
    }

    _filteredComboboxItems.forEach((it, idx) => {
      const itemEl = document.createElement('div');
      itemEl.className = 'combobox-item' + (idx === _highlightedComboboxIndex ? ' active' : '');
      itemEl.setAttribute('role', 'option');
      itemEl.dataset.index = idx;

      const stockNum = Number(it.current_stock || 0);
      const threshNum = Number(it.restock_threshold || 0);
      let stockClass = 'stock-healthy';
      if (stockNum <= 0) stockClass = 'stock-critical';
      else if (stockNum <= threshNum) stockClass = 'stock-warning';

      itemEl.innerHTML = `
        <div class="combobox-item-left">
          <span class="combobox-item-name">${escapeHtml(it.name)}</span>
          <span class="combobox-item-unit">${escapeHtml(it.unit_measure || 'pcs')}</span>
        </div>
        <span class="combobox-item-stock ${stockClass}">
          ${stockNum <= 0 ? 'Out of Stock' : `${stockNum.toLocaleString()} ${escapeHtml(it.unit_measure || '')}`}
        </span>
      `;

      itemEl.addEventListener('mousedown', (e) => {
        e.preventDefault(); // prevent blur before click
        selectComboboxItem(it);
      });

      listEl.appendChild(itemEl);
    });
  }

  function openCombobox() {
    const menu = $('#manualWasteDropdownMenu');
    const wrap = $('#manualWasteCombobox');
    if (!menu || !wrap) return;
    const filterText = $('#manualWasteItemName')?.value || '';
    renderComboboxList(filterText);
    menu.hidden = false;
    wrap.classList.add('open');
  }

  function closeCombobox() {
    const menu = $('#manualWasteDropdownMenu');
    const wrap = $('#manualWasteCombobox');
    if (menu) menu.hidden = true;
    if (wrap) wrap.classList.remove('open');
    _highlightedComboboxIndex = -1;
  }

  function isComboboxOpen() {
    const menu = $('#manualWasteDropdownMenu');
    return menu && !menu.hidden;
  }

  function selectComboboxItem(it) {
    const nameInput = $('#manualWasteItemName');
    if (nameInput) nameInput.value = it.name;
    onItemNameSelected(it.name, _comboboxItems);
    closeCombobox();
    $('#manualWasteAmount')?.focus();
  }

  function updateHighlightedComboboxItem(newIndex) {
    const listEl = $('#manualWasteDropdownList');
    if (!listEl) return;
    const items = listEl.querySelectorAll('.combobox-item');
    if (!items.length) return;

    if (newIndex < 0) newIndex = 0;
    if (newIndex >= items.length) newIndex = items.length - 1;

    _highlightedComboboxIndex = newIndex;
    items.forEach((el, idx) => {
      el.classList.toggle('active', idx === newIndex);
    });

    const activeEl = items[newIndex];
    if (activeEl) {
      activeEl.scrollIntoView({ block: 'nearest' });
    }
  }

  async function openManualWasteModal(prefillItem = null) {
    const modal = $('#manualWasteModal');
    if (!modal) return;

    modal.hidden = false;

    const form = $('#manualWasteForm');
    if (form && typeof form.reset === 'function') {
      form.reset();
    }

    const itemIdInput = $('#manualWasteItemId');
    if (itemIdInput) itemIdInput.value = '';

    const costInput = $('#manualWasteCost');
    if (costInput) delete costInput.dataset.manuallyEdited;

    const hint = $('#manualWasteStockHint');
    if (hint) { hint.textContent = ''; hint.style.color = 'var(--muted)'; }

    const msg = $('#manualWasteMessage');
    if (msg) { msg.textContent = ''; msg.hidden = true; }

    const deductCheck = $('#manualWasteDeductCheck');
    if (deductCheck) deductCheck.checked = true;

    // Load inventory into combobox items
    _comboboxItems = await getInventoryList();
    closeCombobox();

    if (prefillItem) {
      const nameInput = $('#manualWasteItemName');
      if (nameInput) nameInput.value = prefillItem.name || '';
      onItemNameSelected(prefillItem.name, _comboboxItems);
    }

    $('#manualWasteItemName')?.focus();
  }

  function closeManualWasteModal() {
    const modal = $('#manualWasteModal');
    if (modal) modal.hidden = true;
    closeCombobox();
  }

  async function submitManualWaste() {
    const itemName = $('#manualWasteItemName')?.value?.trim();
    const unit = $('#manualWasteUnit')?.value;
    const amount = $('#manualWasteAmount')?.value;
    const cost = $('#manualWasteCost')?.value;
    const reason = $('#manualWasteReason')?.value?.trim();
    const itemId = $('#manualWasteItemId')?.value;
    const deductStock = $('#manualWasteDeductCheck') ? $('#manualWasteDeductCheck').checked : true;
    const btn = $('#manualWasteSubmitBtn');
    const msg = $('#manualWasteMessage');

    if (!itemName) {
      showManualWasteMessage('Please enter or select a material or supply name.', 'error');
      $('#manualWasteItemName')?.focus();
      return;
    }
    if (!amount || parseFloat(amount) <= 0) {
      showManualWasteMessage('Please enter a valid amount wasted (greater than 0).', 'error');
      $('#manualWasteAmount')?.focus();
      return;
    }

    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Logging…';
    }
    if (msg) { msg.innerHTML = ''; msg.hidden = true; }

    try {
      const payload = {
        item_id: itemId ? parseInt(itemId, 10) : undefined,
        item_name: itemName,
        unit: unit,
        amount_wasted: parseFloat(amount),
        cost_php: cost ? parseFloat(cost) : undefined,
        deduct_inventory: deductStock,
        quantity: 1,
        reason: reason || 'Manual waste entry',
      };

      const res = await api('manual_waste.php', payload);

      closeManualWasteModal();
      toast(res.message || 'Waste entry logged successfully.', 'success', 3500);

      // Invalidate inventory cache
      _inventoryCache = null;

      // Refresh waste reports if currently active
      if (window.CafeFlow.state && window.CafeFlow.state.currentView === 'waste' && typeof loadWasteReport === 'function') {
        loadWasteReport();
      }

      // Refresh inventory table if currently active
      if (window.CafeFlow.state && window.CafeFlow.state.currentView === 'inventory' && window.CafeFlow.inventory && window.CafeFlow.inventory.loadInventory) {
        window.CafeFlow.inventory.loadInventory();
      }

      // Refresh manager dashboard cards if manager
      if (window.CafeFlow.dashboard && window.CafeFlow.dashboard.loadManagerDashboard) {
        window.CafeFlow.dashboard.loadManagerDashboard();
      }
    } catch (err) {
      showManualWasteMessage(err.message || 'Failed to record waste entry.', 'error');
    } finally {
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Log Waste Entry';
      }
    }
  }

  function showManualWasteMessage(text, type) {
    const msg = $('#manualWasteMessage');
    if (!msg) return;
    msg.hidden = false;
    msg.textContent = text;
    msg.className = 'manual-waste-inline-msg ' + (type === 'error' ? 'error' : 'success');
  }

  function getWasteApiUrl() {
    let url = `waste_api.php?period=${encodeURIComponent(_wastePeriod)}`;
    if (_wastePeriod === 'hourly' || _wastePeriod === 'daily') {
      url += `&date=${encodeURIComponent(_selectedDate)}`;
    } else if (_wastePeriod === 'weekly') {
      url += `&week=${encodeURIComponent(_selectedWeekStart)}`;
    } else if (_wastePeriod === 'yearly') {
      url += `&year=${encodeURIComponent(_selectedYear)}`;
    }
    return url;
  }

  function syncToolbarUI() {
    $$('#wastePeriodSeg button').forEach(b => {
      b.classList.toggle('on', b.dataset.wastePeriod === _wastePeriod);
    });

    const hourlyGrp = $('#wasteHourlyPickerGroup');
    if (hourlyGrp) hourlyGrp.hidden = _wastePeriod !== 'hourly';

    const dailyGrp = $('#wasteDailyPickerGroup');
    if (dailyGrp) dailyGrp.hidden = _wastePeriod !== 'daily';

    const weeklyGrp = $('#wasteWeeklyPickerGroup');
    if (weeklyGrp) weeklyGrp.hidden = _wastePeriod !== 'weekly';

    const yearlyGrp = $('#wasteYearlyPickerGroup');
    if (yearlyGrp) yearlyGrp.hidden = _wastePeriod !== 'yearly';

    const hDatePicker = $('#wasteHourlyDatePicker');
    if (hDatePicker) hDatePicker.value = _selectedDate;

    const dDatePicker = $('#wasteDailyDatePicker');
    if (dDatePicker) dDatePicker.value = _selectedDate;

    const wDatePicker = $('#wasteWeeklyDatePicker');
    if (wDatePicker) wDatePicker.value = _selectedWeekStart;

    const wBadge = $('#wasteWeeklyBadge');
    if (wBadge) {
      const s = parseIsoDate(_selectedWeekStart);
      const e = addDays(s, 6);
      wBadge.textContent = `${toIsoDate(s)} – ${toIsoDate(e)}`;
    }

    const ySelect = $('#wasteYearSelect');
    if (ySelect && ySelect.value != String(_selectedYear)) {
      ySelect.value = String(_selectedYear);
    }
  }

  async function loadWasteReport() {
    syncToolbarUI();
    const statusPeriod = $('#wasteStatusPeriod');
    if (statusPeriod) statusPeriod.textContent = 'Loading waste audit data...';

    try {
      const data = await api(getWasteApiUrl());
      _cachedData = data;
      if (statusPeriod) statusPeriod.textContent = data.label || 'Waste Report';

      // Populate year selector if available
      const yrSelect = $('#wasteYearSelect');
      if (yrSelect && data.available_years && data.available_years.length) {
        if (yrSelect.children.length !== data.available_years.length) {
          yrSelect.innerHTML = '';
          data.available_years.forEach(yr => {
            const opt = document.createElement('option');
            opt.value = String(yr);
            opt.textContent = String(yr);
            yrSelect.appendChild(opt);
          });
        }
        yrSelect.value = String(_selectedYear);
      }

      renderWasteWorkspace(data);
    } catch (e) {
      toast(e.message || 'Could not load waste report.', 'error', 4000);
      const body = $('#wasteBody');
      if (body) {
        body.innerHTML = '';
        body.append(emptyState({
          icon: '⚠️',
          title: 'Unable to Load Waste Records',
          desc: e.message || 'Server error occurred while fetching waste report.'
        }));
      }
    }
  }

  function renderWasteWorkspace(data) {
    const body = $('#wasteBody');
    if (!body) return;
    body.innerHTML = '';

    destroyWasteCharts();

    const sum = data.summary || {};

    // 1. KPI Cards Row (Always visible for fast executive scan)
    const kpiGrid = h('div', { class: 'waste-kpi-grid' },
      h('div', { class: 'waste-kpi-card loss-hero' },
        h('span', { class: 'waste-kpi-label', text: 'Estimated Income Loss' }),
        h('span', { class: 'waste-kpi-val', text: money(sum.total_income_loss_php || 0) }),
        h('span', { class: 'waste-kpi-sub', text: `${sum.total_cancelled_orders || 0} cancelled customer order${sum.total_cancelled_orders === 1 ? '' : 's'}` })
      ),
      h('div', { class: 'waste-kpi-card cost-hero' },
        h('span', { class: 'waste-kpi-label', text: 'Raw Material Cost Loss' }),
        h('span', { class: 'waste-kpi-val', text: money(sum.total_material_cost_php || 0) }),
        h('span', { class: 'waste-kpi-sub', text: 'Estimated ingredient & packaging cost' })
      ),
      h('div', { class: 'waste-kpi-card' },
        h('span', { class: 'waste-kpi-label', text: 'Total Waste Events' }),
        h('span', { class: 'waste-kpi-val', text: String(sum.total_waste_events || 0) }),
        h('span', { class: 'waste-kpi-sub', text: `${(data.materials_summary || []).length} distinct raw supplies wasted` })
      ),
      h('div', { class: 'waste-kpi-card' },
        h('span', { class: 'waste-kpi-label', text: 'Physical Waste Volume' }),
        h('span', { class: 'waste-kpi-val', style: 'font-size: 18px;', text: `${(sum.total_wasted_kg || 0)} kg / ${(sum.total_wasted_l || 0)} L` }),
        h('span', { class: 'waste-kpi-sub', text: `${Math.round(sum.total_wasted_pcs || 0)} cups, lids & straws` })
      )
    );
    body.append(kpiGrid);

    // 2. View Tab Content Containers
    const overviewContainer = h('div', { class: 'waste-view-panel', id: 'wastePanelOverview' });
    const materialsContainer = h('div', { class: 'waste-view-panel', id: 'wastePanelMaterials' });
    const incomelossContainer = h('div', { class: 'waste-view-panel', id: 'wastePanelIncomeLoss' });
    const logContainer = h('div', { class: 'waste-view-panel', id: 'wastePanelLog' });

    overviewContainer.hidden   = _activeViewTab !== 'overview';
    materialsContainer.hidden  = _activeViewTab !== 'materials';
    incomelossContainer.hidden = _activeViewTab !== 'incomeloss';
    logContainer.hidden        = _activeViewTab !== 'log';

    body.append(overviewContainer, materialsContainer, incomelossContainer, logContainer);

    renderOverviewView(overviewContainer, data);
    renderMaterialsView(materialsContainer, data);
    renderIncomeLossView(incomelossContainer, data);
    renderLogView(logContainer, data);

    $$('#wasteViewTabs .waste-tab').forEach(t => {
      t.classList.toggle('active', t.dataset.viewTab === _activeViewTab);
    });
  }

  /* ------------------------------------------------------------------ *
   *  Overview Tab: Visual Charts & Quick Summary
   * ------------------------------------------------------------------ */
  function renderOverviewView(container, data) {
    const hasEvents = (data.summary && data.summary.total_waste_events > 0);

    if (!hasEvents) {
      container.append(emptyState({
        icon: '🍃',
        title: 'Zero Waste Recorded',
        desc: `No customer order cancellations or ingredient waste logged for this ${_wastePeriod} timeframe.`,
        tip: 'All orders in this interval either completed cleanly or no sales occurred.'
      }));
      return;
    }

    const chartsGrid = h('div', { class: 'waste-charts-grid' });

    // Chart 1: Trend over Time
    const trendCard = h('div', { class: 'waste-chart-card' },
      h('div', { class: 'waste-chart-head' },
        h('span', { class: 'waste-chart-title', text: 'Waste Impact & Income Loss Over Time' }),
        h('span', { class: 'waste-chart-badge', text: _wastePeriod.toUpperCase() })
      ),
      h('div', { class: 'waste-chart-wrap' },
        h('canvas', { id: 'wasteTrendCanvas', 'aria-label': 'Waste trend chart' })
      )
    );

    // Chart 2: Top Wasted Raw Materials
    const materialCard = h('div', { class: 'waste-chart-card' },
      h('div', { class: 'waste-chart-head' },
        h('span', { class: 'waste-chart-title', text: 'Top Wasted Supplies (Estimated Cost ₱)' }),
        h('span', { class: 'waste-chart-badge', text: 'MATERIALS' })
      ),
      h('div', { class: 'waste-chart-wrap' },
        h('canvas', { id: 'wasteMaterialCanvas', 'aria-label': 'Top wasted materials chart' })
      )
    );

    chartsGrid.append(trendCard, materialCard);
    container.append(chartsGrid);

    // Deferred Chart.js rendering
    requestAnimationFrame(() => {
      renderTrendChart($('#wasteTrendCanvas'), data.charts.trend);
      renderMaterialBarChart($('#wasteMaterialCanvas'), data.charts.top_materials);
    });
  }

  function renderTrendChart(canvas, trendData) {
    if (!canvas || !trendData || typeof Chart === 'undefined') return;
    const ctx = canvas.getContext('2d');

    _wasteTrendChart = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: trendData.labels || [],
        datasets: [
          {
            label: 'Income Loss (₱)',
            data: trendData.income_losses || [],
            backgroundColor: 'rgba(184, 50, 38, 0.75)',
            borderColor: '#b83226',
            borderWidth: 1.5,
            borderRadius: 4,
            yAxisID: 'y'
          },
          {
            label: 'Material Cost Loss (₱)',
            data: trendData.material_costs || [],
            backgroundColor: 'rgba(232, 98, 58, 0.65)',
            borderColor: '#e8623a',
            borderWidth: 1.5,
            borderRadius: 4,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Waste Events',
            data: trendData.waste_events || [],
            borderColor: '#162318',
            backgroundColor: '#162318',
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 5,
            tension: 0.25,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, family: 'system-ui' } } },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const label = ctx.dataset.label || '';
                if (label.includes('₱')) {
                  return `${label}: ₱${Number(ctx.parsed.y).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
                }
                return `${label}: ${ctx.parsed.y} event${ctx.parsed.y === 1 ? '' : 's'}`;
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false } },
          y: {
            type: 'linear',
            position: 'left',
            beginAtZero: true,
            ticks: {
              callback: (v) => '₱' + v
            }
          },
          y1: {
            type: 'linear',
            position: 'right',
            beginAtZero: true,
            grid: { drawOnChartArea: false },
            ticks: { precision: 0 }
          }
        }
      }
    });
  }

  function renderMaterialBarChart(canvas, matData) {
    if (!canvas || !matData || typeof Chart === 'undefined') return;
    const ctx = canvas.getContext('2d');

    const labels = matData.labels || [];
    const costs = matData.costs || [];

    if (!labels.length) return;

    _wasteMaterialChart = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: costs,
          backgroundColor: [
            '#e8623a', '#2a9b52', '#1a5ecb', '#b83226',
            '#f07348', '#52C96E', '#8b5cf6', '#d97706'
          ],
          borderWidth: 1.5,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'right', labels: { boxWidth: 10, font: { size: 11 } } },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const val = Number(ctx.parsed);
                return ` ${ctx.label}: ₱${val.toFixed(2)}`;
              }
            }
          }
        }
      }
    });
  }

  /* ------------------------------------------------------------------ *
   *  Materials Summary View (Grouped by Raw Inventory Supply Item)
   * ------------------------------------------------------------------ */
  function renderMaterialsView(container, data) {
    const items = data.materials_summary || [];

    const card = h('div', { class: 'waste-table-card' });
    const headerRow = h('div', { class: 'waste-table-header-row' },
      h('div', { class: 'waste-table-title-group' },
        h('h3', { text: `Raw Supplies & Packaging Waste Summary (${items.length} item${items.length === 1 ? '' : 's'})` }),
        h('span', { class: 'waste-table-subtitle', text: 'Aggregated consumption quantity and estimated financial cost per raw supply' })
      ),
      h('div', { class: 'waste-table-search-box' },
        h('span', { text: '🔍' }),
        h('input', {
          type: 'search',
          placeholder: 'Search raw materials...',
          oninput: (e) => filterTable(tbody, e.target.value)
        })
      )
    );
    card.append(headerRow);

    if (!items.length) {
      card.append(emptyState({
        icon: '📦',
        title: 'No Material Waste Logged',
        desc: 'Zero raw ingredient waste events have been recorded for this period.'
      }));
      container.append(card);
      return;
    }

    const tableWrap = h('div', { class: 'waste-table-container' });
    const tbody = h('tbody');
    const table = h('table', { class: 'waste-table' },
      h('thead', {}, h('tr', {},
        h('th', { text: 'Supply Item' }),
        h('th', { text: 'Unit' }),
        h('th', { class: 'num', text: 'Total Wasted' }),
        h('th', { class: 'num', text: 'Waste Events' }),
        h('th', { class: 'num', text: 'Cancelled Orders' }),
        h('th', { class: 'num', text: 'Estimated Cost (₱)' })
      )),
      tbody
    );

    items.forEach(it => {
      const tr = h('tr', {},
        h('td', { style: 'font-weight: 600;', text: it.item_name }),
        h('td', {}, h('span', { class: 'waste-pill unit', text: it.unit })),
        h('td', { class: 'num', style: 'font-weight: 700;', text: it.formatted_amount }),
        h('td', { class: 'num', text: String(it.events_count) }),
        h('td', { class: 'num', text: String(it.orders_count) }),
        h('td', { class: 'num', style: 'font-weight: 700; color: #b54516;', text: it.formatted_cost })
      );
      tbody.append(tr);
    });

    tableWrap.append(table);
    card.append(tableWrap);
    container.append(card);
  }

  /* ------------------------------------------------------------------ *
   *  Income Loss Report View (Broken down by Period & Cancelled Product)
   * ------------------------------------------------------------------ */
  function renderIncomeLossView(container, data) {
    const products = data.income_loss_by_product || [];
    const sum = data.summary || {};

    const card = h('div', { class: 'waste-table-card' });
    const headerRow = h('div', { class: 'waste-table-header-row' },
      h('div', { class: 'waste-table-title-group' },
        h('h3', { text: `Sales Income Loss from Cancelled Orders (${money(sum.total_income_loss_php || 0)})` }),
        h('span', { class: 'waste-table-subtitle', text: 'Potential revenue lost grouped by menu product catalog item' })
      ),
      h('div', { class: 'waste-table-search-box' },
        h('span', { text: '🔍' }),
        h('input', {
          type: 'search',
          placeholder: 'Search products...',
          oninput: (e) => filterTable(tbody, e.target.value)
        })
      )
    );
    card.append(headerRow);

    if (!products.length) {
      card.append(emptyState({
        icon: '💵',
        title: 'Zero Sales Income Loss',
        desc: 'No cancelled customer sales tickets were recorded during this period.'
      }));
      container.append(card);
      return;
    }

    const tableWrap = h('div', { class: 'waste-table-container' });
    const tbody = h('tbody');
    const table = h('table', { class: 'waste-table' },
      h('thead', {}, h('tr', {},
        h('th', { text: 'Product Name' }),
        h('th', { text: 'Category' }),
        h('th', { class: 'num', text: 'Cancelled Units' }),
        h('th', { class: 'num', text: 'Affected Orders' }),
        h('th', { class: 'num', text: 'Lost Sales Revenue (₱)' })
      )),
      tbody
    );

    products.forEach(p => {
      const tr = h('tr', {},
        h('td', { style: 'font-weight: 600;', text: p.product_name }),
        h('td', {}, h('span', { class: 'waste-pill unit', text: p.category })),
        h('td', { class: 'num', style: 'font-weight: 700;', text: `${p.qty_cancelled} pcs` }),
        h('td', { class: 'num', text: String(p.orders_count) }),
        h('td', { class: 'num', style: 'font-weight: 700; color: var(--red);', text: p.formatted_loss })
      );
      tbody.append(tr);
    });

    tableWrap.append(table);
    card.append(tableWrap);
    container.append(card);
  }

  /* ------------------------------------------------------------------ *
   *  Waste Log Table View (Granular Individual Waste Event Records)
   * ------------------------------------------------------------------ */
  function renderLogView(container, data) {
    const logs = data.logs || [];

    const card = h('div', { class: 'waste-table-card' });
    const headerRow = h('div', { class: 'waste-table-header-row' },
      h('div', { class: 'waste-table-title-group' },
        h('h3', { text: `Waste Event Audit Log (${logs.length} logged record${logs.length === 1 ? '' : 's'})` }),
        h('span', { class: 'waste-table-subtitle', text: 'Real-time granular audit trail of all cancelled orders, raw supplies, and responsible staff' })
      ),
      h('div', { class: 'waste-table-search-box' },
        h('span', { text: '🔍' }),
        h('input', {
          type: 'search',
          placeholder: 'Search receipt, supply, product, staff...',
          oninput: (e) => filterTable(tbody, e.target.value)
        })
      )
    );
    card.append(headerRow);

    if (!logs.length) {
      card.append(emptyState({
        icon: '📋',
        title: 'No Waste Logs Recorded',
        desc: 'No cancellation waste events were recorded for the selected timeframe.'
      }));
      container.append(card);
      return;
    }

    const tableWrap = h('div', { class: 'waste-table-container' });
    const tbody = h('tbody');
    const table = h('table', { class: 'waste-table' },
      h('thead', {}, h('tr', {},
        h('th', { text: 'Type / Order #' }),
        h('th', { text: 'Timestamp' }),
        h('th', { text: 'Menu Product' }),
        h('th', { text: 'Size' }),
        h('th', { class: 'num', text: 'Qty' }),
        h('th', { text: 'Supply Wasted' }),
        h('th', { class: 'num', text: 'Amount' }),
        h('th', { class: 'num', text: 'Cost (₱)' }),
        h('th', { text: 'Staff' }),
        h('th', { text: 'Reason / Details' })
      )),
      tbody
    );

    logs.forEach(log => {
      const isManual = log.is_manual || log.receipt_no === 'Manual' || !log.transaction_id;
      const receiptCell = isManual
        ? h('span', { class: 'waste-pill', style: 'background: var(--parchment-mid); color: var(--muted); font-weight: 700;', text: 'Manual Waste' })
        : h('span', { style: 'font-family: monospace; font-weight: 700; color: var(--ink);', text: log.receipt_no });

      const tr = h('tr', {},
        h('td', {}, receiptCell),
        h('td', { style: 'white-space: nowrap;', text: log.formatted_datetime }),
        h('td', { style: 'font-weight: 600;', text: log.product_name }),
        h('td', {}, h('span', { class: 'waste-pill unit', text: log.size_label || '—' })),
        h('td', { class: 'num', text: String(log.quantity) }),
        h('td', { style: 'font-weight: 600;', text: log.item_name }),
        h('td', { class: 'num', style: 'font-weight: 700;', text: log.formatted_amount }),
        h('td', { class: 'num', style: 'font-weight: 700; color: #b54516;', text: log.formatted_cost }),
        h('td', {}, h('span', { class: 'waste-pill role', text: `${log.wasted_by_name} (${log.wasted_by_role})` })),
        h('td', { style: 'max-width: 220px; font-style: italic; color: var(--muted);', text: log.reason })
      );
      tbody.append(tr);
    });

    tableWrap.append(table);
    card.append(tableWrap);
    container.append(card);
  }

  function filterTable(tbody, query) {
    const q = query.trim().toLowerCase();
    Array.from(tbody.querySelectorAll('tr')).forEach(row => {
      if (!q) {
        row.hidden = false;
        return;
      }
      const text = row.textContent.toLowerCase();
      row.hidden = !text.includes(q);
    });
  }

  function stepPeriod(delta) {
    if (_wastePeriod === 'yearly') {
      _selectedYear += delta;
    } else if (_wastePeriod === 'weekly') {
      _selectedWeekStart = toIsoDate(addDays(parseIsoDate(_selectedWeekStart), delta * 7));
    } else {
      _selectedDate = toIsoDate(addDays(parseIsoDate(_selectedDate), delta));
    }
    loadWasteReport();
  }

  function initWasteEvents() {
    // Period selector toggles (Hourly, Daily, Weekly, Yearly)
    $$('#wastePeriodSeg button').forEach(btn => {
      btn.addEventListener('click', () => {
        _wastePeriod = btn.dataset.wastePeriod || 'daily';
        loadWasteReport();
      });
    });

    // View tabs (Overview, Materials, Income Loss, Log)
    $$('#wasteViewTabs .waste-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        _activeViewTab = tab.dataset.viewTab || 'overview';
        $$('#wasteViewTabs .waste-tab').forEach(t => t.classList.toggle('active', t === tab));
        const overviewEl = $('#wastePanelOverview');
        const materialsEl = $('#wastePanelMaterials');
        const lossEl = $('#wastePanelIncomeLoss');
        const logEl = $('#wastePanelLog');

        if (overviewEl) overviewEl.hidden = _activeViewTab !== 'overview';
        if (materialsEl) materialsEl.hidden = _activeViewTab !== 'materials';
        if (lossEl) lossEl.hidden = _activeViewTab !== 'incomeloss';
        if (logEl) logEl.hidden = _activeViewTab !== 'log';
      });
    });

    // Step period buttons
    const prevBtn = $('#wastePrevPeriodBtn');
    if (prevBtn) prevBtn.addEventListener('click', () => stepPeriod(-1));
    const nextBtn = $('#wasteNextPeriodBtn');
    if (nextBtn) nextBtn.addEventListener('click', () => stepPeriod(1));

    // Date Pickers
    const hDatePicker = $('#wasteHourlyDatePicker');
    if (hDatePicker) hDatePicker.addEventListener('change', (e) => {
      _selectedDate = e.target.value;
      loadWasteReport();
    });

    const dDatePicker = $('#wasteDailyDatePicker');
    if (dDatePicker) dDatePicker.addEventListener('change', (e) => {
      _selectedDate = e.target.value;
      loadWasteReport();
    });

    const wDatePicker = $('#wasteWeeklyDatePicker');
    if (wDatePicker) wDatePicker.addEventListener('change', (e) => {
      _selectedWeekStart = e.target.value;
      loadWasteReport();
    });

    const ySelect = $('#wasteYearSelect');
    if (ySelect) ySelect.addEventListener('change', (e) => {
      _selectedYear = Number(e.target.value);
      loadWasteReport();
    });

    // Jump Buttons
    const jumpHourlyToday = $('#wasteJumpHourlyTodayBtn');
    if (jumpHourlyToday) jumpHourlyToday.addEventListener('click', () => {
      _selectedDate = toIsoDate(new Date());
      loadWasteReport();
    });

    const jumpDailyToday = $('#wasteJumpDailyTodayBtn');
    if (jumpDailyToday) jumpDailyToday.addEventListener('click', () => {
      _selectedDate = toIsoDate(new Date());
      loadWasteReport();
    });

    const jumpThisWeek = $('#wasteJumpThisWeekBtn');
    if (jumpThisWeek) jumpThisWeek.addEventListener('click', () => {
      _selectedWeekStart = toIsoDate(addDays(new Date(), -6));
      loadWasteReport();
    });

    const jumpThisYear = $('#wasteJumpThisYearBtn');
    if (jumpThisYear) jumpThisYear.addEventListener('click', () => {
      _selectedYear = (new Date()).getFullYear();
      loadWasteReport();
    });

    // Refresh Button
    const refreshBtn = $('#wasteRefreshBtn');
    if (refreshBtn) refreshBtn.addEventListener('click', () => loadWasteReport());

    // Manual Waste Entry Open Buttons (Waste Reports modal, Inventory modal, Manager card)
    const manualEntryBtn = $('#wasteManualEntryBtn');
    if (manualEntryBtn) {
      manualEntryBtn.addEventListener('click', () => openManualWasteModal());
    }

    const invLogWasteBtn = $('#invLogWasteBtn');
    if (invLogWasteBtn) {
      invLogWasteBtn.addEventListener('click', () => openManualWasteModal());
    }

    const mgrLogWasteQuickBtn = $('#mgrLogWasteQuickBtn');
    if (mgrLogWasteQuickBtn) {
      mgrLogWasteQuickBtn.addEventListener('click', () => openManualWasteModal());
    }

    // Close buttons for Manual Waste Modal
    const closeBtn = $('#closeManualWasteBtn');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => closeManualWasteModal());
    }

    const cancelBtn = $('#manualWasteCancelBtn');
    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => closeManualWasteModal());
    }

    const wasteModal = $('#manualWasteModal');
    if (wasteModal) {
      wasteModal.addEventListener('click', (e) => {
        if (e.target === wasteModal) closeManualWasteModal();
      });
    }

    // Item selection and combobox events
    const itemNameInput = $('#manualWasteItemName');
    const comboboxToggle = $('#manualWasteComboboxToggle');

    if (itemNameInput) {
      itemNameInput.addEventListener('input', () => {
        openCombobox();
        onItemNameSelected(itemNameInput.value, _comboboxItems);
      });

      itemNameInput.addEventListener('focus', () => {
        openCombobox();
      });

      itemNameInput.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (!isComboboxOpen()) {
            openCombobox();
          } else {
            updateHighlightedComboboxItem(_highlightedComboboxIndex + 1);
          }
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (isComboboxOpen()) {
            updateHighlightedComboboxItem(_highlightedComboboxIndex - 1);
          }
        } else if (e.key === 'Enter') {
          if (isComboboxOpen() && _highlightedComboboxIndex >= 0 && _filteredComboboxItems[_highlightedComboboxIndex]) {
            e.preventDefault();
            selectComboboxItem(_filteredComboboxItems[_highlightedComboboxIndex]);
          }
        } else if (e.key === 'Escape' || e.key === 'Tab') {
          if (isComboboxOpen()) {
            if (e.key === 'Escape') {
              e.preventDefault();
              e.stopPropagation();
            }
            closeCombobox();
          }
        }
      });
    }

    if (comboboxToggle) {
      comboboxToggle.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (isComboboxOpen()) {
          closeCombobox();
        } else {
          openCombobox();
          itemNameInput?.focus();
        }
      });
    }

    // Dismiss combobox on outside click
    document.addEventListener('click', (e) => {
      const combobox = $('#manualWasteCombobox');
      if (combobox && !combobox.contains(e.target)) {
        closeCombobox();
      }
    });

    const amountInput = $('#manualWasteAmount');
    if (amountInput) {
      amountInput.addEventListener('input', () => updateEstimatedCost());
    }

    const unitSelect = $('#manualWasteUnit');
    if (unitSelect) {
      unitSelect.addEventListener('change', () => updateEstimatedCost());
    }

    const costInput = $('#manualWasteCost');
    if (costInput) {
      costInput.addEventListener('input', () => {
        if (costInput.value.trim() !== '') {
          costInput.dataset.manuallyEdited = 'true';
        } else {
          delete costInput.dataset.manuallyEdited;
          updateEstimatedCost();
        }
      });
    }

    // Manual Waste Submit
    const manualSubmitBtn = $('#manualWasteSubmitBtn');
    if (manualSubmitBtn) {
      manualSubmitBtn.addEventListener('click', submitManualWaste);
    }
  }

  window.CafeFlow.waste = {
    loadWasteReport,
    initWasteEvents,
    openManualWasteModal,
    closeManualWasteModal
  };
})();
