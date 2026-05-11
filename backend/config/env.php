<?php
/**
 * Global Environment & Session Settings
 * Cross-Domain Production Optimization
 */

// 1. Session Security (Crucial for Cross-Domain)
// This ensures the session cookie is sent from Vercel to Railway
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', 'On');
ini_set('session.cookie_httponly', 'On');
ini_set('session.use_only_cookies', 'On');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors in production
ini_set('log_errors', 1);

// 3. Timezone
date_default_timezone_set('Asia/Kolkata');

// 4. Load .env if exists (for local development)
if (file_exists(__DIR__ . '/../../.env')) {
    $lines = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}
?>