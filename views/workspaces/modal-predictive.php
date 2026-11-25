  <!-- ===== Dedicated Predictive Machine Learning Analytics Modal ===== -->
  <div class="modal" id="predictiveModal" hidden>
    <div class="modal-card predictive-modal-card">
      <div class="modal-head">
        <div class="modal-title-wrap">
          <h2>Predictive Machine Learning Analytics</h2>
          <p class="modal-subtitle">Seasonality-aware demand forecasting and revenue projections derived from historical transaction data.</p>
        </div>
        <div class="seg" id="predPeriodSeg" role="group" aria-label="Prediction period">
          <button type="button" class="on" data-pred-period="daily">Daily Prediction</button>
          <button type="button" data-pred-period="7days">7-Day Prediction</button>
          <button type="button" data-pred-period="month">Specific Month Prediction</button>
          <button type="button" data-pred-period="year">Yearly Prediction</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn small primary" id="predExportExcelBtn" title="Export AI Forecast &amp; Demand Models to Excel (.xlsx)">Export Forecast (.xlsx)</button>
          <button type="button" class="btn small" id="predRecalculateBtn" title="Recalculate forecast from latest transactions">Recalculate</button>
          <button type="button" class="btn ghost" data-close>Close</button>
        </div>
      </div>

      <!-- Predictive Contextual Toolbar -->
      <div class="report-toolbar" id="predToolbar">
        <div class="toolbar-nav">
          <button type="button" class="btn-step" id="predPrevPeriodBtn" title="Previous period">◀</button>

          <div class="picker-group" id="predDailyPickerGroup">
            <label for="predDatePicker" class="picker-label">Target Date:</label>
            <input type="date" id="predDatePicker" class="date-input">
            <span class="pill-range" id="predDailyBadge">Next Day Forecast</span>
            <button type="button" class="btn ghost small" id="predJumpTomorrowBtn">Tomorrow</button>
          </div>

          <div class="picker-group" id="pred7DayPickerGroup" hidden>
            <label for="pred7DayPicker" class="picker-label">Start Date:</label>
            <input type="date" id="pred7DayPicker" class="date-input">
            <span class="pill-range" id="pred7DayBadge">7-Day Horizon</span>
            <button type="button" class="btn ghost small" id="predJumpNext7Btn">Upcoming 7 Days</button>
          </div>

          <div class="picker-group" id="predMonthPickerGroup" hidden>
            <label for="predMonthPicker" class="picker-label">Target Month:</label>
            <input type="month" id="predMonthPicker" class="date-input">
            <span class="pill-range" id="predMonthBadge">Monthly Cycle</span>
            <button type="button" class="btn ghost small" id="predJumpNextMonthBtn">Next Month</button>
          </div>

          <div class="picker-group" id="predYearPickerGroup" hidden>
            <label for="predYearPicker" class="picker-label">Target Year:</label>
            <select id="predYearPicker" class="year-select"></select>
            <span class="pill-range" id="predYearBadge">Annual Horizon</span>
            <button type="button" class="btn ghost small" id="predJumpNextYearBtn">Next Year</button>
          </div>

          <button type="button" class="btn-step" id="predNextPeriodBtn" title="Next period">▶</button>
        </div>

        <div class="toolbar-status">
          <span class="status-period" id="predStatusPeriod">Loading predictive model...</span>
        </div>
      </div>

      <div class="modal-body" id="predictiveBody"></div>
    </div>
  </div>
