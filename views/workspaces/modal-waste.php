  <!-- ===== Dedicated Waste & Loss Reports Modal ===== -->
  <div class="modal" id="wasteModal" hidden>
    <div class="modal-card waste-modal-card">
      <div class="modal-head">
        <div class="modal-title-wrap">
          <h2>Waste &amp; Loss Reports</h2>
          <p class="modal-subtitle">Track raw ingredient consumption waste and estimated financial loss from cancelled orders.</p>
        </div>
        <div class="seg" id="wastePeriodSeg" role="group" aria-label="Waste reporting period">
          <button type="button" data-waste-period="hourly">Hourly</button>
          <button type="button" class="on" data-waste-period="daily">Daily</button>
          <button type="button" data-waste-period="weekly">Weekly</button>
          <button type="button" data-waste-period="monthly">Monthly</button>
          <button type="button" data-waste-period="yearly">Yearly</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small primary" id="wasteRefreshBtn" title="Refresh waste data">Refresh</button>
          <button type="button" class="btn small" id="wasteManualEntryBtn" title="Log manual waste entry">+ Log Manual Waste</button>
          <button type="button" class="btn ghost" data-close>Close</button>
        </div>
      </div>

      <!-- Waste Contextual Toolbar -->
      <div class="report-toolbar" id="wasteToolbar">
        <div class="toolbar-nav">
          <button type="button" class="btn-step" id="wastePrevPeriodBtn" title="Previous period">◀</button>

          <div class="picker-group" id="wasteHourlyPickerGroup" hidden>
            <label for="wasteHourlyDatePicker" class="picker-label">Date:</label>
            <input type="date" id="wasteHourlyDatePicker" class="date-input">
            <span class="pill-range">24-Hour Breakdown</span>
            <button type="button" class="btn ghost small" id="wasteJumpHourlyTodayBtn">Today</button>
          </div>

          <div class="picker-group" id="wasteDailyPickerGroup">
            <label for="wasteDailyDatePicker" class="picker-label">Date:</label>
            <input type="date" id="wasteDailyDatePicker" class="date-input">
            <span class="pill-range">Single Day</span>
            <button type="button" class="btn ghost small" id="wasteJumpDailyTodayBtn">Today</button>
          </div>

          <div class="picker-group" id="wasteWeeklyPickerGroup" hidden>
            <label for="wasteWeeklyDatePicker" class="picker-label">Start Date:</label>
            <input type="date" id="wasteWeeklyDatePicker" class="date-input">
            <span class="pill-range" id="wasteWeeklyBadge">7-Day Span</span>
            <button type="button" class="btn ghost small" id="wasteJumpThisWeekBtn">This Week</button>
          </div>

          <div class="picker-group" id="wasteMonthlyPickerGroup" hidden>
            <label for="wasteMonthPicker" class="picker-label">Month:</label>
            <input type="month" id="wasteMonthPicker" class="date-input">
            <span class="pill-range" id="wasteMonthlyBadge">Full Month</span>
            <button type="button" class="btn ghost small" id="wasteJumpThisMonthBtn">This Month</button>
          </div>

          <div class="picker-group" id="wasteYearlyPickerGroup" hidden>
            <label for="wasteYearSelect" class="picker-label">Year:</label>
            <select id="wasteYearSelect" class="year-select"></select>
            <span class="pill-range">Annual Window</span>
            <button type="button" class="btn ghost small" id="wasteJumpThisYearBtn">This Year</button>
          </div>

          <button type="button" class="btn-step" id="wasteNextPeriodBtn" title="Next period">▶</button>
        </div>

        <div class="toolbar-status">
          <span class="status-period" id="wasteStatusPeriod">Loading waste audit data...</span>
        </div>
      </div>

      <!-- View Navigation Tabs -->
      <div class="waste-view-tabs" id="wasteViewTabs">
        <button type="button" class="waste-tab active" data-view-tab="overview">Overview &amp; Charts</button>
        <button type="button" class="waste-tab" data-view-tab="materials">Material Waste Summary</button>
        <button type="button" class="waste-tab" data-view-tab="incomeloss">Income Loss Report</button>
        <button type="button" class="waste-tab" data-view-tab="log">Waste Event Logs</button>
      </div>

      <div class="modal-body" id="wasteBody"></div>
    </div>
  </div>
