<?php
require_once '../../config/cors.php';
/**
 * Update Admin Profile API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Get current session admin ID
    $adminId = $_SESSION['user_id'];

    // Get JSON data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        throw new Exception('No data provided');
    }

    $targetUserId = trim($data['user_id'] ?? $adminId); // If no user_id, update self
    $fullName = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');

    if (empty($fullName) || empty($email)) {
        throw new Exception('Full name and email are required');
    }

    // Check if email already exists for a different user
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $targetUserId]);
    if ($stmt->fetch()) {
        throw new Exception('Email address is already in use by another account');
    }

    // Build dynamic update query
    $updateFields = ['full_name = ?', 'email = ?'];
    $params = [$fullName, $email];

    // Only update phone if provided
    if (isset($data['phone'])) {
        $updateFields[] = 'phone = ?';
        $params[] = trim($data['phone']);
    }

    // Only update address if provided
    if (isset($data['address'])) {
        $updateFields[] = 'address = ?';
        $params[] = trim($data['address']);
    }

    // Only update country if provided
    if (isset($data['country'])) {
        $updateFields[] = 'country = ?';
        $params[] = trim($data['country']);
        if ($targetUserId == $adminId) $_SESSION['user_country'] = trim($data['country']);
    }

    // Only update currency if provided
    if (isset($data['currency_code']) && isset($data['currency_symbol'])) {
        $updateFields[] = 'currency_code = ?';
        $params[] = trim($data['currency_code']);
        $updateFields[] = 'currency_symbol = ?';
        $params[] = trim($data['currency_symbol']);
        if ($targetUserId == $adminId) {
            $_SESSION['user_currency_code'] = trim($data['currency_code']);
            $_SESSION['user_currency_symbol'] = trim($data['currency_symbol']);
        }
    }

    // Only update admin_access if provided
    if (isset($data['admin_access'])) {
        $updateFields[] = 'admin_access = ?';
        $params[] = trim($data['admin_access']);
        if ($targetUserId == $adminId) {
            $_SESSION['admin_access'] = trim($data['admin_access']);
        }
    }

    $params[] = $targetUserId;

    $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);

    if ($result) {
        // Update Session Immediately if updating self
        if ($targetUserId == $adminId) {
            $_SESSION['user_name'] = $fullName;
            $_SESSION['user_email'] = $email;
        }

        echo json_encode(['success' => true, 'message' => 'User updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update profile']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
