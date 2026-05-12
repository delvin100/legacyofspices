<?php
/**
 * Bulletproof CORS Configuration
 * This MUST be included before any session_start() or output.
 */

// 1. Get Origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// 2. Define Allowed Origins (No Wildcards!)
$allowed_origins = [
    'https://legacyofspices.vercel.app',
    'https://www.legacyofspices.vercel.app',
    'http://localhost:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5500'
];

// 3. Match Origin or Default to the primary Vercel app
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // If we're on production, default to the Vercel domain to prevent '*'
    header("Access-Control-Allow-Origin: https://legacyofspices.vercel.app");
}

// 4. Required for Credentials
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept, Cache-Control, Pragma, X-CORS-Debug");
header("Access-Control-Max-Age: 86400");
header("Vary: Origin");

// 5. Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
?>
