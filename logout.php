<?php
/**
 * CafeFlow - Logout handler
 */
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/auth.php';

logout_user();
header('Location: login.php?logged_out=1');
exit;
