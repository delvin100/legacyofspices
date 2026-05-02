<?php
/**
 * Update Admin Password API
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
    $userId = $_SESSION['user_id'];

    // Get JSON data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        throw new Exception('No data provided');
    }

    $currentPassword = $data['current_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        throw new Exception('All fields are required');
    }

    if ($newPassword !== $confirmPassword) {
        throw new Exception('New passwords do not match');
    }

    if (strlen($newPassword) < 8) {
        throw new Exception('New password must be at least 8 characters long');
    }

    // Fetch current hashed password
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception('Admin user not found');
    }

    // Verify current password
    // Note: If user was created without a password (Google Sign In), password_verify will return false.
    // However, an admin should typically have a set password for this dashboard.
    if (!password_verify($currentPassword, $user['password'])) {
        throw new Exception('Incorrect current password');
    }

    // Hash new password
    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

    // Update password
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
    $result = $stmt->execute([$hashedPassword, $userId]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update password']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>