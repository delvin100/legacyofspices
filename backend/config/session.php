<?php
/**
 * Centralized Session Management
 * This file should be included in every API endpoint that requires session access.
 */

// Prevent browser caching for session-dependent pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

// Set secure session cookie parameters
if (session_status() === PHP_SESSION_NONE) {
    // Set secure session cookie parameters
    $duration = 30 * 24 * 60 * 60; // 30 days
    $is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || getenv('FRONTEND_URL');
    
    session_set_cookie_params([
        'lifetime' => $duration,
        'path' => '/',
        'secure' => $is_secure,
        'httponly' => true,
        'samesite' => $is_secure ? 'None' : 'Lax'
    ]);
    session_start();
}

/**
 * Check if a user is logged in
 */
function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

/**
 * Require a user to be logged in, otherwise return 401 Unauthorized
 */
function require_login()
{
    if (!is_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login to continue']);
        exit;
    }
}

/**
 * Require an admin to have a specific access level
 * @param string $permission The required permission ('delivery' or 'cert')
 */
function require_admin_access($permission)
{
    require_role('admin');

    if (!has_admin_permission($permission)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access Denied: Your administrative level does not permit this action']);
        exit;
    }
}

/**
 * Check if current admin has a specific permission
 * @param string $permission The permission to check ('delivery' or 'cert')
 */
function has_admin_permission($permission)
{
    $admin_access = $_SESSION['admin_access'] ?? 'all';
    if ($admin_access === 'all') return true;

    if ($permission === 'delivery') {
        return ($admin_access === 'delivery_only' || $admin_access === 'delivery_cert');
    }
    if ($permission === 'cert') {
        return ($admin_access === 'cert_only' || $admin_access === 'delivery_cert');
    }
    
    return false;
}

/**
 * Require a user to have a specific role, otherwise return 403 Forbidden
 * @param string|array $role The required role or an array of roles
 */
function require_role($role)
{
    require_login();

    $user_role = $_SESSION['user_role'] ?? '';
    $has_role = is_array($role) ? in_array($user_role, $role) : ($user_role === $role);

    if (!$has_role) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: You do not have permission to access this resource']);
        exit;
    }
}