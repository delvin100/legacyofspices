<?php
/**
 * Assign Delivery Agent by Farmer API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization: Farmer only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Farmer access required.']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = $data['order_id'] ?? $data['id'] ?? null;
    $agent_id = $data['agent_id'] ?? null;
    $type = $data['type'] ?? 'order'; // default to order if not specified

    if (!$order_id || !$agent_id) {
        throw new Exception('ID and Agent ID are required.');
    }

    $farmer_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    if ($type === 'auction') {
        // Verify auction belongs to farmer, is completed and paid
        $stmt = $pdo->prepare("SELECT id, status, payment_status, shipping_status FROM auctions WHERE id = ? AND farmer_id = ?");
        $stmt->execute([$order_id, $farmer_id]);
        $auction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$auction) {
            throw new Exception('Auction not found or access denied.');
        }

        if ($auction['status'] !== 'completed' || $auction['payment_status'] !== 'paid') {
            throw new Exception('Only completed and paid auctions can be shipped.');
        }

        if ($auction['shipping_status'] !== 'pending') {
            throw new Exception('This auction is already shipped or in process.');
        }

        // Verify Agent exists and is active
        $agentStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'delivery_agent' AND is_active = 1");
        $agentStmt->execute([$agent_id]);
        $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$agent) {
            throw new Exception('Invalid or inactive delivery agent selected.');
        }

        // Begin transaction
        $pdo->beginTransaction();

        // 1. Update auction with agent and shipping status
        $sql = "UPDATE auctions SET shipping_status = 'shipped_pending', delivery_agent_id = ?, shipped_at = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$agent_id, $order_id]);

        // 2. Log to order tracking
        $comment = "Auction item assigned to agent " . $agent['full_name'] . ". Awaiting agent receipt confirmation.";
        $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, type, comment) VALUES (?, 'shipped_pending', 'auction', ?)");
        $trackStmt->execute([$order_id, $comment]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Auction item assigned to agent and status updated.'
        ]);
        exit;
    }

    // Default: Order Flow
    // Verify order belongs to farmer and is in 'ordered' status
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? AND farmer_id = ?");
    $stmt->execute([$order_id, $farmer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found or access denied.');
    }

    if ($order['status'] !== 'ordered') {
        throw new Exception('Only new orders can be assigned and shipped.');
    }

    // Verify Agent exists and is active
    $agentStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'delivery_agent' AND is_active = 1");
    $agentStmt->execute([$agent_id]);
    $agent = $agentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$agent) {
        throw new Exception('Invalid or inactive delivery agent selected.');
    }

    // Begin transaction
    $pdo->beginTransaction();

    // 1. Update order with agent and status
    $sql = "UPDATE orders SET status = 'shipped_pending', delivery_agent_id = ?, shipped_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id, $order_id]);

    // 2. Log to order tracking
    $comment = "Order assigned to agent " . $agent['full_name'] . ". Awaiting agent receipt confirmation.";
    $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment) VALUES (?, 'shipped_pending', ?)");
    $trackStmt->execute([$order_id, $comment]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Order assigned to agent and status updated to shipped.'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>