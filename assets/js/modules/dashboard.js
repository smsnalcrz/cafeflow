/**
 * CafeFlow POS - Manager Dashboard Module
 * Provides business-owner KPI overview, accounts count, inventory health alerts,
 * and today's sales trends and top seller bar charts.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, money, toIsoDate, formatHour } = window.CafeFlow.utils;
  const api = window.CafeFlow.api;

  let _mgrLineChart = null;
  let _mgrBarChart = null;

  async function loadManagerDashboard() {
    try {
      // 1. Load accounts overview
      const accData = await api('accounts_api.php');
      const userCountEl = $('#mgrCardUserCount');
      if (userCountEl) {
        userCountEl.textContent = (accData.overview && accData.overview.total ? accData.overview.total : 0) + ' accounts';
      }

      // 2. Load inventory items count
      const invData = await api('inventory_api.php');
      const supplyCountEl = $('#mgrCardSupplyCount');
      if (supplyCountEl) {
        const totalItems = (invData.items || []).length;
        const lowCount = (invData.items || []).filter(function(i) { return i.low; }).length;
        supplyCountEl.textContent = totalItems + ' items' + (lowCount > 0 ? ' (' + lowCount + ' low)' : '');
      }

      // 3. Load today's sales + render dashboard charts
      const todayIso = toIsoDate(new Date());
      const repData = await api('report.php?range=date&date=' + encodeURIComponent(todayIso));
      const salesTodayEl = $('#mgrCardSalesToday');
      if (salesTodayEl) {
        const totalSales = (repData.summary && repData.summary.total) || 0;
        salesTodayEl.textContent = money(totalSales);
      }

      // Update dashboard chart subtitle
      const periodLabel = $('#mgrChartsPeriodLabel');
      if (periodLabel) periodLabel.textContent = repData.label || todayIso;

      const reports = window.CafeFlow.reports || {};

      // Dashboard Line Chart: Hourly Sales Trend
      const lineCanvasEl = $('#mgrLineChart');
      const lineEmptyEl  = $('#mgrLineChartEmpty');
      if (lineCanvasEl && reports.destroyChart && reports.buildHourlyDataset && reports.renderLineChart) {
        _mgrLineChart = reports.destroyChart(_mgrLineChart);
        const byHour = repData.by_hour || [];
        if (byHour.length > 0) {
          lineCanvasEl.hidden = false;
          if (lineEmptyEl) lineEmptyEl.hidden = true;
          const ds = reports.buildHourlyDataset(byHour);
          const lastActive = Math.max.apply(null, byHour.map(function(x) { return x.hour; }));
          const sliceEnd   = Math.max(lastActive + 1, 12);
          const labels  = Array.from({ length: sliceEnd - 6 }, function(_, i) { return formatHour(i + 6); });
          const totals  = ds.totals.slice(6, sliceEnd);
          const orders  = ds.orders.slice(6, sliceEnd);
          _mgrLineChart = reports.renderLineChart(lineCanvasEl, { labels: labels, totals: totals, orders: orders });
        } else {
          lineCanvasEl.hidden = true;
          if (lineEmptyEl) lineEmptyEl.hidden = false;
        }
      }

      // Dashboard Bar Chart: Top Sellers
      const barCanvasEl = $('#mgrBarChart');
      const barEmptyEl  = $('#mgrBarChartEmpty');
      if (barCanvasEl && reports.destroyChart && reports.renderBarChart) {
        _mgrBarChart = reports.destroyChart(_mgrBarChart);
        const topItems = repData.top_items || [];
        if (topItems.length > 0) {
          barCanvasEl.hidden = false;
          if (barEmptyEl) barEmptyEl.hidden = true;
          const barWrap = barCanvasEl.closest('.mgr-chart-wrap');
          if (barWrap) barWrap.style.height = Math.max(180, topItems.length * 36) + 'px';
          _mgrBarChart = reports.renderBarChart(barCanvasEl, {
            labels: topItems.map(function(t) { return t.name; }),
            qtys:   topItems.map(function(t) { return t.qty; })
          });
        } else {
          barCanvasEl.hidden = true;
          if (barEmptyEl) barEmptyEl.hidden = false;
        }
      }
    } catch (_) {
      // Ignore background stats refresh errors
    }
  }

  function initDashboardEvents() {
    const mgrOpenInventoryBtn = $('#mgrOpenInventoryBtn');
    if (mgrOpenInventoryBtn) {
      mgrOpenInventoryBtn.addEventListener('click', () => {
        if (window.CafeFlow.nav && window.CafeFlow.nav.showView) {
          window.CafeFlow.nav.showView('inventory');
        }
      });
    }

    const mgrOpenReportBtn = $('#mgrOpenReportBtn');
    if (mgrOpenReportBtn) {
      mgrOpenReportBtn.addEventListener('click', () => {
        if (window.CafeFlow.nav && window.CafeFlow.nav.showView) {
          window.CafeFlow.nav.showView('report');
        }
      });
    }

    const mgrOpenAccountsBtn = $('#mgrOpenAccountsBtn');
    if (mgrOpenAccountsBtn) {
      mgrOpenAccountsBtn.addEventListener('click', () => {
        if (window.CafeFlow.nav && window.CafeFlow.nav.showView) {
          window.CafeFlow.nav.showView('accounts');
        }
      });
    }
  }

  window.CafeFlow.dashboard = {
    loadManagerDashboard,
    initDashboardEvents
  };
})();
