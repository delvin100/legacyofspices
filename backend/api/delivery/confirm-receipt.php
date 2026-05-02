<?php
/**
 * Confirm Order Receipt by Delivery Agent API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization: Delivery Agent only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'delivery_agent') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = $data['order_id'] ?? null;
    $type = $data['type'] ?? 'order'; // Default to 'order', can be 'auction'

    if (!$order_id) {
        throw new Exception('Order/Auction ID is required.');
    }

    $agent_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // Verify order/auction is assigned to this agent and is in 'shipped_pending'
    if ($type === 'auction') {
        $stmt = $pdo->prepare("SELECT shipping_status as status FROM auctions WHERE id = ? AND delivery_agent_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? AND delivery_agent_id = ?");
    }

    $stmt->execute([$order_id, $agent_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        throw new Exception('Item not found or access denied.');
    }

    // In this specific flow, 'shipped_pending' (initial) or 'shipped' (reassignment) can be confirmed
    if (!in_array($result['status'], ['shipped_pending', 'shipped'])) {
        throw new Exception('This item does not require confirmation.');
    }

    // Begin transaction
    $pdo->beginTransaction();

    if ($type === 'auction') {
        // Update auction
        $sql = "UPDATE auctions SET shipping_status = 'shipped' WHERE id = ?";
    } else {
        // Update order
        $sql = "UPDATE orders SET status = 'shipped' WHERE id = ?";
    }

    $updateStmt = $pdo->prepare($sql);
    $updateStmt->execute([$order_id]);

    // Log to order_tracking
    $comment = "Delivery agent confirmed receipt of the item.";
    if ($type === 'order') {
        $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment, created_by, type) VALUES (?, 'shipped', ?, ?, 'order')");
        $trackStmt->execute([$order_id, $comment, $agent_id]);
    } else {
        $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment, created_by, type) VALUES (?, 'shipped', ?, ?, 'auction')");
        $trackStmt->execute([$order_id, $comment, $agent_id]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Shipment confirmed. Status updated to Shipped.'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>