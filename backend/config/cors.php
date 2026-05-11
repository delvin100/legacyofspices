<?php
/**
 * CORS Configuration - Debug Version
 * Temporary ultra-permissive setup to isolate the 502 crash.
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allow everything from trusted domains or anyone during debug
header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept, Cache-Control, Pragma, X-CORS-Debug");
header("X-CORS-Debug: Active");

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
?>
