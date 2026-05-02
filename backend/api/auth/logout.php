<?php
/**
 * User Logout API
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/session.php';

// Destroy all session data
$_SESSION = array();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy session
session_destroy();

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully',
    'redirect' => '/index.html'
]);
?>