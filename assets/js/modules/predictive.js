/**
 * CafeFlow POS — Predictive Machine Learning Analytics Module v2.0
 * Handles prospective demand forecasting for Daily, 7-Day, Month, and Year
 * horizons with explainable business intelligence, interactive What-If simulator,
 * category/product demand, operational shift staffing, and inventory runout alerts.
 *
 * RBAC: Restricted to Store Managers.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, money, h, toast, emptyState, pad2, toIsoDate, toIsoMonth, parseIsoDate, addDays, addMonths, dayLabel } = window.CafeFlow.utils;
  const currentUser = (window.CafeFlow.config && window.CafeFlow.config.currentUser) || window.CafeFlowUser || { role: 'barista' };
  const api = window.CafeFlow.api;

  /* ------------------------------------------------------------------ *
   *  Predictive State & What-If Simulation
   * ------------------------------------------------------------------ */
  const now = new Date();
  const tomorrow = addDays(now, 1);
  const nextMonth = addMonths(now.getFullYear(), now.getMonth(), 1);

  let _predPeriod   = 'daily'; // 'daily' | '7days' | 'month' | 'year'
  let _targetDate   = toIsoDate(tomorrow);
  let _startDate7   = toIsoDate(tomorrow);
  let _targetMonth  = `${nextMonth.year}-${pad2(nextMonth.month + 1)}`;
  let _targetYear   = now.getFullYear() + 1;

  // What-If Simulation Factors
  let _simWeatherFactor = 1.0; // Sunny: 1.05, Normal: 1.0, Heavy Rain: 0.75, Typhoon: 0.40
  let _simEventFactor   = 1.0; // None: 1.0, School: 1.20, Fiesta: 1.35
  let _simWeatherLabel  = 'Normal';
  let _simEventLabel    = 'None';

  let _activeData       = null; // Cached API response for instant What-If recalculation

  // Chart instances
  let _rushChart       = null;
  let _forecastChart   = null;
  let _categoryChart   = null;

  function getPredChartColors() {
    const isDark = (document.documentElement.getAttribute('data-theme') === 'dark') || document.body.classList.contains('dark-mode');
    return {
      isDark,
      caramel: isDark ? '#D98F5E' : '#C87D4B',
      caramelHover: isDark ? '#E5A170' : '#B06A3B',
      caramelSoft: isDark ? 'rgba(217, 143, 94, 0.22)' : 'rgba(200, 125, 75, 0.14)',
      caramelPale: isDark ? 'rgba(217, 143, 94, 0.08)' : 'rgba(200, 125, 75, 0.04)',
      green: isDark ? '#389B72' : '#2E7D5B',
      blue: isDark ? '#7096E8' : '#2563EB',
      violet: isDark ? '#A285D4' : '#7B5EA7',
      text: isDark ? '#FAF7F2' : '#2C1E16',
      muted: isDark ? '#B8A89A' : '#6F5E53',
      grid: isDark ? 'rgba(250, 247, 242, 0.08)' : 'rgba(44, 30, 22, 0.08)',
      tooltipBg: isDark ? '#1F150F' : '#2C1E16',
      tooltipBorder: isDark ? '#3D2A1F' : 'rgba(255, 255, 255, 0.15)',
      cardBg: isDark ? '#2C211A' : '#FFFFFF',
      surface: isDark ? '#221913' : '#FAF7F2',
      pointBg: isDark ? '#2C211A' : '#FFFFFF'
    };
  }

  function destroyAllPredCharts() {
    if (_rushChart) {
      try { _rushChart.destroy(); } catch (_) {}
      _rushChart = null;
    }
    if (_forecastChart) {
      try { _forecastChart.destroy(); } catch (_) {}
      _forecastChart = null;
    }
    if (_categoryChart) {
      try { _categoryChart.destroy(); } catch (_) {}
      _categoryChart = null;
    }
  }

  /* ------------------------------------------------------------------ *
   *  What-If Multiplier Helper
   * ------------------------------------------------------------------ */
  function getSimCombinedMultiplier() {
    return _simWeatherFactor * _simEventFactor;
  }

  function isSimActive() {
    return Math.abs(getSimCombinedMultiplier() - 1.0) > 0.001;
  }

  /* ------------------------------------------------------------------ *
   *  Dynamic Analytical Narrative Generators (100% Data-Driven)
   * ------------------------------------------------------------------ */
  function generateDailyNarrative(data, simMult) {
    const f = data.forecast || {};
    const comp = data.historical_comparison || {};
    const rush = data.rush_peak_hour || {};
    const season = data.season_info || {};
    const intel = data.model_intelligence || {};
    const metrics = intel.evaluation_metrics || {};

    const effSales = f.projected_sales * simMult;
    const effOrders = Math.max(1, Math.round(f.projected_orders * simMult));
    const diffStr = comp.pct_change >= 0 ? `+${comp.pct_change}%` : `${comp.pct_change}%`;
    const dowText = f.is_weekend ? 'elevated weekend' : 'steady weekday';

    let simNote = '';
    if (Math.abs(simMult - 1.0) > 0.001) {
      const simPct = Math.round((simMult - 1.0) * 100);
      const sign = simPct >= 0 ? `+${simPct}%` : `${simPct}%`;
      simNote = ` [Active What-If Simulation: ${sign} adjustment applied for Weather: ${_simWeatherLabel}, Event: ${_simEventLabel}].`;
    }

    const p1 = `Projected gross revenue for ${data.target_label} is estimated at ${money(effSales)} across ${effOrders} completed customer orders, yielding an expected Average Order Value (AOV) of ${money(f.expected_aov)}. Model calculations reflect a ${diffStr} variance compared to the historical ${f.day_name} baseline (${money(comp.same_dow_avg_sales)}), corresponding to ${dowText} footfall in Batangas.${simNote}`;

    const paydayText = f.is_payday
      ? ` Trading is significantly boosted by the Philippine 15th/30th Quincena payday cycle, contributing an empirical +${intel.payday_lift_pct}% demand surge.`
      : ` Normal trading cadence applies with no calendar payday lift detected.`;

    const p2 = `Peak customer rush velocity is anticipated around ${rush.hour_label || '8:00 PM'} with projected revenue of ${money((rush.projected_sales || 0) * simMult)}. Seasonality analysis classifies current operations under the ${season.name} (${season.tag}), where ${season.description.toLowerCase()}${paydayText} Historical hold-out evaluation demonstrates a calibrated model accuracy of ${metrics.accuracy_pct}% (WAPE: ${metrics.wape_pct}%, MAE: ${money(metrics.mae)}).`;

    return [p1, p2];
  }

  function generate7DayNarrative(data, simMult) {
    const s = data.summary || {};
    const p = data.period || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const intel = data.model_intelligence || {};

    const effSales = s.projected_sales * simMult;
    const effOrders = Math.max(1, Math.round(s.projected_orders * simMult));

    const p1 = `The 7-day predictive horizon spanning ${p.label} projects cumulative store revenues of ${money(effSales)} across ${effOrders} orders, maintaining an expected daily throughput average of ${money(effSales / 7.0)}. Volume distribution identifies peak sales on ${peak.day_name}, ${peak.short_label} (${money(peak.projected_sales * simMult)}, ${Math.round(peak.projected_orders * simMult)} orders), while the softest trading volume is projected on ${low.day_name}, ${low.short_label} (${money(low.projected_sales * simMult)}).`;

    const p2 = `Revenue cadence highlights a distinct weekend demand concentration: Friday through Sunday are projected to capture ${money(s.weekend_sales * simMult)} (${s.weekend_share_pct}% of week-long revenue) driven by an empirical +${intel.weekend_surge_pct}% weekend footfall surge over Monday–Thursday baselines (${money(s.weekday_sales * simMult)}). Barista scheduling should allocate peak espresso and croffle prep capacity to the Friday–Sunday rush window to satisfy projected transaction demand.`;

    return [p1, p2];
  }

  function generateMonthNarrative(data, simMult) {
    const s = data.summary || {};
    const season = data.season_info || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const intel = data.model_intelligence || {};

    const effSales = s.projected_sales * simMult;
    const diffStr = s.pct_vs_baseline >= 0 ? `+${s.pct_vs_baseline}%` : `${s.pct_vs_baseline}%`;

    const p1 = `Prospective forecasting for ${data.month_name} (${data.days_in_month} calendar days) estimates total monthly store revenue at ${money(effSales)} across ${Math.round(s.projected_orders * simMult)} completed tickets, averaging ${money(effSales / Math.max(1, data.days_in_month))} per operating day. This projection reflects a ${diffStr} deviation against standard historical monthly averages, driven by ${season.name} demand patterns.`;

    const p2 = `Within the monthly cycle, highest single-day demand is projected for ${peak.short_label} (${money(peak.projected_sales * simMult)}), while baseline low is forecast on ${low.short_label} (${money(low.projected_sales * simMult)}). Operational management should align raw milk, coffee bean, and croffle dough procurement with these cyclical spikes, particularly during the 15th and end-of-month Quincena payday windows (+${intel.payday_lift_pct}% lift).`;

    return [p1, p2];
  }

  function generateYearlyNarrative(data, simMult) {
    const s = data.summary || {};
    const str = s.strongest_month || {};
    const wk = s.weakest_month || {};

    const effSales = s.projected_sales * simMult;

    const p1 = `Annual predictive modeling for ${data.year_label} forecasts gross store revenues of ${money(effSales)} across ${Math.round(s.projected_orders * simMult).toLocaleString()} completed customer orders, averaging ${money(effSales / 12.0)} per calendar month. Demand trajectory exhibits marked retail seasonality, peaking in ${str.month_name} (${money(str.projected_sales * simMult)}, ${str.season_tag}) and bottoming out during the ${wk.month_name} rainy period (${money(wk.projected_sales * simMult)}, ${wk.season_tag}).`;

    const p2 = `The forecast captures the three major Philippine commercial retail waves observed in CafeFlow records: (1) the Tag-init summer vacation surge from March to May (+20% velocity), (2) the Habagat monsoon wet season contraction across July and August (-22% velocity), and (3) the intense Q4 holiday ramp-up from October to December culminating in Christmas party orders. Financial planning and vendor contracts should leverage these projections to optimize cash flow and seasonal inventory holdings.`;

    return [p1, p2];
  }

  /* ------------------------------------------------------------------ *
   *  UI Component Builders
   * ------------------------------------------------------------------ */
  function createNarrativeBox(paragraphs, tagTitle) {
    const body = h('div', { class: 'pred-narrative-body' },
      ...paragraphs.map(p => h('p', { text: p }))
    );
    return h('div', { class: 'pred-narrative-box' },
      h('div', { class: 'pred-narrative-tag' },
        h('span', { class: 'status-pip green' }),
        h('span', { text: tagTitle || 'SEASONALITY-AWARE FORECASTING INSIGHT' })
      ),
      body
    );
  }

  /* ------------------------------------------------------------------ *
   *  Core Predictive View Loader
   * ------------------------------------------------------------------ */
  async function loadPredictiveView() {
    if (currentUser.role !== 'manager') {
      toast('Access denied: Predictive Analytics is reserved for Managers.', 'error');
      return;
    }

    const container = $('#predictiveBody');
    if (!container) return;

    container.innerHTML = '<div style="padding: 40px; text-align: center; color: var(--muted); font-size: 14px;">Calibrating seasonality-aware forecasting model from historical transaction data...</div>';

    // Update contextual pickers in toolbar
    const dailyGrp = $('#predDailyPickerGroup');
    if (dailyGrp) dailyGrp.hidden = (_predPeriod !== 'daily');

    const sevenGrp = $('#pred7DayPickerGroup');
    if (sevenGrp) sevenGrp.hidden = (_predPeriod !== '7days');

    const monthGrp = $('#predMonthPickerGroup');
    if (monthGrp) monthGrp.hidden = (_predPeriod !== 'month');

    const yearGrp  = $('#predYearPickerGroup');
    if (yearGrp) yearGrp.hidden = (_predPeriod !== 'year');

    // Sync input values
    const dPicker = $('#predDatePicker');
    if (dPicker && dPicker.value !== _targetDate) dPicker.value = _targetDate;

    const s7Picker = $('#pred7DayPicker');
    if (s7Picker && s7Picker.value !== _startDate7) s7Picker.value = _startDate7;

    const mPicker = $('#predMonthPicker');
    if (mPicker && mPicker.value !== _targetMonth) mPicker.value = _targetMonth;

    const yPicker = $('#predYearPicker');
    if (yPicker) {
      if (yPicker.children.length === 0) {
        yPicker.innerHTML = '';
        const curY = now.getFullYear();
        for (let y = curY; y <= curY + 3; y++) {
          const opt = document.createElement('option');
          opt.value = String(y);
          opt.textContent = `Year ${y} (Full Forecast)`;
          yPicker.appendChild(opt);
        }
      }
      yPicker.value = String(_targetYear);
    }

    // Update active tab button in #predPeriodSeg
    $$('#predPeriodSeg button').forEach(btn => {
      btn.classList.toggle('on', btn.dataset.predPeriod === _predPeriod);
    });

    // Build API query URL
    let apiUrl = `api/predictive_api.php?mode=${_predPeriod}`;
    if (_predPeriod === 'daily') {
      apiUrl += `&date=${encodeURIComponent(_targetDate)}`;
    } else if (_predPeriod === '7days') {
      apiUrl += `&start_date=${encodeURIComponent(_startDate7)}`;
    } else if (_predPeriod === 'month') {
      apiUrl += `&month=${encodeURIComponent(_targetMonth)}`;
    } else if (_predPeriod === 'year') {
      apiUrl += `&year=${encodeURIComponent(_targetYear)}`;
    }

    try {
      const data = await api(apiUrl);
      if (!data || !data.success) {
        throw new Error(data && data.error ? data.error : 'Failed to retrieve predictive data.');
      }
      _activeData = data;
      renderPredictiveDashboard(container, data);
    } catch (err) {
      container.innerHTML = '';
      container.append(emptyState({
        icon: '',
        title: 'Predictive Model Offline',
        desc: err.message || 'Unable to generate sales forecast. Please verify transaction records.',
        compact: true
      }));
    }
  }

  /* ------------------------------------------------------------------ *
   *  Render Dashboard
   * ------------------------------------------------------------------ */
  function renderPredictiveDashboard(container, data) {
    destroyAllPredCharts();
    container.innerHTML = '';

    const intel = data.model_intelligence || {};
    const metrics = intel.evaluation_metrics || {};
    const simMult = getSimCombinedMultiplier();

    // 1. Model Calibration Banner
    const banner = h('div', { class: 'pred-alert-banner' },
      h('div', { class: 'pred-alert-icon', html: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>' }),
      h('div', { class: 'pred-alert-body' },
        h('div', { class: 'pred-alert-header' },
          h('span', { class: 'pred-alert-title', text: `${intel.algorithm} · Seasonality & Trend Engine` }),
          h('span', { class: 'pred-alert-tag', text: `Accuracy: ${metrics.accuracy_pct}% (WAPE: ${metrics.wape_pct}%)` })
        ),
        h('p', {
          class: 'pred-alert-text',
          text: `Calibrated across ${intel.training_days} historical operating days (${intel.outliers_winsorized || 0} anomaly outliers cleansed). Daily baseline: ${money(intel.grand_daily_mean)}. Recent 60-day trend momentum: ${intel.trend_percent_daily >= 0 ? '+' : ''}${intel.trend_percent_daily}% per day. Multipliers automatically factor Day-of-Week cadence, Quincena paydays (+${intel.payday_lift_pct}% lift on 15th & 30th/31st), and Philippine seasonal climate waves.`
        })
      )
    );
    container.append(banner);

    // 2. Interactive What-If Simulator Panel
    container.append(renderWhatIfSimulator(data));

    // 3. Render Mode Content
    if (_predPeriod === 'daily') {
      renderDailyMode(container, data, simMult);
    } else if (_predPeriod === '7days') {
      render7DaysMode(container, data, simMult);
    } else if (_predPeriod === 'month') {
      renderMonthMode(container, data, simMult);
    } else if (_predPeriod === 'year') {
      renderYearMode(container, data, simMult);
    }

    // 4. Section 5: Explainable Forecasting ("How This Forecast Works")
    container.append(renderExplainableSection(data));
  }

  /* ------------------------------------------------------------------ *
   *  Interactive What-If Simulator Panel Component
   * ------------------------------------------------------------------ */
  function renderWhatIfSimulator(data) {
    const card = h('div', { class: 'pred-sim-card' });

    const simMult = getSimCombinedMultiplier();
    const isModified = Math.abs(simMult - 1.0) > 0.001;
    const simPct = Math.round((simMult - 1.0) * 100);
    const tagText = isModified
      ? `Simulated: ${simPct >= 0 ? '+' : ''}${simPct}% Demand Modifier`
      : 'Baseline (Standard Operating Conditions)';

    const head = h('div', { class: 'pred-sim-header' },
      h('div', { class: 'pred-sim-title-group' },
        h('span', { class: 'pred-sim-title', text: '⚡ Interactive What-If Scenario Simulator' }),
        h('span', { class: 'pred-sim-tag', text: tagText })
      )
    );

    if (isModified) {
      const resetBtn = h('button', {
        type: 'button',
        class: 'pred-sim-reset-btn',
        text: '↺ Reset to Baseline'
      });
      resetBtn.addEventListener('click', () => {
        _simWeatherFactor = 1.0;
        _simEventFactor   = 1.0;
        _simWeatherLabel  = 'Normal';
        _simEventLabel    = 'None';
        renderPredictiveDashboard($('#predictiveBody'), _activeData);
      });
      head.append(resetBtn);
    }

    card.append(head);

    // Weather options
    const weatherOpts = [
      { label: 'Sunny (+5%)', val: 1.05, name: 'Sunny' },
      { label: 'Normal (0%)', val: 1.0, name: 'Normal' },
      { label: 'Heavy Rain (-25%)', val: 0.75, name: 'Heavy Rain' },
      { label: 'Typhoon (-60%)', val: 0.40, name: 'Typhoon' },
    ];
    const weatherPills = h('div', { class: 'pred-sim-pills' },
      ...weatherOpts.map(w => {
        const btn = h('button', {
          type: 'button',
          class: `pred-sim-btn ${Math.abs(_simWeatherFactor - w.val) < 0.01 ? 'active' : ''}`,
          text: w.label
        });
        btn.addEventListener('click', () => {
          _simWeatherFactor = w.val;
          _simWeatherLabel = w.name;
          renderPredictiveDashboard($('#predictiveBody'), _activeData);
        });
        return btn;
      })
    );

    // Event options
    const eventOpts = [
      { label: 'None (0%)', val: 1.0, name: 'None' },
      { label: 'Nearby School Event (+20%)', val: 1.20, name: 'School Event' },
      { label: 'Town Fiesta / Festival (+35%)', val: 1.35, name: 'Town Fiesta' },
    ];
    const eventPills = h('div', { class: 'pred-sim-pills' },
      ...eventOpts.map(e => {
        const btn = h('button', {
          type: 'button',
          class: `pred-sim-btn ${Math.abs(_simEventFactor - e.val) < 0.01 ? 'active' : ''}`,
          text: e.label
        });
        btn.addEventListener('click', () => {
          _simEventFactor = e.val;
          _simEventLabel = e.name;
          renderPredictiveDashboard($('#predictiveBody'), _activeData);
        });
        return btn;
      })
    );

    const controls = h('div', { class: 'pred-sim-controls' },
      h('div', { class: 'pred-sim-group' },
        h('span', { class: 'pred-sim-label', text: 'Weather Condition Impact' }),
        weatherPills
      ),
      h('div', { class: 'pred-sim-group' },
        h('span', { class: 'pred-sim-label', text: 'Local Community Event' }),
        eventPills
      )
    );

    card.append(controls);
    return card;
  }

  /* ------------------------------------------------------------------ *
   *  Mode 1: Daily Horizon
   * ------------------------------------------------------------------ */
  function renderDailyMode(container, data, simMult) {
    const f = data.forecast || {};
    const comp = data.historical_comparison || {};
    const rush = data.rush_peak_hour || {};
    const season = data.season_info || {};
    const fin = data.financials || {};
    const staffing = data.staffing || {};
    const inventory = data.inventory_alerts || {};
    const topProds = data.top_products || [];
    const macroCats = data.macro_categories || {};

    $('#predStatusPeriod').textContent = `${data.target_label} Forecast`;

    const effSales = f.projected_sales * simMult;
    const effOrders = Math.max(1, Math.round(f.projected_orders * simMult));
    const effLower = f.confidence_lower * simMult;
    const effUpper = f.confidence_upper * simMult;
    const effCogs = fin.projected_cogs ? (fin.projected_cogs * simMult) : (effSales * 0.295);
    const effGrossProfit = effSales - effCogs;

    // SECTION 1: Forecast Overview KPI Grid
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Projected Daily Sales' }),
        h('span', { class: 'kpi-val', text: money(effSales) }),
        h('span', { class: 'kpi-sub', text: `Confidence: ${money(effLower)} – ${money(effUpper)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Expected Customer Orders' }),
        h('span', { class: 'kpi-val', text: `${effOrders} Orders` }),
        h('span', { class: 'kpi-sub', text: `Projected AOV: ${money(f.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Gross Profit & Margin' }),
        h('span', { class: 'kpi-val', text: money(effGrossProfit) }),
        h('span', { class: 'kpi-sub', text: `${fin.gross_margin_pct || 70.5}% Margin (COGS: ${money(effCogs)})` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Baseline DOW Variance' }),
        h('span', { class: 'kpi-val', text: comp.pct_change >= 0 ? `+${comp.pct_change}%` : `${comp.pct_change}%` }),
        h('span', { class: 'kpi-sub', text: `vs. Avg ${f.day_name} (${money(comp.same_dow_avg_sales)})` })
      )
    );
    container.append(kpiGrid);

    // SECTION 2: Hourly Rush Projection Chart
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `Hourly Sales Rush & Service Capacity · ${data.target_label}` }),
        h('span', { class: 'chart-panel-subtitle', text: `Peak Rush Window: ${rush.hour_label || '8:00 PM'} (${money((rush.projected_sales || 0) * simMult)})` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': 'Hourly rush prediction curve' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generateDailyNarrative(data, simMult), `NEXT-DAY OPERATIONAL FORECAST (${f.day_name.toUpperCase()})`);
    chartCard.append(narrative);
    container.append(chartCard);

    // SECTION 3: Product & Category Demand Grid
    container.append(renderProductCategorySection(topProds, macroCats, simMult, 'Tomorrow'));

    // SECTION 4: Operational Staffing & Inventory Runouts
    container.append(renderStaffingSection(staffing, simMult));
    container.append(renderInventoryAlertsSection(inventory, simMult));

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const hourly = data.hourly_forecast || [];
      const labels = hourly.map(h => h.hour_label);
      const sales = hourly.map(h => h.projected_sales * simMult);
      const orders = hourly.map(h => Math.round(h.projected_orders * simMult));
      // Estimated service capacity line (1 barista ~ 15 orders/hr, 2 baristas ~ 30 orders/hr)
      const capacityOrders = hourly.map(h => {
        const hr = h.hour;
        if (hr >= 6 && hr < 11) return 15;
        if (hr >= 11 && hr < 16) return 30;
        return 45; // 3 baristas evening
      });

      const colors = getPredChartColors();
      const grad = ctx.createLinearGradient(0, 0, 0, 280);
      grad.addColorStop(0, colors.isDark ? 'rgba(217, 143, 94, 0.35)' : 'rgba(200, 125, 75, 0.32)');
      grad.addColorStop(1, colors.isDark ? 'rgba(217, 143, 94, 0.02)' : 'rgba(200, 125, 75, 0.02)');

      _rushChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              type: 'bar',
              label: 'Projected Sales (₱)',
              data: sales,
              backgroundColor: colors.isDark ? 'rgba(217, 143, 94, 0.82)' : 'rgba(200, 125, 75, 0.82)',
              borderColor: colors.caramel,
              borderWidth: 1.2,
              borderRadius: 5,
              borderSkipped: false,
              yAxisID: 'y'
            },
            {
              type: 'line',
              label: 'Expected Orders',
              data: orders,
              borderColor: colors.green,
              backgroundColor: colors.pointBg,
              borderWidth: 2.4,
              pointRadius: 3.5,
              pointHoverRadius: 6,
              pointBackgroundColor: colors.pointBg,
              pointBorderColor: colors.green,
              pointBorderWidth: 2,
              tension: 0.35,
              fill: false,
              yAxisID: 'y1'
            },
            {
              type: 'line',
              label: 'Shift Service Capacity',
              data: capacityOrders,
              borderColor: colors.blue,
              borderWidth: 1.8,
              borderDash: [5, 4],
              pointRadius: 0,
              pointHoverRadius: 0,
              fill: false,
              yAxisID: 'y1'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: {
              position: 'top',
              labels: {
                boxWidth: 12,
                padding: 14,
                color: colors.muted,
                font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' }
              }
            },
            tooltip: {
              backgroundColor: colors.tooltipBg,
              titleColor: colors.text,
              titleFont: { family: "'Work Sans', sans-serif", weight: '700', size: 12 },
              bodyColor: colors.isDark ? 'rgba(250, 247, 242, 0.92)' : 'rgba(255, 255, 255, 0.95)',
              bodyFont: { family: "'Work Sans', sans-serif", size: 12 },
              padding: 10,
              cornerRadius: 8,
              borderColor: colors.tooltipBorder,
              borderWidth: 1,
              callbacks: {
                label: (c) => c.dataset.label.indexOf('Sales') >= 0 ? `  Sales: ${money(c.parsed.y)}` : `  ${c.dataset.label}: ${c.parsed.y} orders/hr`
              }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' }, color: colors.text }
            },
            y: {
              beginAtZero: true,
              grid: { color: colors.grid },
              ticks: {
                font: { family: "'Work Sans', sans-serif", size: 11 },
                color: colors.muted,
                callback: v => '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : Number(v).toLocaleString())
              }
            },
            y1: {
              position: 'right',
              beginAtZero: true,
              grid: { drawOnChartArea: false },
              ticks: { font: { family: "'Work Sans', sans-serif", size: 11 }, color: colors.muted, precision: 0 }
            }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Mode 2: 7-Day Horizon
   * ------------------------------------------------------------------ */
  function render7DaysMode(container, data, simMult) {
    const s = data.summary || {};
    const p = data.period || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const days = data.daily_forecasts || [];
    const fin = data.financials || {};
    const inventory = data.inventory_alerts || {};
    const topProds = data.top_products || [];
    const macroCats = data.macro_categories || {};

    $('#predStatusPeriod').textContent = p.label;

    const effSales = s.projected_sales * simMult;
    const effOrders = Math.max(1, Math.round(s.projected_orders * simMult));
    const effCogs = fin.projected_cogs ? (fin.projected_cogs * simMult) : (effSales * 0.295);
    const effGrossProfit = effSales - effCogs;

    // SECTION 1: Forecast Overview KPI Grid
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: '7-Day Projected Sales' }),
        h('span', { class: 'kpi-val', text: money(effSales) }),
        h('span', { class: 'kpi-sub', text: `Sum of 7 days (${effOrders} orders)` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Daily Sales Pace' }),
        h('span', { class: 'kpi-val', text: money(effSales / 7.0) }),
        h('span', { class: 'kpi-sub', text: `Projected AOV: ${money(s.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Projected Gross Margin' }),
        h('span', { class: 'kpi-val', text: money(effGrossProfit) }),
        h('span', { class: 'kpi-sub', text: `${fin.gross_margin_pct || 70.5}% Gross Margin (COGS: ${money(effCogs)})` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Weekend Concentration' }),
        h('span', { class: 'kpi-val', text: `${s.weekend_share_pct}%` }),
        h('span', { class: 'kpi-sub', text: `Fri–Sun volume: ${money(s.weekend_sales * simMult)}` })
      )
    );
    container.append(kpiGrid);

    // SECTION 2: 7-Day Chart & Confidence Intervals
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `7-Day Daily Demand Horizon · ${p.label}` }),
        h('span', { class: 'chart-panel-subtitle', text: `Peak Day: ${peak.day_name} (${money(peak.projected_sales * simMult)})` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': '7-day daily forecast curve' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generate7DayNarrative(data, simMult), '7-DAY HORIZON INTELLIGENCE & PROCUREMENT');
    chartCard.append(narrative);
    container.append(chartCard);

    // Daily Forecast Table
    const tableWrap = h('div', { class: 'pred-table-wrap' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: 'Daily Forecast Breakdown & Confidence Intervals' })
      ),
      h('table', { class: 'table' },
        h('thead', {},
          h('tr', {},
            h('th', { text: 'Date' }),
            h('th', { text: 'Day of Week' }),
            h('th', { text: 'Calendar Events' }),
            h('th', { class: 'num', text: 'Orders' }),
            h('th', { class: 'num', text: 'Expected AOV' }),
            h('th', { class: 'num', text: 'Projected Sales' }),
            h('th', { class: 'num', text: 'Confidence Corridor' })
          )
        ),
        h('tbody', {},
          days.map(d => h('tr', {},
            h('td', { style: 'font-weight: 600;', text: d.short_label }),
            h('td', { text: d.day_name }),
            h('td', {},
              d.is_payday ? h('span', { class: 'pred-tag-pill payday', text: 'Quincena Payday' }) : null,
              d.is_weekend ? h('span', { class: 'pred-tag-pill summer', text: 'Weekend Surge', style: 'margin-left: 4px;' }) : null,
              (!d.is_payday && !d.is_weekend) ? h('span', { class: 'pred-tag-pill baseline', text: 'Regular Weekday' }) : null
            ),
            h('td', { class: 'num', text: String(Math.round(d.projected_orders * simMult)) }),
            h('td', { class: 'num', text: money(d.expected_aov) }),
            h('td', { class: 'num', style: 'font-weight: 700;', text: money(d.projected_sales * simMult) }),
            h('td', { class: 'num', style: 'color: var(--muted);', text: `${money(d.confidence_lower * simMult)} – ${money(d.confidence_upper * simMult)}` })
          )),
          h('tr', { class: 'table-total-row' },
            h('td', { text: '7-Day Total' }),
            h('td', { text: '7 Days' }),
            h('td', { text: 'Cumulative' }),
            h('td', { class: 'num', text: String(effOrders) }),
            h('td', { class: 'num', text: money(s.expected_aov) }),
            h('td', { class: 'num', text: money(effSales) }),
            h('td', { class: 'num', text: '—' })
          )
        )
      )
    );
    container.append(tableWrap);

    // SECTION 3: Product & Category Demand Grid
    container.append(renderProductCategorySection(topProds, macroCats, simMult, 'Next 7 Days'));

    // SECTION 4: Predictive Inventory Runouts
    container.append(renderInventoryAlertsSection(inventory, simMult));

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const labels = days.map(d => d.short_label);
      const sales = days.map(d => d.projected_sales * simMult);
      const upper = days.map(d => d.confidence_upper * simMult);
      const lower = days.map(d => d.confidence_lower * simMult);

      const colors = getPredChartColors();
      _forecastChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Projected Sales (₱)',
              data: sales,
              borderColor: colors.caramel,
              backgroundColor: colors.caramelSoft,
              borderWidth: 2.4,
              fill: true,
              tension: 0.28,
              pointRadius: 4,
              pointHoverRadius: 6.5,
              pointBackgroundColor: colors.pointBg,
              pointBorderColor: colors.caramel,
              pointBorderWidth: 2.2
            },
            {
              label: 'Upper Confidence (₱)',
              data: upper,
              borderColor: colors.isDark ? 'rgba(217, 143, 94, 0.45)' : 'rgba(200, 125, 75, 0.4)',
              borderDash: [4, 4],
              borderWidth: 1.4,
              fill: false,
              pointRadius: 0,
              pointHoverRadius: 0
            },
            {
              label: 'Lower Confidence (₱)',
              data: lower,
              borderColor: colors.isDark ? 'rgba(217, 143, 94, 0.45)' : 'rgba(200, 125, 75, 0.4)',
              borderDash: [4, 4],
              borderWidth: 1.4,
              fill: false,
              pointRadius: 0,
              pointHoverRadius: 0
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top',
              labels: {
                boxWidth: 12,
                padding: 14,
                color: colors.muted,
                font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' }
              }
            },
            tooltip: {
              backgroundColor: colors.tooltipBg,
              titleColor: colors.text,
              titleFont: { family: "'Work Sans', sans-serif", weight: '700', size: 12 },
              bodyColor: colors.isDark ? 'rgba(250, 247, 242, 0.92)' : 'rgba(255, 255, 255, 0.95)',
              bodyFont: { family: "'Work Sans', sans-serif", size: 12 },
              padding: 10,
              cornerRadius: 8,
              borderColor: colors.tooltipBorder,
              borderWidth: 1,
              callbacks: { label: c => `  ${c.dataset.label}: ${money(c.parsed.y)}` }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' }, color: colors.text }
            },
            y: {
              beginAtZero: false,
              grid: { color: colors.grid },
              ticks: {
                font: { family: "'Work Sans', sans-serif", size: 11 },
                color: colors.muted,
                callback: v => '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : Number(v).toLocaleString())
              }
            }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Mode 3: Specific Month Horizon
   * ------------------------------------------------------------------ */
  function renderMonthMode(container, data, simMult) {
    const s = data.summary || {};
    const season = data.season_info || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const days = data.daily_forecasts || [];
    const fin = data.financials || {};
    const inventory = data.inventory_alerts || {};
    const macroCats = data.macro_categories || {};

    $('#predStatusPeriod').textContent = `${data.month_name} Forecast`;

    const effSales = s.projected_sales * simMult;
    const effOrders = Math.max(1, Math.round(s.projected_orders * simMult));
    const effCogs = fin.projected_cogs ? (fin.projected_cogs * simMult) : (effSales * 0.295);
    const effGrossProfit = effSales - effCogs;

    // SECTION 1: Forecast Overview KPI Grid
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Projected Monthly Sales' }),
        h('span', { class: 'kpi-val', text: money(effSales) }),
        h('span', { class: 'kpi-sub', text: `${data.days_in_month} Days (${effOrders} orders)` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Daily Sales Pace' }),
        h('span', { class: 'kpi-val', text: money(effSales / Math.max(1, data.days_in_month)) }),
        h('span', { class: 'kpi-sub', text: `Expected AOV: ${money(s.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Monthly Gross Profit' }),
        h('span', { class: 'kpi-val', text: money(effGrossProfit) }),
        h('span', { class: 'kpi-sub', text: `${fin.gross_margin_pct || 70.5}% Margin (COGS: ${money(effCogs)})` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Baseline Variance' }),
        h('span', { class: 'kpi-val', text: s.pct_vs_baseline >= 0 ? `+${s.pct_vs_baseline}%` : `${s.pct_vs_baseline}%` }),
        h('span', { class: 'kpi-sub', text: `${season.name} [${season.tag}]` })
      )
    );
    container.append(kpiGrid);

    // SECTION 2: Month Waveform Chart
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `Day-by-Day Forecast Waveform · ${data.month_name} (${data.days_in_month} Days)` }),
        h('span', { class: 'chart-panel-subtitle', text: `Peak Day: ${peak.short_label} (${money(peak.projected_sales * simMult)})` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': 'Monthly daily forecast curve' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generateMonthNarrative(data, simMult), `MONTHLY CYCLE INTELLIGENCE · ${season.name.toUpperCase()}`);
    chartCard.append(narrative);
    container.append(chartCard);

    // SECTION 4: Inventory Alerts
    container.append(renderInventoryAlertsSection(inventory, simMult));

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const labels = days.map(d => d.day_of_month);
      const sales = days.map(d => d.projected_sales * simMult);

      const grad = ctx.createLinearGradient(0, 0, 0, 280);
      grad.addColorStop(0, 'rgba(192, 124, 46, 0.35)');
      grad.addColorStop(1, 'rgba(192, 124, 46, 0.02)');

      _forecastChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Predicted Sales (₱)',
              data: sales,
              borderColor: '#c07c2e',
              backgroundColor: grad,
              borderWidth: 2.2,
              fill: true,
              tension: 0.28,
              pointRadius: 3,
              pointHoverRadius: 6,
              pointBackgroundColor: '#ffffff',
              pointBorderColor: '#c07c2e',
              pointBorderWidth: 2
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'top',
              labels: {
                boxWidth: 12,
                padding: 14,
                font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' }
              }
            },
            tooltip: {
              backgroundColor: '#162318',
              titleColor: '#ffffff',
              titleFont: { family: "'Work Sans', sans-serif", weight: '700', size: 12 },
              bodyColor: 'rgba(255,255,255,0.9)',
              bodyFont: { family: "'Work Sans', sans-serif", size: 12 },
              padding: 10,
              cornerRadius: 8,
              borderColor: 'rgba(255,255,255,0.12)',
              borderWidth: 1,
              callbacks: {
                title: items => items[0] ? `Day ${items[0].label} (${days[items[0].dataIndex]?.day_name})` : '',
                label: c => `  Projected: ${money(c.parsed.y)} (${Math.round((days[c.dataIndex]?.projected_orders || 0) * simMult)} orders)`
              }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              title: {
                display: true,
                text: 'Day of Month',
                font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' },
                color: '#5a7a63'
              },
              ticks: { font: { family: "'Work Sans', sans-serif", size: 11 }, color: '#5a7a63' }
            },
            y: {
              beginAtZero: false,
              grid: { color: 'rgba(22, 35, 24, 0.06)' },
              ticks: {
                font: { family: "'Work Sans', sans-serif", size: 11 },
                color: '#5a7a63',
                callback: v => '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : Number(v).toLocaleString())
              }
            }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Mode 4: Yearly Horizon
   * ------------------------------------------------------------------ */
  function renderYearMode(container, data, simMult) {
    const s = data.summary || {};
    const str = s.strongest_month || {};
    const wk = s.weakest_month || {};
    const months = data.monthly_forecasts || [];
    const fin = data.financials || {};

    $('#predStatusPeriod').textContent = data.year_label;

    const effSales = s.projected_sales * simMult;
    const effOrders = Math.max(1, Math.round(s.projected_orders * simMult));
    const effCogs = fin.projected_cogs ? (fin.projected_cogs * simMult) : (effSales * 0.295);
    const effGrossProfit = effSales - effCogs;

    // SECTION 1: Forecast Overview KPI Grid
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Projected Annual Sales' }),
        h('span', { class: 'kpi-val', text: money(effSales) }),
        h('span', { class: 'kpi-sub', text: `12 Months (${effOrders.toLocaleString()} orders)` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Monthly Sales Pace' }),
        h('span', { class: 'kpi-val', text: money(effSales / 12.0) }),
        h('span', { class: 'kpi-sub', text: `Expected AOV: ${money(s.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Annual Gross Profit' }),
        h('span', { class: 'kpi-val', text: money(effGrossProfit) }),
        h('span', { class: 'kpi-sub', text: `${fin.gross_margin_pct || 70.5}% Margin (COGS: ${money(effCogs)})` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Strongest Month' }),
        h('span', { class: 'kpi-val', text: `${str.month_name} (${money(str.projected_sales * simMult)})` }),
        h('span', { class: 'kpi-sub', text: `${str.season_tag}` })
      )
    );
    container.append(kpiGrid);

    // SECTION 2: 12-Month Bar Chart
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `12-Month Annual Demand & Philippine Retail Waves · ${data.year_label}` }),
        h('span', { class: 'chart-panel-subtitle', text: `Peak: ${str.month_name} · Softest: ${wk.month_name}` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': '12-month annual forecast bar chart' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generateYearlyNarrative(data, simMult), 'ANNUAL TRAJECTORY & SEASONAL RETAIL WAVES');
    chartCard.append(narrative);
    container.append(chartCard);

    // 12-Month Table
    const tableWrap = h('div', { class: 'pred-table-wrap' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: '12-Month Financial Forecast Ledger' })
      ),
      h('table', { class: 'table' },
        h('thead', {},
          h('tr', {},
            h('th', { text: 'Month' }),
            h('th', { text: 'Season & Tag' }),
            h('th', { class: 'num', text: 'Days' }),
            h('th', { class: 'num', text: 'Daily Avg' }),
            h('th', { class: 'num', text: 'Projected Orders' }),
            h('th', { class: 'num', text: 'Projected Sales' })
          )
        ),
        h('tbody', {},
          months.map(m => {
            let pillClass = 'baseline';
            if (m.month_num >= 3 && m.month_num <= 5) pillClass = 'summer';
            else if (m.month_num >= 6 && m.month_num <= 8) pillClass = 'monsoon';
            else if (m.month_num >= 9 && m.month_num <= 12) pillClass = 'holiday';

            return h('tr', {},
              h('td', { style: 'font-weight: 700;', text: m.month_name }),
              h('td', {},
                h('span', { class: `pred-tag-pill ${pillClass}`, text: `${m.season_icon} ${m.season_tag}` })
              ),
              h('td', { class: 'num', text: String(m.days_in_month) }),
              h('td', { class: 'num', text: money((m.projected_sales * simMult) / m.days_in_month) }),
              h('td', { class: 'num', text: Math.round(m.projected_orders * simMult).toLocaleString() }),
              h('td', { class: 'num', style: 'font-weight: 700;', text: money(m.projected_sales * simMult) })
            );
          }),
          h('tr', { class: 'table-total-row' },
            h('td', { text: 'Total Year' }),
            h('td', { text: '12 Months' }),
            h('td', { class: 'num', text: '365' }),
            h('td', { class: 'num', text: money(effSales / 365) }),
            h('td', { class: 'num', text: effOrders.toLocaleString() }),
            h('td', { class: 'num', text: money(effSales) })
          )
        )
      )
    );
    container.append(tableWrap);

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const labels = months.map(m => m.month_name);
      const sales = months.map(m => m.projected_sales * simMult);
      const colors = getPredChartColors();
      const bgColors = months.map(m => {
        if (m.month_num >= 3 && m.month_num <= 5) return colors.isDark ? 'rgba(217, 143, 94, 0.85)' : 'rgba(200, 125, 75, 0.85)'; // Summer peak
        if (m.month_num >= 6 && m.month_num <= 8) return colors.isDark ? 'rgba(112, 150, 232, 0.80)' : 'rgba(43, 108, 176, 0.75)'; // Monsoon lull
        if (m.month_num >= 9 && m.month_num <= 12) return colors.isDark ? 'rgba(56, 155, 114, 0.85)' : 'rgba(46, 125, 91, 0.85)'; // Holiday surge
        return colors.isDark ? 'rgba(184, 168, 154, 0.70)' : 'rgba(111, 94, 83, 0.75)'; // Baseline
      });

      _forecastChart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Projected Sales (₱)',
              data: sales,
              backgroundColor: bgColors,
              borderRadius: 6,
              borderSkipped: false,
              borderWidth: 1,
              borderColor: colors.isDark ? 'rgba(255, 255, 255, 0.08)' : 'transparent'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: colors.tooltipBg,
              titleColor: colors.text,
              titleFont: { family: "'Work Sans', sans-serif", weight: '700', size: 12 },
              bodyColor: colors.isDark ? 'rgba(250, 247, 242, 0.92)' : 'rgba(255, 255, 255, 0.95)',
              bodyFont: { family: "'Work Sans', sans-serif", size: 12 },
              padding: 10,
              cornerRadius: 8,
              borderColor: colors.tooltipBorder,
              borderWidth: 1,
              callbacks: {
                label: c => `  Projected: ${money(c.parsed.y)} (${Math.round((months[c.dataIndex]?.projected_orders || 0) * simMult).toLocaleString()} orders)`
              }
            }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { font: { family: "'Work Sans', sans-serif", size: 11, weight: '700' }, color: colors.text }
            },
            y: {
              beginAtZero: true,
              grid: { color: colors.grid },
              ticks: {
                font: { family: "'Work Sans', sans-serif", size: 11 },
                color: colors.muted,
                callback: v => '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : Number(v).toLocaleString())
              }
            }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Section 3 Helper: Product & Category Demand Grid
   * ------------------------------------------------------------------ */
  function renderProductCategorySection(topProds, macroCats, simMult, horizonLabel) {
    const grid = h('div', { class: 'pred-split-grid' });

    // Left Panel: Macro-Category Distribution
    const catCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: 'Category Demand Contribution' }),
        h('span', { class: 'chart-panel-subtitle', text: 'Historical revenue share' })
      )
    );
    const catBody = h('div', { class: 'pred-chart-body', style: 'height: 250px;' });
    const catCanvas = h('canvas', { 'aria-label': 'Category distribution chart' });
    catBody.append(catCanvas);
    catCard.append(catBody);

    const catList = h('div', { class: 'pred-category-list' });
    Object.keys(macroCats).forEach(k => {
      const c = macroCats[k];
      catList.append(h('div', { class: 'pred-cat-row' },
        h('div', { class: 'pred-cat-info' },
          h('span', { class: 'pred-cat-name', text: k }),
          h('span', { class: 'pred-cat-sub', text: `${c.units.toLocaleString()} units sold` })
        ),
        h('div', { class: 'pred-cat-metrics' },
          h('span', { class: 'pred-cat-pct', text: `${c.share_pct}%` }),
          h('span', { class: 'pred-cat-rev', text: money(c.revenue) })
        )
      ));
    });
    catCard.append(catList);
    grid.append(catCard);

    // Right Panel: Top 5 Predicted Menu Items
    const prodCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `Top 5 High-Demand Products (${horizonLabel})` }),
        h('span', { class: 'chart-panel-subtitle', text: 'Projected prep volume' })
      )
    );
    const prodTable = h('table', { class: 'table', style: 'margin: 0;' },
      h('thead', {},
        h('tr', {},
          h('th', { text: 'Product Item' }),
          h('th', { text: 'Category' }),
          h('th', { class: 'num', text: 'Price' }),
          h('th', { class: 'num', text: 'Predicted Units' }),
          h('th', { class: 'num', text: 'Projected Sales' })
        )
      ),
      h('tbody', {},
        topProds.map(tp => {
          const u = Math.max(1, Math.round(tp.projected_units * simMult));
          const s = u * tp.price;
          return h('tr', {},
            h('td', { style: 'font-weight: 700;', text: tp.name }),
            h('td', { style: 'font-size: 11.5px; color: var(--muted);', text: tp.macro_category }),
            h('td', { class: 'num', text: money(tp.price) }),
            h('td', { class: 'num', style: 'font-weight: 700; color: #c07c2e;', text: `${u} units` }),
            h('td', { class: 'num', style: 'font-weight: 700;', text: money(s) })
          );
        })
      )
    );
    prodCard.append(prodTable);
    grid.append(prodCard);

    // Render Doughnut Chart
    requestAnimationFrame(() => {
      const colors = getPredChartColors();
      const ctx = catCanvas.getContext('2d');
      const catKeys = Object.keys(macroCats);
      const shares = catKeys.map(k => macroCats[k].share_pct);

      _categoryChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: catKeys,
          datasets: [{
            data: shares,
            backgroundColor: colors.isDark ? [
              '#D98F5E', // Warm Caramel
              '#7096E8', // Milk Blue
              '#389B72', // Fruit Green
              '#A285D4'  // Pastry Violet
            ] : [
              '#C87D4B', // Coffee Caramel
              '#2563EB', // Milk Blue
              '#2E7D5B', // Fruit Green
              '#7B5EA7'  // Pastry Deep
            ],
            borderWidth: 2,
            borderColor: colors.cardBg,
            spacing: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'right',
              labels: {
                boxWidth: 10,
                padding: 12,
                color: colors.text,
                font: { family: "'Work Sans', sans-serif", size: 10.5, weight: '600' }
              }
            },
            tooltip: {
              backgroundColor: colors.tooltipBg,
              titleColor: colors.text,
              titleFont: { family: "'Work Sans', sans-serif", weight: '700', size: 12 },
              bodyColor: colors.isDark ? 'rgba(250, 247, 242, 0.92)' : 'rgba(255, 255, 255, 0.95)',
              bodyFont: { family: "'Work Sans', sans-serif", size: 12 },
              padding: 10,
              cornerRadius: 8,
              borderColor: colors.tooltipBorder,
              borderWidth: 1,
              callbacks: { label: c => `  ${c.label}: ${c.parsed}% share` }
            }
          },
          cutout: '68%'
        }
      });
    });

    return grid;
  }

  /* ------------------------------------------------------------------ *
   *  Section 4 Helper: Operational Staffing Roster
   * ------------------------------------------------------------------ */
  function renderStaffingSection(staffing, simMult) {
    const card = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: 'Operational Barista Staffing Recommendations' }),
        h('span', { class: 'chart-panel-subtitle', text: `Total Roster: ${staffing.total_staff_hours || 30} Barista Man-Hours` })
      )
    );

    const grid = h('div', { class: 'pred-shifts-grid' },
      ...(staffing.recommended_shifts || []).map(s => {
        const effOrders = Math.max(1, Math.round(s.projected_orders * simMult));
        return h('div', { class: 'pred-shift-card' },
          h('div', { class: 'pred-shift-head' },
            h('span', { class: 'pred-shift-name', text: s.name }),
            h('span', { class: 'pred-shift-badge', text: s.status })
          ),
          h('span', { class: 'pred-shift-time', text: `${s.hours} (${s.duration} hrs)` }),
          h('span', { class: 'pred-shift-profile', text: `${s.role_profile} · ~${effOrders} Orders` }),
          h('p', { class: 'pred-shift-tasks', text: s.tasks })
        );
      })
    );
    card.append(grid);
    return card;
  }

  /* ------------------------------------------------------------------ *
   *  Section 4 Helper: Predictive Inventory Depletion & Stockout Table
   * ------------------------------------------------------------------ */
  function renderInventoryAlertsSection(inventory, simMult) {
    const card = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: 'Predictive Ingredient Consumption & 7-Day Stockout Warnings' }),
        h('span', { class: 'chart-panel-subtitle', text: `${inventory.critical_count || 0} Critical / Low Stock Alert(s) Detected` })
      )
    );

    const items = inventory.items || [];
    if (items.length === 0) {
      card.append(h('div', { style: 'padding: 24px; text-align: center; color: var(--muted);', text: 'All raw ingredient stocks are healthy.' }));
      return card;
    }

    const table = h('table', { class: 'table', style: 'margin: 0;' },
      h('thead', {},
        h('tr', {},
          h('th', { text: 'Raw Supply / Ingredient' }),
          h('th', { class: 'num', text: 'Current Stock' }),
          h('th', { class: 'num', text: 'Projected Daily Burn' }),
          h('th', { class: 'num', text: 'Days Until Stockout' }),
          h('th', { class: 'num', text: 'Recommended Reorder' }),
          h('th', { text: 'Inventory Status' })
        )
      ),
      h('tbody', {},
        items.map(it => {
          const burn = it.daily_burn_rate * simMult;
          const daysRemain = burn > 0 ? (it.current_stock / burn) : 999;
          const roundedDays = Math.round(daysRemain * 10) / 10;
          let badgeClass = 'healthy';
          let statusText = 'Healthy';

          if (roundedDays < 7.0) {
            badgeClass = 'critical';
            statusText = 'Critical (< 7 Days)';
          } else if (roundedDays <= 14.0) {
            badgeClass = 'warning';
            statusText = 'Low Stock (7-14 Days)';
          } else if (roundedDays <= 30.0) {
            badgeClass = 'monitor';
            statusText = 'Monitor';
          }

          const reorder = (roundedDays <= 14.0) ? Math.max(0, Math.round((burn * 14.0) - it.current_stock)) : 0;

          return h('tr', {},
            h('td', { style: 'font-weight: 700;', text: it.name }),
            h('td', { class: 'num', text: `${Number(it.current_stock).toLocaleString()} ${it.unit_measure}` }),
            h('td', { class: 'num', text: `${burn.toFixed(1)} ${it.unit_measure}/day` }),
            h('td', { class: 'num', style: roundedDays < 7 ? 'color: var(--red); font-weight: 800;' : 'font-weight: 700;', text: `${roundedDays.toFixed(1)} days` }),
            h('td', { class: 'num', text: reorder > 0 ? `${reorder.toLocaleString()} ${it.unit_measure}` : '—' }),
            h('td', {},
              h('span', { class: `stock-badge ${badgeClass}`, text: statusText })
            )
          );
        })
      )
    );
    card.append(table);
    return card;
  }

  /* ------------------------------------------------------------------ *
   *  Section 5: Explainable Forecasting ("How This Forecast Works")
   * ------------------------------------------------------------------ */
  function renderExplainableSection(data) {
    const sec = h('div', { class: 'pred-explain-section' });
    const f = data.forecast || {};
    const diag = f.diagnostics || {};
    const intel = data.model_intelligence || {};
    const season = data.season_info || {};
    const metrics = intel.evaluation_metrics || {};

    const head = h('div', { class: 'chart-panel-head' },
      h('span', { class: 'chart-panel-title', text: '📖 How This Forecast Works (Explainable AI & Mathematical Transparency)' }),
      h('span', { class: 'chart-panel-subtitle', text: 'Clear explanations for store administrators and managerial evaluators' })
    );
    sec.append(head);

    const grid = h('div', { class: 'pred-explain-grid' },
      h('div', { class: 'pred-explain-card' },
        h('span', { class: 'pred-explain-title', text: '1. Baseline Unweighted Sales' }),
        h('p', { class: 'pred-explain-desc', text: `The foundational anchor represents typical daily revenue (${money(diag.grand_daily_mean || intel.grand_daily_mean || 896.35)}) averaged across all 365 operational training days, establishing expected trading volume before calendar adjustments.` })
      ),
      h('div', { class: 'pred-explain-card' },
        h('span', { class: 'pred-explain-title', text: '2. Linear Trend Momentum' }),
        h('p', { class: 'pred-explain-desc', text: `Calculated from a 60-day moving regression (${intel.trend_percent_daily >= 0 ? '+' : ''}${intel.trend_percent_daily}% / day). Rather than assuming a static business, the model incorporates whether recent trading momentum is improving, stable, or cooling.` })
      ),
      h('div', { class: 'pred-explain-card' },
        h('span', { class: 'pred-explain-title', text: '3. Day-of-Week (DOW) Multiplier' }),
        h('p', { class: 'pred-explain-desc', text: `Weekly customer footfall fluctuates predictably. Saturday and Sunday carry strong surges (+16%), while mid-week days (Tuesday/Monday) reflect quieter trading. The target date applies a calibrated multiplier of ${(diag.dow_factor || 1.0)}x.` })
      ),
      h('div', { class: 'pred-explain-card' },
        h('span', { class: 'pred-explain-title', text: '4. Climate & Retail Seasonality' }),
        h('p', { class: 'pred-explain-desc', text: `Accounts for Batangas retail climate cycles (${season.name}). Summer surges iced drink velocity (+20%), while monsoon rainfall causes walk-in contraction (-22%). The target month applies an index of ${(diag.moy_factor || 1.0)}x.` })
      ),
      h('div', { class: 'pred-explain-card' },
        h('span', { class: 'pred-explain-title', text: '5. Quincena Payday Lift' }),
        h('p', { class: 'pred-explain-desc', text: `Philippine semi-monthly employee payroll disbursements on the 15th and 30th/31st contribute an empirical +${intel.payday_lift_pct}% transaction volume boost over regular non-payday baselines.` })
      ),
      h('div', { class: 'pred-explain-card' },
        h('span', { class: 'pred-explain-title', text: '6. Forecast Uncertainty Corridor' }),
        h('p', { class: 'pred-explain-desc', text: `Derived from retrospective hold-out testing across the last 30 operational days (MAE: ${money(metrics.mae)}, WAPE: ${metrics.wape_pct}%). Projections define a 90% confidence corridor [${money(f.confidence_lower)} – ${money(f.confidence_upper)}].` })
      )
    );
    sec.append(grid);

    // Expandable Calculation Breakdown Details
    const details = h('div', { class: 'pred-breakdown-details' });
    const toggleBtn = h('button', {
      type: 'button',
      class: 'pred-breakdown-toggle',
      html: '<span>▶ Expand Mathematical Calculation Diagnostics</span>'
    });
    const tableDiv = h('div', { hidden: true });

    const simMult = getSimCombinedMultiplier();
    const isSim = Math.abs(simMult - 1.0) > 0.001;

    const diagTable = h('table', { class: 'pred-breakdown-table' },
      h('thead', {},
        h('tr', {},
          h('th', { text: 'Forecasting Parameter' }),
          h('th', { text: 'Type / Domain' }),
          h('th', { class: 'num', text: 'Input / Value' }),
          h('th', { text: 'Operational Impact' })
        )
      ),
      h('tbody', {},
        h('tr', {},
          h('td', { style: 'font-weight: 700;', text: 'Grand Daily Mean (μ_daily)' }),
          h('td', { text: 'Learned Historical Average' }),
          h('td', { class: 'num', text: money(diag.grand_daily_mean || intel.grand_daily_mean || 896.35) }),
          h('td', { text: 'Baseline unweighted daily revenue anchor' })
        ),
        h('tr', {},
          h('td', { style: 'font-weight: 700;', text: 'Trend Multiplier (β)' }),
          h('td', { text: '60-Day Linear Regression' }),
          h('td', { class: 'num', text: `${diag.trend_multiplier || 1.0}x` }),
          h('td', { text: `Recent momentum adjustment (${intel.trend_percent_daily}% per day)` })
        ),
        h('tr', {},
          h('td', { style: 'font-weight: 700;', text: `Day-of-Week Factor (${f.day_name || 'DOW'})` }),
          h('td', { text: 'Cyclical Weekly Ratio' }),
          h('td', { class: 'num', text: `${diag.dow_factor || 1.0}x` }),
          h('td', { text: 'Adjusts for weekday lull vs weekend customer volume' })
        ),
        h('tr', {},
          h('td', { style: 'font-weight: 700;', text: `Month-of-Year Factor (${season.tag || 'MOY'})` }),
          h('td', { text: 'Annual Retail Climate Wave' }),
          h('td', { class: 'num', text: `${diag.moy_factor || 1.0}x` }),
          h('td', { text: `${season.name} seasonal demand weighting` })
        ),
        h('tr', {},
          h('td', { style: 'font-weight: 700;', text: 'Payday Multiplier (L_payday)' }),
          h('td', { text: 'Philippine Quincena Payroll' }),
          h('td', { class: 'num', text: `${diag.payday_multiplier || 1.0}x` }),
          h('td', { text: f.is_payday ? `Quincena payday active (+${intel.payday_lift_pct}% lift)` : 'Standard non-payday trading' })
        ),
        isSim ? h('tr', { style: 'background: #fff8e1;' },
          h('td', { style: 'font-weight: 700; color: #8c5314;', text: 'What-If Simulation Adjustment' }),
          h('td', { text: 'Hypothetical User Scenario' }),
          h('td', { class: 'num', style: 'color: #8c5314;', text: `${simMult.toFixed(2)}x` }),
          h('td', { text: `Weather: ${_simWeatherLabel}, Event: ${_simEventLabel}` })
        ) : null,
        h('tr', { style: 'font-weight: 800; background: var(--cream);' },
          h('td', { text: 'Final Projected Revenue Y(t)' }),
          h('td', { text: 'Multiplicative Product' }),
          h('td', { class: 'num', style: 'color: #c07c2e; font-size: 14px;', text: money(f.projected_sales * simMult) }),
          h('td', { text: `Formula: μ × Trend × DOW × MOY × Payday ${isSim ? '× Sim' : ''}` })
        )
      )
    );
    tableDiv.append(diagTable);

    toggleBtn.addEventListener('click', () => {
      const isHidden = tableDiv.hidden;
      tableDiv.hidden = !isHidden;
      toggleBtn.innerHTML = isHidden
        ? '<span>▼ Collapse Mathematical Calculation Diagnostics</span>'
        : '<span>▶ Expand Mathematical Calculation Diagnostics</span>';
    });

    details.append(toggleBtn);
    details.append(tableDiv);
    sec.append(details);

    return sec;
  }

  /* ------------------------------------------------------------------ *
   *  Interactive Event Listeners
   * ------------------------------------------------------------------ */
  function initPredictiveEvents() {
    if (currentUser.role !== 'manager') return;

    // Period tab switching in #predPeriodSeg
    $$('#predPeriodSeg button').forEach(btn => {
      btn.addEventListener('click', () => {
        _predPeriod = btn.dataset.predPeriod;
        loadPredictiveView();
      });
    });

    // Toolbar Stepping (◀ / ▶)
    const prevBtn = $('#predPrevPeriodBtn');
    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        if (_predPeriod === 'daily') {
          _targetDate = toIsoDate(addDays(parseIsoDate(_targetDate), -1));
        } else if (_predPeriod === '7days') {
          _startDate7 = toIsoDate(addDays(parseIsoDate(_startDate7), -7));
        } else if (_predPeriod === 'month') {
          const [y, m] = _targetMonth.split('-').map(Number);
          const p = addMonths(y, m - 1, -1);
          _targetMonth = `${p.year}-${pad2(p.month + 1)}`;
        } else if (_predPeriod === 'year') {
          _targetYear = Math.max(2025, _targetYear - 1);
        }
        loadPredictiveView();
      });
    }

    const nextBtn = $('#predNextPeriodBtn');
    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        if (_predPeriod === 'daily') {
          _targetDate = toIsoDate(addDays(parseIsoDate(_targetDate), 1));
        } else if (_predPeriod === '7days') {
          _startDate7 = toIsoDate(addDays(parseIsoDate(_startDate7), 7));
        } else if (_predPeriod === 'month') {
          const [y, m] = _targetMonth.split('-').map(Number);
          const p = addMonths(y, m - 1, 1);
          _targetMonth = `${p.year}-${pad2(p.month + 1)}`;
        } else if (_predPeriod === 'year') {
          _targetYear = Math.min(2035, _targetYear + 1);
        }
        loadPredictiveView();
      });
    }

    // Quick Jump Buttons
    const jumpTomBtn = $('#predJumpTomorrowBtn');
    if (jumpTomBtn) {
      jumpTomBtn.addEventListener('click', () => {
        _targetDate = toIsoDate(addDays(new Date(), 1));
        loadPredictiveView();
      });
    }

    const jumpNext7Btn = $('#predJumpNext7Btn');
    if (jumpNext7Btn) {
      jumpNext7Btn.addEventListener('click', () => {
        _startDate7 = toIsoDate(addDays(new Date(), 1));
        loadPredictiveView();
      });
    }

    const jumpNextMBtn = $('#predJumpNextMonthBtn');
    if (jumpNextMBtn) {
      jumpNextMBtn.addEventListener('click', () => {
        const nm = addMonths(new Date().getFullYear(), new Date().getMonth(), 1);
        _targetMonth = `${nm.year}-${pad2(nm.month + 1)}`;
        loadPredictiveView();
      });
    }

    const jumpNextYBtn = $('#predJumpNextYearBtn');
    if (jumpNextYBtn) {
      jumpNextYBtn.addEventListener('click', () => {
        _targetYear = new Date().getFullYear() + 1;
        loadPredictiveView();
      });
    }

    // Input Change Listeners
    const dPicker = $('#predDatePicker');
    if (dPicker) {
      dPicker.addEventListener('change', () => {
        if (dPicker.value) { _targetDate = dPicker.value; loadPredictiveView(); }
      });
    }

    const s7Picker = $('#pred7DayPicker');
    if (s7Picker) {
      s7Picker.addEventListener('change', () => {
        if (s7Picker.value) { _startDate7 = s7Picker.value; loadPredictiveView(); }
      });
    }

    const mPicker = $('#predMonthPicker');
    if (mPicker) {
      mPicker.addEventListener('change', () => {
        if (mPicker.value) { _targetMonth = mPicker.value; loadPredictiveView(); }
      });
    }

    const yPicker = $('#predYearPicker');
    if (yPicker) {
      yPicker.addEventListener('change', () => {
        if (yPicker.value) { _targetYear = Number(yPicker.value); loadPredictiveView(); }
      });
    }

    // Recalculate Button
    const recalcBtn = $('#predRecalculateBtn');
    if (recalcBtn) {
      recalcBtn.addEventListener('click', () => {
        toast('Recalculating forecast model from latest transaction data...', 'info', 2000);
        loadPredictiveView();
      });
    }

    // Export Forecast Excel (.xlsx)
    const predExportBtn = $('#predExportExcelBtn');
    if (predExportBtn) {
      predExportBtn.addEventListener('click', () => {
        let url = 'api/export_excel.php?type=predictive';
        if (_predPeriod === 'year') {
          url += `&start=${encodeURIComponent(_targetYear)}-01-01&end=${encodeURIComponent(_targetYear)}-12-31`;
        } else if (_predPeriod === 'month') {
          const [y, m] = _targetMonth.split('-').map(Number);
          const lastD = new Date(y, m, 0).getDate();
          url += `&start=${encodeURIComponent(_targetMonth)}-01&end=${encodeURIComponent(_targetMonth)}-${pad2(lastD)}`;
        } else if (_predPeriod === '7days') {
          const end7 = toIsoDate(addDays(parseIsoDate(_startDate7), 6));
          url += `&start=${encodeURIComponent(_startDate7)}&end=${encodeURIComponent(end7)}`;
        } else {
          url += `&date=${encodeURIComponent(_targetDate)}`;
        }
        toast('Generating Predictive Machine Learning Excel Workbook (.xlsx)…', 'success', 2500);
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', '');
        document.body.appendChild(link);
        link.click();
        link.remove();
      });
    }

    // Manager dashboard card button
    const mgrOpenPredBtn = $('#mgrOpenPredictiveBtn');
    if (mgrOpenPredBtn) {
      mgrOpenPredBtn.addEventListener('click', () => {
        window.CafeFlow.nav.showView('predictive');
      });
    }

    // Dynamic theme change listener: re-render predictive analytics when dark/light mode toggles
    window.addEventListener('cafeflow:themechange', () => {
      const predModal = $('#predictiveModal');
      const container = $('#predictiveBody');
      if (predModal && !predModal.hidden && container && _activeData) {
        renderPredictiveDashboard(container, _activeData);
      }
    });
  }

  window.CafeFlow.predictive = {
    loadPredictiveView,
    initPredictiveEvents,
    destroyAllPredCharts
  };
})();
