  <!-- Audit Log & User Activity Modal -->
  <div class="modal" id="auditModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <div style="display:flex; align-items:center; gap:12px;">
          <h2>Audit Log &amp; Activity Trail</h2>
          <div class="audit-live-status">
            <span class="audit-live-dot"></span>
            <span id="auditLiveOnlineCount">0</span> Online
          </div>
        </div>
        <div class="modal-search-wrap">
          <input type="search" id="auditSearch" class="search-input" placeholder="Search by user, action, target ID, IP..." autocomplete="off">
          <button type="button" class="search-clear" id="auditSearchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn primary" id="btnExportAuditCsv">📥 Export CSV</button>
          <button type="button" class="btn ghost" data-close>✕ Close</button>
        </div>
      </div>

      <!-- KPI Summary Cards -->
      <div class="audit-overview-grid">
        <div class="audit-stat-card stat-online">
          <span class="audit-stat-num" id="statAuditOnline">0</span>
          <span class="audit-stat-label">Online Staff Now</span>
        </div>
        <div class="audit-stat-card stat-sessions">
          <span class="audit-stat-num" id="statAuditSessionsToday">0</span>
          <span class="audit-stat-label">Staff Sessions Today</span>
        </div>
        <div class="audit-stat-card stat-actions">
          <span class="audit-stat-num" id="statAuditActionsToday">0</span>
          <span class="audit-stat-label">Actions Logged Today</span>
        </div>
        <div class="audit-stat-card stat-duration">
          <span class="audit-stat-num" id="statAuditAvgDuration">0m</span>
          <span class="audit-stat-label">Avg Shift / Session</span>
        </div>
      </div>

      <!-- Filter Controls Bar -->
      <div class="audit-controls-bar">
        <div class="audit-category-chips">
          <button type="button" class="audit-chip active" data-category="all">All Events</button>
          <button type="button" class="audit-chip" data-category="navigation">Navigation</button>
          <button type="button" class="audit-chip" data-category="pos_order">POS &amp; Orders</button>
          <button type="button" class="audit-chip" data-category="inventory">Inventory &amp; Waste</button>
          <button type="button" class="audit-chip" data-category="catalog">Menu Catalog</button>
          <button type="button" class="audit-chip" data-category="account">Accounts</button>
          <button type="button" class="audit-chip" data-category="security">Security &amp; 2FA</button>
        </div>
        <div class="audit-secondary-filters">
          <select id="auditStaffFilter" class="audit-select" aria-label="Filter by staff member">
            <option value="">All Staff Members</option>
          </select>
          <input type="date" id="auditDateFrom" class="audit-date-input" title="From Date">
          <input type="date" id="auditDateTo" class="audit-date-input" title="To Date">
          <button type="button" class="btn ghost btn-sm" id="auditResetDateBtn" style="padding:6px 10px;" title="Reset Dates">Reset</button>
        </div>
      </div>

      <div class="modal-body">
        <!-- Activity Feed Table -->
        <div class="audit-table-wrap">
          <table class="audit-table">
            <thead>
              <tr>
                <th>Timestamp</th>
                <th>Staff User</th>
                <th>Category</th>
                <th>Action &amp; Description</th>
                <th>Session Dwell</th>
                <th>IP Address</th>
                <th style="text-align:center;">Metadata</th>
              </tr>
            </thead>
            <tbody id="auditTableBody">
              <tr>
                <td colspan="7" style="text-align:center; padding: 30px; color: var(--muted);">
                  Loading audit trail records...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Footer -->
        <div class="audit-footer-bar">
          <div class="audit-page-info" id="auditPaginationInfo">Loading...</div>
          <div class="audit-page-buttons">
            <button type="button" class="btn ghost btn-sm" id="auditPrevPageBtn" disabled>◀ Previous</button>
            <button type="button" class="btn ghost btn-sm" id="auditNextPageBtn" disabled>Next ▶</button>
          </div>
        </div>
      </div>
    </div>
  </div>
