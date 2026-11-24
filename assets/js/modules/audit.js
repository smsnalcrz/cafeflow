/**
 * CafeFlow POS - Audit Log & Activity Tracking Client Module
 * Backs the Manager-only Audit Log interface, live heartbeat display,
 * multi-faceted filter controllers, metadata inspection, and CSV compliance export.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const { $, $$, toast } = window.CafeFlow.utils;
  const { currentUser } = window.CafeFlow.config;

  const state = {
    category: 'all',
    userId: '',
    dateFrom: '',
    dateTo: '',
    search: '',
    limit: 50,
    offset: 0,
    total: 0,
    isLoading: false
  };

  let searchDebounceTimer = null;

  async function loadAuditLogs() {
    if (currentUser.role !== 'manager') return;
    if (state.isLoading) return;
    state.isLoading = true;

    const tbody = $('#auditTableBody');
    if (tbody && state.offset === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 30px; color: var(--muted);">Loading audit records...</td></tr>';
    }

    try {
      const params = new URLSearchParams();
      if (state.category && state.category !== 'all') params.set('category', state.category);
      if (state.userId) params.set('user_id', state.userId);
      if (state.dateFrom) params.set('date_from', state.dateFrom);
      if (state.dateTo) params.set('date_to', state.dateTo);
      if (state.search) params.set('search', state.search);
      params.set('limit', state.limit);
      params.set('offset', state.offset);

      const res = await fetch(`api/audit_api.php?${params.toString()}`);
      if (!res.ok) {
        if (res.status === 403) {
          toast('Access denied: Manager role required.', 'error');
          return;
        }
        throw new Error(`HTTP ${res.status}`);
      }

      const data = await res.json();
      if (!data.success) {
        throw new Error(data.error || 'Failed to load audit logs.');
      }

      state.total = data.pagination.total;
      renderMetrics(data.metrics);
      populateStaffFilter(data.staff_options);
      renderTable(data.logs);
      renderPagination(data.pagination);
    } catch (err) {
      console.error('[CafeFlow Audit]', err);
      if (tbody) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 30px; color: var(--red);">Error loading audit records: ${err.message}</td></tr>`;
      }
    } finally {
      state.isLoading = false;
    }
  }

  function renderMetrics(metrics) {
    if (!metrics) return;

    const liveBadge = $('#auditLiveOnlineCount');
    if (liveBadge) liveBadge.textContent = metrics.online_count || 0;

    const statOnline = $('#statAuditOnline');
    if (statOnline) statOnline.textContent = metrics.online_count || 0;

    const statSessions = $('#statAuditSessionsToday');
    if (statSessions) statSessions.textContent = metrics.sessions_today || 0;

    const statActions = $('#statAuditActionsToday');
    if (statActions) statActions.textContent = metrics.actions_today || 0;

    const statDuration = $('#statAuditAvgDuration');
    if (statDuration) statDuration.textContent = metrics.avg_duration_today || '0m';

    // Also update manager dashboard card if present
    const mgrCardAudit = $('#mgrCardAuditActionsToday');
    if (mgrCardAudit) mgrCardAudit.textContent = `${metrics.actions_today || 0} events today`;
  }

  function populateStaffFilter(staffList) {
    const select = $('#auditStaffFilter');
    if (!select || select.dataset.populated === 'true' || !Array.isArray(staffList)) return;

    const currentVal = select.value;
    select.innerHTML = '<option value="">All Staff Members</option>';
    staffList.forEach(s => {
      const opt = document.createElement('option');
      opt.value = s.user_id;
      opt.textContent = `${s.full_name} (@${s.username}) - ${s.role.toUpperCase()}`;
      select.appendChild(opt);
    });
    select.value = currentVal;
    select.dataset.populated = 'true';
  }

  function renderTable(logs) {
    const tbody = $('#auditTableBody');
    if (!tbody) return;

    if (!Array.isArray(logs) || logs.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 40px; color: var(--muted); font-size: 14px;">No audit trail events match the selected filters.</td></tr>';
      return;
    }

    tbody.innerHTML = logs.map(log => {
      const role = (log.role || 'system').toLowerCase();
      const roleInitial = (log.username || 'S').charAt(0).toUpperCase();
      const roleClass = role === 'manager' ? 'audit-avatar-manager' : (role === 'barista' ? 'audit-avatar-barista' : 'audit-avatar-system');
      const catClass = `cat-${log.category || 'system'}`;

      const metaString = log.metadata ? JSON.stringify(log.metadata) : '';
      const hasMeta = !!log.metadata && Object.keys(log.metadata).length > 0;

      return `
        <tr>
          <td class="audit-time-cell">
            <strong>${escapeHtml(log.created_at)}</strong>
            <span class="audit-relative-time">${escapeHtml(log.relative_time)}</span>
          </td>
          <td>
            <div class="audit-staff-cell">
              <div class="audit-avatar-xs ${roleClass}">${escapeHtml(roleInitial)}</div>
              <div>
                <strong style="display:block; line-height:1.2; color:var(--ink);">@${escapeHtml(log.username)}</strong>
                <span style="font-size:10px; font-weight:700; text-transform:uppercase; color:var(--muted);">${escapeHtml(role)}</span>
              </div>
            </div>
          </td>
          <td>
            <span class="audit-cat-badge ${catClass}">
              ${escapeHtml(log.category)}
            </span>
          </td>
          <td>
            <div style="font-weight:600; color:var(--ink);">${escapeHtml(log.description)}</div>
            <div style="font-size:11px; color:var(--muted); font-family:monospace;">${escapeHtml(log.action)}</div>
          </td>
          <td>
            ${log.session_duration ? `<span style="font-weight:700; color:var(--purple);">${escapeHtml(log.session_duration)}</span>` : '<span class="muted">--</span>'}
          </td>
          <td style="font-family:monospace; font-size:12px; color:var(--muted);">
            ${escapeHtml(log.ip_address)}
          </td>
          <td style="text-align:center;">
            ${hasMeta ? `
              <button type="button" class="btn ghost btn-sm btn-inspect-audit" data-meta="${escapeAttr(metaString)}" data-title="${escapeAttr(log.description)}" style="padding:4px 10px; font-size:11.5px;">
                Inspect
              </button>
            ` : '<span class="muted" style="font-size:11px;">None</span>'}
          </td>
        </tr>
      `;
    }).join('');

    // Attach inspect buttons
    tbody.querySelectorAll('.btn-inspect-audit').forEach(btn => {
      btn.addEventListener('click', () => {
        try {
          const meta = JSON.parse(btn.dataset.meta || '{}');
          showInspectModal(btn.dataset.title, meta);
        } catch (e) {
          showInspectModal(btn.dataset.title, btn.dataset.meta);
        }
      });
    });
  }

  function showInspectModal(title, metadata) {
    let modal = $('#auditInspectModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'auditInspectModal';
      modal.className = 'sheet confirm-modal-sheet';
      modal.innerHTML = `
        <div class="modal-card">
          <div class="modal-head" style="padding: 16px 20px;">
            <h3 id="auditInspectTitle" style="margin:0; font-size:16px; font-weight:800; color:var(--ink);">Audit Record Details</h3>
            <button type="button" class="btn ghost btn-sm" id="auditInspectCloseBtn" style="padding:4px 8px; margin-left:auto;">✕</button>
          </div>
          <div class="modal-body" style="padding: 18px 22px; overflow-y: auto;">
            <p style="font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; margin:0 0 10px;">Contextual Event Payload (JSON):</p>
            <pre class="audit-json-box" id="auditInspectJson"></pre>
          </div>
          <div style="padding: 12px 20px; background: var(--surface); border-top: 1.5px solid var(--line); display:flex; justify-content:flex-end;">
            <button type="button" class="btn primary btn-sm" id="auditInspectOkBtn" style="padding:8px 22px;">Done</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);

      const close = () => { modal.hidden = true; modal.style.display = 'none'; };
      $('#auditInspectCloseBtn').addEventListener('click', close);
      $('#auditInspectOkBtn').addEventListener('click', close);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) close();
      });
    }

    $('#auditInspectTitle').textContent = title || 'Audit Record Payload';
    $('#auditInspectJson').textContent = typeof metadata === 'object' ? JSON.stringify(metadata, null, 2) : String(metadata);
    modal.hidden = false;
    modal.style.display = 'flex';
  }

  function renderPagination(p) {
    const info = $('#auditPaginationInfo');
    const prevBtn = $('#auditPrevPageBtn');
    const nextBtn = $('#auditNextPageBtn');

    if (info) {
      if (p.total === 0) {
        info.textContent = '0 records';
      } else {
        const start = p.offset + 1;
        const end = Math.min(p.offset + p.limit, p.total);
        info.textContent = `Showing ${start}–${end} of ${p.total} records`;
      }
    }

    if (prevBtn) prevBtn.disabled = p.offset === 0;
    if (nextBtn) nextBtn.disabled = !p.has_more;
  }

  function initAuditEvents() {
    if (currentUser.role !== 'manager') return;

    // 1. Category Chips
    $$('.audit-chip').forEach(chip => {
      chip.addEventListener('click', () => {
        $$('.audit-chip').forEach(c => c.classList.remove('active'));
        chip.classList.add('active');
        state.category = chip.dataset.category || 'all';
        state.offset = 0;
        loadAuditLogs();
      });
    });

    // 2. Staff Filter
    const staffSelect = $('#auditStaffFilter');
    if (staffSelect) {
      staffSelect.addEventListener('change', () => {
        state.userId = staffSelect.value;
        state.offset = 0;
        loadAuditLogs();
      });
    }

    // 3. Date Filters
    const dateFrom = $('#auditDateFrom');
    const dateTo = $('#auditDateTo');
    const applyDate = () => {
      state.dateFrom = dateFrom ? dateFrom.value : '';
      state.dateTo = dateTo ? dateTo.value : '';
      state.offset = 0;
      loadAuditLogs();
    };
    if (dateFrom) dateFrom.addEventListener('change', applyDate);
    if (dateTo) dateTo.addEventListener('change', applyDate);

    const resetDateBtn = $('#auditResetDateBtn');
    if (resetDateBtn) {
      resetDateBtn.addEventListener('click', () => {
        if (dateFrom) dateFrom.value = '';
        if (dateTo) dateTo.value = '';
        applyDate();
      });
    }

    // 4. Keyword Search
    const searchInput = $('#auditSearch');
    const searchClear = $('#auditSearchClearBtn');
    if (searchInput) {
      searchInput.addEventListener('input', () => {
        const val = searchInput.value.trim();
        if (searchClear) searchClear.hidden = val === '';
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
          state.search = val;
          state.offset = 0;
          loadAuditLogs();
        }, 300);
      });
    }
    if (searchClear) {
      searchClear.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        searchClear.hidden = true;
        state.search = '';
        state.offset = 0;
        loadAuditLogs();
      });
    }

    // 5. Pagination Buttons
    const prevBtn = $('#auditPrevPageBtn');
    if (prevBtn) {
      prevBtn.addEventListener('click', () => {
        if (state.offset > 0) {
          state.offset = Math.max(0, state.offset - state.limit);
          loadAuditLogs();
        }
      });
    }
    const nextBtn = $('#auditNextPageBtn');
    if (nextBtn) {
      nextBtn.addEventListener('click', () => {
        if (state.offset + state.limit < state.total) {
          state.offset += state.limit;
          loadAuditLogs();
        }
      });
    }

    // 6. CSV Export Button
    const exportBtn = $('#btnExportAuditCsv');
    if (exportBtn) {
      exportBtn.addEventListener('click', () => {
        const params = new URLSearchParams();
        params.set('export', 'csv');
        if (state.category && state.category !== 'all') params.set('category', state.category);
        if (state.userId) params.set('user_id', state.userId);
        if (state.dateFrom) params.set('date_from', state.dateFrom);
        if (state.dateTo) params.set('date_to', state.dateTo);
        if (state.search) params.set('search', state.search);
        window.location.href = `api/audit_api.php?${params.toString()}`;
      });
    }

    // 7. Manager Dashboard Audit card trigger
    const mgrOpenAuditBtn = $('#mgrOpenAuditBtn');
    if (mgrOpenAuditBtn) {
      mgrOpenAuditBtn.addEventListener('click', () => {
        if (window.CafeFlow.nav && window.CafeFlow.nav.showView) {
          window.CafeFlow.nav.showView('audit');
        }
      });
    }
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function escapeAttr(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  window.CafeFlow.audit = {
    loadAuditLogs,
    initAuditEvents
  };
})();
