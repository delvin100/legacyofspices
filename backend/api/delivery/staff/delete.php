<?php
header('Content-Type: application/json');
require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery agents can delete their staff
require_role('delivery_agent');

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['staff_id'])) {
    echo json_encode(['success' => false, 'message' => 'Staff ID required']);
    exit;
}

$staffId = $data['staff_id'];
$agentId = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Verify staff belongs to agent's hub and fetch name for logging
    $checkStmt = $pdo->prepare("SELECT full_name, email FROM users WHERE id = ? AND hub_id = ? AND role = 'delivery_staff'");
    $checkStmt->execute([$staffId, $agentId]);
    $staff = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized or staff member not found']);
        exit;
    }

    // Optional: Check for active tasks before deletion
    // For now, we'll proceed with deletion as requested.

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $result = $stmt->execute([':id' => $staffId]);

    if ($result) {
        // Log the deletion
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, 'staff_deleted', 'users', ?, ?)");
        $description = "Deleted delivery staff: {$staff['full_name']} ({$staff['email']})";
        $logStmt->execute([$agentId, $staffId, $description]);

        echo json_encode(['success' => true, 'message' => 'Staff member deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete staff member']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>