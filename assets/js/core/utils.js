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
   * @param {string} [opts.icon='☕'] - Icon or emoji
   * @param {string} [opts.title='No Data'] - Primary heading
   * @param {string} [opts.desc=''] - Descriptive guidance
   * @param {string} [opts.tip=''] - Pro-tip or hint badge text
   * @param {string} [opts.actionText=''] - Action button text
   * @param {Function} [opts.onAction=null] - Action button click handler
   * @param {boolean} [opts.compact=false] - Whether to use compact padding
   * @returns {HTMLElement}
   */
  const emptyState = ({ icon = '☕', title = 'No Data', desc = '', tip = '', actionText = '', onAction = null, compact = false }) => {
    const root = h('div', { class: 'cf-empty-state' + (compact ? ' compact' : '') },
      h('div', { class: 'cf-empty-icon-wrap' }, h('span', { text: icon })),
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
