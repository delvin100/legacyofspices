<?php
header('Content-Type: application/json');
require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery agents can update their staff
require_role('delivery_agent');

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['staff_id']) || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$staffId = $data['staff_id'];
$action = $data['action']; // 'block', 'unblock'
$agentId = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Verify staff belongs to agent's hub
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND hub_id = ? AND role = 'delivery_staff'");
    $checkStmt->execute([$staffId, $agentId]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized or staff member not found']);
        exit;
    }

    $status = ($action === 'unblock') ? 1 : 0;
    $query = "UPDATE users SET is_active = :status WHERE id = :id";

    $stmt = $pdo->prepare($query);
    $result = $stmt->execute([':status' => $status, ':id' => $staffId]);

    if ($result) {
        // Log the action
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, ?, 'users', ?, ?)");
        $statusText = ($action === 'unblock') ? 'enabled' : 'disabled';

        // Fetch staff name for description
        $nameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $nameStmt->execute([$staffId]);
        $staffName = $nameStmt->fetchColumn() ?: 'Unknown Staff';

        $description = "{$statusText} delivery staff member: {$staffName}";
        $logStmt->execute([$agentId, 'staff_status_updated', $staffId, $description]);

        $message = ($action === 'unblock') ? 'Staff enabled successfully' : 'Staff disabled successfully';
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update staff status']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>