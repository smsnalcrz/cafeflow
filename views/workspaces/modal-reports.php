  <!-- ===== Sales report modal ===== -->
  <div class="modal" id="reportModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>Sales Report & Transactions</h2>
        <div class="seg" id="rangeSeg" role="group" aria-label="Report period">
          <button type="button" class="on" data-range="today">Today</button>
          <button type="button" data-range="date">Specific Date</button>
          <button type="button" data-range="7days">7-Day Report</button>
          <button type="button" data-range="month">Specific Month</button>
          <?php if ($currentRole === 'manager'): ?>
          <button type="button" data-range="year" id="yearlyRangeBtn">Yearly</button>
          <?php endif; ?>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small" id="toggleCalBtn" title="Open or close visual calendar">Calendar</button>
          <button type="button" class="btn small primary" id="exportExcelBtn" title="Export transactions to Excel spreadsheet">Export Excel (.xlsx)</button>
          <button type="button" class="btn small" id="printReportBtn" title="Print executive report or export to PDF">🖨️ Print / PDF</button>
          <button type="button" class="btn ghost" data-close>Close</button>
        </div>
      </div>

      <!-- Contextual toolbar for stepping, picking, and quick navigation -->
      <div class="report-toolbar" id="reportToolbar">
        <div class="toolbar-nav">
          <button type="button" class="btn-step" id="prevPeriodBtn" title="Previous period">◀</button>
          
          <div class="picker-group" id="todayPickerGroup">
            <span class="pill-badge" id="todayBadge">Today's Live Sales</span>
          </div>

          <div class="picker-group" id="datePickerGroup" hidden>
            <label for="reportDatePicker" class="picker-label">Date:</label>
            <input type="date" id="reportDatePicker" class="date-input">
            <button type="button" class="btn ghost small" id="jumpTodayBtn">Jump to Today</button>
          </div>

          <div class="picker-group" id="sevenDayPickerGroup" hidden>
            <label for="report7DayPicker" class="picker-label">7-Day Start:</label>
            <input type="date" id="report7DayPicker" class="date-input">
            <span class="pill-range" id="report7DayBadge">7-Day Span</span>
            <button type="button" class="btn ghost small" id="jumpLast7Btn">Last 7 Days</button>
          </div>

          <div class="picker-group" id="monthPickerGroup" hidden>
            <label for="reportMonthPicker" class="picker-label">Month:</label>
            <input type="month" id="reportMonthPicker" class="date-input">
            <button type="button" class="btn ghost small" id="jumpThisMonthBtn">This Month</button>
          </div>

          <div class="picker-group" id="yearPickerGroup" hidden>
            <label for="reportYearPicker" class="picker-label">Year:</label>
            <select id="reportYearPicker" class="year-select"></select>
            <button type="button" class="btn ghost small" id="jumpThisYearBtn">This Year</button>
          </div>

          <button type="button" class="btn-step" id="nextPeriodBtn" title="Next period">▶</button>
        </div>

        <div class="toolbar-status">
          <span class="status-period" id="reportStatusPeriod">Loading...</span>
        </div>
      </div>

      <!-- Interactive visual calendar drawer -->
      <div class="cal-drawer" id="reportCalDrawer" hidden>
        <div class="cal-card">
          <div class="cal-head">
            <button type="button" class="cal-nav-btn" id="calPrevMonth" title="Previous month">◀</button>
            <div class="cal-selects">
              <select id="calMonthSelect" aria-label="Select month" class="cal-select"></select>
              <select id="calYearSelect" aria-label="Select year" class="cal-select"></select>
            </div>
            <button type="button" class="cal-nav-btn" id="calNextMonth" title="Next month">▶</button>
            <span class="cal-tip" id="calModeTip">Tap a date to inspect report</span>
            <button type="button" class="btn ghost small cal-close-btn" id="calCloseBtn">Close Calendar</button>
          </div>

          <div class="cal-weekdays">
            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
          </div>

          <div class="cal-grid" id="calDaysGrid"></div>

          <div class="cal-foot">
            <span class="cal-foot-label">Quick select:</span>
            <div class="cal-presets">
              <button type="button" class="cal-pill" data-cal-action="today">Today</button>
              <button type="button" class="cal-pill" data-cal-action="yesterday">Yesterday</button>
              <button type="button" class="cal-pill" data-cal-action="last7">Last 7 Days</button>
              <button type="button" class="cal-pill" data-cal-action="thisMonth">This Month</button>
              <button type="button" class="cal-pill" data-cal-action="lastMonth">Last Month</button>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-body" id="reportBody"></div>
    </div>
  </div>
