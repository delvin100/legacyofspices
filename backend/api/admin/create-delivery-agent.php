<?php
/**
 * Create Delivery Agent API
 * Allows admins to create new delivery agent accounts
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';

require_once '../../config/session.php';

// Strict Admin Check
require_role('admin');

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
    $required = ['name', 'email', 'phone', 'password', 'address'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            throw new Exception(ucfirst($field) . ' is required');
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

    // 5. Insert Agent
    $country = trim($data['country'] ?? '');
    $currency_code = trim($data['currency_code'] ?? '');
    $currency_symbol = trim($data['currency_symbol'] ?? '');

    if (empty($country) || empty($currency_code)) {
        // Fallback to Admin's country if not provided/optional, 
        // but requirement implies we should allow selecting it.
        // Let's enforce it if the frontend is sending it.
        // For now, if empty, we might throw error or fallback. 
        // Let's fallback to session if empty for backward compatibility or ease.
        $country = $_SESSION['user_country'] ?? 'Unknown';
        $currency_code = $_SESSION['user_currency_code'] ?? 'USD';
        $currency_symbol = $_SESSION['user_currency_symbol'] ?? '$';
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (email, password, full_name, phone, address, role, country, currency_code, currency_symbol, is_verified, is_active) 
        VALUES (?, ?, ?, ?, ?, 'delivery_agent', ?, ?, ?, TRUE, TRUE)
    ");

    if (!$stmt->execute([$email, $password_hash, $name, $phone, $address, $country, $currency_code, $currency_symbol])) {
        throw new Exception('Database error while creating agent');
    }

    $agent_id = $pdo->lastInsertId();

    // 6. Log Admin Action
    $admin_id = $_SESSION['user_id'];
    $log_stmt = $pdo->prepare("
        INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description)
        VALUES (?, 'create_user', 'users', ?, ?)
    ");
    $log_stmt->execute([$admin_id, $agent_id, "Created new delivery agent: $email"]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Delivery agent created successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>