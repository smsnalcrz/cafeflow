  <!-- ===== Top navigation ===== -->
  <header class="topbar">
    <div class="brand" role="banner" title="CafeFlow & DK Cafe">
      <div class="brand-lockup">
        <div class="brand-top-row">
          <div class="brand-emblem-wrap" title="CafeFlow POS">
            <img src="assets/images/cafeflow_logo.svg?v=<?= $ver('assets/images/cafeflow_logo.svg') ?>" alt="CafeFlow" class="brand-emblem-img">
          </div>
          <div class="brand-logo-wrap" title="DK Cafe">
            <img src="assets/images/dk_cafe_logo_cropped.png?v=<?= $ver('assets/images/dk_cafe_logo_cropped.png') ?>" alt="DK Cafe" class="brand-logo-dk">
          </div>
        </div>
        <div class="brand-pill-bar">
          <span class="brand-pill-item">CAFEFLOW</span>
          <span class="brand-pill-amp" aria-hidden="true">&amp;</span>
          <span class="brand-pill-item">DK CAFE</span>
        </div>
      </div>
    </div>
    <nav class="nav" aria-label="Main">
      <?php if ($currentRole === 'barista'): ?>
      <button type="button" class="nav-btn active" data-view="pos">
        <svg class="nav-icon" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
        <span>POS</span> <span class="badge" id="queueBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="products">
        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
        <span>Products</span>
      </button>
      <?php endif; ?>
      <button type="button" class="nav-btn" data-view="inventory">
        <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
        <span>Inventory</span> <span class="badge warn" id="lowBadge" hidden>0</span>
      </button>
      <button type="button" class="nav-btn" data-view="report">
        <svg class="nav-icon" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
        <span>Sales Report</span>
      </button>
      <button type="button" class="nav-btn" data-view="waste" id="wasteNavBtn">
        <svg class="nav-icon" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
        <span>Waste Reports</span>
      </button>
      <?php if ($currentRole === 'manager'): ?>
      <button type="button" class="nav-btn" data-view="predictive" id="predictiveNavBtn">
        <svg class="nav-icon" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
        <span>Predictive Analytics</span>
      </button>
      <?php endif; ?>
    </nav>

    <!-- Theme Toggle Button (Dark / Light Mode) -->
    <div class="theme-toggle-wrap">
      <button type="button" class="theme-toggle-btn" id="themeToggleBtn" aria-label="Switch Theme (Dark / Light)" title="Switch Theme (Dark / Light)" aria-pressed="false">
        <span class="theme-icon-slot">
          <svg class="theme-icon icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
          <svg class="theme-icon icon-moon" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
        </span>
        <span class="theme-toggle-text" id="themeToggleText">Dark</span>
      </button>
    </div>

    <!-- Profile Button in Topbar (Positioned between Nav/Theme and Clock) -->
    <div class="user-profile-wrap" id="userProfileWrap">
      <button type="button" class="profile-btn" id="profileBtn" aria-expanded="false" aria-haspopup="true" title="User Profile">
        <div class="profile-avatar <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>" id="topbarAvatar">
          <?= strtoupper(substr($currentUser['full_name'] ?: $currentUser['username'], 0, 1)) ?>
        </div>
        <div class="profile-meta">
          <span class="profile-name" id="topbarUserName"><?= htmlspecialchars($currentUser['full_name']) ?></span>
          <span class="profile-role-badge <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>">
            <?= ucfirst($currentRole) ?>
          </span>
        </div>
        <span class="profile-chevron" aria-hidden="true">▾</span>
      </button>

      <!-- Profile Dropdown Menu -->
      <div class="profile-dropdown" id="profileDropdown" hidden>
        <div class="profile-dropdown-header">
          <div class="dropdown-avatar-lg <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>">
            <?= strtoupper(substr($currentUser['full_name'] ?: $currentUser['username'], 0, 1)) ?>
          </div>
          <div class="dropdown-user-info">
            <strong class="dropdown-fullname"><?= htmlspecialchars($currentUser['full_name']) ?></strong>
            <span class="dropdown-username">@<?= htmlspecialchars($currentUser['username']) ?></span>
            <span class="dropdown-role-tag <?= $currentRole === 'manager' ? 'role-manager' : 'role-barista' ?>">
              <?= ucfirst($currentRole) ?>
            </span>
          </div>
        </div>
        <div class="profile-dropdown-menu">
          <button type="button" class="dropdown-item" id="openSecurity2faBtn">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            </span>
            <span>Account Security &amp; 2FA</span>
          </button>
          <button type="button" class="dropdown-item" id="dropdownThemeToggle">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </span>
            <span id="dropdownThemeLabel">Switch to Dark Mode</span>
          </button>
          <?php if ($currentRole === 'manager'): ?>
          <button type="button" class="dropdown-item" id="openAccountsBtn">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </span>
            <span>Account Management</span>
          </button>
          <button type="button" class="dropdown-item" id="openAuditBtn">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
            </span>
            <span>Audit Log &amp; Activity</span>
          </button>
          <?php endif; ?>
          <a href="logout.php" class="dropdown-item danger" id="logoutLink" style="text-decoration: none;">
            <span class="dropdown-item-icon">
              <svg class="dropdown-svg" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
            </span>
            <span>Sign Out</span>
          </a>
        </div>
      </div>
    </div>
    <div class="clock" id="clock" aria-hidden="true"></div>
  </header>
