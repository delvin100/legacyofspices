<?php
/**
 * Create Admin API
 * Allows existing admins to create new admin accounts
 */

header('Content-Type: application/json');
require_once '../../config/cors.php';
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';
require_once '../../config/session.php';

// Strict Admin Check
require_role('admin');

// Super Admin Check (only admin@gmail.com can create other admins)
if ($_SESSION['user_email'] !== 'admin@gmail.com') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Only the Super Admin can create new administrators.']);
    exit;
}

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

    // 2. Validate Inputs
    $required = ['name', 'email', 'phone', 'password', 'address', 'currency_code', 'currency_symbol', 'admin_access'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
        }
    }

    $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        throw new Exception('Invalid email address');
    }

    $name = trim($data['name']);
    $phone = trim($data['phone']);
    $address = trim($data['address']);
    $password = $data['password'];
    $currency_code = trim($data['currency_code']);
    $currency_symbol = trim($data['currency_symbol']);

    // Strict Phone Validation: Optional + at start, then 10-15 digits
    if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
        throw new Exception('Invalid phone format. Only digits and optional + are allowed (10-15 digits).');
    }

    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    $pdo = getDBConnection();

    // 3. Check for Duplicate Email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception('Email already registered');
    }

    // 4. Hash Password
    $password_hash = password_hash($password, PASSWORD_ARGON2ID);

    // 5. Insert Admin
    // For admins, we'll use the current admin's country as default if not provided
    $country = $data['country'] ?? ($_SESSION['user_country'] ?? 'India');
    // Set Admin Access Level
    $allowed_access = ['all', 'delivery_only', 'cert_only', 'delivery_cert'];
    $admin_access = in_array($data['admin_access'], $allowed_access) ? $data['admin_access'] : 'all';

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, full_name, phone, address, role, country, currency_code, currency_symbol, is_verified, is_active, admin_access)
        VALUES (?, ?, ?, ?, ?, 'admin', ?, ?, ?, TRUE, TRUE, ?)
    ");

    if (!$stmt->execute([$email, $password_hash, $name, $phone, $address, $country, $currency_code, $currency_symbol, $admin_access])) {
        throw new Exception('Database error while creating admin');
    }

    $new_admin_id = $pdo->lastInsertId();

    // 6. Log Admin Action
    $admin_id = $_SESSION['user_id'];
    $log_stmt = $pdo->prepare("
        INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description)
        VALUES (?, 'create_user', 'users', ?, ?)
    ");
    $log_stmt->execute([$admin_id, $new_admin_id, "Created new admin: $email (Access: $admin_access)"]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Admin account created successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
