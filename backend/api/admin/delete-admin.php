<?php
require_once '../../config/cors.php';
ob_start();
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/session.php';

// Enable error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

try {
    // Strict Admin Check
    require_role('admin');

    // Super Admin Check (only admin@gmail.com or ID 1 can delete other admins)
    if ($_SESSION['user_email'] !== 'admin@gmail.com' && $_SESSION['user_id'] != 1) {
        http_response_code(403);
        throw new Exception('Unauthorized: Only the Super Admin can delete administrator accounts.');
    }

    $data = json_decode(file_get_contents('php://input'), true);

    // Supporting both admin_id and user_id for flexibility
    $admin_id = $data['admin_id'] ?? $data['user_id'] ?? null;

    if (!$admin_id) {
        throw new Exception('Administrator ID is required');
    }

    // Debug session
    file_put_contents(__DIR__ . '/status_debug.txt', date('[Y-m-d H:i:s] ') . "Delete Admin Attempt | Target: $admin_id | Session Email: " . ($_SESSION['user_email'] ?? 'NULL') . PHP_EOL, FILE_APPEND);

    $pdo = getDBConnection();

    // Verify the target is actually an admin and not the super admin itself
    $stmt = $pdo->prepare("SELECT email, role FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        throw new Exception('Administrator not found');
    }

    if ($target['role'] !== 'admin') {
        throw new Exception('User is not an administrator');
    }

    if ($target['email'] === 'admin@gmail.com' || $admin_id == 1) {
        throw new Exception('The Super Admin account cannot be deleted');
    }

    // Delete the admin
    $delete_stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $result = $delete_stmt->execute([$admin_id]);

    ob_end_clean();
    if ($result) {
        // Log the action
        $current_admin_id = $_SESSION['user_id'];
        $log_stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description)
            VALUES (?, 'delete_user', 'users', ?, ?)
        ");
        $log_stmt->execute([$current_admin_id, $admin_id, "Deleted administrator: " . $target['email']]);

        echo json_encode([
            'success' => true,
            'message' => 'Administrator account deleted successfully'
        ]);
    } else {
        throw new Exception('Failed to delete administrator account');
    }

} catch (Exception $e) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(isset($_SESSION['user_id']) ? 400 : 401);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
