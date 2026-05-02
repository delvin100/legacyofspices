<?php
/**
 * Reset Password Confirmation API
 * Validates the local token and updates the MySQL database
 */

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../../config/env.php';

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->oobCode) || !isset($data->password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid request: Missing parameters"]);
    exit;
}

try {
    $db = getDBConnection();
    $token = $data->oobCode;

    // 1. Verify Local Token and Expiry (10 minutes)
    $stmt = $db->prepare("SELECT email, reset_token_sent_at FROM users WHERE reset_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("Security mismatch: Reset link is invalid or has already been used.");
    }

    $email = $user['email'];
    $sentAt = strtotime($user['reset_token_sent_at']);
    
    if (time() - $sentAt > 600) { // 10 minutes
        throw new Exception("Reset link has expired (10 min limit).");
    }

    // 2. Update Password in MySQL Database
    // We use the same hashing algorithm as in register.php (Argon2id)
    $password_hash = password_hash($data->password, PASSWORD_ARGON2ID);

    // Update password AND clear the token so it can't be reused
    $stmt = $db->prepare("UPDATE users SET password = :password, reset_token = NULL WHERE email = :email");
    $result = $stmt->execute([
        ':password' => $password_hash,
        ':email' => $email
    ]);

    if (!$result) {
        throw new Exception("Failed to update password in database. Please contact support.");
    }

    echo json_encode([
        "status" => "success",
        "message" => "Password updated successfully"
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    error_log("Reset Confirm Error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>