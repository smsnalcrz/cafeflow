/**
 * CafeFlow POS - Reports, Analytics & Excel Export Module
 * Handles sales reporting, date range pickers (Today, Date, 7-Day, Month),
 * visual calendar drawer, Chart.js financial charts, searchable transactions, and Excel exports.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, money, escapeHtml, h, toast, emptyState, pad2, toIsoDate, toIsoMonth, parseIsoDate, addDays, addMonths, formatHour, dayLabel } = window.CafeFlow.utils;
  const currentUser = (window.CafeFlow.config && window.CafeFlow.config.currentUser) || window.currentUser || window.CafeFlowUser || { role: 'barista' };
  const api = window.CafeFlow.api;
  const state = window.CafeFlow.state;
  const viewReceipt = (txId) => window.CafeFlow.receipt ? window.CafeFlow.receipt.viewReceipt(txId) : null;

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

      : [emptyState({
          icon: '🏆',
          title: 'No Items Sold',
          desc: 'No beverage or food orders were recorded in this period.',
          compact: true
        })];



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

        emptyState({
          icon: '📊',
          title: 'No Breakdown Data',
          desc: 'No order activity recorded to compute hourly or daily trends.',
          compact: true
        })

      );

    }



    body.append(h('div', { class: 'report-cols' },

      h('section', {}, h('h3', { text: 'Top Sellers (Cups & Pieces)' }), top),

      breakdownSection

    ));



    // Daily Transaction Records & E-Receipt Section

    const txSection = h('section', { class: 'tx-section' });

    body.append(txSection);



    const isMultiDayRange = Boolean(r && r.start_date && r.end_date && r.start_date !== r.end_date);
    let txUrl = 'transactions_api.php';
    if (isMultiDayRange) {
      txUrl += `?start=${encodeURIComponent(r.start_date)}&end=${encodeURIComponent(r.end_date)}`;
    } else if (r && r.start_date) {
      txUrl += `?date=${encodeURIComponent(r.start_date)}`;
    } else if (r && r.range) {
      txUrl += `?range=${encodeURIComponent(r.range)}`;
    }

    try {
      const txData = await api(txUrl);
      renderTransactionRecords(txSection, txData, r);
    } catch (_) {
      txSection.textContent = '';
      txSection.append(h('p', { class: 'muted', text: 'Could not load individual transaction records.' }));
    }

  }



  /* ------------------------------------------------------------------ *

   *  Daily Transaction Records List

   * ------------------------------------------------------------------ */

  function renderTransactionRecords(container, txData, r) {
    container.textContent = '';
    const records = txData.transactions || [];
    const isMultiDay = r && r.start_date && r.end_date && r.start_date !== r.end_date;

    const headTitle = h('h3', { text: `📋 Transaction History (${records.length} order${records.length === 1 ? '' : 's'})` });
    const head = h('div', { class: 'tx-section-head' },
      headTitle,
      h('div', { style: 'display: flex; gap: 8px;' },
        h('button', {
          type: 'button',
          class: 'btn small primary',
          text: '📥 Export Excel (.xlsx)',
          title: 'Export executive multi-sheet presentation report to Excel (.xlsx)',
          onclick: () => exportTransactions(r.start_date, r.end_date)
        })
      )
    );
    container.append(head);

    if (!records.length) {
      const periodDesc = isMultiDay ? 'this date range' : 'this date';
      container.append(emptyState({
        icon: '📋',
        title: 'No Transaction Records',
        desc: `No completed or pending transactions were recorded for ${periodDesc}.`,
        tip: '💡 Tip: Select another date or choose "7-Day Report" / "Specific Month" above.'
      }));
      return;
    }

    // Filter toolbar (Search input + Filter count badge)
    let currentQuery = '';
    const filterBadge = h('span', { class: 'tx-filter-meta', text: `${records.length} order${records.length === 1 ? '' : 's'} total` });

    const searchInput = h('input', {
      type: 'text',
      class: 'tx-search-input',
      placeholder: '🔍 Search receipt #, item, payment, status, reference...',
      value: '',
      autocomplete: 'off',
      'aria-label': 'Search transaction history'
    });

    const clearBtn = h('button', {
      type: 'button',
      class: 'tx-search-clear',
      text: '✕',
      title: 'Clear search',
      onclick: () => {
        searchInput.value = '';
        currentQuery = '';
        clearBtn.hidden = true;
        applyFilter('');
        searchInput.focus();
      }
    });
    clearBtn.hidden = true;

    searchInput.addEventListener('input', e => {
      currentQuery = e.target.value.trim().toLowerCase();
      clearBtn.hidden = !currentQuery;
      applyFilter(currentQuery);
    });

    searchInput.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        searchInput.value = '';
        currentQuery = '';
        clearBtn.hidden = true;
        applyFilter('');
      }
    });

    const searchBox = h('div', { class: 'tx-search-box' },
      h('span', { class: 'tx-search-icon', text: '🔍' }),
      searchInput,
      clearBtn
    );

    const toolbar = h('div', { class: 'tx-toolbar' },
      searchBox,
      filterBadge
    );
    container.append(toolbar);

    // Scrollable Table Container
    const tableContainer = h('div', { class: 'tx-table-container' });

    const tbody = h('tbody');
    const table = h('table', { class: 'tx-table' },
      h('thead', {}, h('tr', {},
        h('th', { text: 'Receipt #' }),
        h('th', { text: isMultiDay ? 'Date & Time' : 'Time' }),
        h('th', { text: 'Items Ordered' }),
        h('th', { text: 'Payment' }),
        h('th', { text: 'Status' }),
        h('th', { class: 'num', text: 'Total' }),
        h('th', { class: 'act', text: 'Receipt' })
      )),
      tbody
    );
    tableContainer.append(table);

    // No Results Message
    const noResults = h('div', { class: 'tx-no-results' });
    noResults.hidden = true;
    tableContainer.append(noResults);

    container.append(tableContainer);

    function renderRow(tx) {
      const statusClass = tx.status.toLowerCase();
      const displayTime = isMultiDay ? (tx.formatted_datetime || `${tx.formatted_date || ''} ${tx.formatted_time}`.trim()) : tx.formatted_time;
      return h('tr', {},
        h('td', { class: 'tx-no', text: tx.receipt_no }),
        h('td', { text: displayTime, style: isMultiDay ? 'white-space: nowrap;' : '' }),
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
    }

    function applyFilter(q) {
      const filtered = records.filter(tx => {
        if (!q) return true;
        const receiptNo = (tx.receipt_no || '').toLowerCase();
        const txId = String(tx.transaction_id || '');
        const items = (tx.items_summary || '').toLowerCase();
        const pay = (tx.payment_method || '').toLowerCase();
        const status = (tx.status || '').toLowerCase();
        const ref = (tx.reference_number || '').toLowerCase();
        const dt = (tx.formatted_datetime || `${tx.formatted_date || ''} ${tx.formatted_time}`).toLowerCase();
        const total = String(tx.total_amount || '');

        return receiptNo.includes(q) ||
               txId.includes(q) ||
               items.includes(q) ||
               pay.includes(q) ||
               status.includes(q) ||
               ref.includes(q) ||
               dt.includes(q) ||
               total.includes(q);
      });

      tbody.textContent = '';
      if (filtered.length > 0) {
        table.hidden = false;
        noResults.hidden = true;
        filtered.forEach(tx => tbody.append(renderRow(tx)));
      } else {
        table.hidden = true;
        noResults.textContent = '';
        noResults.append(
          emptyState({
            icon: '🔍',
            title: `No transactions match "${searchInput.value.trim()}"`,
            desc: 'Search matches receipt #, drink names, payment method, or reference #.',
            actionText: '✕ Clear Search Filter',
            compact: true,
            onAction: () => {
              searchInput.value = '';
              currentQuery = '';
              clearBtn.hidden = true;
              applyFilter('');
              searchInput.focus();
            }
          })
        );
        noResults.hidden = false;
      }

      if (q) {
        filterBadge.textContent = `Showing ${filtered.length} of ${records.length} order${records.length === 1 ? '' : 's'}`;
        headTitle.textContent = `📋 Transaction History (${filtered.length} found)`;
      } else {
        filterBadge.textContent = `${records.length} order${records.length === 1 ? '' : 's'} total`;
        headTitle.textContent = `📋 Transaction History (${records.length} order${records.length === 1 ? '' : 's'})`;
      }
    }

    // Initial render
    applyFilter('');
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




  function initReportEvents() {
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
    const prevPeriodBtn = $('#prevPeriodBtn');
    if (prevPeriodBtn) prevPeriodBtn.addEventListener('click', () => stepPeriod(-1));
    const nextPeriodBtn = $('#nextPeriodBtn');
    if (nextPeriodBtn) nextPeriodBtn.addEventListener('click', () => stepPeriod(1));

    // Date inputs
    const rDatePicker = $('#reportDatePicker');
    if (rDatePicker) {
      rDatePicker.addEventListener('change', e => {
        if (e.target.value) {
          selectedDate = e.target.value;
          calYear = parseIsoDate(selectedDate).getFullYear();
          calMonth = parseIsoDate(selectedDate).getMonth();
          loadReport();
        }
      });
    }

    const r7DayPicker = $('#report7DayPicker');
    if (r7DayPicker) {
      r7DayPicker.addEventListener('change', e => {
        if (e.target.value) {
          selected7DayStart = e.target.value;
          calYear = parseIsoDate(selected7DayStart).getFullYear();
          calMonth = parseIsoDate(selected7DayStart).getMonth();
          loadReport();
        }
      });
    }

    const rMonthPicker = $('#reportMonthPicker');
    if (rMonthPicker) {
      rMonthPicker.addEventListener('change', e => {
        if (e.target.value) {
          selectedMonth = e.target.value;
          const [y, m] = selectedMonth.split('-').map(Number);
          calYear = y;
          calMonth = m - 1;
          loadReport();
        }
      });
    }

    // Jump buttons
    const jTodayBtn = $('#jumpTodayBtn');
    if (jTodayBtn) {
      jTodayBtn.addEventListener('click', () => {
        selectedDate = toIsoDate(new Date());
        calYear = new Date().getFullYear();
        calMonth = new Date().getMonth();
        loadReport();
      });
    }

    const jLast7Btn = $('#jumpLast7Btn');
    if (jLast7Btn) {
      jLast7Btn.addEventListener('click', () => {
        selected7DayStart = toIsoDate(addDays(new Date(), -6));
        calYear = new Date().getFullYear();
        calMonth = new Date().getMonth();
        loadReport();
      });
    }

    const jThisMonthBtn = $('#jumpThisMonthBtn');
    if (jThisMonthBtn) {
      jThisMonthBtn.addEventListener('click', () => {
        selectedMonth = toIsoMonth(new Date());
        calYear = new Date().getFullYear();
        calMonth = new Date().getMonth();
        loadReport();
      });
    }

    // Calendar drawer toggle
    const toggleCalBtn = $('#toggleCalBtn');
    if (toggleCalBtn) {
      toggleCalBtn.addEventListener('click', () => {
        calDrawerOpen = !calDrawerOpen;
        syncToolbarUI();
        if (calDrawerOpen) renderCalendar();
      });
    }

    const calCloseBtn = $('#calCloseBtn');
    if (calCloseBtn) {
      calCloseBtn.addEventListener('click', () => {
        calDrawerOpen = false;
        syncToolbarUI();
      });
    }

    const calPrevMonth = $('#calPrevMonth');
    if (calPrevMonth) {
      calPrevMonth.addEventListener('click', () => {
        calMonth--;
        if (calMonth < 0) {
          calMonth = 11;
          calYear--;
        }
        renderCalendar();
      });
    }

    const calNextMonth = $('#calNextMonth');
    if (calNextMonth) {
      calNextMonth.addEventListener('click', () => {
        calMonth++;
        if (calMonth > 11) {
          calMonth = 0;
          calYear++;
        }
        renderCalendar();
      });
    }

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
    const exportExcelBtn = $('#exportExcelBtn') || $('#exportTxBtn');
    if (exportExcelBtn) {
      exportExcelBtn.addEventListener('click', () => {
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
    }
  }

  window.loadReport = loadReport;
  window.exportTransactions = exportTransactions;

  window.CafeFlow.reports = {
    loadReport,
    loadReportData: loadReport,
    renderReport,
    renderCalendar,
    exportTransactions,
    destroyChart,
    buildHourlyDataset,
    renderLineChart,
    renderBarChart,
    initReportEvents
  };
})();
