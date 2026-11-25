  <!-- ===== Accounts Management modal (Manager Only) ===== -->
  <div class="modal" id="accountsModal" hidden>
    <div class="modal-card">
      <div class="modal-head">
        <h2>Account Management</h2>
        <div class="modal-search-wrap">
          <input type="search" id="accountSearch" class="search-input" placeholder="Search accounts by name, username, email..." autocomplete="off">
          <button type="button" class="search-clear" id="accountSearchClearBtn" hidden title="Clear search">✕</button>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn primary" id="addNewAccountBtn">+ New Account</button>
          <button type="button" class="btn ghost" data-close>✕ Close</button>
        </div>
      </div>

      <!-- Account Overview Stats Cards -->
      <div class="accounts-overview-grid">
        <div class="account-stat-card">
          <span class="stat-num" id="statTotalAccounts">0</span>
          <span class="stat-label">Total Accounts</span>
        </div>
        <div class="account-stat-card">
          <span class="stat-num" id="statBaristaAccounts">0</span>
          <span class="stat-label">Baristas</span>
        </div>
        <div class="account-stat-card">
          <span class="stat-num" id="statManagerAccounts">0</span>
          <span class="stat-label">Managers</span>
        </div>
        <div class="account-stat-card stat-active">
          <span class="stat-num" id="statActiveAccounts">0</span>
          <span class="stat-label">Active</span>
        </div>
        <div class="account-stat-card stat-pending">
          <span class="stat-num" id="statPendingAccounts">0</span>
          <span class="stat-label">Pending / Inactive</span>
        </div>
      </div>

      <!-- Filter Tabs -->
      <div class="accounts-filter-bar">
        <button type="button" class="acc-filter-tab active" data-filter="all">All Accounts</button>
        <button type="button" class="acc-filter-tab" data-filter="barista">Baristas</button>
        <button type="button" class="acc-filter-tab" data-filter="manager">Managers</button>
        <button type="button" class="acc-filter-tab" data-filter="active">Active</button>
        <button type="button" class="acc-filter-tab" data-filter="pending">Pending Approval</button>
      </div>

      <div class="modal-body">
        <div class="accounts-table-container">
          <table class="table">
            <thead>
              <tr>
                <th>Full Name</th>
                <th>Username</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>2FA</th>
                <th>Created</th>
                <th>Last Login</th>
                <th class="act">Actions</th>
              </tr>
            </thead>
            <tbody id="accountsTableBody">
              <!-- Rendered via JS -->
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /.modal-card -->

    <!-- Add / Edit Account Sheet -->
    <div class="sheet" id="accountSheet" hidden>
      <form class="sheet-card" id="accountForm" autocomplete="off" novalidate>
        <div class="sheet-head">
          <h3 id="accountSheetTitle">Create New Account</h3>
          <button type="button" class="btn-close-sheet" id="accountSheetCloseIcon" title="Close sheet">✕</button>
        </div>
        <input type="hidden" id="accUserId" value="">
        <input type="hidden" id="accCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="sheet-grid-2">
          <label>Full Name *
            <input type="text" id="accFullName" maxlength="100" placeholder="e.g. Maria Santos" required>
          </label>
          <label>Username *
            <input type="text" id="accUsername" maxlength="50" placeholder="e.g. maria_barista" required>
          </label>
        </div>

        <div class="sheet-grid-2" id="accStatusGroup">
          <label>Email Address
            <input type="email" id="accEmail" maxlength="100" placeholder="e.g. maria@cafeflow.com">
          </label>
          <label>Account Status *
            <select id="accStatus" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="pending">Pending Approval</option>
            </select>
          </label>
        </div>

        <div class="sheet-grid-full">
          <label>Role *
            <select id="accRole" required>
              <option value="barista">Barista (Full POS &amp; System Access)</option>
              <option value="manager">Manager (Inventory, Reports &amp; Accounts)</option>
            </select>
          </label>
        </div>

        <div id="accPasswordSection">
          <div class="sheet-grid-2">
            <label><span id="accPassLabel">Password *</span>
              <div class="pass-input-wrap">
                <input type="password" id="accPassword" minlength="6" placeholder="Min. 6 characters">
                <button type="button" class="btn-toggle-pass" data-target="accPassword" tabindex="-1">👁️</button>
              </div>
            </label>
            <label><span id="accPassConfirmLabel">Confirm Password *</span>
              <div class="pass-input-wrap">
                <input type="password" id="accConfirmPassword" minlength="6" placeholder="Repeat password">
                <button type="button" class="btn-toggle-pass" data-target="accConfirmPassword" tabindex="-1">👁️</button>
              </div>
            </label>
          </div>
          <small class="auth-hint" id="accPassHint" hidden style="margin-top: 4px; display: block; color: var(--muted); font-size: 12px;">Leave password blank if you do not wish to change it.</small>
        </div>

        <p class="form-error" id="accountSheetError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="accountSheetCancel">Cancel</button>
          <button type="submit" class="btn primary" id="accountSheetSaveBtn">Save Account</button>
        </div>
      </form>
    </div><!-- /#accountSheet -->

    <!-- Reset Password Sheet -->
    <div class="sheet" id="resetPassSheet" hidden>
      <form class="sheet-card" id="resetPassForm" autocomplete="off" novalidate>
        <div class="sheet-head">
          <h3>Reset Account Password</h3>
          <button type="button" class="btn-close-sheet" id="resetPassCloseIcon" title="Close sheet">✕</button>
        </div>
        <p class="muted" style="margin: 0; font-size: 13px;">Resetting password for: <strong id="resetTargetUsername" style="color: var(--espresso);">--</strong></p>
        <input type="hidden" id="resetUserId" value="">
        <input type="hidden" id="resetCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

        <div class="sheet-grid-2">
          <label>New Password *
            <div class="pass-input-wrap">
              <input type="password" id="resetNewPass" minlength="6" placeholder="Min. 6 characters" required>
              <button type="button" class="btn-toggle-pass" data-target="resetNewPass" tabindex="-1">👁️</button>
            </div>
          </label>
          <label>Confirm Password *
            <div class="pass-input-wrap">
              <input type="password" id="resetConfirmPass" minlength="6" placeholder="Repeat password" required>
              <button type="button" class="btn-toggle-pass" data-target="resetConfirmPass" tabindex="-1">👁️</button>
            </div>
          </label>
        </div>

        <p class="form-error" id="resetPassError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="resetPassCancel">Cancel</button>
          <button type="submit" class="btn primary" id="resetPassSaveBtn">Set New Password</button>
        </div>
      </form>
    </div><!-- /#resetPassSheet -->

    <!-- Reset Employee 2FA Sheet -->
    <div class="sheet" id="reset2faSheet" hidden>
      <form class="sheet-card" id="reset2faForm" autocomplete="off" novalidate>
        <div class="sheet-head">
          <h3 style="color: var(--amber);">Reset Employee 2FA</h3>
          <button type="button" class="btn-close-sheet" id="reset2faCloseIcon" title="Close sheet">✕</button>
        </div>
        <p class="muted" style="margin: 0; font-size: 13px;">Resetting 2FA for: <strong id="reset2faTargetUsername" style="color: var(--espresso);">--</strong> (<span id="reset2faTargetFullName">--</span>)</p>
        <div style="background: var(--amber-light); border: 1px solid rgba(232,98,58,0.3); border-radius: var(--radius-sm); padding: 10px 12px; font-size: 12.5px; color: var(--espresso); line-height: 1.45;">
          ⚠️ <strong>Security Action:</strong> This will erase the employee's existing Google Authenticator secret and recovery codes. The employee will be required to re-enroll with Google Authenticator upon their next login.
        </div>
        <input type="hidden" id="reset2faUserId" value="">
        <input type="hidden" id="reset2faCsrfToken" value="<?= htmlspecialchars($csrfToken) ?>">

        <label style="display: block;">Confirm Manager Password *
          <div class="pass-input-wrap">
            <input type="password" id="reset2faManagerPass" placeholder="Enter your manager password" required>
            <button type="button" class="btn-toggle-pass" data-target="reset2faManagerPass" tabindex="-1">👁️</button>
          </div>
        </label>

        <p class="form-error" id="reset2faError" hidden></p>
        <div class="sheet-actions">
          <button type="button" class="btn ghost" id="reset2faCancel">Cancel</button>
          <button type="submit" class="btn" id="reset2faSubmitBtn" style="background: var(--amber); color: #fff; font-weight: 800;">Confirm Reset 2FA</button>
        </div>
      </form>
    </div><!-- /#reset2faSheet -->

  </div><!-- /#accountsModal -->
