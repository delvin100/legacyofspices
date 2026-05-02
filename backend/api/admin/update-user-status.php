<?php
ob_start();
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/session.php';

// Enable error reporting for debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    // Security Check: Ensure user is admin
    require_role('admin');

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['user_id']) || !isset($data['action'])) {
        throw new Exception('Missing required fields');
    }

    $userId = $data['user_id'];
    $action = $data['action'];

    // Debug session and action
    file_put_contents(__DIR__ . '/status_debug.txt', date('[Y-m-d H:i:s] ') . "Action: $action | UserID: $userId | Session Email: " . ($_SESSION['user_email'] ?? 'NULL') . PHP_EOL, FILE_APPEND);

    // Super Admin Check for sensitive actions (block/unblock other admins)
    // We check the target user's role first
    $pdo = getDBConnection();
    $checkStmt = $pdo->prepare("SELECT role, email FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    $target = $checkStmt->fetch();

    if (!$target) {
        throw new Exception('User not found');
    }

    if ($target['role'] === 'admin' || in_array($action, ['block', 'unblock'])) {
        if ($_SESSION['user_email'] !== 'admin@gmail.com' && $_SESSION['user_id'] != 1) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized: Only the Super Admin can perform this action.']);
            exit;
        }
    }

    $query = "";
    $params = [':id' => $userId];

    switch ($action) {
        case 'block':
            $query = "UPDATE users SET is_active = 0 WHERE id = :id";
            break;
        case 'unblock':
            $query = "UPDATE users SET is_active = 1 WHERE id = :id";
            break;
        case 'verify':
            $query = "UPDATE users SET is_verified = 1 WHERE id = :id";
            break;
        case 'unverify':
            $query = "UPDATE users SET is_verified = 0 WHERE id = :id";
            break;
        default:
            throw new Exception('Invalid action');
    }

    $stmt = $pdo->prepare($query);
    $result = $stmt->execute($params);

    ob_end_clean();
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'User status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update user']);
    }

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(isset($target) ? 400 : 500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>