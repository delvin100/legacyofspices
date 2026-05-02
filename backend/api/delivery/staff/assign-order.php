<?php
/**
 * Assign Staff to Order API
 * Allows delivery agents to assign a specific staff member to an order/auction
 */

header('Content-Type: application/json');
require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery agents can assign staff
require_role('delivery_agent');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'));

    if (!isset($data->order_id) || !isset($data->staff_id)) {
        throw new Exception('Order ID and Staff ID are required');
    }

    $id = $data->order_id;
    $staff_id = $data->staff_id;
    $type = $data->type ?? 'order';
    $hub_id = $_SESSION['user_id'];

    $pdo = getDBConnection();

    // Verify Staff exists, belongs to this hub, and is ACTIVE
    $staffStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'delivery_staff' AND hub_id = ? AND is_active = 1");
    $staffStmt->execute([$staff_id, $hub_id]);
    if (!$staffStmt->fetch()) {
        throw new Exception('Invalid delivery staff selected, not part of your hub, or staff is currently disabled');
    }

    // Verify the order is assigned to this hub
    if ($type === 'auction') {
        $checkStmt = $pdo->prepare("SELECT id FROM auctions WHERE id = ? AND delivery_agent_id = ?");
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND delivery_agent_id = ?");
    }
    $checkStmt->execute([$id, $hub_id]);
    if (!$checkStmt->fetch()) {
        throw new Exception('Order/Auction not assigned to your hub or not found');
    }

    // Update corresponding table
    if ($type === 'auction') {
        $sql = "UPDATE auctions SET delivery_staff_id = ? WHERE id = ?";
    } else {
        $sql = "UPDATE orders SET delivery_staff_id = ? WHERE id = ?";
    }

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([$staff_id, $id]);

    if ($result) {
        // Log the assignment
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, 'staff_assigned', ?, ?, ?)");
        $targetTable = ($type === 'auction') ? 'auctions' : 'orders';

        // Fetch staff name for description
        $staffNameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $staffNameStmt->execute([$staff_id]);
        $staffName = $staffNameStmt->fetchColumn();

        $formattedId = ($type === 'auction') ? 'AUC-' . str_pad($id, 5, '0', STR_PAD_LEFT) : 'ORD-' . str_pad($id, 5, '0', STR_PAD_LEFT);
        $description = "Assigned staff {$staffName} to {$formattedId}";
        $logStmt->execute([$hub_id, $targetTable, $id, $description]);

        echo json_encode(['success' => true, 'message' => 'Staff assigned successfully']);
    } else {
        throw new Exception('Failed to assign staff');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>