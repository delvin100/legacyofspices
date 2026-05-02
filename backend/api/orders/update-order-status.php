<?php
/**
 * Update Order Status API
 * Handles status changes: pending -> confirmed (Accept), pending -> cancelled (Reject), confirmed -> shipped (Shipped)
 */

header('Content-Type: application/json');

require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authorization
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Farmer access required.']);
    exit;
}

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$order_id = $data['order_id'] ?? null;
$new_status = $data['status'] ?? null;

if (!$order_id || !$new_status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Order ID and new status are required.']);
    exit;
}

try {
    $farmer_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // First, verify that this order belongs to the requesting farmer and check its current status
    $stmt = $pdo->prepare("SELECT status, total_price, farmer_id FROM orders WHERE id = ? AND farmer_id = ?");
    $stmt->execute([$order_id, $farmer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found or access denied.']);
        exit;
    }

    $current_status = $order['status'];
    $total_price = (float)$order['total_price'];

    // Validation for state transitions
    $allowed = false;

    // 1. Cancel/Reject logic (Reject is removed, but we keep Cancel)
    // 1. Cancel logic
    // 1. Cancel logic
    if ($new_status === 'cancelled' && ($current_status === 'ordered'))
        $allowed = true; // Cancel before shipping

    // 2. Ordered -> Shipped
    if ($new_status === 'shipped' && ($current_status === 'ordered'))
        $allowed = true;

    // 3. Shipped -> Delivered
    if ($new_status === 'delivered' && $current_status === 'shipped')
        $allowed = true;

    if (!$allowed) {
        throw new Exception("Invalid status transition from $current_status to $new_status.");
    }

    // Update status and timestamp
    if ($new_status === 'cancelled') {
        // We removed rejection_reason and rejected_at columns from schema, so we just update status
        // If we want to store reason, we need a column. But schema update removed it.
        // Let's just update status.
        $sql = "UPDATE orders SET status = 'cancelled' WHERE id = ?";
        $result = $pdo->prepare($sql)->execute([$order_id]);
    } else {
        $sql = "UPDATE orders SET status = ?";

        if ($new_status === 'shipped')
            $sql .= ", shipped_at = NOW()";
        if ($new_status === 'delivered')
            $sql .= ", delivered_at = NOW()";

        $sql .= " WHERE id = ?";
        $updateStmt = $pdo->prepare($sql);
        $result = $updateStmt->execute([$new_status, $order_id]);
    }

    if ($result) {
        // --- Update Wallet if Delivered ---
        if ($new_status === 'delivered') {
            $updateWallet = $pdo->prepare("
                INSERT INTO wallet (user_id, balance) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + ?, updated_at = NOW()
            ");
            $updateWallet->execute([$farmer_id, $total_price, $total_price]);

            $logTx = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, reference_type)
                VALUES (?, ?, 'credit', ?, ?, 'order')
            ");
            $logTx->execute([
                $farmer_id, 
                $total_price, 
                'Earnings for Order #ORD-' . str_pad($order_id, 5, '0', STR_PAD_LEFT), 
                $order_id
            ]);
        }

        // --- Log to Order Tracking ---
        $comment = "Order status updated to $new_status";
        if (isset($reason))
            $comment .= ". Reason: $reason";

        $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment, created_by) VALUES (?, ?, ?, ?)");
        $trackStmt->execute([$order_id, $new_status, $comment, $farmer_id]);
        // -----------------------------

        echo json_encode(['success' => true, 'message' => "Order status updated to $new_status."]);
    } else {
        throw new Exception("Failed to update order status.");
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>