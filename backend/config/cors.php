<?php
/**
 * Strict CORS Configuration
 * Required for Cross-Domain sessions with credentials: 'include'
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// 1. Explicitly allow the Vercel domain and local dev
$allowed_origins = [
    'https://legacyofspices.vercel.app',
    'https://www.legacyofspices.vercel.app',
    'http://localhost:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5500' // Live Server default
];

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // If not in list but we need to debug, allow the specific origin that requested it
    // NEVER use '*' when credentials: 'include' is used
    if (!empty($origin)) {
        header("Access-Control-Allow-Origin: $origin");
    }
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept, Cache-Control, Pragma, X-CORS-Debug");
header("X-CORS-Debug: Active");

// 2. Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
?>
