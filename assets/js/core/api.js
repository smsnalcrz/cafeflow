/**
 * CafeFlow POS - API Client
 * fetch() wrapper: supports JSON and FormData, auto-attaches CSRF token, handles session expiration.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

(() => {
  async function api(url, body) {
    const config = window.CafeFlow.config || {};
    const currentUser = config.currentUser || window.CafeFlowUser || { role: 'barista', username: 'guest' };

    const finalUrl = (url.startsWith('api/') || url.startsWith('http')) ? url : 'api/' + url;
    let init;
    const headers = {};
    if (currentUser && currentUser.csrf_token) {
      headers['X-CSRF-Token'] = currentUser.csrf_token;
    }

    if (body === undefined) {
      init = { cache: 'no-store', headers };
    } else if (body instanceof FormData) {
      init = { method: 'POST', body, cache: 'no-store', headers };
    } else {
      headers['Content-Type'] = 'application/json';
      init = {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
        cache: 'no-store'
      };
    }

    let res;
    try {
      res = await fetch(finalUrl, init);
    } catch (_) {
      throw new Error('Cannot reach the server. Is XAMPP running?');
    }

    if (res.status === 401) {
      window.location.href = 'login.php?timeout=1';
      throw new Error('Your session has expired. Redirecting to sign in…');
    }

    let data = null;
    try {
      data = await res.json();
    } catch (_) {
      /* non-JSON reply */
    }

    if (!res.ok || !data || data.success !== true) {
      throw new Error((data && data.error) || 'Something went wrong. Please try again.');
    }
    return data;
  }

  window.CafeFlow.api = api;
})();
