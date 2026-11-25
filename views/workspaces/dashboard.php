  <!-- ===== Manager Dedicated Dashboard Workspace ===== -->
  <div class="manager-dashboard-container" id="managerDashboard">
    <div class="mgr-welcome-banner">
      <div>
        <h1>Welcome back, <?= htmlspecialchars($currentUser['full_name']) ?>!</h1>
        <p>Manager Portal &middot; Access raw inventory levels, sales revenue analytics, and account administration.</p>
      </div>
      <span class="mgr-banner-badge">Role: Manager (Administrative Access)</span>
    </div>

    <div class="mgr-cards-grid">
      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Raw Supplies Inventory</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardSupplyCount">--</div>
        <p class="muted" style="margin: 0 0 10px;">Monitor stock counts, restock raw materials, and inspect low-stock alerts.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenInventoryBtn">Manage Inventory</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Today's Sales Revenue</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardSalesToday">₱0.00</div>
        <p class="muted" style="margin: 0 0 10px;">Inspect historical transaction records, date spans, and export spreadsheets.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenReportBtn">View Sales Reports</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Account Administration</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardUserCount">--</div>
        <p class="muted" style="margin: 0 0 10px;">Create Barista/Manager accounts, approve registrations, and manage credentials.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenAccountsBtn">Manage Accounts</button>
      </div>

      <div class="mgr-card">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Waste &amp; Loss Tracking</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardWasteToday">₱0.00 loss</div>
        <p class="muted" style="margin: 0 0 10px;">Monitor raw material waste, ingredient consumption, and income loss from cancelled orders.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenWasteBtn">View Waste Reports</button>
      </div>

      <div class="mgr-card mgr-card-featured">
        <div class="mgr-card-header">
          <span class="mgr-card-title">Predictive Sales Analytics</span>
          <div class="mgr-card-icon-badge">
            <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
          </div>
        </div>
        <div class="mgr-card-value" id="mgrCardPredictiveHighlight">Forecast Engine</div>
        <p class="muted" style="margin: 0 0 10px;">Seasonality-aware ML demand forecasting for daily, 7-day, monthly, and yearly revenue.</p>
        <button type="button" class="btn primary mgr-card-btn" id="mgrOpenPredictiveBtn">Launch Predictive Engine</button>
      </div>
    </div>

    <!-- ===== Manager Dashboard Charts (Option B) ===== -->
    <div class="mgr-charts-section" id="mgrChartsSection">
      <div class="mgr-charts-header">
        <h2 class="mgr-charts-title">Today's Sales Overview</h2>
        <span class="mgr-charts-subtitle" id="mgrChartsPeriodLabel">Loading...</span>
      </div>
      <div class="mgr-charts-grid">
        <div class="mgr-chart-card">
          <div class="mgr-chart-card-head">
            <span class="mgr-chart-card-title">Hourly Sales Trend (₱)</span>
          </div>
          <div class="mgr-chart-wrap">
            <canvas id="mgrLineChart" aria-label="Hourly sales trend chart"></canvas>
            <p class="mgr-chart-empty" id="mgrLineChartEmpty" hidden>No hourly data for today yet.</p>
          </div>
        </div>
        <div class="mgr-chart-card">
          <div class="mgr-chart-card-head">
            <span class="mgr-chart-card-title">Top Sellers Today (Units)</span>
          </div>
          <div class="mgr-chart-wrap">
            <canvas id="mgrBarChart" aria-label="Top selling products chart"></canvas>
            <p class="mgr-chart-empty" id="mgrBarChartEmpty" hidden>No product sales recorded today.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
