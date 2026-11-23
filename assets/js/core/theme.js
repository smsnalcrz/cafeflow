/**
 * CafeFlow POS - Theme Management Module (Dark / Light Mode)
 * Controls theme switching, localStorage persistence, system preference synchronization,
 * topbar toggle button states, and theme change event broadcasting for charts.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  const STORAGE_KEY = 'cafeflow_theme';

  function getSystemPreference() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function getTheme() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored === 'dark' || stored === 'light') return stored;
    } catch (_) {}
    return getSystemPreference();
  }

  function updateThemeUI(theme) {
    const isDark = theme === 'dark';

    // 1. Root DOM attribute
    document.documentElement.setAttribute('data-theme', theme);
    document.body.classList.toggle('dark-mode', isDark);

    // 2. Meta theme-color for browser chrome
    const metaTheme = document.querySelector('meta[name="theme-color"]');
    if (metaTheme) {
      metaTheme.setAttribute('content', isDark ? '#0c150e' : '#162318');
    }

    // 3. Topbar theme toggle button
    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
      toggleBtn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
      toggleBtn.setAttribute('title', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
      toggleBtn.setAttribute('aria-label', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
      
      const labelText = document.getElementById('themeToggleText');
      if (labelText) {
        labelText.textContent = isDark ? 'Light' : 'Dark';
      }
    }

    // 4. Dropdown theme toggle button (inside profile menu)
    const dropdownToggle = document.getElementById('dropdownThemeToggle');
    const dropdownLabel = document.getElementById('dropdownThemeLabel');
    if (dropdownToggle && dropdownLabel) {
      dropdownLabel.textContent = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
    }
  }

  function setTheme(theme, broadcast = true) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (_) {}

    updateThemeUI(theme);

    if (broadcast) {
      window.dispatchEvent(new CustomEvent('cafeflow:themechange', { detail: { theme } }));
    }
  }

  function toggleTheme() {
    const current = getTheme();
    const next = current === 'dark' ? 'light' : 'dark';
    setTheme(next, true);

    if (window.CafeFlow.utils && window.CafeFlow.utils.toast) {
      window.CafeFlow.utils.toast(
        next === 'dark' ? '🌙 Dark Mode Activated' : '☀️ Light Mode Activated',
        'info',
        2000,
        next === 'dark' ? 'Optimized for low-light environments' : 'Clean high-contrast daytime mode'
      );
    }

    return next;
  }

  function initTheme() {
    const current = getTheme();
    updateThemeUI(current);

    // Attach listener to topbar button
    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        toggleTheme();
      });
    }

    // Attach listener to dropdown toggle button
    const dropdownToggle = document.getElementById('dropdownThemeToggle');
    if (dropdownToggle) {
      dropdownToggle.addEventListener('click', (e) => {
        e.preventDefault();
        const profileDropdown = document.getElementById('profileDropdown');
        if (profileDropdown) profileDropdown.hidden = true;
        const profileBtn = document.getElementById('profileBtn');
        if (profileBtn) profileBtn.classList.remove('active');
        toggleTheme();
      });
    }

    // Listen for OS system theme changes if user has not explicitly set a preference
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        try {
          if (!localStorage.getItem(STORAGE_KEY)) {
            setTheme(e.matches ? 'dark' : 'light', true);
          }
        } catch (_) {}
      });
    }
  }

  // Helper for Chart.js theme colors
  function getChartThemeColors() {
    const isDark = getTheme() === 'dark';
    return {
      isDark,
      textColor: isDark ? '#f1f7f3' : '#162318',
      mutedColor: isDark ? '#8da694' : '#5a7a63',
      gridColor: isDark ? 'rgba(255, 255, 255, 0.08)' : 'rgba(22, 35, 24, 0.07)',
      borderColor: isDark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(22, 35, 24, 0.10)',
      tooltipBg: isDark ? '#111d14' : '#162318',
      tooltipText: isDark ? '#f1f7f3' : '#ffffff',
      tooltipBorder: isDark ? '#233828' : 'rgba(255, 255, 255, 0.15)'
    };
  }

  window.CafeFlow.theme = {
    getTheme,
    setTheme,
    toggleTheme,
    initTheme,
    getChartThemeColors
  };
})();
