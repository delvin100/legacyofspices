<?php
/**
 * Advanced User Registration API
 * Handles secure email/password registration with strict validation
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

    // 1. Basic Presence Validation
    $required = ['email', 'password', 'full_name', 'role', 'country', 'currency_code', 'currency_symbol'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
        }
    }

    // 2. Email Validation (Strict)
    $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        throw new Exception('Please provide a valid email address');
    }

    // 3. Name Validation
    $full_name = trim($data['full_name']);
    if (strlen($full_name) < 2 || strlen($full_name) > 100) {
        throw new Exception('Full name must be between 2 and 100 characters');
    }
    if (!preg_match("/^[a-zA-Z\s'-]+$/", $full_name)) {
        throw new Exception('Full name contains invalid characters');
    }

    // 4. Role Validation
    $role = strtolower(trim($data['role']));
    $allowed_roles = ['farmer', 'customer'];
    if (!in_array($role, $allowed_roles)) {
        throw new Exception('Invalid account type selected');
    }

    // 5. Password Validation (Min 8 chars, no spaces)
    $password = $data['password'];
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }
    if (strpos($password, ' ') !== false) {
        throw new Exception('Password cannot contain spaces');
    }

    $country = trim($data['country']);
    $currency_code = trim($data['currency_code']);
    $currency_symbol = trim($data['currency_symbol']);

    $pdo = getDBConnection();

    // 6. Check for duplicate email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception('This email address is already registered');
    }

    // 7. Secure Password Hashing
    $password_hash = password_hash($password, PASSWORD_ARGON2ID);

    // 8. Insert User
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, full_name, country, currency_code, currency_symbol, role, is_verified) 
        VALUES (?, ?, ?, ?, ?, ?, ?, FALSE)
    ");

    if (!$stmt->execute([$email, $password_hash, $full_name, $country, $currency_code, $currency_symbol, $role])) {
        throw new Exception('System error during registration. Please try again later.');
    }

    $user_id = $pdo->lastInsertId();

    // 9. Fetch and set session
    $stmt = $pdo->prepare("SELECT id, email, full_name, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (session_status() === PHP_SESSION_NONE) {
        if (!empty($data['keep_logged'])) {
            $duration = 30 * 24 * 60 * 60; // 30 days
            ini_set('session.gc_maxlifetime', $duration);
            session_set_cookie_params($duration);
        }
        session_start();
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_country'] = $country;
    $_SESSION['user_currency_code'] = $currency_code;
    $_SESSION['user_currency_symbol'] = $currency_symbol;

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'redirect' => getDashboardUrl($user['role'])
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getDashboardUrl($role)
{
    return $role === 'farmer'
        ? '../farmer/farmer-dashboard.html'
        : '../customer/customer-dashboard.html';
}
?>