<?php
/**
 * CORS Configuration
 * Handles cross-origin requests for Vercel + Railway deployment.
 */

// Use FRONTEND_URL from environment variable
$allowed_origin = getenv('FRONTEND_URL') ?: ($_ENV['FRONTEND_URL'] ?? '');

$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Robust Origin Check
if (empty($allowed_origin) || $allowed_origin === '*' || $request_origin === $allowed_origin || strpos($request_origin, 'localhost') !== false || strpos($request_origin, 'vercel.app') !== false) {
    header("Access-Control-Allow-Origin: " . ($request_origin ?: '*'));
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
