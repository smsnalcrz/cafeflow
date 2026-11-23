/**
 * CafeFlow POS - Core Utilities & DOM Helpers
 * Pure vanilla helper functions for DOM traversal, element construction,
 * currency/number formatting, HTML sanitization, toast alerts, and date/time manipulation.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  /* ------------------------------------------------------------------ *
   *  DOM Query Helpers
   * ------------------------------------------------------------------ */
  const $  = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* ------------------------------------------------------------------ *
   *  String & Currency Formatters / Sanitizers
   * ------------------------------------------------------------------ */
  const money = n => '₱' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const qtyFmt = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });

  const escapeHtml = s => String(s == null ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  /* ------------------------------------------------------------------ *
   *  DOM Element Builder
   *  Text is always inserted safely as text, never as HTML.
   * ------------------------------------------------------------------ */
  function h(tag, props = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [key, val] of Object.entries(props)) {
      if (val == null || val === false) continue;
      if (key === 'class') el.className = val;
      else if (key === 'text') el.textContent = val;
      else if (key === 'dataset') Object.assign(el.dataset, val);
      else if (key.startsWith('on')) el.addEventListener(key.slice(2), val);
      else el.setAttribute(key, val === true ? '' : val);
    }
    for (const kid of kids.flat()) {
      if (kid != null && kid !== false) el.append(kid);
    }
    return el;
  }

  /* ------------------------------------------------------------------ *
   *  Toast Notifications & Clock
   * ------------------------------------------------------------------ */
  let toastTimer;
  function toast(title, kind = 'success', ms = 2600, sub = '') {
    const el = $('#toast');
    if (!el) return;
    el.textContent = '';
    el.append(h('strong', { text: title }));
    if (sub) el.append(h('span', { text: sub }));
    el.className = 'toast show ' + kind;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), ms);
  }

  function tickClock() {
    const clock = $('#clock');
    if (clock) {
      clock.textContent = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    }
  }

  /* ------------------------------------------------------------------ *
   *  Date & Time Helpers
   * ------------------------------------------------------------------ */
  const pad2 = n => String(n).padStart(2, '0');

  const toIsoDate = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

  const toIsoMonth = d => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`;

  const parseIsoDate = str => {
    const [y, m, d] = str.split('-').map(Number);
    return new Date(y, m - 1, d);
  };

  const addDays = (d, n) => {
    const res = new Date(d);
    res.setDate(res.getDate() + n);
    return res;
  };

  const addMonths = (year, monthIndex, n) => {
    const res = new Date(year, monthIndex + n, 1);
    return { year: res.getFullYear(), month: res.getMonth() };
  };

  const formatHour = hour => {
    const ampm = hour >= 12 ? 'PM' : 'AM';
    const displayH = hour % 12 || 12;
    return `${displayH}:00 ${ampm}`;
  };

  const dayLabel = iso => {
    if (!iso) return '';
    const [y, m, d] = iso.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
  };

  /**
   * Generates a contextual, user-friendly empty state component with guidance & tips.
   * @param {object} opts
   * @param {string} [opts.icon=''] - Optional icon
   * @param {string} [opts.title='No Data'] - Primary heading
   * @param {string} [opts.desc=''] - Descriptive guidance
   * @param {string} [opts.tip=''] - Pro-tip or hint badge text
   * @param {string} [opts.actionText=''] - Action button text
   * @param {Function} [opts.onAction=null] - Action button click handler
   * @param {boolean} [opts.compact=false] - Whether to use compact padding
   * @returns {HTMLElement}
   */
  const emptyState = ({ icon = '', title = 'No Data', desc = '', tip = '', actionText = '', onAction = null, compact = false }) => {
    const iconEl = icon ? h('div', { class: 'cf-empty-icon-wrap' }, h('span', { text: icon })) : null;
    const root = h('div', { class: 'cf-empty-state' + (compact ? ' compact' : '') },
      iconEl,
      h('h4', { class: 'cf-empty-title', text: title }),
      desc ? h('p', { class: 'cf-empty-desc', text: desc }) : null,
      tip ? h('div', { class: 'cf-empty-tip', text: tip }) : null,
      (actionText && typeof onAction === 'function') ? h('button', {
        type: 'button',
        class: 'btn ghost small cf-empty-action',
        text: actionText,
        onclick: onAction
      }) : null
    );
    return root;
  };

  /* ------------------------------------------------------------------ *
   *  Universal Promise-Based Confirmation Dialog
   * ------------------------------------------------------------------ */
  function confirmDialog(options) {
    return new Promise(resolve => {
      const modal = $('#customConfirmModal');
      if (!modal) {
        const msg = typeof options === 'string' ? options : (options.message || 'Are you sure?');
        return resolve(window.confirm(msg));
      }

      const opts = typeof options === 'string' ? { message: options } : (options || {});
      const title = opts.title || 'Confirm Action';
      const message = opts.message || 'Are you sure you want to proceed?';
      const hint = opts.hint || '';
      const confirmText = opts.confirmText || 'Confirm';
      const cancelText = opts.cancelText || 'Cancel';
      const type = opts.type || 'danger'; // 'danger' | 'warning' | 'primary' | 'success'

      // Title & Subtitle
      const titleEl = $('#confirmDialogTitle');
      if (titleEl) titleEl.textContent = title;
      const subEl = $('#confirmDialogSubtitle');
      if (subEl) subEl.textContent = opts.subtitle || (type === 'danger' ? 'Confirmation Required' : 'Action Confirmation');

      // Icon wrap theme & SVG icon
      const iconWrap = $('#confirmDialogIconWrap');
      if (iconWrap) {
        iconWrap.className = 'confirm-dialog-icon-wrap ' + type;
        if (type === 'success') {
          iconWrap.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        } else if (type === 'warning' || type === 'danger') {
          iconWrap.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
        } else {
          iconWrap.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        }
      }

      // Message with @username and "quotes" bold highlighting
      const msgEl = $('#confirmDialogMessage');
      if (msgEl) {
        const formatted = escapeHtml(message).replace(/(@[a-zA-Z0-9_\-]+|&quot;[^&]+&quot;)/g, '<strong>$1</strong>');
        msgEl.innerHTML = formatted;
      }

      // Optional Hint
      const hintEl = $('#confirmDialogHint');
      if (hintEl) {
        if (hint) {
          hintEl.textContent = hint;
          hintEl.hidden = false;
        } else {
          hintEl.hidden = true;
          hintEl.textContent = '';
        }
      }

      // Buttons
      const cancelBtn = $('#confirmDialogCancelBtn');
      const confirmBtn = $('#confirmDialogConfirmBtn');
      const closeBtn = $('#confirmDialogCloseBtn');

      if (cancelBtn) cancelBtn.textContent = cancelText;
      if (confirmBtn) {
        confirmBtn.textContent = confirmText;
        confirmBtn.className = 'btn ' + (type === 'danger' ? 'danger' : (type === 'success' ? 'primary' : (type === 'warning' ? 'danger' : 'primary')));
        if (type === 'success') {
          confirmBtn.style.background = 'var(--green)';
          confirmBtn.style.borderColor = 'var(--green)';
          confirmBtn.style.color = '#ffffff';
        } else {
          confirmBtn.style.background = '';
          confirmBtn.style.borderColor = '';
          confirmBtn.style.color = '';
        }
      }

      // Open Modal
      modal.hidden = false;
      modal.style.display = 'flex';
      if (confirmBtn) confirmBtn.focus();

      function cleanup(result) {
        modal.hidden = true;
        modal.style.display = 'none';
        window.removeEventListener('keydown', onKeyDown);
        if (cancelBtn) cancelBtn.removeEventListener('click', onCancel);
        if (confirmBtn) confirmBtn.removeEventListener('click', onConfirm);
        if (closeBtn) closeBtn.removeEventListener('click', onCancel);
        modal.removeEventListener('click', onBackdrop);
        resolve(result);
      }

      function onConfirm() { cleanup(true); }
      function onCancel() { cleanup(false); }
      function onBackdrop(e) { if (e.target === modal) cleanup(false); }
      function onKeyDown(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          cleanup(false);
        } else if (e.key === 'Enter' && e.target !== cancelBtn) {
          e.preventDefault();
          cleanup(true);
        }
      }

      if (cancelBtn) cancelBtn.addEventListener('click', onCancel);
      if (confirmBtn) confirmBtn.addEventListener('click', onConfirm);
      if (closeBtn) closeBtn.addEventListener('click', onCancel);
      modal.addEventListener('click', onBackdrop);
      window.addEventListener('keydown', onKeyDown);
    });
  }

  // Failsafe initialization: ensure confirm modal is hidden and has emergency dismissal bindings
  function initConfirmModalFailsafe() {
    const m = $('#customConfirmModal');
    if (!m) return;
    m.hidden = true;
    m.style.display = 'none';
    const cBtn = $('#confirmDialogCancelBtn');
    const xBtn = $('#confirmDialogCloseBtn');
    const dismiss = () => { m.hidden = true; m.style.display = 'none'; };
    if (cBtn && !cBtn._cfInit) { cBtn._cfInit = true; cBtn.addEventListener('click', dismiss); }
    if (xBtn && !xBtn._cfInit) { xBtn._cfInit = true; xBtn.addEventListener('click', dismiss); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initConfirmModalFailsafe);
  } else {
    initConfirmModalFailsafe();
  }

  /* ------------------------------------------------------------------ *
   *  Export to CafeFlow Namespace
   * ------------------------------------------------------------------ */
  window.CafeFlow.utils = {
    $,
    $$,
    money,
    qtyFmt,
    escapeHtml,
    h,
    emptyState,
    toast,
    confirmDialog,
    tickClock,
    pad2,
    toIsoDate,
    toIsoMonth,
    parseIsoDate,
    addDays,
    addMonths,
    formatHour,
    dayLabel
  };
})();
