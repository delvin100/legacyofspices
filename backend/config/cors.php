<?php
/**
 * CORS Configuration
 * Handles cross-origin requests for Vercel + Railway deployment.
 */

// Use FRONTEND_URL from environment variable, or allow all in development
$allowed_origin = getenv('FRONTEND_URL') ?: 'http://localhost';

// Get the actual origin from the request
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// If the request origin matches our allowed origin (or we're in dev), set the header
if (empty($allowed_origin) || $allowed_origin === '*' || $request_origin === $allowed_origin || strpos($request_origin, 'localhost') !== false) {
    header("Access-Control-Allow-Origin: $request_origin");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
