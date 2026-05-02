<?php
/**
 * Assign Order to Other Delivery Agent API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization: Delivery Agent only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'delivery_agent') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Delivery Agent access required.']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = $data['order_id'] ?? null;
    $target_agent_id = $data['agent_id'] ?? null;
    $type = $data['type'] ?? 'order'; // 'order' or 'auction'

    if (!$order_id || !$target_agent_id) {
        throw new Exception('Order ID and Agent ID are required.');
    }

    $current_agent_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // Determine table and initial check
    $table = ($type === 'auction') ? 'auctions' : 'orders';

    // Verify order is currently assigned to this agent and is in a transferable status
    // Allowing re-assignment if it's already shipped but not delivered, or if it's just assigned
    $stmt = $pdo->prepare("SELECT status, delivery_agent_id FROM $table WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Task not found.');
    }

    if ($order['delivery_agent_id'] != $current_agent_id) {
        throw new Exception('You are not authorized to reassign this task.');
    }

    if (in_array($order['status'], ['delivered', 'cancelled'])) {
        throw new Exception('Completed or cancelled tasks cannot be reassigned.');
    }

    // Verify Target Agent exists and is active
    $agentStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'delivery_agent' AND is_active = 1");
    $agentStmt->execute([$target_agent_id]);
    $target_agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target_agent) {
        throw new Exception('Invalid or inactive delivery agent selected.');
    }

    if ($target_agent_id == $current_agent_id) {
        throw new Exception('You cannot assign a task to yourself.');
    }

    // Verify Current Agent (to get name)
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$current_agent_id]);
    $current_agent = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_agent_name = $current_agent ? $current_agent['full_name'] : "Agent #$current_agent_id";

    // Begin transaction
    $pdo->beginTransaction();

    // 1. Update order with new agent
    // If already shipped, keep as shipped. If pending/ordered, set to shipped_pending.

    $new_status = ($order['status'] === 'shipped') ? 'shipped' : 'shipped_pending';
    $status_update_sql = ($new_status === 'shipped')
        ? "UPDATE $table SET delivery_agent_id = ?, delivery_staff_id = NULL WHERE id = ?"
        : "UPDATE $table SET status = 'shipped_pending', delivery_agent_id = ?, delivery_staff_id = NULL, shipped_at = NOW() WHERE id = ?";

    $stmt = $pdo->prepare($status_update_sql);
    $stmt->execute([$target_agent_id, $order_id]);

    // 2. Log to tracking
    $comment = ($new_status === 'shipped')
        ? "Task transferred from " . $current_agent_name . " to " . $target_agent['full_name']
        : "Task reassigned from " . $current_agent_name . " to " . $target_agent['full_name'];

    // Always use order_tracking, utilizing the 'type' column to distinguish
    $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment, type, created_by) VALUES (?, ?, ?, ?, ?)");
    $trackStmt->execute([$order_id, $new_status, $comment, $type, $current_agent_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Task successfully reassigned to Agent ' . $target_agent['full_name']
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>