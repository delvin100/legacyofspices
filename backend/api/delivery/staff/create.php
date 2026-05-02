<?php
/**
 * Create Delivery Staff API
 * Allows delivery agents to create staff accounts linked to their hub
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery agents can create staff
require_role('delivery_agent');

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

    // Validate Inputs
    $required = ['name', 'email', 'phone', 'password'];
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
    $password = $data['password'];
    $address = trim($data['address'] ?? '');

    // Phone Validation
    if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
        throw new Exception('Invalid phone format. Only digits and optional + are allowed (10-15 digits).');
    }

    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    $pdo = getDBConnection();

    // Check for Duplicate Email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception('Email already registered');
    }

    // Hash Password
    $password_hash = password_hash($password, PASSWORD_ARGON2ID);

    // Get Hub ID (current agent's ID)
    $hub_id = $_SESSION['user_id'];

    // Inherit location and currency info from the agent, or use provided values
    $country = trim($data['country'] ?? '');
    $currency_code = trim($data['currency_code'] ?? '');
    $currency_symbol = trim($data['currency_symbol'] ?? '');

    if (empty($country) || empty($currency_code)) {
        // Fallback to agent session if not provided
        $country = $_SESSION['user_country'] ?? 'Unknown';
        $currency_code = $_SESSION['user_currency_code'] ?? 'USD';
        $currency_symbol = $_SESSION['user_currency_symbol'] ?? '$';
    }

    // Insert Staff
    $stmt = $pdo->prepare("
        INSERT INTO users (
            email, password, full_name, phone, address, role, 
            hub_id, country, currency_code, currency_symbol, 
            is_verified, is_active
        ) VALUES (?, ?, ?, ?, ?, 'delivery_staff', ?, ?, ?, ?, TRUE, TRUE)
    ");

    if (
        !$stmt->execute([
            $email,
            $password_hash,
            $name,
            $phone,
            $address,
            $hub_id,
            $country,
            $currency_code,
            $currency_symbol
        ])
    ) {
        throw new Exception('Database error while creating staff');
    }

    $staff_id = $pdo->lastInsertId();

    // Log the creation
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, 'staff_created', 'users', ?, ?)");
    $description = "Created delivery staff: {$name} ({$email})";
    $logStmt->execute([$hub_id, $staff_id, $description]);

    echo json_encode([
        'success' => true,
        'message' => 'Delivery staff created successfully'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>