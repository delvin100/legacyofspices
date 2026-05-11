<?php
/**
 * CORS Configuration
 * Handles cross-origin requests for Vercel + Railway deployment.
 */

// Determine the origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allow specific origins
$allowed_patterns = ['localhost', 'vercel.app', 'railway.app', 'github.dev'];
$is_allowed = false;

if (empty($origin)) {
    // If no origin (e.g. same-origin), it's allowed
    $is_allowed = true;
} else {
    foreach ($allowed_patterns as $pattern) {
        if (strpos($origin, $pattern) !== false) {
            $is_allowed = true;
            break;
        }
    }
}

if ($is_allowed && !empty($origin)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept");
} elseif (!$is_allowed && !empty($origin)) {
    // Log for debugging if needed
    // error_log("CORS Denied for origin: $origin");
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
