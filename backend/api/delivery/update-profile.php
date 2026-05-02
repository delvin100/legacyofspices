<?php
/**
 * Update Delivery Agent Profile API
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'delivery_agent') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $_SESSION['user_id'];
    $full_name = trim($data['full_name'] ?? '');
    $email = trim($data['email'] ?? '');

    if (empty($full_name) || empty($email)) {
        throw new Exception('Name and Email are required');
    }

    $pdo = getDBConnection();

    // Check email uniqueness if changed
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$email, $user_id]);
    if ($stmt->fetch()) {
        throw new Exception('Email is already in use by another account');
    }

    // Build dynamic update query
    $updateFields = ['full_name = ?', 'email = ?'];
    $params = [$full_name, $email];

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

    $updateFields[] = 'updated_at = CURRENT_TIMESTAMP';
    $params[] = $user_id;

    $stmt = $pdo->prepare("
        UPDATE users 
        SET " . implode(', ', $updateFields) . "
        WHERE id = ?
    ");

    if ($stmt->execute($params)) {
        // Update session
        $_SESSION['user_name'] = $full_name;
        $_SESSION['user_email'] = $email;

        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } else {
        throw new Exception('Failed to update profile');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>