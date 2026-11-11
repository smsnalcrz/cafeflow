/**
 * CafeFlow POS - Configuration & Runtime Environment
 * Sets application-wide constants, intervals, and current session user context.
 */
'use strict';

window.CafeFlow = window.CafeFlow || {};

const currentUser = window.CafeFlowUser || { role: 'barista', username: 'guest' };
window.currentUser = currentUser;

window.CafeFlow.config = {
  POLL_MS: 3000,   // Barista queue refresh interval (milliseconds)
  MAX_QTY: 99,     // Maximum quantity allowed per order line
  currentUser: currentUser
};
