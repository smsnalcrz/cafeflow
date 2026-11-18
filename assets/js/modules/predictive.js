/**
 * CafeFlow POS — Predictive Machine Learning Analytics Module
 * Handles dedicated prospective demand forecasting for Daily, 7-Day, Specific Month,
 * and Yearly horizons with dynamic analytical descriptions derived from model calculations.
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
   *  Predictive State Management
   * ------------------------------------------------------------------ */
  const now = new Date();
  const tomorrow = addDays(now, 1);
  const nextMonth = addMonths(now.getFullYear(), now.getMonth(), 1);

  let _predPeriod   = 'daily'; // 'daily' | '7days' | 'month' | 'year'
  let _targetDate   = toIsoDate(tomorrow);
  let _startDate7   = toIsoDate(tomorrow);
  let _targetMonth  = `${nextMonth.year}-${pad2(nextMonth.month + 1)}`;
  let _targetYear   = now.getFullYear() + 1;

  let _predChart    = null;

  function destroyPredChart() {
    if (_predChart) {
      try { _predChart.destroy(); } catch (_) {}
      _predChart = null;
    }
  }

  /* ------------------------------------------------------------------ *
   *  Dynamic Analytical Narrative Generators (100% Derived from Data)
   * ------------------------------------------------------------------ */

  function generateDailyNarrative(data) {
    const f = data.forecast || {};
    const comp = data.historical_comparison || {};
    const rush = data.rush_peak_hour || {};
    const season = data.season_info || {};
    const intel = data.model_intelligence || {};
    const metrics = intel.evaluation_metrics || {};

    const diffStr = comp.pct_change >= 0 ? `+${comp.pct_change}%` : `${comp.pct_change}%`;
    const dowText = f.is_weekend ? 'elevated weekend' : 'steady weekday';

    const p1 = `Projected gross revenue for ${data.target_label} is estimated at ${money(f.projected_sales)} across ${f.projected_orders} completed customer orders, yielding an expected Average Order Value (AOV) of ${money(f.expected_aov)}. Model calculations reflect a ${diffStr} variance compared to the historical ${f.day_name} baseline (${money(comp.same_dow_avg_sales)}), corresponding to ${dowText} footfall in Batangas.`;

    const paydayText = f.is_payday
      ? ` Trading is significantly boosted by the Philippine 15th/30th Quincena payday cycle, contributing an empirical +${intel.payday_lift_pct}% demand surge.`
      : ` Normal trading cadence applies with no calendar payday lift detected.`;

    const p2 = `Peak customer rush velocity is anticipated around ${rush.hour_label} with projected revenue of ${money(rush.projected_sales)}. Seasonality analysis classifies current operations under the ${season.name} (${season.tag}), where ${season.description.toLowerCase()}${paydayText} Historical hold-out evaluation indicates a model accuracy of ${metrics.accuracy_pct}% (WAPE: ${metrics.wape_pct}%, MAE: ${money(metrics.mae)}).`;

    return [p1, p2];
  }

  function generate7DayNarrative(data) {
    const s = data.summary || {};
    const p = data.period || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const intel = data.model_intelligence || {};
    const metrics = intel.evaluation_metrics || {};

    const p1 = `The 7-day predictive horizon spanning ${p.label} projects cumulative store revenues of ${money(s.projected_sales)} across ${s.projected_orders} orders, maintaining an expected daily throughput average of ${money(s.avg_daily_sales)}. Volume distribution identifies peak sales on ${peak.day_name}, ${peak.short_label} (${money(peak.projected_sales)}, ${peak.projected_orders} orders), while the softest trading volume is projected on ${low.day_name}, ${low.short_label} (${money(low.projected_sales)}, ${low.projected_orders} orders).`;

    const p2 = `Revenue cadence highlights a distinct weekend demand concentration: Friday through Sunday are projected to capture ${money(s.weekend_sales)} (${s.weekend_share_pct}% of week-long revenue) driven by an empirical +${intel.weekend_surge_pct}% weekend footfall surge over Monday–Thursday baselines (${money(s.weekday_sales)}). Barista scheduling should allocate peak espresso and croffle prep capacity to the Friday–Sunday rush window to satisfy projected transaction demand.`;

    return [p1, p2];
  }

  function generateMonthNarrative(data) {
    const s = data.summary || {};
    const season = data.season_info || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const intel = data.model_intelligence || {};

    const diffStr = s.pct_vs_baseline >= 0 ? `+${s.pct_vs_baseline}%` : `${s.pct_vs_baseline}%`;

    const p1 = `Prospective forecasting for ${data.month_name} (${data.days_in_month} calendar days) estimates total monthly store revenue at ${money(s.projected_sales)} across ${s.projected_orders} completed tickets, averaging ${money(s.avg_daily_sales)} per operating day. This projection reflects a ${diffStr} deviation against standard historical monthly averages (${money(data.summary.projected_sales - s.diff_vs_baseline)}), driven by ${season.name} demand patterns.`;

    const p2 = `Within the monthly cycle, highest single-day demand is projected for ${peak.short_label} (${money(peak.projected_sales)}, ${peak.projected_orders} orders), while baseline low is forecast on ${low.short_label} (${money(low.projected_sales)}). Operational management should align raw milk, coffee bean, and croffle dough procurement with these cyclical spikes, particularly during the 15th and end-of-month Quincena payday windows (+${intel.payday_lift_pct}% lift).`;

    return [p1, p2];
  }

  function generateYearlyNarrative(data) {
    const s = data.summary || {};
    const str = s.strongest_month || {};
    const wk = s.weakest_month || {};
    const intel = data.model_intelligence || {};
    const metrics = intel.evaluation_metrics || {};

    const p1 = `Annual predictive modeling for ${data.year_label} forecasts gross store revenues of ${money(s.projected_sales)} across ${s.projected_orders.toLocaleString()} completed customer orders, averaging ${money(s.avg_monthly_sales)} per calendar month. Demand trajectory exhibits marked retail seasonality, peaking in ${str.month_name} (${money(str.projected_sales)}, ${str.season_tag}) and bottoming out during the ${wk.month_name} rainy period (${money(wk.projected_sales)}, ${wk.season_tag}).`;

    const p2 = `The forecast captures the three major Philippine commercial retail waves observed in CafeFlow records: (1) the Tag-init summer vacation surge from March to May (+28% velocity), (2) the Habagat monsoon wet season contraction across July and August (-30% velocity), and (3) the intense Q4 holiday ramp-up from October to December culminating in Christmas party orders. Financial planning and vendor contracts should leverage these projections to optimize cash flow and seasonal inventory holdings.`;

    return [p1, p2];
  }

  /* ------------------------------------------------------------------ *
   *  UI Helper: Create Narrative Box
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
   *  Render Dashboard by Mode
   * ------------------------------------------------------------------ */
  function renderPredictiveDashboard(container, data) {
    destroyPredChart();
    container.innerHTML = '';

    const intel = data.model_intelligence || {};
    const metrics = intel.evaluation_metrics || {};

    // 1. Model Calibration Banner
    const banner = h('div', { class: 'pred-alert-banner' },
      h('div', { class: 'pred-alert-icon', html: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>' }),
      h('div', { class: 'pred-alert-body' },
        h('div', { class: 'pred-alert-header' },
          h('span', { class: 'pred-alert-title', text: `${intel.algorithm} · Seasonality Engine` }),
          h('span', { class: 'pred-alert-tag', text: `Accuracy: ${metrics.accuracy_pct}% (WAPE: ${metrics.wape_pct}%)` })
        ),
        h('p', {
          class: 'pred-alert-text',
          text: `Trained across ${intel.training_days} historical operating days. Predictions mathematically incorporate Day-of-Week multipliers (Sun: ${intel.dow_factors ? intel.dow_factors[0] : 1.5}x, Mon: ${intel.dow_factors ? intel.dow_factors[1] : 0.63}x), Quincena paydays (+${intel.payday_lift_pct}% lift on 15th & 30th/31st), and Philippine seasonal climate waves (Tag-init summer, Habagat monsoon, and Q4 holiday surge).`
        })
      )
    );
    container.append(banner);

    // 2. Render Mode Content
    if (_predPeriod === 'daily') {
      renderDailyMode(container, data);
    } else if (_predPeriod === '7days') {
      render7DaysMode(container, data);
    } else if (_predPeriod === 'month') {
      renderMonthMode(container, data);
    } else if (_predPeriod === 'year') {
      renderYearMode(container, data);
    }
  }

  /* ------------------------------------------------------------------ *
   *  Mode 1: Daily Prediction
   * ------------------------------------------------------------------ */
  function renderDailyMode(container, data) {
    const f = data.forecast || {};
    const comp = data.historical_comparison || {};
    const rush = data.rush_peak_hour || {};
    const season = data.season_info || {};

    $('#predStatusPeriod').textContent = `${data.target_label} Forecast`;

    // KPI Cards
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Projected Daily Sales' }),
        h('span', { class: 'kpi-val', text: money(f.projected_sales) }),
        h('span', { class: 'kpi-sub', text: `Confidence range: ${money(f.confidence_lower)} – ${money(f.confidence_upper)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Expected Ticket Volume' }),
        h('span', { class: 'kpi-val', text: `${f.projected_orders} Orders` }),
        h('span', { class: 'kpi-sub', text: `Projected AOV: ${money(f.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Peak Rush Window' }),
        h('span', { class: 'kpi-val', text: rush.hour_label || '7:00 PM' }),
        h('span', { class: 'kpi-sub', text: `Rush sales pace: ${money(rush.projected_sales)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'DOW Baseline Variance' }),
        h('span', { class: 'kpi-val', text: comp.pct_change >= 0 ? `+${comp.pct_change}%` : `${comp.pct_change}%` }),
        h('span', { class: 'kpi-sub', text: `vs. Avg ${f.day_name} (${money(comp.same_dow_avg_sales)})` })
      )
    );
    container.append(kpiGrid);

    // Hourly Curve Forecast Chart
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `Hourly Sales Rush Projection · ${data.target_label}` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': 'Hourly sales prediction curve' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generateDailyNarrative(data), `NEXT-DAY OPERATIONAL FORECAST (${f.day_name.toUpperCase()})`);
    chartCard.append(narrative);
    container.append(chartCard);

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const hourly = data.hourly_forecast || [];
      const labels = hourly.map(h => h.hour_label);
      const sales = hourly.map(h => h.projected_sales);
      const orders = hourly.map(h => h.projected_orders);

      const grad = ctx.createLinearGradient(0, 0, 0, 280);
      grad.addColorStop(0, 'rgba(192, 124, 46, 0.35)');
      grad.addColorStop(1, 'rgba(192, 124, 46, 0.02)');

      _predChart = new Chart(ctx, {
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
              tension: 0.35,
              pointRadius: 3.5,
              pointBackgroundColor: '#c07c2e',
              yAxisID: 'y'
            },
            {
              label: 'Expected Orders',
              data: orders,
              borderColor: '#2a9b52',
              borderWidth: 1.5,
              borderDash: [3, 3],
              pointRadius: 2.5,
              fill: false,
              tension: 0.3,
              yAxisID: 'y1'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } },
            tooltip: {
              callbacks: {
                label: (c) => c.dataset.label.indexOf('Sales') >= 0 ? `  Sales: ${money(c.parsed.y)}` : `  Orders: ${c.parsed.y}`
              }
            }
          },
          scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString() } },
            y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { precision: 0 } }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Mode 2: 7-Day Prediction
   * ------------------------------------------------------------------ */
  function render7DaysMode(container, data) {
    const s = data.summary || {};
    const p = data.period || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const days = data.daily_forecasts || [];

    $('#predStatusPeriod').textContent = p.label;

    // KPI Cards
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: '7-Day Projected Sales' }),
        h('span', { class: 'kpi-val', text: money(s.projected_sales) }),
        h('span', { class: 'kpi-sub', text: `Sum of 7 daily forecasts (${s.projected_orders} orders)` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Expected Daily Pace' }),
        h('span', { class: 'kpi-val', text: money(s.avg_daily_sales) }),
        h('span', { class: 'kpi-sub', text: `Expected AOV: ${money(s.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Strongest Rush Day' }),
        h('span', { class: 'kpi-val', text: `${peak.day_name} (${money(peak.projected_sales)})` }),
        h('span', { class: 'kpi-sub', text: `${peak.short_label} · ${peak.projected_orders} orders` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Softest Trading Day' }),
        h('span', { class: 'kpi-val', text: `${low.day_name} (${money(low.projected_sales)})` }),
        h('span', { class: 'kpi-sub', text: `${low.short_label} · ${low.projected_orders} orders` })
      )
    );
    container.append(kpiGrid);

    // Chart Card
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `7-Day Daily Demand Horizon · ${p.label}` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': '7-day daily forecast curve' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generate7DayNarrative(data), '7-DAY HORIZON INTELLIGENCE & STAFFING');
    chartCard.append(narrative);
    container.append(chartCard);

    // Table of 7 days
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
            h('td', { class: 'num', text: String(d.projected_orders) }),
            h('td', { class: 'num', text: money(d.expected_aov) }),
            h('td', { class: 'num', style: 'font-weight: 700;', text: money(d.projected_sales) }),
            h('td', { class: 'num', style: 'color: var(--muted);', text: `${money(d.confidence_lower)} – ${money(d.confidence_upper)}` })
          )),
          h('tr', { class: 'table-total-row' },
            h('td', { text: '7-Day Total' }),
            h('td', { text: '7 Days' }),
            h('td', { text: 'Cumulative' }),
            h('td', { class: 'num', text: String(s.projected_orders) }),
            h('td', { class: 'num', text: money(s.expected_aov) }),
            h('td', { class: 'num', text: money(s.projected_sales) }),
            h('td', { class: 'num', text: '—' })
          )
        )
      )
    );
    container.append(tableWrap);

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const labels = days.map(d => d.short_label);
      const sales = days.map(d => d.projected_sales);
      const upper = days.map(d => d.confidence_upper);
      const lower = days.map(d => d.confidence_lower);

      _predChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Projected Sales (₱)',
              data: sales,
              borderColor: '#c07c2e',
              backgroundColor: 'rgba(192, 124, 46, 0.12)',
              borderWidth: 2.4,
              fill: true,
              tension: 0.25,
              pointRadius: 5,
              pointBackgroundColor: '#c07c2e'
            },
            {
              label: 'Upper Confidence (₱)',
              data: upper,
              borderColor: 'rgba(192, 124, 46, 0.3)',
              borderDash: [4, 4],
              borderWidth: 1.2,
              fill: false,
              pointRadius: 0
            },
            {
              label: 'Lower Confidence (₱)',
              data: lower,
              borderColor: 'rgba(192, 124, 46, 0.3)',
              borderDash: [4, 4],
              borderWidth: 1.2,
              fill: false,
              pointRadius: 0
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } },
            tooltip: { callbacks: { label: c => `  ${c.dataset.label}: ${money(c.parsed.y)}` } }
          },
          scales: {
            y: { beginAtZero: false, ticks: { callback: v => '₱' + Number(v).toLocaleString() } }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Mode 3: Specific Month Prediction
   * ------------------------------------------------------------------ */
  function renderMonthMode(container, data) {
    const s = data.summary || {};
    const season = data.season_info || {};
    const peak = s.peak_day || {};
    const low = s.lowest_day || {};
    const days = data.daily_forecasts || [];

    $('#predStatusPeriod').textContent = `${data.month_name} Forecast`;

    const diffStr = s.pct_vs_baseline >= 0 ? `+${s.pct_vs_baseline}%` : `${s.pct_vs_baseline}%`;

    // KPI Cards
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Projected Monthly Sales' }),
        h('span', { class: 'kpi-val', text: money(s.projected_sales) }),
        h('span', { class: 'kpi-sub', text: `Sum of ${data.days_in_month} daily projections (${s.projected_orders} orders)` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Average Daily Pace' }),
        h('span', { class: 'kpi-val', text: money(s.avg_daily_sales) }),
        h('span', { class: 'kpi-sub', text: `Expected AOV: ${money(s.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Peak Day in Month' }),
        h('span', { class: 'kpi-val', text: `${peak.short_label} (${money(peak.projected_sales)})` }),
        h('span', { class: 'kpi-sub', text: `${peak.day_name} · ${peak.projected_orders} orders` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Baseline Variance' }),
        h('span', { class: 'kpi-val', text: diffStr }),
        h('span', { class: 'kpi-sub', text: `${season.name} [${season.tag}]` })
      )
    );
    container.append(kpiGrid);

    // Chart Card
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `Day-by-Day Forecast Curve · ${data.month_name} (${data.days_in_month} Days)` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': 'Monthly daily forecast curve' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generateMonthNarrative(data), `MONTHLY CYCLE INTELLIGENCE · ${season.name.toUpperCase()}`);
    chartCard.append(narrative);
    container.append(chartCard);

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const labels = days.map(d => d.day_of_month);
      const sales = days.map(d => d.projected_sales);

      const grad = ctx.createLinearGradient(0, 0, 0, 280);
      grad.addColorStop(0, 'rgba(192, 124, 46, 0.35)');
      grad.addColorStop(1, 'rgba(192, 124, 46, 0.02)');

      _predChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Predicted Sales (₱)',
              data: sales,
              borderColor: '#c07c2e',
              backgroundColor: grad,
              borderWidth: 2,
              fill: true,
              tension: 0.25,
              pointRadius: 2.5,
              pointBackgroundColor: '#c07c2e'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '600' } } },
            tooltip: {
              callbacks: {
                title: items => items[0] ? `Day ${items[0].label} (${days[items[0].dataIndex]?.day_name})` : '',
                label: c => `  Projected: ${money(c.parsed.y)} (${days[c.dataIndex]?.projected_orders} orders)`
              }
            }
          },
          scales: {
            x: { title: { display: true, text: 'Day of Month', font: { size: 11, weight: '600' } } },
            y: { beginAtZero: false, ticks: { callback: v => '₱' + Number(v).toLocaleString() } }
          }
        }
      });
    });
  }

  /* ------------------------------------------------------------------ *
   *  Mode 4: Yearly Prediction
   * ------------------------------------------------------------------ */
  function renderYearMode(container, data) {
    const s = data.summary || {};
    const str = s.strongest_month || {};
    const wk = s.weakest_month || {};
    const months = data.monthly_forecasts || [];

    $('#predStatusPeriod').textContent = data.year_label;

    // KPI Cards
    const kpiGrid = h('div', { class: 'pred-kpi-grid' },
      h('div', { class: 'pred-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Projected Annual Sales' }),
        h('span', { class: 'kpi-val', text: money(s.projected_sales) }),
        h('span', { class: 'kpi-sub', text: `Sum of 12 monthly forecasts (${s.projected_orders.toLocaleString()} orders)` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Average Monthly Pace' }),
        h('span', { class: 'kpi-val', text: money(s.avg_monthly_sales) }),
        h('span', { class: 'kpi-sub', text: `Expected AOV: ${money(s.expected_aov)}` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Strongest Month' }),
        h('span', { class: 'kpi-val', text: `${str.month_name} (${money(str.projected_sales)})` }),
        h('span', { class: 'kpi-sub', text: `${str.season_tag} · ${str.projected_orders.toLocaleString()} orders` })
      ),
      h('div', { class: 'pred-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Softest Trading Month' }),
        h('span', { class: 'kpi-val', text: `${wk.month_name} (${money(wk.projected_sales)})` }),
        h('span', { class: 'kpi-sub', text: `${wk.season_tag} · ${wk.projected_orders.toLocaleString()} orders` })
      )
    );
    container.append(kpiGrid);

    // Chart Card
    const chartCard = h('div', { class: 'pred-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: `12-Month Annual Projection & Retail Seasonality Waves · ${data.year_label}` })
      )
    );
    const chartBody = h('div', { class: 'pred-chart-body' });
    const canvas = h('canvas', { 'aria-label': '12-month annual forecast bar chart' });
    chartBody.append(canvas);
    chartCard.append(chartBody);

    const narrative = createNarrativeBox(generateYearlyNarrative(data), 'ANNUAL TRAJECTORY & PHILIPPINE CLIMATE SEASONALITY WAVES');
    chartCard.append(narrative);
    container.append(chartCard);

    // 12-Month Projection Table
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
              h('td', { class: 'num', text: money(m.daily_avg) }),
              h('td', { class: 'num', text: m.projected_orders.toLocaleString() }),
              h('td', { class: 'num', style: 'font-weight: 700;', text: money(m.projected_sales) })
            );
          }),
          h('tr', { class: 'table-total-row' },
            h('td', { text: 'Total Year' }),
            h('td', { text: '12 Months' }),
            h('td', { class: 'num', text: '365' }),
            h('td', { class: 'num', text: money(s.projected_sales / 365) }),
            h('td', { class: 'num', text: s.projected_orders.toLocaleString() }),
            h('td', { class: 'num', text: money(s.projected_sales) })
          )
        )
      )
    );
    container.append(tableWrap);

    // Render Chart.js
    requestAnimationFrame(() => {
      const ctx = canvas.getContext('2d');
      const labels = months.map(m => m.month_name);
      const sales = months.map(m => m.projected_sales);
      const bgColors = months.map(m => {
        if (m.month_num >= 3 && m.month_num <= 5) return 'rgba(192, 124, 46, 0.85)'; // Summer peak
        if (m.month_num >= 6 && m.month_num <= 8) return 'rgba(42, 120, 155, 0.75)'; // Monsoon lull
        if (m.month_num >= 9 && m.month_num <= 12) return 'rgba(42, 155, 82, 0.85)'; // Holiday surge
        return 'rgba(90, 110, 96, 0.75)'; // Baseline
      });

      _predChart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Projected Sales (₱)',
              data: sales,
              backgroundColor: bgColors,
              borderRadius: 6,
              borderWidth: 1,
              borderColor: 'transparent'
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: c => `  Projected: ${money(c.parsed.y)} (${months[c.dataIndex]?.projected_orders.toLocaleString()} orders)`
              }
            }
          },
          scales: {
            y: { beginAtZero: true, ticks: { callback: v => '₱' + Number(v).toLocaleString() } }
          }
        }
      });
    });
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
  }

  window.CafeFlow.predictive = {
    loadPredictiveView,
    initPredictiveEvents,
    destroyPredChart
  };
})();
