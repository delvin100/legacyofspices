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
    $is_allowed = true;
} else {
    foreach ($allowed_patterns as $pattern) {
        if (strpos(strtolower($origin), $pattern) !== false) {
            $is_allowed = true;
            break;
        }
    }
}

if ($is_allowed) {
    $allowed_origin = $origin ?: '*';
    header("Access-Control-Allow-Origin: $allowed_origin");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept, Cache-Control, Pragma");
    header("X-CORS-Status: Allowed");
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
?>
