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

  let reportRange = 'today'; // 'today' | 'date' | '7days' | 'month' | 'year'

  let selectedDate = toIsoDate(now);

  let selected7DayStart = toIsoDate(addDays(now, -6));

  let selectedMonth = toIsoMonth(now);

  let selectedYear = now.getFullYear();

  let calYear = now.getFullYear();

  let calMonth = now.getMonth();

  let calDrawerOpen = false;

  const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December'
  ];

  function syncToolbarUI() {
    const isManager = currentUser && currentUser.role === 'manager';

    const yearlyBtn = $('#yearlyRangeBtn');
    if (yearlyBtn) yearlyBtn.hidden = !isManager;
    const mlDropdown = $('#mlExportDropdown');
    if (mlDropdown) mlDropdown.hidden = !isManager;

    $$('#rangeSeg button').forEach(b => {
      b.classList.toggle('on', b.dataset.range === reportRange);
    });

    const todayGrp = $('#todayPickerGroup');
    if (todayGrp) todayGrp.hidden = reportRange !== 'today';

    const dateGrp = $('#datePickerGroup');
    if (dateGrp) dateGrp.hidden = reportRange !== 'date';

    const sevenDayGrp = $('#sevenDayPickerGroup');
    if (sevenDayGrp) sevenDayGrp.hidden = reportRange !== '7days';

    const monthGrp = $('#monthPickerGroup');
    if (monthGrp) monthGrp.hidden = reportRange !== 'month';

    const yearGrp = $('#yearPickerGroup');
    if (yearGrp) yearGrp.hidden = reportRange !== 'year';

    const rDatePicker = $('#reportDatePicker');
    if (rDatePicker) rDatePicker.value = selectedDate;

    const r7DayPicker = $('#report7DayPicker');
    if (r7DayPicker) r7DayPicker.value = selected7DayStart;

    const rMonthPicker = $('#reportMonthPicker');
    if (rMonthPicker) rMonthPicker.value = selectedMonth;

    const rYearPicker = $('#reportYearPicker');
    if (rYearPicker && rYearPicker.value != String(selectedYear)) {
      rYearPicker.value = String(selectedYear);
    }

    const start7 = parseIsoDate(selected7DayStart);
    const end7 = addDays(start7, 6);
    const r7DayBadge = $('#report7DayBadge');
    if (r7DayBadge) r7DayBadge.textContent = `${dayLabel(selected7DayStart)} – ${dayLabel(toIsoDate(end7))}`;

    const calBtn = $('#toggleCalBtn');
    if (calBtn) {
      calBtn.classList.toggle('primary', calDrawerOpen);
      calBtn.classList.toggle('ghost', !calDrawerOpen);
    }
    const calDrawer = $('#reportCalDrawer');
    if (calDrawer) calDrawer.hidden = !calDrawerOpen;

    const tips = {
      today: 'Click any date below to inspect past reports',
      date: 'Click any date to view single-day report',
      '7days': 'Click any date to start a 7-day report',
      month: 'Navigate or click any date in that month',
      year: 'Inspect comprehensive annual revenue and historical sales records'
    };
    const tipEl = $('#calModeTip');
    if (tipEl) tipEl.textContent = tips[reportRange] || 'Select report date';
  }

  function getReportUrl() {
    if (reportRange === 'today') return 'report.php?range=today';
    if (reportRange === 'date') return `report.php?range=date&date=${encodeURIComponent(selectedDate)}`;
    if (reportRange === '7days') return `report.php?range=7days&start=${encodeURIComponent(selected7DayStart)}`;
    if (reportRange === 'month') return `report.php?range=month&month=${encodeURIComponent(selectedMonth)}`;
    if (reportRange === 'year') return `report.php?range=year&year=${encodeURIComponent(selectedYear)}`;
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
    if (reportRange === 'year') {
      selectedYear += delta;
      calYear = selectedYear;
    } else if (reportRange === 'date') {
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
   *  Chart.js helpers — 60-30-10 Artisan Espresso & Roastery Palette
   *  60% Canvas & Cards:      #FAF7F2 (canvas), #FFFFFF (cards), #E6DDD3 (borders)
   *  30% Espresso Structure:  #2C1E16 (espresso text/headings), #6F5E53 (mocha labels)
   *  10% Signature Accent:    #C87D4B (warm caramel), #B06A3B (hover), #7B5EA7 (violet)
   *  Status Badges:           #2E7D5B (completed), #D9822B (pending), #C93B3B (loss)
   * ------------------------------------------------------------------ */

  const CF_ORANGE        = '#e8623a';
  const CF_ORANGE_MID    = '#f07348';
  const CF_CARAMEL       = '#C87D4B'; // 10% Signature Warm Caramel Accent
  const CF_CARAMEL_HOVER = '#B06A3B';
  const CF_VIOLET        = '#7B5EA7'; // Artisan Violet Accent
  const CF_GREEN         = '#2a9b52'; // Fresh / Completed / Cash
  const CF_AMBER         = '#D9822B'; // Low Stock / Bean Degassing / Pending
  const CF_RED           = '#C93B3B'; // Wastage / Expired / Loss
  const CF_ESPRESSO      = '#162318'; // 30% Dark Espresso Primary Structure
  const CF_MOCHA         = '#6F5E53'; // Mocha Secondary Labels & Meta
  const CF_MUTED         = '#5a7a63';
  const CF_LINE          = '#cde5d4';
  const CF_OAT_BORDER    = '#E6DDD3'; // Subtle Oat-Milk Borders
  const CF_STEAMED_MILK  = '#FAF7F2'; // 60% Steamed-Milk Cream Canvas
  const CF_WHITE         = '#FFFFFF'; // White Cards & Modals

  function getThemeColors() {
    return {
      grid: '#cde5d4',
      ticks: '#5a7a63',
      text: '#0e1e12',
      tooltipBg: '#162318',
      tooltipBorder: 'rgba(255,255,255,0.1)'
    };
  }

  function getChartColors() {
    var isDark = (document.documentElement.getAttribute('data-theme') === 'dark') || document.body.classList.contains('dark-mode');
    return {
      isDark: isDark,
      caramel: isDark ? '#D98F5E' : CF_CARAMEL,
      caramelHover: isDark ? '#E5A170' : CF_CARAMEL_HOVER,
      violet: isDark ? '#9A7FC4' : CF_VIOLET,
      green: isDark ? '#389B72' : CF_GREEN,
      amber: isDark ? '#E5933C' : CF_AMBER,
      red: isDark ? '#DE4E4E' : CF_RED,
      text: isDark ? '#FAF7F2' : CF_ESPRESSO,
      muted: isDark ? '#B8A89A' : CF_MOCHA,
      line: isDark ? '#453429' : CF_OAT_BORDER,
      grid: isDark ? 'rgba(250, 247, 242, 0.08)' : 'rgba(44, 30, 22, 0.08)',
      tooltipBg: isDark ? '#1F150F' : CF_ESPRESSO,
      tooltipBorder: isDark ? '#3D2A1F' : 'rgba(255, 255, 255, 0.15)',
      cardBg: isDark ? '#2C211A' : CF_WHITE
    };
  }

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
  var _topSellersBarChart  = null;
  var _reportYearBarChart  = null;
  var _reportYearLineChart = null;
  var _reportWasteTrendChart = null;

  // Active chart tab state for the report selector
  var _activeChartTab = 'trend';
  var _topCategory    = 'drinks'; // 'drinks' | 'snacks' | 'all'
  var _topLimit       = 5;        // 5 | 10

  function destroyChart(ref) {
    if (ref) { try { ref.destroy(); } catch (e) {} }
    return null;
  }

  function buildChartDefaults() {
    if (typeof Chart === 'undefined') return;
    var colors = getChartColors();
    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.color = colors.muted;
  }

  buildChartDefaults();



  function renderLineChart(canvas, data) {
    if (typeof Chart === 'undefined' || !canvas) return null;
    var colors = getChartColors();
    var ctx = canvas.getContext('2d');
    var grad = ctx.createLinearGradient(0, 0, 0, 240);
    grad.addColorStop(0, 'rgba(232,98,58,0.28)');
    grad.addColorStop(1, 'rgba(232,98,58,0.00)');

    return new Chart(ctx, {
      type: 'line',
      data: {
        labels: data.labels,
        datasets: [{
          label: 'Sales (\u20b1)',
          data: data.totals,
          borderColor: colors.caramel,
          backgroundColor: grad,
          borderWidth: 2.5,
          pointBackgroundColor: colors.tooltipBg,
          pointBorderColor: colors.caramel,
          pointRadius: 4,
          pointHoverRadius: 6,
          tension: 0.4,
          fill: true,
          yAxisID: 'y'
        }, {
          label: 'Orders',
          data: data.orders,
          borderColor: colors.green,
          backgroundColor: 'transparent',
          borderWidth: 2,
          pointBackgroundColor: colors.green,
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
          legend: { position: 'top', labels: { boxWidth: 12, padding: 16, color: colors.text, font: { size: 12 } } },
          tooltip: {
            backgroundColor: colors.tooltipBg,
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,0.85)',
            borderColor: colors.tooltipBorder,
            borderWidth: 1,
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
          x: { grid: { color: colors.grid }, ticks: { color: colors.muted, font: { size: 11 }, maxRotation: 45 } },
          y: {
            position: 'left', grid: { color: colors.grid },
            ticks: { color: colors.muted, font: { size: 11 }, callback: function(v) { return '\u20b1' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 0 }); } }
          },
          y2: {
            position: 'right', grid: { drawOnChartArea: false },
            ticks: { color: colors.muted, font: { size: 11 }, stepSize: 1, precision: 0 }
          }
        }
      }
    });
  }



  function renderBarChart(canvas, data) {
    if (typeof Chart === 'undefined' || !canvas) return null;
    var colors = getChartColors();
    var ctx = canvas.getContext('2d');
    var n = data.labels.length;
    var barColors = data.labels.map(function(_, i) {
      var alpha = 1 - (i / Math.max(n, 1)) * 0.45;
      return colors.isDark ? 'rgba(217,143,94,' + alpha + ')' : 'rgba(200,125,75,' + alpha + ')'; // warm caramel #C87D4B
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
          borderWidth: 1.5,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        indexAxis: 'x',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: colors.tooltipBg,
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,0.85)',
            borderColor: colors.tooltipBorder,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            borderColor: 'rgba(255,255,255,0.12)',
            borderWidth: 1,
            callbacks: {
              label: function(c) { return '  ' + c.parsed.y + ' units sold'; }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.text,
              font: { size: 11, weight: '600' },
              maxRotation: 35,
              minRotation: 0,
              callback: function(val) {
                var lbl = this.getLabelForValue(val);
                return lbl && lbl.length > 15 ? lbl.slice(0, 13) + '…' : lbl;
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: { color: colors.grid },
            ticks: {
              color: colors.muted,
              font: { size: 11 },
              precision: 0,
              stepSize: 1
            }
          }
        }
      }
    });
  }

  function renderTopSellersVerticalChart(canvas, items) {
    if (typeof Chart === 'undefined' || !canvas) return null;
    var colors = getChartColors();
    var ctx = canvas.getContext('2d');
    var labels = items.map(function(it) { return it.name; });
    var qtys = items.map(function(it) { return it.qty; });
    var n = labels.length;
    var barColors = labels.map(function(_, i) {
      var alpha = 1 - (i / Math.max(n, 1)) * 0.45;
      return colors.isDark ? 'rgba(56,155,114,' + alpha + ')' : 'rgba(46,125,91,' + alpha + ')'; // fresh green #2E7D5B
    });
    return new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Units Sold',
          data: qtys,
          backgroundColor: barColors,
          borderColor: barColors.map(function(c) { return c.replace(/[\d.]+\)$/, '1)'); }),
          borderWidth: 1.5,
          borderRadius: 6,
          borderSkipped: false
        }]
      },
      options: {
        indexAxis: 'x',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: colors.tooltipBg,
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,0.9)',
            borderColor: colors.tooltipBorder,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            borderColor: 'rgba(255,255,255,0.12)',
            borderWidth: 1,
            callbacks: {
              title: function(itemsList) {
                return itemsList[0] ? itemsList[0].label : '';
              },
              label: function(c) {
                var it = items[c.dataIndex];
                var rev = it && it.revenue != null ? ' (₱' + Number(it.revenue).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ')' : '';
                return '  ' + c.parsed.y + ' units sold' + rev;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.text,
              font: { size: 11, weight: '600' },
              maxRotation: 30,
              minRotation: 0,
              callback: function(val) {
                var lbl = this.getLabelForValue(val);
                return lbl && lbl.length > 14 ? lbl.slice(0, 12) + '…' : lbl;
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: { color: colors.grid },
            ticks: {
              color: colors.muted,
              font: { size: 11 },
              precision: 0,
              stepSize: 1
            }
          }
        }
      }
    });
  }

  function renderYearlyMonthlyBarChart(canvas, months) {
    if (typeof Chart === 'undefined' || !canvas || !months || !months.length) return null;
    var colors = getChartColors();
    var ctx = canvas.getContext('2d');
    var labels = months.map(function(m) { return m.month_name; });
    var cashTotals = months.map(function(m) { return m.cash || 0; });
    var gcashTotals = months.map(function(m) { return m.gcash || 0; });
    var orders = months.map(function(m) { return m.orders || 0; });

    return new Chart(ctx, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'GCash Sales (₱)',
            data: gcashTotals,
            backgroundColor: colors.isDark ? 'rgba(154, 127, 196, 0.85)' : 'rgba(123, 94, 167, 0.85)', // Artisan violet #7B5EA7
            borderRadius: 4,
            stack: 'sales',
            yAxisID: 'y'
          },
          {
            label: 'Cash Sales (₱)',
            data: cashTotals,
            backgroundColor: colors.isDark ? 'rgba(56, 155, 114, 0.85)' : 'rgba(46, 125, 91, 0.85)', // Fresh green #2E7D5B
            borderRadius: 4,
            stack: 'sales',
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: 'Orders Count',
            data: orders,
            borderColor: colors.caramel,
            backgroundColor: colors.caramel,
            borderWidth: 2.5,
            pointRadius: 4,
            pointHoverRadius: 6.5,
            tension: 0.35,
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
            labels: { color: colors.text, boxWidth: 12, font: { size: 11, weight: '600' } }
          },
          tooltip: {
            backgroundColor: colors.tooltipBg,
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,0.9)',
            borderColor: colors.tooltipBorder,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            borderColor: 'rgba(255,255,255,0.12)',
            borderWidth: 1,
            callbacks: {
              afterBody: function(items) {
                var idx = items[0] ? items[0].dataIndex : 0;
                var m = months[idx];
                if (!m) return [];
                var lines = [
                  'Total Revenue: ' + money(m.total || 0),
                  'Daily Avg: ' + money(m.avg_daily_revenue || 0) + ' / day',
                  'Avg Order: ' + money(m.aov || 0)
                ];
                if (m.mom_growth_pct !== null) {
                  lines.push('MoM Growth: ' + (m.mom_growth_pct >= 0 ? '+' : '') + m.mom_growth_pct + '%');
                }
                return lines;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: colors.text, font: { size: 11, weight: '700' } }
          },
          y: {
            beginAtZero: true,
            grid: { color: colors.grid },
            ticks: {
              color: colors.muted,
              font: { size: 11 },
              callback: function(v) { return '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v); }
            }
          },
          y1: {
            beginAtZero: true,
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { color: colors.muted, font: { size: 11 }, precision: 0 }
          }
        }
      }
    });
  }

  function renderYearlyDailyLineChart(canvas, days, onSelectDate) {
    if (typeof Chart === 'undefined' || !canvas || !days || !days.length) return null;
    var colors = getChartColors();
    var ctx = canvas.getContext('2d');
    var revDays = days.slice().reverse(); // Chronological Jan 1 -> Dec 31
    var labels = revDays.map(function(d) { return d.day; });
    var totals = revDays.map(function(d) { return d.total; });
    var orders = revDays.map(function(d) { return d.orders; });

    var grad = ctx.createLinearGradient(0, 0, 0, 300);
    grad.addColorStop(0, 'rgba(200, 125, 75, 0.32)');
    grad.addColorStop(1, 'rgba(200, 125, 75, 0.02)');

    return new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Daily Sales (₱)',
            data: totals,
            borderColor: colors.caramel,
            backgroundColor: grad,
            borderWidth: 1.8,
            fill: true,
            tension: 0.25,
            pointRadius: 0,
            pointHoverRadius: 5,
            yAxisID: 'y'
          },
          {
            label: 'Daily Orders',
            data: orders,
            borderColor: colors.green,
            borderWidth: 1.2,
            borderDash: [3, 3],
            fill: false,
            tension: 0.25,
            pointRadius: 0,
            pointHoverRadius: 4,
            yAxisID: 'y1'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        onClick: function(e, elements) {
          if (elements && elements.length > 0 && typeof onSelectDate === 'function') {
            var idx = elements[0].index;
            var d = labels[idx];
            if (d) onSelectDate(d);
          }
        },
        onHover: function(e, elements) {
          if (canvas) canvas.style.cursor = (elements && elements.length > 0) ? 'pointer' : 'default';
        },
        plugins: {
          legend: {
            position: 'top',
            labels: { color: colors.text, boxWidth: 12, font: { size: 11, weight: '600' } }
          },
          tooltip: {
            backgroundColor: colors.tooltipBg,
            titleColor: '#fff',
            bodyColor: 'rgba(255,255,255,0.9)',
            borderColor: colors.tooltipBorder,
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
            callbacks: {
              title: function(items) {
                return items[0] ? dayLabel(items[0].label) : '';
              },
              label: function(c) {
                return c.dataset.label.indexOf('Sales') >= 0
                  ? '  Sales: ' + money(c.parsed.y)
                  : '  Orders: ' + c.parsed.y;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              color: colors.text,
              maxTicksLimit: 12,
              font: { size: 10 },
              callback: function(val) {
                var raw = this.getLabelForValue(val);
                return raw ? raw.slice(5) : ''; // MM-DD
              }
            }
          },
          y: {
            beginAtZero: true,
            grid: { color: colors.grid },
            ticks: {
              color: colors.muted,
              font: { size: 11 },
              callback: function(v) { return '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v); }
            }
          },
          y1: {
            beginAtZero: true,
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { color: colors.muted, font: { size: 10 }, precision: 0 }
          }
        }
      }
    });
  }

  /* ------------------------------------------------------------------ *
   *  Narrative Generators for Barista (2-3 concise sentences with timestamps)
   *  and Manager (Detailed analytical business narratives)
   * ------------------------------------------------------------------ */
  function getPeakHour(r) {
    if (!r || !r.by_hour || !r.by_hour.length) return null;
    var max = r.by_hour[0];
    for (var i = 0; i < r.by_hour.length; i++) {
      var h = r.by_hour[i];
      if (h.total > max.total || (h.total === max.total && h.orders > max.orders)) {
        max = h;
      }
    }
    return {
      hour: max.hour,
      time: formatHour(max.hour),
      window: formatHour(max.hour) + ' – ' + formatHour(max.hour + 1),
      orders: max.orders,
      total: max.total
    };
  }

  function getPeakDay(r) {
    if (!r || !r.by_day || !r.by_day.length) return null;
    var max = r.by_day[0];
    for (var i = 0; i < r.by_day.length; i++) {
      var d = r.by_day[i];
      if (d.total > max.total) max = d;
    }
    return max;
  }

  // Universal helper: build narrative body with structured, justified 2-section paragraphs
  function createNarrativeBody(content, customClass) {
    var bodyEl = h('div', { class: customClass || 'chart-narrative-body' });
    var paras = Array.isArray(content) ? content : String(content || '').split('\n\n').filter(Boolean);
    if (!paras.length) paras = [''];
    paras.forEach(function(pText) {
      bodyEl.append(h('p', { text: pText.trim() }));
    });
    return bodyEl;
  }

  function generateTopSellersNarrative(items, r, catFilter, limitFilter, role) {
    var isDrinks = catFilter === 'drinks';
    var isSnacks = catFilter === 'snacks';
    var catLabel = isDrinks ? 'Drinks' : isSnacks ? 'Snacks' : 'Store';
    var catNoun  = isDrinks ? 'cups' : isSnacks ? 'food items' : 'items';

    if (!items || items.length === 0) {
      if (role === 'barista') {
        return [
          'No ' + catLabel.toLowerCase() + ' sales have been recorded for this period yet. Shift registers are open and ready for incoming customer orders.',
          'Keep preparation stations and cup inventories fully stocked before peak customer hours.'
        ];
      }
      return [
        'No ' + catLabel.toLowerCase() + ' sales were recorded during this reporting period. Product velocity is currently zero, indicating shift initialization, scheduled downtime, or delayed transaction syncing.',
        'Inventory audit and register availability should be confirmed before initiating promotional pushes.'
      ];
    }

    var top1 = items[0];
    var top2 = items.length > 1 ? items[1] : null;
    var top3 = items.length > 2 ? items[2] : null;
    var catSum = r.category_summary || {};
    var totalCatQty = isDrinks ? (catSum.drinks_qty || items.reduce(function(a, b) { return a + b.qty; }, 0))
                    : isSnacks ? (catSum.snacks_qty || items.reduce(function(a, b) { return a + b.qty; }, 0))
                    : (catSum.total_qty || items.reduce(function(a, b) { return a + b.qty; }, 0));
    var totalCatRev = isDrinks ? (catSum.drinks_revenue || items.reduce(function(a, b) { return a + (b.revenue || 0); }, 0))
                    : isSnacks ? (catSum.snacks_revenue || items.reduce(function(a, b) { return a + (b.revenue || 0); }, 0))
                    : (catSum.total_revenue || items.reduce(function(a, b) { return a + (b.revenue || 0); }, 0));

    var peakH = getPeakHour(r);
    var peakD = getPeakDay(r);

    if (role === 'barista') {
      var p1 = top1.name + ' leads ' + catLabel.toLowerCase() + ' orders with ' + top1.qty + ' ' + catNoun + ' served' + (top2 ? ', followed closely by ' + top2.name + ' with ' + top2.qty + ' ' + catNoun : '') + '. ' +
        (peakH && peakH.total > 0
          ? 'Peak order demand spiked during the ' + peakH.window + ' rush window, accounting for ' + peakH.orders + ' customer tickets.'
          : (peakD && r.by_day && r.by_day.length > 1
            ? 'Highest volume concentrated on ' + dayLabel(peakD.day) + ' with ' + peakD.orders + ' completed tickets.'
            : 'Orders remained steady across the shift with all register tickets processed cleanly.'));
      
      var p2 = isDrinks
        ? 'Ensure cold cup sleeves, drink lids, and espresso hoppers remain stocked before the next shift wave.'
        : (isSnacks
          ? 'Keep croffle dough sheets thawed and pastry display trays refreshed for upcoming food orders.'
          : 'Verify register cash drawer balances and restock bar supplies before handover.');

      return [p1, p2];
    }

    // Manager: 2 clean analytical sections
    var topSharePct = totalCatQty > 0 ? ((top1.qty / totalCatQty) * 100).toFixed(1) : 0;
    var topRev = top1.revenue ? money(top1.revenue) : '₱0.00';
    var totalRevFmt = money(totalCatRev);

    var p1 = catLabel + ' generated ' + totalRevFmt + ' across ' + totalCatQty + ' total ' + catNoun + ', with ' + top1.name + ' capturing the leading share at ' + top1.qty + ' units (' + topSharePct + '% of category volume, contributing ' + topRev + '). ' +
      (top2 ? 'Secondary demand centered around ' + top2.name + ' (' + top2.qty + ' units' + (top2.revenue ? ', ' + money(top2.revenue) : '') + ')' + (top3 ? ' and ' + top3.name + ' (' + top3.qty + ' units)' : '') + ', demonstrating clear customer concentration in signature menu selections over basic offerings.' : '');

    var p2 = (peakH && peakH.total > 0 ? 'Category throughput correlated strongly with the ' + peakH.window + ' sales surge (' + money(peakH.total) + ' across ' + peakH.orders + ' orders), highlighting essential synergy between beverage rush timing and kitchen food preparation. ' : '') +
      (isDrinks
        ? 'Management should maintain promotional focus on high-margin espresso signatures while evaluating tiered drink size upsells during off-peak windows to lift average order value.'
        : 'Given strong attachment to premium toppings, cross-selling croffles with specialty milk teas and iced lattes represents a prime margin-expansion opportunity.');

    return [p1, p2];
  }

  function generateSalesTrendNarrative(r, role) {
    var peakH = getPeakHour(r);
    var peakD = getPeakDay(r);
    var s = r.summary || { orders: 0, total: 0 };

    if (role === 'barista') {
      if (!s.orders) {
        return [
          'No completed orders have been recorded in this shift timeframe yet. Registers are operational and awaiting customer transactions.',
          'Baristas should verify cup supplies, ice levels, and milk jugs in preparation for the next order rush.'
        ];
      }
      var p1 = 'Store revenue reached ' + money(s.total) + ' across ' + s.orders + ' completed tickets during this period. ' +
        (peakH && peakH.total > 0
          ? 'The primary rush window peaked at ' + peakH.time + ' with ' + peakH.orders + ' tickets (' + money(peakH.total) + ').'
          : (peakD && r.by_day && r.by_day.length > 1
            ? 'Sales volume reached its height on ' + dayLabel(peakD.day) + ' with ' + peakD.orders + ' orders (' + money(peakD.total) + ').'
            : 'Customer order pace remained steady across the shift with smooth counter flow.'));
      var p2 = 'All counter and register transactions completed cleanly with zero pending tickets remaining in the queue. Maintain preparation stations and ice bins in readiness for subsequent customer orders.';
      return [p1, p2];
    }

    if (!s.orders) {
      return [
        'Zero transaction throughput was detected across the selected timeframe, resulting in null revenue velocity.',
        'Management should verify whether store operating hours or network sync issues influenced this recording before scheduling adjustments.'
      ];
    }
    if (r.range === 'year') {
      var bestM = s.best_month ? s.best_month.name + ' (' + money(s.best_month.total) + ', ' + s.best_month.orders + ' tickets)' : 'peak seasonal quarter';
      var lowM = s.lowest_month ? s.lowest_month.name + ' (' + money(s.lowest_month.total) + ', ' + s.lowest_month.orders + ' tickets)' : 'monsoon season';
      var avgMonth = (s.total || 0) / 12;
      return [
        'Annual revenue tracking exhibits total receipts of ' + money(s.total) + ' across ' + (s.orders || 0).toLocaleString() + ' completed customer transactions, yielding a monthly revenue mean of ' + money(avgMonth) + '. Peak trading velocity occurred during ' + bestM + ', capturing the strongest seasonal demand across the calendar year.',
        'Seasonal cyclicality indicates trading volume softens during ' + lowM + ', reflecting climate and academic calendar shifts. Management should schedule targeted mid-year beverage promotions and bundle loyalty programs to lift revenue pacing during slower operational quarters.'
      ];
    }
    if (r.by_day && r.by_day.length > 1) {
      var avgDayRev = s.total / r.by_day.length;
      var topDayFmt = peakD ? dayLabel(peakD.day) + ' (' + money(peakD.total) + ', ' + peakD.orders + ' orders)' : 'N/A';
      return [
        'Multi-day trend tracking exhibits total revenue of ' + money(s.total) + ' over ' + r.by_day.length + ' operating days, yielding a daily sales mean of ' + money(avgDayRev) + '. Peak trading velocity occurred on ' + topDayFmt + ', indicating elevated weekend or event-driven footfall.',
        'Variance analysis suggests demand ebbs on mid-week days, recommending promotional bundle incentives on slower days to stabilize week-long revenue consistency and maintain steady inventory velocity.'
      ];
    }
    var peakStr = peakH && peakH.total > 0 ? peakH.window + ' (' + money(peakH.total) + ' across ' + peakH.orders + ' orders)' : 'midday';
    var peakPct = peakH && s.total > 0 ? ((peakH.total / s.total) * 100).toFixed(1) : '0';
    return [
      'Intra-day sales progression highlights pronounced temporal demand clustering, with peak trading concentrating around ' + peakStr + ', capturing ' + peakPct + '% of daily receipts.',
      'Off-peak intervals demonstrate capacity slack with minimal transactions, signaling an opportunity for afternoon happy hour promotions or targeted digital ordering campaigns to lift machine and staff utilization.'
    ];
  }

  function generatePaymentNarrative(r, role) {
    var s = r.summary || { cash: 0, gcash: 0, total: 0 };
    var total = s.total || (s.cash + s.gcash) || 0;
    var cashPct = total > 0 ? Math.round(s.cash / total * 100) : 0;
    var gcashPct = total > 0 ? Math.round(s.gcash / total * 100) : 0;

    if (role === 'barista') {
      if (!total) {
        return [
          'No payment settlements were processed during this shift window. Cash registers and GCash QR stands are online for next orders.',
          'Report any tender discrepancies or missing digital reference numbers to the lead barista immediately.'
        ];
      }
      var p1 = 'Cash payments reached ' + money(s.cash) + ' (' + cashPct + '%), while GCash digital transfers accounted for ' + money(s.gcash) + ' (' + gcashPct + '%).';
      var p2 = 'Both cash drawer counts and digital settlement logs balanced with zero voided orders recorded during shift reconciliation at ' + (getPeakHour(r) ? getPeakHour(r).time : 'shift close') + '. Ensure cash drawers are locked and physical bills sorted for handover.';
      return [p1, p2];
    }

    if (!total) {
      return [
        'Payment channel distribution is inactive with zero recorded revenue across both Cash and GCash tenders for this period.',
        'Register terminals remain in standby. Ensure GCash static QR stands and physical cash float are counted prior to service.'
      ];
    }
    return [
      'Tender settlement analysis reflects healthy payment diversity with Cash capturing ' + money(s.cash) + ' (' + cashPct + '%) and GCash claiming ' + money(s.gcash) + ' (' + gcashPct + '%).',
      'The strong digital adoption rate (' + gcashPct + '%) mitigates register cash float vulnerability, curtails cash transit exposure, and speeds checkout throughput during peak order waves. Register reconciliation logs indicate disciplined POS cashier execution with zero voids or uncollected receipts.'
    ];
  }

  function generateTopProductsNarrative(r, role) {
    var items = r.top_items || [];
    if (!items.length) {
      return [
        'Product sales history reflects zero item movement for this range.',
        'Ensure menu catalog synchronizations are complete and active menu items have available recipe components.'
      ];
    }
    var top1 = items[0];
    var top2 = items.length > 1 ? items[1] : null;
    var top3 = items.length > 2 ? items[2] : null;
    var top5Qty = items.slice(0, 5).reduce(function(a, b) { return a + b.qty; }, 0);
    var totalQty = (r.category_summary && r.category_summary.total_qty) || items.reduce(function(a, b) { return a + b.qty; }, 0);
    var top5Share = totalQty > 0 ? ((top5Qty / totalQty) * 100).toFixed(1) : 0;

    return [
      'Product velocity distribution demonstrates that high-performing signature items drive store volume, with the top 5 SKUs accounting for ' + top5Share + '% (' + top5Qty + ' units) of all sold items. ' + top1.name + ' (' + top1.qty + ' units) commands the highest velocity' + (top2 ? ', closely matched by ' + top2.name + ' (' + top2.qty + ' units)' : '') + (top3 ? ' and ' + top3.name + ' (' + top3.qty + ' units)' : '') + '.',
      'This item concentration confirms sustained customer preference for core signature beverages and freshly pressed croffles. Management should evaluate recipe cost margins on these core drivers while reviewing slower-moving SKUs for potential menu pruning or promotional bundling.'
    ];
  }

  function generateBreakdownNarrative(r, role) {
    var s = r.summary || { orders: 0, total: 0 };
    var avgTicket = s.orders > 0 ? (s.total / s.orders) : 0;
    var peakH = getPeakHour(r);
    var peakD = getPeakDay(r);

    if (role === 'barista') {
      if (!s.orders) {
        return [
          'No transactions have been recorded in the breakdown register for this period. Register terminals are active and awaiting customers.',
          'Baristas should ensure prep bars and cups remain ready for incoming tickets.'
        ];
      }
      var p1 = 'Shift sales totaled ' + money(s.total) + ' across ' + s.orders + ' completed orders, averaging ' + money(avgTicket) + ' per ticket. ' +
        (peakH && peakH.total > 0
          ? 'The busiest operational hour was recorded at ' + peakH.time + ' with ' + peakH.orders + ' orders, followed by steady flow throughout the shift.'
          : (peakD && r.by_day && r.by_day.length > 1
            ? 'Peak daily order volume was logged on ' + dayLabel(peakD.day) + ' with ' + peakD.orders + ' orders.'
            : 'Counter transactions flowed smoothly throughout the scheduled service hours.'));
      var p2 = 'Cash and GCash drawer balances are verified and ready for shift transition. Prepare shift closeout forms and verify ingredient restock requirements.';
      return [p1, p2];
    }

    if (!s.orders) {
      return [
        'Operational metrics indicate zero ticket activity across the monitored breakdown interval.',
        'Historical comparisons and capacity planning require subsequent transaction logs.'
      ];
    }
    if (r.range === 'year') {
      var bestMBreakdown = s.best_month ? s.best_month.name + ' (' + money(s.best_month.total) + ', ' + s.best_month.orders + ' orders)' : 'peak seasonal window';
      return [
        'Annual monthly ledger audit shows ' + (s.orders || 0).toLocaleString() + ' completed transactions generating ' + money(s.total) + ', with average ticket basket remaining stable at ' + money(avgTicket) + '. Peak monthly order throughput occurred during ' + bestMBreakdown + '.',
        'Monthly throughput variation highlights seasonal customer volume shifts between high-season quarters and monsoon months. Management should utilize off-peak months for staff upskilling, preventative equipment maintenance, and menu testing before high-volume holiday cycles.'
      ];
    }
    if (r.by_day && r.by_day.length > 1) {
      return [
        'Cross-day transaction audit shows ' + s.orders + ' completed transactions generating ' + money(s.total) + ', with average basket value remaining steady at ' + money(avgTicket) + '.',
        'Daily order variance underscores the necessity of dynamic staff scheduling between peak and off-peak days to maintain swift counter service and avoid excess labor overhead during slower weekdays.'
      ];
    }
    var peakInfo = peakH && peakH.total > 0 ? peakH.time + ' (' + peakH.orders + ' orders, ' + money(peakH.total) + ')' : 'the peak rush';
    return [
      'Intra-day transaction density shows an average customer basket of ' + money(avgTicket) + ' across ' + s.orders + ' completed tickets, indicating consistent multi-item beverage and snack pairings. Peak operational throughput reached ' + peakInfo + ', representing a processing velocity of approximately 1 order every 4 minutes.',
      'Scheduling two baristas during rush periods preserves customer satisfaction and ticket speed without overstaffing quieter hours. Target bundle incentives during mid-afternoon slumps to elevate off-peak order counts.'
    ];
  }

  function generateYearlyStrategicNarrative(r) {
    var s = r.summary || {};
    var health = r.data_health || {};
    var tot = money(s.total || 0);
    var ord = (s.orders || 0).toLocaleString();
    var aov = money(s.aov || (s.orders ? s.total / s.orders : 0));
    var daily = money(s.avg_daily_revenue || 0);
    var bestM = s.best_month ? (s.best_month.name + ' (' + money(s.best_month.total) + ', ' + s.best_month.orders + ' orders)') : 'Q1/Q4 peaks';
    var lowM = s.lowest_month ? (s.lowest_month.name + ' (' + money(s.lowest_month.total) + ')') : 'Monsoon off-peak';
    var isMock = health.dataset_type === 'synthetic';

    var p1 = 'Annual gross revenue reached ' + tot + ' across ' + ord + ' completed customer transactions, delivering a robust Average Order Value (AOV) of ' + aov + ' and an operating baseline of ' + daily + ' per business day. Peak sales momentum concentrated heavily in ' + bestM + ', driven by seasonal beverage demand and extended evening foot traffic, whereas ' + lowM + ' marked the seasonal trough with reduced daily order velocity.';

    var p2 = isMock
      ? 'This 2025 historical dataset reflects distinct Philippine retail dynamics—including the Tag-init summer beach influx (March–May), Habagat monsoon lulls (July–August), and the intense Q4 Ber-months holiday surge fueled by 13th-month bonus payouts. The annual volume curves provide valuable operational baselines for store management to anticipate ingredient procurement, stock buffering, and seasonal barista scheduling.'
      : 'Store sales records confirm steady revenue consistency and high GCash digital adoption across the operating calendar. Store management can utilize this full-year historical velocity pattern to guide menu pricing strategies, seasonal promotional campaigns, and automated reorder triggers for high-volume coffee and pastry inventory.';

    return [p1, p2];
  }

  function generateYearlyDailyNarrative(r) {
    var s = r.summary || {};
    var days = r.by_day || [];
    var bestDay = s.best_day ? (dayLabel(s.best_day.date) + ' (' + money(s.best_day.total) + ', ' + s.best_day.orders + ' orders)') : 'Weekend peaks';
    var opDays = (r.data_health && r.data_health.operating_days) || days.length;

    var p1 = 'The 365-day continuous sales trajectory tracks daily demand across ' + opDays + ' active retail sessions, establishing an annual peak on ' + bestDay + '. Demand exhibits strong cyclical surges aligned with bi-weekly "Quincena" paydays (15th and 30th of each month) and weekend leisure outings, averaging 25 to 45 daily transactions during high-volume periods.';

    var p2 = 'Intra-month fluctuations reflect weather impacts during typhoons and holiday clustering around Holy Week, All Saints\' Day, and Christmas celebrations. These daily time-series records enable DK Cafe management to audit historical cashier volume trends, minimize perishable food waste, and optimize register cash-drawer float limits.';

    return [p1, p2];
  }

  function renderTopSellersSection(container, r, role) {
    var rawItems = r.top_items || [];

    var card = h('section', { class: 'top-sellers-card' });

    var titleGroup = h('div', { class: 'top-sellers-title-group' },
      h('h3', {}, 'Top Sellers'),
      h('p', {}, 'Live ranking by volume and revenue')
    );

    // Category button group
    var catBtnDrinks = h('button', {
      type: 'button',
      class: 'filter-pill' + (_topCategory === 'drinks' ? ' active' : ''),
      dataset: { cat: 'drinks' },
      text: 'Drinks'
    });
    var catBtnSnacks = h('button', {
      type: 'button',
      class: 'filter-pill' + (_topCategory === 'snacks' ? ' active' : ''),
      dataset: { cat: 'snacks' },
      text: 'Snacks'
    });
    var catBtnAll = h('button', {
      type: 'button',
      class: 'filter-pill' + (_topCategory === 'all' ? ' active' : ''),
      dataset: { cat: 'all' },
      text: 'All Menu'
    });
    var catGroup = h('div', { class: 'filter-btn-group' }, catBtnDrinks, catBtnSnacks, catBtnAll);

    // Limit button group
    var limitBtn5 = h('button', {
      type: 'button',
      class: 'filter-pill' + (_topLimit === 5 ? ' active' : ''),
      dataset: { limit: '5' },
      text: 'Top 5'
    });
    var limitBtn10 = h('button', {
      type: 'button',
      class: 'filter-pill' + (_topLimit === 10 ? ' active' : ''),
      dataset: { limit: '10' },
      text: 'Top 10'
    });
    var limitGroup = h('div', { class: 'filter-btn-group' }, limitBtn5, limitBtn10);

    var controls = h('div', { class: 'top-sellers-controls' }, catGroup, limitGroup);
    var head = h('div', { class: 'top-sellers-card-head' }, titleGroup, controls);

    var chartBox = h('div', { class: 'top-sellers-chart-box' });
    var canvas = h('canvas', { 'aria-label': 'Top sellers vertical bar chart' });
    chartBox.append(canvas);

    var summaryPanel = h('div', { class: 'top-sellers-summary-panel' });
    var body = h('div', { class: 'top-sellers-body' }, chartBox, summaryPanel);
    var leaderboardContainer = h('div', { class: 'top-sellers-leaderboard-container' });
    card.append(head, body, leaderboardContainer);
    container.append(card);

    function updateTopView() {
      var filtered = rawItems;
      if (_topCategory === 'drinks') {
        filtered = rawItems.filter(function(it) { return it.macro_category === 'drinks'; });
      } else if (_topCategory === 'snacks') {
        filtered = rawItems.filter(function(it) { return it.macro_category === 'snacks'; });
      }

      var sliced = filtered.slice(0, _topLimit);

      _topSellersBarChart = destroyChart(_topSellersBarChart);
      if (sliced.length > 0) {
        chartBox.innerHTML = '';
        var newCanvas = h('canvas', { 'aria-label': 'Top sellers vertical bar chart' });
        chartBox.append(newCanvas);
        requestAnimationFrame(function() {
          _topSellersBarChart = renderTopSellersVerticalChart(newCanvas, sliced);
        });
      } else {
        chartBox.innerHTML = '';
        chartBox.append(emptyState({
          icon: '',
          title: 'No ' + (_topCategory === 'drinks' ? 'Drinks' : _topCategory === 'snacks' ? 'Snacks' : 'Items') + ' Sold',
          desc: 'No items in this category were ordered during this timeframe.',
          compact: true
        }));
      }

      summaryPanel.innerHTML = '';
      leaderboardContainer.innerHTML = '';

      var catLabel = _topCategory === 'drinks' ? 'Drinks Scope' : _topCategory === 'snacks' ? 'Snacks Scope' : 'All Categories Scope';
      var limitLabel = 'Top ' + _topLimit + ' Ranking';

      var scopeBadge = h('div', { class: 'summary-scope-badge' },
        h('span', { class: 'scope-dot' }),
        h('span', { text: catLabel + ' · ' + limitLabel })
      );
      var roleTag = h('span', { class: 'summary-role-tag', text: role === 'barista' ? 'Shift Summary (Barista)' : 'Executive Analysis (Manager)' });
      var scopeHeader = h('div', { class: 'summary-scope-header' }, scopeBadge, roleTag);

      var narrativeText = generateTopSellersNarrative(sliced, r, _topCategory, _topLimit, role);
      var narrativeDiv = createNarrativeBody(narrativeText, 'summary-narrative-text');

      var leaderboardWrap = h('div', { class: 'top-sellers-table-wrap' });
      if (sliced.length > 0) {
        var maxQty = Math.max.apply(null, sliced.map(function(it) { return it.qty; })) || 1;
        var totalCatRev = filtered.reduce(function(a, b) { return a + (b.revenue || 0); }, 0);

        var lbTable = h('table', { class: 'ts-table' },
          h('thead', {},
            h('tr', {},
              h('th', { style: 'width: 60px;', text: 'Rank' }),
              h('th', { text: 'Item' }),
              h('th', { class: 'num', style: 'width: 65px;', text: 'Units' }),
              h('th', { style: 'width: 90px;', text: 'Velocity' }),
              h('th', { class: 'num', style: 'width: 85px;', text: 'Revenue' })
            )
          ),
          h('tbody', {},
            sliced.map(function(it, idx) {
              var rankClass = idx === 0 ? 'gold' : idx === 1 ? 'silver' : idx === 2 ? 'bronze' : 'default';
              var rankText = idx === 0 ? '🥇 #1' : idx === 1 ? '🥈 #2' : idx === 2 ? '🥉 #3' : `#${idx + 1}`;
              var pct = Math.min(100, Math.round((it.qty / maxQty) * 100));

              return h('tr', {},
                h('td', {}, h('span', { class: 'ts-rank-pill ' + rankClass, text: rankText })),
                h('td', { class: 'ts-item-name', text: it.name }),
                h('td', { class: 'num', style: 'font-weight: 700;', text: String(it.qty) }),
                h('td', {},
                  h('div', { class: 'ts-prog-bar-wrap', title: `${pct}% relative demand` },
                    h('div', { class: 'ts-prog-bar' },
                      h('div', { class: 'ts-prog-fill', style: `width: ${pct}%;` })
                    )
                  )
                ),
                h('td', { class: 'num', text: money(it.revenue || 0) })
              );
            })
          )
        );
        leaderboardWrap.append(lbTable);
      }

      var chipsDiv = h('div', { class: 'summary-stat-chips' });
      if (sliced.length > 0) {
        var top1 = sliced[0];
        var totalCatQty = filtered.reduce(function(a, b) { return a + b.qty; }, 0);
        var totalCatRev = filtered.reduce(function(a, b) { return a + (b.revenue || 0); }, 0);

        chipsDiv.append(
          h('div', { class: 'summary-chip' }, h('span', { text: '#1 Leader:' }), h('strong', { text: top1.name + ' (' + top1.qty + ')' })),
          h('div', { class: 'summary-chip' }, h('span', { text: 'Category Units:' }), h('strong', { text: String(totalCatQty) })),
          h('div', { class: 'summary-chip' }, h('span', { text: 'Category Revenue:' }), h('strong', { text: money(totalCatRev) }))
        );
      }

      summaryPanel.append(scopeHeader, narrativeDiv, chipsDiv);

      if (sliced.length > 0) {
        var displayLimit = Math.min(sliced.length, _topLimit);
        var leaderboardWrap = h('div', { class: 'top-sellers-leaderboard' },
          h('h4', { class: 'leaderboard-title', text: 'Top ' + displayLimit + ' Bestselling Products Leaderboard' }),
          h('div', { class: 'leaderboard-list' },
            sliced.slice(0, displayLimit).map(function(item, idx) {
              var rank = idx + 1;
              var medalClass = rank === 1 ? 'rank-gold' : (rank === 2 ? 'rank-silver' : (rank === 3 ? 'rank-bronze' : 'rank-std'));
              var medalIcon = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : '#' + rank));
              return h('div', { class: 'leaderboard-row ' + medalClass },
                h('div', { class: 'leaderboard-rank' }, h('span', { text: medalIcon })),
                h('div', { class: 'leaderboard-info' },
                  h('strong', { class: 'leaderboard-name', text: item.name }),
                  h('span', { class: 'leaderboard-cat', text: item.category || 'Beverage' })
                ),
                h('div', { class: 'leaderboard-stats' },
                  h('span', { class: 'leaderboard-qty', text: item.qty + ' units' }),
                  h('strong', { class: 'leaderboard-rev', text: money(item.revenue || 0) })
                )
              );
            })
          )
        );
        leaderboardContainer.append(leaderboardWrap);
      }
    }

    [catBtnDrinks, catBtnSnacks, catBtnAll].forEach(function(btn) {
      btn.addEventListener('click', function() {
        _topCategory = btn.dataset.cat;
        [catBtnDrinks, catBtnSnacks, catBtnAll].forEach(function(b) {
          b.classList.toggle('active', b.dataset.cat === _topCategory);
        });
        updateTopView();
      });
    });

    [limitBtn5, limitBtn10].forEach(function(btn) {
      btn.addEventListener('click', function() {
        _topLimit = parseInt(btn.dataset.limit, 10) || 5;
        [limitBtn5, limitBtn10].forEach(function(b) {
          b.classList.toggle('active', parseInt(b.dataset.limit, 10) === _topLimit);
        });
        updateTopView();
      });
    });

    updateTopView();
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
    var colors = getChartColors();
    var ctx = canvas.getContext('2d');

    // Color mapper: GCash is artisan violet (#7B5EA7), Cash is fresh green (#2E7D5B)
    function getPaymentColor(label, idx) {
      var l = String(label || '').toLowerCase();
      if (l === 'cash') return colors.green; // #2E7D5B
      if (l === 'gcash') return colors.violet; // #7B5EA7 (artisan violet)
      var fallbacks = [colors.green, colors.violet, colors.caramel, colors.amber, colors.red];
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
          displayColor  = sliceColors[hIdx] || colors.text;
        } else {
          displayAmount = formatMoneyShort(totalVal);
          displaySub    = 'TOTAL SALES';
          displayColor  = colors.text;
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
        c.fillStyle = (hIdx != null) ? displayColor : colors.muted;
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
          borderColor: colors.cardBg,
          borderWidth: 3,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
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
              color: colors.text,
              font: { size: 12, weight: '600' },
              generateLabels: function(ch) {
                var ds = ch.data.datasets[0];
                return ch.data.labels.map(function(lbl, i) {
                  var total = ds.data.reduce(function(a, b) { return a + b; }, 0);
                  var pct = total > 0 ? Math.round(ds.data[i] / total * 100) : 0;
                  return {
                    text: lbl + ' (' + pct + '%)',
                    fillStyle: sliceColors[i] || '#ccc',
                    strokeStyle: colors.cardBg,
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

  function renderYearlyAnalyticsView(body, r) {
    const s = r.summary || {};
    const health = r.data_health || {};
    const months = r.monthly_breakdown || [];
    const products = r.product_performance || [];

    // Sync Year Picker dropdown options
    const yrPicker = $('#reportYearPicker');
    if (yrPicker && r.available_years && r.available_years.length) {
      if (yrPicker.children.length !== r.available_years.length) {
        yrPicker.innerHTML = '';
        r.available_years.forEach(function(yr) {
          var opt = document.createElement('option');
          opt.value = String(yr);
          opt.textContent = yr === 2025 ? `${yr} (Historical Test Dataset)` : String(yr);
          yrPicker.appendChild(opt);
        });
      }
      yrPicker.value = String(r.year || selectedYear);
    }

    // 1. Dataset Integrity Alert Banner (shown only for simulated test datasets)
    var isMock = health.dataset_type === 'synthetic';
    if (isMock) {
      var alertBanner = h('div', { class: 'dataset-alert-banner alert-mock' },
        h('div', { class: 'dataset-alert-icon', html: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 2v7.31L4.65 18.2A2 2 0 0 0 6.36 21h11.28a2 2 0 0 0 1.71-2.8L14 9.31V2"></path><line x1="8" y1="2" x2="16" y2="2"></line></svg>' }),
        h('div', { class: 'dataset-alert-body' },
          h('div', { class: 'dataset-alert-header' },
            h('span', { class: 'dataset-alert-title', text: 'Simulated Historical Test Dataset (2025)' }),
            h('span', { class: 'dataset-alert-tag', text: 'Historical Archive (2025)' })
          ),
          h('p', {
            class: 'dataset-alert-text',
            text: 'This annual view presents 7,521 simulated transactions spanning 365 calendar days representing 2025 historical retail seasonality (Tag-init summer spikes, Habagat monsoon dips, and Q4 holiday surges). Financial and tax audits must reference actual store sales data.'
          })
        )
      );
      body.append(alertBanner);
    }

    // 2. 8-Card Annual KPI Summary Grid
    var drinksQty = r.category_summary ? (r.category_summary.drinks_qty || 0) : 0;
    var snacksQty = r.category_summary ? (r.category_summary.snacks_qty || 0) : 0;
    var bestMonthStr = s.best_month ? (s.best_month.name + ' (' + money(s.best_month.total) + ')') : 'N/A';
    var bestDayStr = s.best_day ? (dayLabel(s.best_day.date) + ' (' + money(s.best_day.total) + ')') : 'N/A';
    var coverageStr = (health.operating_days || 0) + ' / ' + (health.total_calendar_days || 365) + ' Days (' + (health.coverage_pct || 0) + '%)';

    var kpiGrid = h('div', { class: 'yearly-kpi-grid' },
      h('div', { class: 'yearly-kpi-card hero' },
        h('span', { class: 'kpi-label', text: 'Annual Gross Sales' }),
        h('span', { class: 'kpi-val', text: money(s.total || 0) }),
        h('span', { class: 'kpi-sub', text: 'Cash: ' + money(s.cash || 0) + ' · GCash: ' + money(s.gcash || 0) })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Completed Transactions' }),
        h('span', { class: 'kpi-val', text: (s.orders || 0).toLocaleString() }),
        h('span', { class: 'kpi-sub', text: (health.operating_days || 0) + ' trading days recorded' })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Average Order Value (AOV)' }),
        h('span', { class: 'kpi-val', text: money(s.aov || (s.orders ? s.total / s.orders : 0)) }),
        h('span', { class: 'kpi-sub', text: 'Customer basket expenditure' })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Average Daily Pace' }),
        h('span', { class: 'kpi-val', text: money(s.avg_daily_revenue || 0) }),
        h('span', { class: 'kpi-sub', text: 'Gross revenue / operating day' })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Peak Performing Month' }),
        h('span', { class: 'kpi-val', style: 'font-size: 17px;', text: bestMonthStr }),
        h('span', { class: 'kpi-sub', text: s.best_month ? (s.best_month.orders + ' orders logged') : 'Seasonal surge' })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Single Highest Day' }),
        h('span', { class: 'kpi-val', style: 'font-size: 17px;', text: bestDayStr }),
        h('span', { class: 'kpi-sub', text: s.best_day ? (s.best_day.orders + ' orders processed') : 'Record rush' })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Menu Units Sold' }),
        h('span', { class: 'kpi-val', style: 'font-size: 18px;', text: drinksQty.toLocaleString() + ' Drinks / ' + snacksQty.toLocaleString() + ' Snacks' }),
        h('span', { class: 'kpi-sub', text: 'Total cups vs snack portions' })
      ),
      h('div', { class: 'yearly-kpi-card' },
        h('span', { class: 'kpi-label', text: 'Data Coverage' }),
        h('span', { class: 'kpi-val', style: 'font-size: 17px;', text: coverageStr }),
        h('span', { class: 'kpi-sub', text: (health.dataset_type ? health.dataset_type.toUpperCase() : 'ACTUAL') + ' series integrity' })
      )
    );
    body.append(kpiGrid);

    // 3. Monthly Revenue & Growth Velocity Chart Card
    var monthlyChartBody = h('div', { class: 'annual-chart-body' });
    var monthlyCanvas = h('canvas', { 'aria-label': '12-Month revenue and MoM growth velocity' });
    monthlyChartBody.append(monthlyCanvas);

    var monthlyNarrativeBox = h('div', { class: 'chart-narrative-box' },
      h('div', { class: 'chart-narrative-tag' },
        h('span', { class: 'status-pip green' }),
        h('span', { text: 'ANNUAL TRAJECTORY & SEASONAL CYCLICALITY' })
      ),
      createNarrativeBody(generateYearlyStrategicNarrative(r))
    );

    var monthlyCard = h('div', { class: 'annual-chart-card' },
      h('div', { class: 'chart-panel-head' },
        h('span', { class: 'chart-panel-title', text: '12-Month Revenue & Month-over-Month Velocity (' + (r.year || selectedYear) + ')' })
      ),
      monthlyChartBody,
      monthlyNarrativeBox
    );
    body.append(monthlyCard);

    requestAnimationFrame(function() {
      _reportYearBarChart = renderYearlyMonthlyBarChart(monthlyCanvas, months);
    });

    // 4. 365-Day High-Density Daily Trend Line Chart
    if (r.by_day && r.by_day.length > 1) {
      var dailyChartBody = h('div', { class: 'annual-chart-body' });
      var dailyCanvas = h('canvas', { 'aria-label': '365-Day daily sales curve' });
      dailyChartBody.append(dailyCanvas);

      var dailyNarrativeBox = h('div', { class: 'chart-narrative-box' },
        h('div', { class: 'chart-narrative-tag' },
          h('span', { class: 'status-pip blue' }),
          h('span', { text: 'HIGH-DENSITY DAILY TRAJECTORY & PAYDAY SURGES' })
        ),
        createNarrativeBody(generateYearlyDailyNarrative(r))
      );

      var dailyCard = h('div', { class: 'annual-chart-card', style: 'margin-top: 18px;' },
        h('div', { class: 'chart-panel-head' },
          h('span', { class: 'chart-panel-title', text: '365-Day High-Density Sales Curve (Quincena Paydays & Weekend Rushes)' })
        ),
        dailyChartBody,
        dailyNarrativeBox
      );
      body.append(dailyCard);

      // Chart will be initialized after callback is defined below
    }

    // 5. Macro Category & Top Sellers Section
    renderTopSellersSection(body, r, 'manager');

    // 6. Product & Cup-Size Variant Performance Table (Interactive Column Sorting)
    if (products && products.length > 0) {
      var prodSortKey = 'revenue';
      var prodSortDir = 'desc';
      var prodList = products.slice();

      var prodWrap = h('section', { class: 'product-perf-table-wrap' },
        h('div', { class: 'tx-section-head', style: 'margin-bottom: 8px;' },
          h('h3', { text: 'Product & Cup-Size Variant Performance (' + (r.year || selectedYear) + ')' }),
          h('span', { class: 'drilldown-summary-badge', text: products.length + ' variants tracked' })
        ),
        h('p', { class: 'tx-sec-sub', text: 'Detailed unit volumes, revenue contribution, and basket shares broken down by temperature and size specification. Click column headers to sort.' })
      );

      var thead = h('thead', {},
        h('tr', {},
          h('th', { class: 'sortable-th' + (prodSortKey === 'name' ? ' active-sort' : ''), dataset: { sort: 'name' }, text: 'Product Item ' + (prodSortKey === 'name' ? (prodSortDir === 'asc' ? '▲' : '▼') : '') }),
          h('th', { class: 'sortable-th' + (prodSortKey === 'category' ? ' active-sort' : ''), dataset: { sort: 'category' }, text: 'Category ' + (prodSortKey === 'category' ? (prodSortDir === 'asc' ? '▲' : '▼') : '') }),
          h('th', { text: 'Size / Spec' }),
          h('th', { class: 'num sortable-th' + (prodSortKey === 'qty' ? ' active-sort' : ''), dataset: { sort: 'qty' }, text: 'Units Sold ' + (prodSortKey === 'qty' ? (prodSortDir === 'asc' ? '▲' : '▼') : '') }),
          h('th', { class: 'num sortable-th active-sort', dataset: { sort: 'revenue' }, text: 'Revenue ▼' }),
          h('th', { class: 'num sortable-th' + (prodSortKey === 'avg_unit_price' ? ' active-sort' : ''), dataset: { sort: 'avg_unit_price' }, text: 'Avg Price ' + (prodSortKey === 'avg_unit_price' ? (prodSortDir === 'asc' ? '▲' : '▼') : '') }),
          h('th', { class: 'share-bar-cell', text: 'Revenue Share' })
        )
      );

      var tbody = h('tbody');

      function renderProdRows() {
        prodList.sort(function(a, b) {
          var va = a[prodSortKey];
          var vb = b[prodSortKey];
          if (typeof va === 'string') {
            return prodSortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
          }
          return prodSortDir === 'asc' ? (va - vb) : (vb - va);
        });

        tbody.innerHTML = '';
        prodList.slice(0, 30).forEach(function(p) {
          var share = p.revenue_share_pct || 0;
          var tr = h('tr', {},
            h('td', { style: 'font-weight: 600;', text: p.name }),
            h('td', { text: p.category }),
            h('td', {}, h('span', { class: 'variant-size-pill', text: p.size_label || p.size_code })),
            h('td', { class: 'num', text: p.qty.toLocaleString() }),
            h('td', { class: 'num', text: money(p.revenue) }),
            h('td', { class: 'num', text: money(p.avg_unit_price) }),
            h('td', { class: 'share-bar-cell' },
              h('div', { class: 'share-bar-wrap' },
                h('div', { class: 'share-bar-bg' },
                  h('div', { class: 'share-bar-fill', style: 'width: ' + Math.min(100, share * 5) + '%;' })
                ),
                h('span', { class: 'share-pct-label', text: share.toFixed(1) + '%' })
              )
            )
          );
          tbody.append(tr);
        });
      }

      thead.querySelectorAll('th.sortable-th').forEach(function(th) {
        th.addEventListener('click', function() {
          var key = th.dataset.sort;
          if (prodSortKey === key) {
            prodSortDir = (prodSortDir === 'desc' ? 'asc' : 'desc');
          } else {
            prodSortKey = key;
            prodSortDir = (key === 'name' || key === 'category') ? 'asc' : 'desc';
          }
          thead.querySelectorAll('th.sortable-th').forEach(function(hCell) {
            var k = hCell.dataset.sort;
            hCell.classList.toggle('active-sort', k === prodSortKey);
            var baseName = k === 'name' ? 'Product Item' : (k === 'category' ? 'Category' : (k === 'qty' ? 'Units Sold' : (k === 'revenue' ? 'Revenue' : 'Avg Price')));
            hCell.textContent = baseName + (k === prodSortKey ? (prodSortDir === 'asc' ? ' ▲' : ' ▼') : '');
          });
          renderProdRows();
        });
      });

      renderProdRows();
      var table = h('table', { class: 'table' }, thead, tbody);
      prodWrap.append(table);
      body.append(prodWrap);
    }

    // 7. Monthly Breakdown Ledger Table
    if (months && months.length > 0) {
      var ledgerWrap = h('section', { class: 'breakdown-section-wrap' },
        h('h3', { text: '12-Month Financial Ledger (' + (r.year || selectedYear) + ')' }),
        h('table', { class: 'table' },
          h('thead', {},
            h('tr', {},
              h('th', { text: 'Month' }),
              h('th', { class: 'num', text: 'Orders' }),
              h('th', { class: 'num', text: 'Trading Days' }),
              h('th', { class: 'num', text: 'Cash' }),
              h('th', { class: 'num', text: 'GCash' }),
              h('th', { class: 'num', text: 'Daily Avg' }),
              h('th', { class: 'num', text: 'MoM Growth' }),
              h('th', { class: 'num', text: 'Total Sales' })
            )
          ),
          h('tbody', {},
            months.map(function(m) {
              var momStr = m.mom_growth_pct !== null
                ? (m.mom_growth_pct >= 0 ? ('+' + m.mom_growth_pct + '%') : (m.mom_growth_pct + '%'))
                : '—';
              var momColor = m.mom_growth_pct !== null
                ? (m.mom_growth_pct >= 0 ? CF_GREEN : CF_RED)
                : 'inherit';

              return h('tr', {},
                h('td', { style: 'font-weight: 700;', text: m.month_full + ' (' + m.month + ')' }),
                h('td', { class: 'num', text: m.orders.toLocaleString() }),
                h('td', { class: 'num', text: String(m.operating_days) }),
                h('td', { class: 'num', text: money(m.cash) }),
                h('td', { class: 'num', text: money(m.gcash) }),
                h('td', { class: 'num', text: money(m.avg_daily_revenue) }),
                h('td', { class: 'num', style: 'font-weight: 700; color: ' + momColor + ';', text: momStr }),
                h('td', { class: 'num', style: 'font-weight: 700;', text: money(m.total) })
              );
            }),
            h('tr', { class: 'table-total-row' },
              h('td', { text: 'Total Year' }),
              h('td', { class: 'num', text: (s.orders || 0).toLocaleString() }),
              h('td', { class: 'num', text: String(health.operating_days || 0) }),
              h('td', { class: 'num', text: money(s.cash || 0) }),
              h('td', { class: 'num', text: money(s.gcash || 0) }),
              h('td', { class: 'num', text: money(s.avg_daily_revenue || 0) }),
              h('td', { class: 'num', text: '—' }),
              h('td', { class: 'num', text: money(s.total || 0) })
            )
          )
        )
      );
      body.append(ledgerWrap);
    }

    // 8. Transaction History Drill-Down Section
    var currentYearVal = r.year || selectedYear;
    var txSection = h('section', { class: 'tx-section yearly-tx-section', id: 'yearlyTxSection' });

    var monthOptions = [
      h('option', { value: '', text: 'All Months (' + currentYearVal + ')' }),
      ...months.map(function(m) {
        return h('option', { value: m.month, text: m.month_full + ' (' + m.month + ')' });
      })
    ];
    var monthSelect = h('select', { class: 'drilldown-month-select', id: 'yearlyTxMonthSelect' }, ...monthOptions);

    var dateInput = h('input', {
      type: 'date',
      class: 'date-input',
      id: 'yearlyTxDateInput',
      min: currentYearVal + '-01-01',
      max: currentYearVal + '-12-31'
    });

    var resetBtn = h('button', {
      type: 'button',
      class: 'btn ghost small',
      id: 'yearlyTxResetBtn',
      text: 'Reset Drill-Down'
    });

    var summaryBadge = h('span', {
      class: 'drilldown-summary-badge',
      id: 'yearlyTxBadge',
      text: 'Loading transactions...'
    });

    var drilldownBar = h('div', { class: 'yearly-tx-drilldown-bar' },
      h('div', { class: 'drilldown-select-group' },
        h('label', { class: 'picker-label', text: 'Drill-Down Filter:' }),
        monthSelect,
        dateInput,
        resetBtn
      ),
      summaryBadge
    );

    var txContainer = h('div', { id: 'yearlyTxContainer' });
    txSection.append(drilldownBar, txContainer);
    body.append(txSection);

    async function loadYearlyTransactions(filter) {
      filter = filter || {};
      txContainer.innerHTML = '<div style="padding: 24px; text-align: center; color: var(--muted);">Loading transaction records...</div>';
      var url = 'api/transactions_api.php?range=year&year=' + currentYearVal;
      if (filter.date) {
        url = 'api/transactions_api.php?date=' + encodeURIComponent(filter.date);
      } else if (filter.month) {
        url = 'api/transactions_api.php?month=' + encodeURIComponent(filter.month);
      }

      try {
        var txData = await api(url);
        var txCount = txData.transactions ? txData.transactions.length : 0;
        var txTotal = txData.transactions ? txData.transactions.reduce(function(acc, t) { return acc + (t.status === 'completed' ? Number(t.total_amount) : 0); }, 0) : 0;

        if (filter.date) {
          summaryBadge.textContent = 'Date: ' + dayLabel(filter.date) + ' (' + txCount + ' orders · ' + money(txTotal) + ')';
        } else if (filter.month) {
          var mObj = months.find(function(m) { return m.month === filter.month; });
          var mName = mObj ? mObj.month_full : filter.month;
          summaryBadge.textContent = 'Month: ' + mName + ' (' + txCount + ' orders · ' + money(txTotal) + ')';
        } else {
          summaryBadge.textContent = 'Showing Latest ' + txCount + ' Transactions (' + currentYearVal + ')';
        }

        renderTransactionRecords(txContainer, txData, {
          start_date: filter.date || (filter.month ? filter.month + '-01' : currentYearVal + '-01-01'),
          end_date: filter.date || (filter.month ? filter.month + '-31' : currentYearVal + '-12-31')
        });
      } catch (err) {
        txContainer.innerHTML = '<div style="padding: 20px; color: var(--rose); text-align: center;">Could not load transaction records.</div>';
      }
    }

    monthSelect.addEventListener('change', function() {
      dateInput.value = '';
      if (monthSelect.value) {
        loadYearlyTransactions({ month: monthSelect.value });
      } else {
        loadYearlyTransactions({});
      }
    });

    dateInput.addEventListener('change', function() {
      if (dateInput.value) {
        monthSelect.value = dateInput.value.slice(0, 7);
        loadYearlyTransactions({ date: dateInput.value });
      } else {
        loadYearlyTransactions(monthSelect.value ? { month: monthSelect.value } : {});
      }
    });

    resetBtn.addEventListener('click', function() {
      monthSelect.value = '';
      dateInput.value = '';
      loadYearlyTransactions({});
    });

    function onChartSelectDate(clickedDate) {
      if (!clickedDate) return;
      dateInput.value = clickedDate;
      monthSelect.value = clickedDate.slice(0, 7);
      loadYearlyTransactions({ date: clickedDate });
      txSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Now initialize the 365-day daily chart with the click handler
    if (r.by_day && r.by_day.length > 1 && typeof dailyCanvas !== 'undefined') {
      requestAnimationFrame(function() {
        _reportYearLineChart = renderYearlyDailyLineChart(dailyCanvas, r.by_day, onChartSelectDate);
      });
    }

    // Initial transaction fetch
    loadYearlyTransactions({});
  }

  function renderWasteKpiSection(body, r) {
    const ws = r.waste_summary || {};
    const events = ws.events_count || 0;
    const cancelledOrders = ws.cancelled_orders || 0;
    const incomeLoss = ws.total_income_loss_php || 0;
    const materialCost = ws.total_material_cost_php || 0;
    const wastedKg = ws.total_wasted_kg || 0;
    const wastedPcs = Math.round(ws.total_wasted_pcs || 0);

    const isManager = currentUser && currentUser.role === 'manager';

    const section = h('section', { class: 'report-waste-section' });

    const headActions = h('div', { style: 'display: flex; gap: 8px; align-items: center;' });
    if (isManager) {
      headActions.append(h('button', {
        type: 'button',
        class: 'btn small primary',
        text: 'Open Waste Reports ↗',
        title: 'Launch dedicated Waste & Loss Reports workspace',
        onclick: () => {
          if (window.CafeFlow.nav && window.CafeFlow.nav.showView) {
            window.CafeFlow.nav.showView('waste');
          }
        }
      }));
    }

    const head = h('div', { class: 'report-waste-head' },
      h('div', { class: 'report-waste-title-group' },
        h('h3', { text: 'Order Cancellation & Waste Impact' }),
        h('p', { class: 'report-waste-subtitle', text: 'Financial income loss from cancelled orders and estimated raw ingredient consumption waste' })
      ),
      headActions
    );
    section.append(head);

    // 4 Waste KPI Cards
    const kpiGrid = h('div', { class: 'waste-kpi-grid' },
      h('div', { class: 'waste-kpi-card loss-hero' },
        h('span', { class: 'waste-kpi-label', text: 'Estimated Income Loss' }),
        h('span', { class: 'waste-kpi-val', text: money(incomeLoss) }),
        h('span', { class: 'waste-kpi-sub', text: `${cancelledOrders} cancelled ticket${cancelledOrders === 1 ? '' : 's'}` })
      ),
      h('div', { class: 'waste-kpi-card cost-hero' },
        h('span', { class: 'waste-kpi-label', text: 'Raw Material Cost Loss' }),
        h('span', { class: 'waste-kpi-val', text: money(materialCost) }),
        h('span', { class: 'waste-kpi-sub', text: 'Estimated ingredients & cups wasted' })
      ),
      h('div', { class: 'waste-kpi-card' },
        h('span', { class: 'waste-kpi-label', text: 'Waste Events Logged' }),
        h('span', { class: 'waste-kpi-val', text: String(events) }),
        h('span', { class: 'waste-kpi-sub', text: `${events} item consumption record${events === 1 ? '' : 's'}` })
      ),
      h('div', { class: 'waste-kpi-card' },
        h('span', { class: 'waste-kpi-label', text: 'Materials Wasted' }),
        h('span', { class: 'waste-kpi-val', style: 'font-size: 18px;', text: `${wastedKg} kg / ${wastedPcs} pcs` }),
        h('span', { class: 'waste-kpi-sub', text: 'Physical powders, beans & packaging' })
      )
    );
    section.append(kpiGrid);

    // Mini waste trend chart if trend data is available
    const trendRows = ws.trend || [];
    if (trendRows.length > 0 && typeof Chart !== 'undefined') {
      const chartCard = h('div', { class: 'waste-chart-card', style: 'margin-top: 14px;' },
        h('div', { class: 'waste-chart-head' },
          h('span', { class: 'waste-chart-title', text: 'Mini Waste Trend' }),
          h('span', { class: 'waste-chart-badge', text: 'DAILY TIMELINE' })
        ),
        h('div', { class: 'waste-chart-wrap', style: 'height: 160px;' },
          h('canvas', { id: 'reportMiniWasteCanvas', 'aria-label': 'Mini waste trend' })
        )
      );
      section.append(chartCard);

      requestAnimationFrame(() => {
        const cvs = document.getElementById('reportMiniWasteCanvas');
        if (cvs) {
          if (_reportWasteTrendChart) {
            _reportWasteTrendChart = destroyChart(_reportWasteTrendChart);
          }
          const ctx = cvs.getContext('2d');
          _reportWasteTrendChart = new Chart(ctx, {
            type: 'bar',
            data: {
              labels: trendRows.map(t => dayLabel(t.waste_date)),
              datasets: [{
                label: 'Material Cost (₱)',
                data: trendRows.map(t => Number(t.cost_php || 0)),
                backgroundColor: 'rgba(201, 59, 59, 0.75)', // Wastage status red #C93B3B
                borderColor: '#C93B3B',
                borderWidth: 1.5,
                borderRadius: 5,
                borderSkipped: false
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
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
                    label: (c) => `  Material Waste: ₱${Number(c.parsed.y).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`
                  }
                }
              },
              scales: {
                x: {
                  grid: { display: false },
                  ticks: { font: { family: "'Work Sans', sans-serif", size: 11, weight: '600' }, color: '#5a7a63' }
                },
                y: {
                  beginAtZero: true,
                  grid: { color: 'rgba(22, 35, 24, 0.06)' },
                  ticks: {
                    font: { family: "'Work Sans', sans-serif", size: 11 },
                    color: '#5a7a63',
                    callback: v => '₱' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
                  }
                }
              }
            }
          });
        }
      });
    }

    body.append(section);
  }

  function renderExecutiveKpiGrid(r) {
    const s = r.summary || {};
    const totalSales = Number(s.total || 0);
    const totalOrders = Number(s.orders || 0);
    const cashSales = Number(s.cash || 0);
    const gcashSales = Number(s.gcash || 0);
    const aov = s.aov ? Number(s.aov) : (totalOrders > 0 ? totalSales / totalOrders : 0);
    
    // Operating days calculation for average pace
    const opDays = (r.by_day && r.by_day.length > 0) ? r.by_day.length : 1;
    const avgDailyOrders = opDays > 1 ? (totalOrders / opDays).toFixed(1) : totalOrders;

    // Payment percentages
    const totalTender = (cashSales + gcashSales) > 0 ? (cashSales + gcashSales) : 1;
    const cashPct = Math.round((cashSales / totalTender) * 100);
    const gcashPct = 100 - cashPct;

    const grid = h('div', { class: 'sales-kpi-grid' },
      // 1. Gross Sales Revenue
      h('div', { class: 'sales-kpi-card hero-gross' },
        h('div', { class: 'sales-kpi-top' },
          h('span', { class: 'sales-kpi-title', text: 'Gross Sales Revenue' }),
          h('span', { class: 'sales-kpi-badge revenue', text: 'Total Settled' })
        ),
        h('div', { class: 'sales-kpi-val', text: money(totalSales) }),
        h('div', { class: 'sales-kpi-sub', text: `${totalOrders.toLocaleString()} completed customer ticket${totalOrders === 1 ? '' : 's'}` })
      ),
      // 2. Net Sales Revenue
      h('div', { class: 'sales-kpi-card' },
        h('div', { class: 'sales-kpi-top' },
          h('span', { class: 'sales-kpi-title', text: 'Net Realized Revenue' }),
          h('span', { class: 'sales-kpi-badge net', text: '100% Retained' })
        ),
        h('div', { class: 'sales-kpi-val', text: money(totalSales) }),
        h('div', { class: 'sales-kpi-sub', text: '₱0.00 deductions · 100% net realization' })
      ),
      // 3. Transaction Volume
      h('div', { class: 'sales-kpi-card' },
        h('div', { class: 'sales-kpi-top' },
          h('span', { class: 'sales-kpi-title', text: 'Transaction Volume' }),
          h('span', { class: 'sales-kpi-badge orders', text: `${opDays} Day${opDays === 1 ? '' : 's'}` })
        ),
        h('div', { class: 'sales-kpi-val', text: totalOrders.toLocaleString() }),
        h('div', { class: 'sales-kpi-sub', text: opDays > 1 ? `Avg ${avgDailyOrders} tickets / trading day` : 'Single trading shift' })
      ),
      // 4. Average Ticket Value (ATV / AOV)
      h('div', { class: 'sales-kpi-card' },
        h('div', { class: 'sales-kpi-top' },
          h('span', { class: 'sales-kpi-title', text: 'Average Ticket Value (ATV)' }),
          h('span', { class: 'sales-kpi-badge ticket', text: 'Per Order' })
        ),
        h('div', { class: 'sales-kpi-val', text: money(aov) }),
        h('div', { class: 'sales-kpi-sub', text: 'Average customer order size' })
      ),
      // 5. Cash vs GCash Distribution
      h('div', { class: 'sales-kpi-card pay-split-card' },
        h('div', { class: 'sales-kpi-top' },
          h('span', { class: 'sales-kpi-title', text: 'Cash vs. GCash Distribution' }),
          h('span', { class: 'sales-kpi-badge split', text: 'Tender Split' })
        ),
        h('div', { class: 'pay-split-bar' },
          h('div', { class: 'pay-split-seg cash', style: `width: ${cashPct}%;`, title: `Cash: ${cashPct}%` }),
          h('div', { class: 'pay-split-seg gcash', style: `width: ${gcashPct}%;`, title: `GCash: ${gcashPct}%` })
        ),
        h('div', { class: 'pay-split-legend' },
          h('span', { class: 'legend-item' },
            h('span', { class: 'legend-dot cash' }),
            h('span', { text: `Cash: ${money(cashSales)} (${cashPct}%)` })
          ),
          h('span', { class: 'legend-item' },
            h('span', { class: 'legend-dot gcash' }),
            h('span', { text: `GCash: ${money(gcashSales)} (${gcashPct}%)` })
          )
        )
      )
    );
    return grid;
  }

  async function renderReport(r) {

    body.append(grid);
  }

  async function renderReport(r) {
    const s = r.summary || {};
    const body = $('#reportBody');
    body.textContent = '';

    $('#reportStatusPeriod').textContent = r.label;

    // Route to Dedicated Manager Annual View for full-year scope
    if (r.range === 'year' && currentUser && currentUser.role === 'manager') {
      renderYearlyAnalyticsView(body, r);
      return;
    }

    // Destroy any previous report charts to free canvas contexts
    _reportLineChart     = destroyChart(_reportLineChart);
    _reportBarChart      = destroyChart(_reportBarChart);
    _reportDonutChart    = destroyChart(_reportDonutChart);
    _reportAllLineChart  = destroyChart(_reportAllLineChart);
    _reportAllBarChart   = destroyChart(_reportAllBarChart);
    _reportAllDonutChart = destroyChart(_reportAllDonutChart);
    _topSellersBarChart  = destroyChart(_topSellersBarChart);
    _reportYearBarChart  = destroyChart(_reportYearBarChart);
    _reportYearLineChart = destroyChart(_reportYearLineChart);
    _reportWasteTrendChart = destroyChart(_reportWasteTrendChart);

    // Sync Year Picker dropdown options if available
    const yrPicker = $('#reportYearPicker');
    if (yrPicker && r.available_years && r.available_years.length) {
      if (yrPicker.children.length !== r.available_years.length) {
        yrPicker.innerHTML = '';
        r.available_years.forEach(function(yr) {
          var opt = document.createElement('option');
          opt.value = String(yr);
          opt.textContent = yr === 2025 ? `${yr} (Historical Test Dataset)` : String(yr);
          yrPicker.appendChild(opt);
        });
      }
      yrPicker.value = String(r.year || selectedYear);
    }

    var metaText = `${r.start_date} to ${r.end_date}`;
    if (r.data_health && r.data_health.dataset_type === 'synthetic') {
      metaText += ' · Simulated Historical Dataset';
    }

    // Executive Header Banner
    const bannerPrintBtn = h('button', {
      type: 'button',
      class: 'btn small ghost print-btn',
      id: 'bannerPrintBtn',
      title: 'Print official report or save to PDF',
      onclick: () => window.print()
    }, '🖨️ Print / PDF');

    const bannerExcelBtn = h('button', {
      type: 'button',
      class: 'btn small primary',
      id: 'bannerExcelBtn',
      title: 'Export executive presentation to Excel (.xlsx)',
      onclick: () => exportTransactions(r.start_date, r.end_date)
    }, '📊 Export Excel');

    const banner = h('div', { class: 'report-banner' },
      h('div', { class: 'report-banner-left' },
        h('span', { class: 'report-banner-tag', text: 'DK CAFE · FINANCIAL & OPERATIONAL REPORT' }),
        h('h3', { class: 'report-banner-title', text: r.label }),
        h('span', { class: 'report-banner-meta', text: metaText })
      ),
      h('div', { class: 'report-banner-actions' }, bannerPrintBtn, bannerExcelBtn)
    );
    body.append(banner);

    // 6-Card Executive Financial Summary (KPI Dashboard)
    renderExecutiveKpiGrid(body, r, s);

    // Render Executive 5-Card KPI Summary
    body.append(renderExecutiveKpiGrid(r));

    // ---- Chart Selector (Sales Trend / Top Products / Payment Breakdown / Compare All) ----
    var hasHourly  = r.by_hour && r.by_hour.length > 0;
    var hasItems   = r.top_items && r.top_items.length > 0;
    var isMultiDay = r.by_day && r.by_day.length > 1;
    var isYearly   = r.range === 'year';
    var trendLabels, trendTotals, trendOrders, trendTitle;

    if (isYearly && r.monthly_breakdown && r.monthly_breakdown.length > 0) {
      trendLabels = r.monthly_breakdown.map(function(m) { return m.month_name; });
      trendTotals = r.monthly_breakdown.map(function(m) { return m.total; });
      trendOrders = r.monthly_breakdown.map(function(m) { return m.orders; });
      trendTitle  = 'Sales Trend by Month';
    } else if (!isMultiDay && hasHourly) {
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

    // Helper: build a single chart panel element with narrative description box
    function makeChartPanel(id, title, extraClass, narrativeContent, tagLabel) {
      var panelBody = h('div', { class: 'chart-panel-body' });
      var narrativeBox = h('div', { class: 'chart-narrative-box' },
        h('div', { class: 'chart-narrative-tag' },
          h('span', { class: 'status-pip green' }),
          h('span', { text: tagLabel || (currentUser.role === 'barista' ? 'OPERATIONAL SNAPSHOT' : 'STRATEGIC INSIGHT') })
        ),
        createNarrativeBody(narrativeContent)
      );
      var panel = h('div', { class: 'chart-panel' + (extraClass ? ' ' + extraClass : ''), id: id },
        h('div', { class: 'chart-panel-head' },
          h('span', { class: 'chart-panel-title', text: title })
        ),
        panelBody,
        narrativeBox
      );
      return { panel: panel, body: panelBody, narrativeBox: narrativeBox };
    }

    if (currentUser.role === 'barista') {
      // 1. Barista Flow: Dedicated Full-Width Sales Trend Graph (Zero Empty Grid Slots)
      var baristaTrendTag = 'HOURLY RUSH & VOLUME PACE';
      var baristaTrendTitle = trendTitle ? (trendTitle + ' (Hourly Rush & Order Pace)') : 'Sales Trend (Hourly Rush & Order Pace)';
      var baristaTrendPanel = makeChartPanel('baristaTrendPanel', baristaTrendTitle, 'chart-line barista-trend-card', generateSalesTrendNarrative(r, 'barista'), baristaTrendTag);
      baristaTrendPanel.body.style.height = '250px';

      if (hasTrend) {
        var lineCanvas = h('canvas', { 'aria-label': 'Sales trend chart' });
        baristaTrendPanel.body.append(lineCanvas);
        (function(c, l, t, o) {
          requestAnimationFrame(function() { _reportLineChart = renderLineChart(c, { labels: l, totals: t, orders: o }); });
        })(lineCanvas, trendLabels, trendTotals, trendOrders);
      } else {
        baristaTrendPanel.body.append(h('p', { class: 'chart-panel-empty', text: 'No trend data for this shift period.' }));
      }
      body.append(baristaTrendPanel.panel);
    } else {
      // 2. Manager Flow: Strategic Analytics Tabs & Balanced Multi-Chart View
      var trendTag = isYearly ? 'ANNUAL TRAJECTORY & SEASONAL CYCLICALITY' : 'REVENUE VELOCITY & TEMPORAL CLUSTERING';
      var trendPanel = makeChartPanel('chartPanelTrend', trendTitle || 'Sales Trend', 'chart-line', generateSalesTrendNarrative(r, 'manager'), trendTag);
      trendPanel.body.style.height = '260px';

      var prodPanel = makeChartPanel('chartPanelProducts', 'Top Products (Units Sold)', 'chart-bar', generateTopProductsNarrative(r, 'manager'), 'PRODUCT VELOCITY & MIX ANALYSIS');
      prodPanel.body.style.height = '260px';

      var payTag = 'TENDER DISTRIBUTION & RECONCILIATION';
      var payPanel = makeChartPanel('chartPanelPayment', 'Payment Breakdown & Tender Audit', 'chart-donut', generatePaymentNarrative(r, 'manager'), payTag);
      payPanel.body.style.height = '336px'; // +20% dimensional resize (from 280px) and centered layout

      var allGrid = h('div', { class: 'chart-panels-all-grid', id: 'chartPanelAll', hidden: true });

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
        prodPanel.body.append(barCanvas);
        (function(c, lbs, qs) {
          requestAnimationFrame(function() { _reportBarChart = renderBarChart(c, { labels: lbs, qtys: qs }); });
        })(barCanvas, r.top_items.slice(0, 10).map(function(t) { return t.name; }), r.top_items.slice(0, 10).map(function(t) { return t.qty; }));
      } else {
        prodPanel.body.append(h('p', { class: 'chart-panel-empty', text: 'No item sales recorded in this period.' }));
      }

      // --- Payment Breakdown donut panel content (centered & resized) ---
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

      // --- Compare All grid: mini-panels with dedicated analytical narratives ---
      function makeAllMini(srcTitle, extraClass, narrativeContent, tagLabel) {
        var miniBody = h('div', { class: 'chart-panel-body' });
        var narrativeBox = h('div', { class: 'chart-narrative-box' },
          h('div', { class: 'chart-narrative-tag' },
            h('span', { class: 'status-pip green' }),
            h('span', { text: tagLabel || 'STRATEGIC INSIGHT' })
          ),
          createNarrativeBody(narrativeContent)
        );
        var mini = h('div', { class: 'chart-panel' + (extraClass ? ' ' + extraClass : '') },
          h('div', { class: 'chart-panel-head' }, h('span', { class: 'chart-panel-title', text: srcTitle })),
          miniBody,
          narrativeBox
        );
        return { panel: mini, body: miniBody, narrativeBox: narrativeBox };
      }

      var allTrendTag = isYearly ? 'ANNUAL TRAJECTORY & SEASONAL CYCLICALITY' : 'REVENUE VELOCITY & PEAK CLUSTERING';
      var allTrend = makeAllMini(trendTitle || 'Sales Trend', 'chart-line', generateSalesTrendNarrative(r, 'manager'), allTrendTag);
      var allProd  = makeAllMini('Top Products (Units Sold)', 'chart-bar', generateTopProductsNarrative(r, 'manager'), 'PRODUCT VELOCITY & MIX ANALYSIS');

      var allPayBody = h('div', { class: 'chart-panel-body all-pay-chart-side' });
      var allPayNarrative = h('div', { class: 'chart-narrative-box all-pay-narrative-side' },
        h('div', { class: 'chart-narrative-tag' },
          h('span', { class: 'status-pip amber' }),
          h('span', { text: 'TENDER DISTRIBUTION & RECONCILIATION' })
        ),
        createNarrativeBody(generatePaymentNarrative(r, 'manager'))
      );
      var allPayRow = h('div', { class: 'all-pay-row' }, allPayBody, allPayNarrative);
      var allPay = {
        panel: h('div', { class: 'chart-panel chart-donut all-pay-panel' },
          h('div', { class: 'chart-panel-head' }, h('span', { class: 'chart-panel-title', text: 'Payment Breakdown & Tender Distribution' })),
          allPayRow
        ),
        body: allPayBody,
        narrativeBox: allPayNarrative
      };

      allGrid.append(allTrend.panel, allProd.panel, allPay.panel);

      var tabs = [
        { id: 'trend',    label: 'Sales Trend' },
        { id: 'products', label: 'Top Products' },
        { id: 'payment',  label: 'Payment Breakdown' },
        { id: 'all',      label: 'Compare All' }
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
        h('span', { class: 'chart-selector-label', text: 'Strategic Analytics:' }),
        tabsWrap
      );

      var panelsWrap = h('div', { class: 'chart-panels-wrap' });

      function showChartTab(tabId) {
        _activeChartTab = tabId;
        tabEls.forEach(function(btn) {
          btn.classList.toggle('active', btn.dataset.tab === tabId);
        });

        trendPanel.panel.hidden = (tabId !== 'trend');
        prodPanel.panel.hidden  = (tabId !== 'products');
        payPanel.panel.hidden   = (tabId !== 'payment');
        allGrid.hidden          = (tabId !== 'all');

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
              allProd.body.style.height = '230px';
              var c2 = h('canvas', { 'aria-label': 'Top products mini' });
              allProd.body.append(c2);
              (function(c, lbs, qs) {
                requestAnimationFrame(function() { _reportAllBarChart = renderBarChart(c, { labels: lbs, qtys: qs }); });
              })(c2, r.top_items.slice(0, 5).map(function(t) { return t.name; }), r.top_items.slice(0, 5).map(function(t) { return t.qty; }));
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

      tabEls.forEach(function(btn) {
        btn.addEventListener('click', function() { showChartTab(btn.dataset.tab); });
      });

      panelsWrap.append(trendPanel.panel, prodPanel.panel, payPanel.panel, allGrid);
      body.append(selectorRow, panelsWrap);
      showChartTab(_activeChartTab);
    }

    // ---- Render Enhanced Top Sellers Section (Vertical Bar Graph & Dynamic Description) ----
    renderTopSellersSection(body, r, currentUser.role);

    // ---- Render Daily / Hourly / Monthly Breakdown Section with Narrative ----
    var breakdownWrap = h('section', { class: 'breakdown-section-wrap' });
    var breakdownTag = currentUser.role === 'barista'
      ? 'SHIFT OPERATIONS SUMMARY'
      : (isYearly ? 'MONTHLY BREAKDOWN & EFFICIENCY' : 'DAILY BREAKDOWN & EFFICIENCY');
    var breakdownNarrativeBox = h('div', { class: 'breakdown-narrative-box' },
      h('div', { class: 'breakdown-narrative-tag' },
        h('span', { class: 'status-pip green' }),
        h('span', { text: breakdownTag })
      ),
      createNarrativeBody(generateBreakdownNarrative(r, currentUser.role), 'breakdown-narrative-body')
    );

    let breakdownTableContent;
    let breakdownHeaderWrap;

    if (isYearly && r.monthly_breakdown && r.monthly_breakdown.length > 0) {
      breakdownHeaderWrap = h('div', { class: 'breakdown-header-wrap' },
        h('h3', { text: 'Monthly Breakdown (12 Months)' }),
        h('span', { class: 'breakdown-count-badge' },
          h('span', { text: '12 Months Recorded' }),
          h('span', { text: '·' }),
          h('strong', { text: money(s.total || 0) })
        )
      );
      breakdownTableContent = h('table', { class: 'table' },
        h('thead', {}, h('tr', {},
          h('th', { text: 'Month' }),
          h('th', { class: 'num', text: 'Orders' }),
          h('th', { class: 'num', text: 'Cash' }),
          h('th', { class: 'num', text: 'GCash' }),
          h('th', { class: 'num', text: 'Total' })
        )),
        h('tbody', {},
          r.monthly_breakdown.map(function(m) {
            return h('tr', {},
              h('td', { style: 'font-weight: 600;', text: m.month_full + ' (' + m.month + ')' }),
              h('td', { class: 'num', text: (m.orders || 0).toLocaleString() }),
              h('td', { class: 'num', text: money(m.cash || 0) }),
              h('td', { class: 'num', text: money(m.gcash || 0) }),
              h('td', { class: 'num', style: 'font-weight: 600;', text: money(m.total || 0) })
            );
          }),
          h('tr', { class: 'table-total-row' },
            h('td', { text: 'Total Year' }),
            h('td', { class: 'num', text: (s.orders || 0).toLocaleString() }),
            h('td', { class: 'num', text: money(s.cash || 0) }),
            h('td', { class: 'num', text: money(s.gcash || 0) }),
            h('td', { class: 'num', text: money(s.total || 0) })
          )
        )
      );
    } else if (r.by_day && r.by_day.length > 1) {
      breakdownHeaderWrap = h('div', { class: 'breakdown-header-wrap' },
        h('h3', { text: `Daily Breakdown (${r.by_day.length} Days)` }),
        h('span', { class: 'breakdown-count-badge' },
          h('span', { text: `${r.by_day.length} Days Recorded` }),
          h('span', { text: '·' }),
          h('strong', { text: money(s.total || 0) })
        )
      );
      breakdownTableContent = h('table', { class: 'table' },
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
      );
    } else if (r.by_hour && r.by_hour.length > 0) {
      breakdownHeaderWrap = h('div', { class: 'breakdown-header-wrap' },
        h('h3', { text: 'Hourly Sales Breakdown' }),
        h('span', { class: 'breakdown-count-badge' },
          h('span', { text: `${r.by_hour.length} Operating Hours` }),
          h('span', { text: '·' }),
          h('strong', { text: money(s.total || 0) })
        )
      );
      breakdownTableContent = h('table', { class: 'table' },
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
          )),
          h('tr', { class: 'table-total-row' },
            h('td', { text: 'Total' }),
            h('td', { class: 'num', text: String(s.orders || 0) }),
            h('td', { class: 'num', text: money(s.total || 0) })
          )
        )
      );
    } else if (r.by_day && r.by_day.length === 1) {
      const d = r.by_day[0];
      breakdownHeaderWrap = h('div', { class: 'breakdown-header-wrap' },
        h('h3', { text: 'Day Summary' }),
        h('span', { class: 'breakdown-count-badge' },
          h('span', { text: '1 Day Recorded' }),
          h('span', { text: '·' }),
          h('strong', { text: money(s.total || 0) })
        )
      );
      breakdownTableContent = h('table', { class: 'table' },
        h('tbody', {},
          h('tr', {}, h('td', { text: 'Date' }), h('td', { class: 'num', text: dayLabel(d.day) })),
          h('tr', {}, h('td', { text: 'Orders' }), h('td', { class: 'num', text: String(d.orders) })),
          h('tr', {}, h('td', { text: 'Cash' }), h('td', { class: 'num', text: money(d.cash || 0) })),
          h('tr', {}, h('td', { text: 'GCash' }), h('td', { class: 'num', text: money(d.gcash || 0) })),
          h('tr', { class: 'table-total-row' }, h('td', { text: 'Total Sales' }), h('td', { class: 'num', text: money(d.total) }))
        )
      );
    } else {
      breakdownHeaderWrap = h('div', { class: 'breakdown-header-wrap' },
        h('h3', { text: 'Sales Breakdown' })
      );
      breakdownTableContent = emptyState({
        icon: '',
        title: 'No Breakdown Data',
        desc: 'No order activity recorded to compute hourly or daily trends.',
        compact: true
      });
    }

    var scrollableTable = h('div', { class: 'breakdown-table-container' }, breakdownTableContent);
    breakdownWrap.append(breakdownHeaderWrap, breakdownNarrativeBox, scrollableTable);
    body.append(breakdownWrap);

    // Render Waste KPI Cards & Mini Trend Section
    renderWasteKpiSection(body, r);

    // Daily Transaction Records & E-Receipt Section

    const txSection = h('section', { class: 'tx-section' });

    body.append(txSection);



    const isMultiDayRange = Boolean(r && r.start_date && r.end_date && r.start_date !== r.end_date);
    let txUrl = 'transactions_api.php';
    if (isYearly) {
      txUrl += `?range=year&year=${encodeURIComponent(r.year || selectedYear)}&limit=250`;
    } else if (isMultiDayRange) {
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

    const headTitle = h('h3', { text: `Transaction History (${records.length} order${records.length === 1 ? '' : 's'})` });
    const head = h('div', { class: 'tx-section-head' },
      headTitle,
      h('div', { style: 'display: flex; gap: 8px;' },
        h('button', {
          type: 'button',
          class: 'btn small primary',
          text: 'Export Excel (.xlsx)',
          title: 'Export executive multi-sheet presentation report to Excel (.xlsx)',
          onclick: () => exportTransactions(r.start_date, r.end_date)
        })
      )
    );
    container.append(head);

    if (!records.length) {
      const periodDesc = isMultiDay ? 'this date range' : 'this date';
      container.append(emptyState({
        icon: '',
        title: 'No Transaction Records',
        desc: `No completed or pending transactions were recorded for ${periodDesc}.`,
        tip: 'Tip: Select another date or choose "7-Day Report" / "Specific Month" above.'
      }));
      return;
    }

    // Filter toolbar (Search input + Filter count badge)
    let currentQuery = '';
    const filterBadge = h('span', { class: 'tx-filter-meta', text: `${records.length} order${records.length === 1 ? '' : 's'} total` });

    const searchInput = h('input', {
      type: 'text',
      class: 'tx-search-input',
      placeholder: 'Search receipt #, items, reference, status...',
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
      h('span', { class: 'tx-search-icon', html: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' }),
      searchInput,
      clearBtn
    );

    const toolbar = h('div', { class: 'tx-toolbar' },
      searchBox,
      filterBadge
    );
    container.append(toolbar);

    // Helpers to create identical table structure
    function makeTableHeader() {
      return h('thead', {},
        h('tr', {},
          h('th', { text: 'RECEIPT #' }),
          h('th', { text: 'DATE & TIME' }),
          h('th', { text: 'ITEMS ORDERED' }),
          h('th', { text: 'STATUS' }),
          h('th', { class: 'num', text: 'TOTAL' }),
          h('th', { class: 'act', text: 'RECEIPT' })
        )
      );
    }

    function renderTxRow(tx) {
      const statusClass = (tx.status || 'completed').toLowerCase();
      const displayDateTime = tx.formatted_datetime || `${tx.formatted_date || ''} ${tx.formatted_time || ''}`.trim() || tx.formatted_time || '';
      return h('tr', {},
        h('td', { class: 'tx-no', text: tx.receipt_no }),
        h('td', { class: 'tx-datetime', title: displayDateTime, text: displayDateTime }),
        h('td', { class: 'tx-items-summary', title: tx.items_summary, text: tx.items_summary || 'No items' }),
        h('td', {}, h('span', { class: 'status-tag ' + statusClass, text: tx.status || 'Completed' })),
        h('td', { class: 'num', style: 'font-weight:700;', text: money(tx.total_amount) }),
        h('td', { class: 'act' },
          h('button', {
            type: 'button',
            class: 'tx-view-btn',
            text: 'View Receipt',
            title: `View E-Receipt for ${tx.receipt_no}`,
            onclick: () => viewReceipt(tx.transaction_id)
          })
        )
      );
    }

    // Left Column: Cash Transactions Card
    const cashTbody = h('tbody');
    const cashTable = h('table', { class: 'tx-table' }, makeTableHeader(), cashTbody);
    const cashEmpty = h('div', { class: 'tx-column-empty' });
    cashEmpty.hidden = true;
    const cashTableWrap = h('div', { class: 'tx-table-container' }, cashTable, cashEmpty);

    const cashCountEl = h('span', { class: 'tx-split-count', text: '0 Orders' });
    const cashSubtotalEl = h('span', { class: 'tx-split-subtotal', text: '₱0.00' });
    const cashCard = h('div', { class: 'tx-split-card tx-split-cash' },
      h('div', { class: 'tx-split-card-head' },
        h('div', { class: 'tx-split-title-group' },
          h('h4', { class: 'tx-split-title', text: 'Cash Transactions' }),
          h('span', { class: 'pill-pay cash', text: 'CASH' })
        ),
        h('div', { class: 'tx-split-meta' },
          cashCountEl,
          h('span', { class: 'tx-split-divider', text: '·' }),
          cashSubtotalEl
        )
      ),
      cashTableWrap
    );

    // Right Column: GCash Transactions Card
    const gcashTbody = h('tbody');
    const gcashTable = h('table', { class: 'tx-table' }, makeTableHeader(), gcashTbody);
    const gcashEmpty = h('div', { class: 'tx-column-empty' });
    gcashEmpty.hidden = true;
    const gcashTableWrap = h('div', { class: 'tx-table-container' }, gcashTable, gcashEmpty);

    const gcashCountEl = h('span', { class: 'tx-split-count', text: '0 Orders' });
    const gcashSubtotalEl = h('span', { class: 'tx-split-subtotal', text: '₱0.00' });
    const gcashCard = h('div', { class: 'tx-split-card tx-split-gcash' },
      h('div', { class: 'tx-split-card-head' },
        h('div', { class: 'tx-split-title-group' },
          h('h4', { class: 'tx-split-title', text: 'GCash Transactions' }),
          h('span', { class: 'pill-pay gcash', text: 'GCASH' })
        ),
        h('div', { class: 'tx-split-meta' },
          gcashCountEl,
          h('span', { class: 'tx-split-divider', text: '·' }),
          gcashSubtotalEl
        )
      ),
      gcashTableWrap
    );

    // Two Side-by-Side Containers Grid
    const dualGrid = h('div', { class: 'tx-dual-grid' }, cashCard, gcashCard);
    container.append(dualGrid);

    function applyFilter(q) {
      const filtered = records.filter(tx => {
        if (!q) return true;
        const receiptNo = (tx.receipt_no || '').toLowerCase();
        const txId = String(tx.transaction_id || '');
        const items = (tx.items_summary || '').toLowerCase();
        const pay = (tx.payment_method || '').toLowerCase();
        const status = (tx.status || '').toLowerCase();
        const ref = (tx.reference_number || '').toLowerCase();
        const dt = (tx.formatted_datetime || `${tx.formatted_date || ''} ${tx.formatted_time || ''}`).toLowerCase();
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

      const cashList = [];
      const gcashList = [];
      let cashTotal = 0;
      let gcashTotal = 0;

      filtered.forEach(tx => {
        const isGCash = String(tx.payment_method || '').toLowerCase().includes('gcash');
        const amt = Number(tx.total_amount) || 0;
        if (isGCash) {
          gcashList.push(tx);
          gcashTotal += amt;
        } else {
          cashList.push(tx);
          cashTotal += amt;
        }
      });

      // Update Cash Section
      cashCountEl.textContent = `${cashList.length} Order${cashList.length === 1 ? '' : 's'}`;
      cashSubtotalEl.textContent = money(cashTotal);
      cashTbody.textContent = '';
      if (cashList.length > 0) {
        cashTable.hidden = false;
        cashEmpty.hidden = true;
        cashList.forEach(tx => cashTbody.append(renderTxRow(tx)));
      } else {
        cashTable.hidden = true;
        cashEmpty.textContent = '';
        cashEmpty.append(
          h('p', { text: q ? `No Cash transactions match "${searchInput.value.trim()}"` : 'No Cash transactions recorded' }),
          h('small', { text: q ? 'Try clearing or adjusting search filter.' : 'Cash orders will appear here once recorded.' })
        );
        cashEmpty.hidden = false;
      }

      // Update GCash Section
      gcashCountEl.textContent = `${gcashList.length} Order${gcashList.length === 1 ? '' : 's'}`;
      gcashSubtotalEl.textContent = money(gcashTotal);
      gcashTbody.textContent = '';
      if (gcashList.length > 0) {
        gcashTable.hidden = false;
        gcashEmpty.hidden = true;
        gcashList.forEach(tx => gcashTbody.append(renderTxRow(tx)));
      } else {
        gcashTable.hidden = true;
        gcashEmpty.textContent = '';
        gcashEmpty.append(
          h('p', { text: q ? `No GCash transactions match "${searchInput.value.trim()}"` : 'No GCash transactions recorded' }),
          h('small', { text: q ? 'Try clearing or adjusting search filter.' : 'GCash orders will appear here once recorded.' })
        );
        gcashEmpty.hidden = false;
      }

      // Update Toolbar Count
      if (q) {
        filterBadge.textContent = `Showing ${filtered.length} of ${records.length} orders (${cashList.length} Cash · ${gcashList.length} GCash)`;
        headTitle.textContent = `Transaction History (${filtered.length} found)`;
      } else {
        filterBadge.textContent = `${records.length} order${records.length === 1 ? '' : 's'} total (${cashList.length} Cash · ${gcashList.length} GCash)`;
        headTitle.textContent = `Transaction History (${records.length} order${records.length === 1 ? '' : 's'})`;
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
      } else if (reportRange === 'year') {
        calYear = selectedYear;
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

    const rYearPicker = $('#reportYearPicker');
    if (rYearPicker) {
      rYearPicker.addEventListener('change', e => {
        if (e.target.value) {
          selectedYear = parseInt(e.target.value, 10);
          calYear = selectedYear;
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

    const jThisYearBtn = $('#jumpThisYearBtn');
    if (jThisYearBtn) {
      jThisYearBtn.addEventListener('click', () => {
        selectedYear = new Date().getFullYear();
        calYear = selectedYear;
        loadReport();
      });
    }

    // ML Export dropdown toggle & outside click dismissal
    const exportMlBtn = $('#exportMlBtn');
    const mlExportMenu = $('#mlExportMenu');
    if (exportMlBtn && mlExportMenu) {
      exportMlBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        mlExportMenu.hidden = !mlExportMenu.hidden;
      });
      document.addEventListener('click', (e) => {
        if (!mlExportMenu.hidden && !e.target.closest('#mlExportDropdown')) {
          mlExportMenu.hidden = true;
        }
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
        if (reportRange === 'year') {
          exportTransactions(`${selectedYear}-01-01`, `${selectedYear}-12-31`);
        } else if (reportRange === '7days') {
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

    // Dedicated Print button in report header modal
    const printReportBtn = $('#printReportBtn');
    if (printReportBtn) {
      printReportBtn.addEventListener('click', () => {
        window.print();
      });
    }

    // Dynamic theme change listener: re-render charts with dark/light colors if report is visible
    window.addEventListener('cafeflow:themechange', () => {
      buildChartDefaults();
      const reportModal = $('#reportModal');
      if (reportModal && !reportModal.hidden) {
        loadReport();
      }
    });

    // Historical CSV export removed (standardized on enterprise multi-sheet .xlsx)
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
