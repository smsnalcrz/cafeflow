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
   *  Manual Waste Entry Form (uncontrollable waste: breakage, expiry, etc.)
   * ------------------------------------------------------------------ */
  function toggleManualWasteForm(show) {
    const section = $('#manualWasteSection');
    const btn = $('#wasteManualEntryBtn');
    const form = $('#manualWasteForm');
    const msg = $('#manualWasteMessage');

    // Reports/dashboard sections to hide when manual waste form is open
    const periodSeg = $('#wastePeriodSeg');
    const toolbar = $('#wasteToolbar');
    const viewTabs = $('#wasteViewTabs');
    const body = $('#wasteBody');

    if (show) {
      if (section) {
        section.hidden = false;
        section.style.display = '';
      }
      if (btn) btn.classList.add('active');
      if (form) form.reset();
      if (msg) { msg.innerHTML = ''; msg.hidden = true; }

      // Hide reports UI when manual waste form is open (clean look)
      if (periodSeg) { periodSeg.hidden = true; periodSeg.style.display = 'none'; }
      if (toolbar) { toolbar.hidden = true; toolbar.style.display = 'none'; }
      if (viewTabs) { viewTabs.hidden = true; viewTabs.style.display = 'none'; }
      if (body) { body.hidden = true; body.style.display = 'none'; }

      _manualWasteOpen = true;
    } else {
      if (section) {
        section.hidden = true;
        section.style.display = 'none';
      }
      if (btn) btn.classList.remove('active');
      if (msg) { msg.innerHTML = ''; msg.hidden = true; }

      // Restore reports UI when manual waste form is closed
      if (periodSeg) { periodSeg.hidden = false; periodSeg.style.display = ''; }
      if (toolbar) { toolbar.hidden = false; toolbar.style.display = ''; }
      if (viewTabs) { viewTabs.hidden = false; viewTabs.style.display = ''; }
      if (body) { body.hidden = false; body.style.display = ''; }

      _manualWasteOpen = false;
    }
  }

  async function submitManualWaste() {
    const itemName = $('#manualWasteItemName')?.value?.trim();
    const unit = $('#manualWasteUnit')?.value;
    const amount = $('#manualWasteAmount')?.value;
    const cost = $('#manualWasteCost')?.value;
    const reason = $('#manualWasteReason')?.value?.trim();
    const msg = $('#manualWasteMessage');
    const btn = $('#manualWasteSubmitBtn');

    if (!itemName) {
      showManualWasteMessage('Please enter the material or supply name.', 'error');
      $('#manualWasteItemName')?.focus();
      return;
    }
    if (!amount || parseFloat(amount) <= 0) {
      showManualWasteMessage('Please enter a valid amount wasted (greater than 0).', 'error');
      $('#manualWasteAmount')?.focus();
      return;
    }
    if (!cost || parseFloat(cost) <= 0) {
      showManualWasteMessage('Please enter a valid cost (greater than 0).', 'error');
      $('#manualWasteCost')?.focus();
      return;
    }

    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Logging…';
    }
    if (msg) { msg.innerHTML = ''; msg.hidden = true; }

    try {
      const res = await api('manual_waste.php', {
        item_name: itemName,
        unit: unit,
        amount_wasted: parseFloat(amount),
        cost_php: parseFloat(cost),
        quantity: 1,
        reason: reason || 'Manual waste entry',
      });

      showManualWasteMessage(res.message || 'Waste entry recorded.', 'success');
      toggleManualWasteForm(false);

      // Refresh waste report data to reflect the new entry
      loadWasteReport();

      // Also refresh dashboard if it's the current view
      if (window.CafeFlow.dashboard && window.CafeFlow.dashboard.loadManagerDashboard) {
        // dashboard will refresh on next view activation
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
    msg.className = 'manual-waste-message ' + (type === 'error' ? 'error' : 'success');
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
        h('th', { text: 'Receipt #' }),
        h('th', { text: 'Timestamp' }),
        h('th', { text: 'Product' }),
        h('th', { text: 'Size' }),
        h('th', { class: 'num', text: 'Qty' }),
        h('th', { text: 'Supply Wasted' }),
        h('th', { class: 'num', text: 'Amount' }),
        h('th', { class: 'num', text: 'Cost (₱)' }),
        h('th', { text: 'Cancelled By' }),
        h('th', { text: 'Reason' })
      )),
      tbody
    );

    logs.forEach(log => {
      const tr = h('tr', {},
        h('td', { style: 'font-family: monospace; font-weight: 700;', text: log.receipt_no }),
        h('td', { style: 'white-space: nowrap;', text: log.formatted_datetime }),
        h('td', { style: 'font-weight: 600;', text: log.product_name }),
        h('td', {}, h('span', { class: 'waste-pill unit', text: log.size_label || 'Standard' })),
        h('td', { class: 'num', text: String(log.quantity) }),
        h('td', { text: log.item_name }),
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

    // Manual Waste Entry Button
    const manualEntryBtn = $('#wasteManualEntryBtn');
    if (manualEntryBtn) {
      manualEntryBtn.addEventListener('click', () => {
        toggleManualWasteForm(!_manualWasteOpen);
      });
    }

    // Manual Waste Submit
    const manualSubmitBtn = $('#manualWasteSubmitBtn');
    if (manualSubmitBtn) {
      manualSubmitBtn.addEventListener('click', submitManualWaste);
    }

    // Manual Waste Cancel
    const manualCancelBtn = $('#manualWasteCancelBtn');
    if (manualCancelBtn) {
      manualCancelBtn.addEventListener('click', () => toggleManualWasteForm(false));
    }
  }

  window.CafeFlow.waste = {
    loadWasteReport,
    initWasteEvents
  };
})();
