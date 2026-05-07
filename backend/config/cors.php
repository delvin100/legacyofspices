<?php
/**
 * CORS Configuration
 * Handles cross-origin requests for Vercel + Railway deployment.
 */

// Determine the origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allow specific origins
$allowed_patterns = ['localhost', 'vercel.app', 'railway.app'];
$is_allowed = false;

foreach ($allowed_patterns as $pattern) {
    if (strpos($origin, $pattern) !== false) {
        $is_allowed = true;
        break;
    }
}

if ($is_allowed) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept");
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
