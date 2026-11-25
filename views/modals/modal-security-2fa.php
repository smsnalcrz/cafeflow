  <!-- ===== Account Security & Two-Factor Authentication Modal (All Personnel) ===== -->
  <div class="modal" id="security2faModal" hidden>
    <div class="modal-card modal-card-2fa" style="max-width: 540px; margin: auto; height: auto; max-height: calc(100vh - 100px); border-radius: var(--radius-xl); overflow: hidden; display: flex; flex-direction: column;">
      <div class="modal-head">
        <div style="display: flex; align-items: center; gap: 10px;">
          <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--green-light); color: var(--green); display: flex; align-items: center; justify-content: center;">
            <svg style="width: 18px; height: 18px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
          <div>
            <h2 style="margin: 0; font-size: 19px;">Account Security</h2>
            <p style="margin: 0; font-size: 12px; color: var(--muted);">Two-Factor Authentication (Google Authenticator)</p>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn ghost" id="closeSecurity2faBtn">✕ Close</button>
        </div>
      </div>

      <div class="modal-body" style="padding: 24px 28px 28px;">
        <!-- Status View (When 2FA is Disabled or Enabled) -->
        <div id="sec2faStatusView">
          <div id="sec2faStatusCard" style="background: var(--cream); border: 1.5px solid var(--line); border-radius: var(--radius); padding: 18px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
              <span style="font-size: 14px; font-weight: 800; color: var(--espresso);">2FA Status</span>
              <span id="sec2faStatusBadge" class="status-badge" style="font-size: 12px;">--</span>
            </div>
            <p id="sec2faStatusDesc" style="font-size: 13.5px; color: var(--ink-mid); margin: 0; line-height: 1.45;"></p>
            <div id="sec2faMetaInfo" style="margin-top: 10px; font-size: 12px; color: var(--muted);" hidden></div>
          </div>

          <!-- Actions when Disabled -->
          <div id="sec2faDisabledActions">
            <button type="button" class="btn primary" id="start2faEnrollBtn" style="width: 100%; height: 44px; font-weight: 800;">
              Enable Two-Factor Authentication
            </button>
          </div>

          <!-- Actions when Enabled -->
          <div id="sec2faEnabledActions" hidden style="display: flex; flex-direction: column; gap: 10px;">
            <button type="button" class="btn" id="openRegenCodesBtn" style="width: 100%; height: 42px; background: var(--parchment-mid); color: var(--espresso); font-weight: 700; border: 1.5px solid var(--line);">
              Regenerate Recovery Codes
            </button>
            <button type="button" class="btn" id="openDisable2faBtn" style="width: 100%; height: 42px; background: var(--red-light); color: var(--red); font-weight: 700; border: 1.5px solid rgba(184,50,38,0.2);">
              Disable Two-Factor Authentication
            </button>
          </div>
        </div>

        <!-- Enrollment Flow Sheet / View -->
        <div id="sec2faEnrollView" hidden>
          <!-- Step 1: Password Check to Start -->
          <div id="sec2faEnrollStepPassword">
            <h3 style="font-size: 16px; margin: 0 0 8px; color: var(--espresso);">Verify Your Password</h3>
            <p style="font-size: 13px; color: var(--muted); margin: 0 0 16px;">Please confirm your current password to begin 2FA setup.</p>
            <form id="sec2faPasswordForm" novalidate>
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">Current Password</label>
                <div class="pass-input-wrap">
                  <input type="password" id="sec2faCurrentPass" class="search-input" style="width: 100%;" placeholder="Enter your password" required>
                  <button type="button" class="btn-toggle-pass" data-target="sec2faCurrentPass" tabindex="-1">👁️</button>
                </div>
              </div>
              <p class="form-error" id="sec2faPassError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
              <div style="display: flex; gap: 10px;">
                <button type="button" class="btn ghost" id="sec2faPassCancelBtn" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn primary" id="sec2faPassSubmitBtn" style="flex: 1;">Continue →</button>
              </div>
            </form>
          </div>

          <!-- Step 2: Show Setup Key & Verification Input -->
          <div id="sec2faEnrollStepKey" hidden>
            <h3 style="font-size: 16px; margin: 0 0 6px; color: var(--espresso);">Connect Google Authenticator</h3>
            <p style="font-size: 12.5px; color: var(--muted); margin: 0 0 12px; line-height: 1.4;">
              1. Open Google Authenticator on your mobile device.<br>
              2. Tap <strong>+</strong> and choose <strong>Enter a setup key</strong>.<br>
              3. Set Account: <strong id="sec2faAccountLabel">CafeFlow</strong>.<br>
              4. Enter the Setup Key below and select <strong>Time-based</strong>:
            </p>

            <div style="background: #F2EBE0; border: 1.5px dashed #2D6A4F; border-radius: var(--radius-sm); padding: 12px 14px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
              <span id="sec2faSetupKeyDisplay" style="font-family: monospace; font-size: 16px; font-weight: 800; color: #1B4332; letter-spacing: 1.5px; user-select: all;">---- ---- ---- ----</span>
              <button type="button" class="btn small" id="sec2faCopyKeyBtn" style="background: #1B4332; color: #fff; font-size: 12px; padding: 6px 12px; border-radius: 6px; white-space: nowrap;">Copy Key</button>
            </div>

            <form id="sec2faConfirmForm" novalidate>
              <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; text-align: center;">Enter 6-Digit Authenticator Code</label>
                <input type="text" id="sec2faConfirmCodeInput" class="search-input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" style="text-align: center; font-family: monospace; font-size: 24px; font-weight: 800; letter-spacing: 8px; height: 50px; width: 100%; box-sizing: border-box;" placeholder="······">
              </div>
              <p class="form-error" id="sec2faConfirmError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
              <div style="display: flex; gap: 10px;">
                <button type="button" class="btn ghost" id="sec2faConfirmCancelBtn" style="flex: 1;">Cancel</button>
                <button type="submit" class="btn primary" id="sec2faConfirmSubmitBtn" style="flex: 1;">Activate 2FA</button>
              </div>
            </form>
          </div>

          <!-- Step 3: Show Recovery Codes on Success -->
          <div id="sec2faEnrollStepSuccess" hidden>
            <div class="auth-alert success" style="margin-bottom: 14px;">
              <span>✓ Two-Factor Authentication is now active!</span>
            </div>
            <h3 style="font-size: 15px; margin: 0 0 6px; color: var(--espresso);">Emergency Recovery Codes</h3>
            <p style="font-size: 12.5px; color: var(--muted); margin: 0 0 12px; line-height: 1.4;">
              Save these recovery codes immediately. If you lose access to your phone, each code can be used once to sign in.
            </p>
            <div class="recovery-codes-grid" id="sec2faRecoveryCodesGrid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px;"></div>
            <div style="display: flex; gap: 8px; margin-bottom: 16px;">
              <button type="button" class="btn ghost" id="sec2faCopyAllCodesBtn" style="flex: 1; font-size: 13px;">Copy All</button>
              <button type="button" class="btn ghost" id="sec2faDownloadCodesBtn" style="flex: 1; font-size: 13px;">Download (.txt)</button>
            </div>
            <label style="display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--ink-mid); cursor: pointer; margin-bottom: 18px;">
              <input type="checkbox" id="sec2faAcknowledgeBox" style="margin-top: 2px;">
              <span>I have saved these emergency recovery codes in a secure location.</span>
            </label>
            <button type="button" class="btn primary" id="sec2faFinishEnrollBtn" disabled style="width: 100%; height: 44px; font-weight: 800;">
              Finish &amp; Close
            </button>
          </div>
        </div>

        <!-- Disable 2FA Sheet / View -->
        <div id="sec2faDisableView" hidden>
          <h3 style="font-size: 16px; margin: 0 0 8px; color: var(--red);">Disable Two-Factor Authentication</h3>
          <p style="font-size: 13px; color: var(--muted); margin: 0 0 16px;">This will remove Google Authenticator protection from your account. Enter your password and current 6-digit code to confirm:</p>
          <form id="sec2faDisableForm" novalidate>
            <div style="margin-bottom: 14px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">Current Password</label>
              <div class="pass-input-wrap">
                <input type="password" id="sec2faDisablePass" class="search-input" style="width: 100%;" placeholder="Enter your password" required>
                <button type="button" class="btn-toggle-pass" data-target="sec2faDisablePass" tabindex="-1">👁️</button>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">6-Digit Authenticator Code</label>
              <input type="text" id="sec2faDisableCode" class="search-input" maxlength="6" inputmode="numeric" style="text-align: center; font-family: monospace; font-size: 20px; font-weight: 800; letter-spacing: 6px; width: 100%; box-sizing: border-box;" placeholder="······" required>
            </div>
            <p class="form-error" id="sec2faDisableError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
            <div style="display: flex; gap: 10px;">
              <button type="button" class="btn ghost" id="sec2faDisableCancelBtn" style="flex: 1;">Cancel</button>
              <button type="submit" class="btn" id="sec2faDisableSubmitBtn" style="flex: 1; background: var(--red); color: #fff; font-weight: 700;">Disable 2FA</button>
            </div>
          </form>
        </div>

        <!-- Regenerate Recovery Codes Sheet / View -->
        <div id="sec2faRegenView" hidden>
          <h3 style="font-size: 16px; margin: 0 0 8px; color: var(--espresso);">Regenerate Emergency Recovery Codes</h3>
          <p style="font-size: 13px; color: var(--muted); margin: 0 0 16px;">This will invalidate all current recovery codes and generate 8 new codes. Enter your password and current 6-digit code to confirm:</p>
          <form id="sec2faRegenForm" novalidate>
            <div style="margin-bottom: 14px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">Current Password</label>
              <div class="pass-input-wrap">
                <input type="password" id="sec2faRegenPass" class="search-input" style="width: 100%;" placeholder="Enter your password" required>
                <button type="button" class="btn-toggle-pass" data-target="sec2faRegenPass" tabindex="-1">👁️</button>
              </div>
            </div>
            <div style="margin-bottom: 16px;">
              <label style="display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px;">6-Digit Authenticator Code</label>
              <input type="text" id="sec2faRegenCode" class="search-input" maxlength="6" inputmode="numeric" style="text-align: center; font-family: monospace; font-size: 20px; font-weight: 800; letter-spacing: 6px; width: 100%; box-sizing: border-box;" placeholder="······" required>
            </div>
            <p class="form-error" id="sec2faRegenError" hidden style="color: var(--red); font-size: 13px; margin: 0 0 14px;"></p>
            <div style="display: flex; gap: 10px;">
              <button type="button" class="btn ghost" id="sec2faRegenCancelBtn" style="flex: 1;">Cancel</button>
              <button type="submit" class="btn primary" id="sec2faRegenSubmitBtn" style="flex: 1;">Generate New Codes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
