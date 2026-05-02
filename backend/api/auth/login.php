<?php
/**
 * Advanced User Login API
 * Handles secure email/password authentication
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        throw new Exception('Invalid input data');
    }

    if (empty($data['email']) || empty($data['password'])) {
        throw new Exception('Email and password are required');
    }

    $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        throw new Exception('Invalid email format');
    }

    $pdo = getDBConnection();

    // Fetch user including password hash and role
    $stmt = $pdo->prepare("SELECT id, email, password, full_name, country, currency_code, currency_symbol, role, is_active, admin_access, is_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($data['password'], $user['password'])) {
        // Use generic error for security
        throw new Exception('Invalid email or password');
    }

    if (isset($user['is_active']) && !$user['is_active']) {
        throw new Exception('User is blocked by admin. Contact admin for enquire');
    }

    require_once '../../config/session.php';

    $pdo = getDBConnection();

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_country'] = $user['country'];
    $_SESSION['user_currency_code'] = $user['currency_code'];
    $_SESSION['user_currency_symbol'] = $user['currency_symbol'];
    $_SESSION['admin_access'] = $user['admin_access'] ?? 'all';
    $_SESSION['is_verified'] = $user['is_verified'] ?? 0;
    $_SESSION['remember_me'] = !empty($data['keep_logged']);

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'redirect' => getDashboardUrl($user['role'])
    ]);

} catch (Exception $e) {
    http_response_code(401); // Unauthorized
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getDashboardUrl($role)
{
    if ($role === 'admin')
        return '../admin/admin-dashboard.html';
    if ($role === 'delivery_agent')
        return '../delivery/delivery-dashboard.html';
    if ($role === 'delivery_staff')
        return '../delivery/staff-dashboard.html';
    return $role === 'farmer'
        ? '../farmer/farmer-dashboard.html'
        : '../customer/customer-dashboard.html';
}
?>