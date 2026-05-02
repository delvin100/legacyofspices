<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

try {
    require_once '../../config/database.php';
    $pdo = getDBConnection();

    session_start();

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $user_id = $_SESSION['user_id'];
    $data = json_decode(file_get_contents("php://input"), true);

    $order_id_input = $data['order_id'] ?? null;
    $order_ids_input = $data['order_ids'] ?? null;
    $restore_to_cart = $data['restore_to_cart'] ?? false;

    $target_ids = [];
    if ($order_ids_input && is_array($order_ids_input)) {
        $target_ids = $order_ids_input;
    } elseif ($order_id_input) {
        $target_ids = [$order_id_input];
    }

    if (empty($target_ids)) {
        echo json_encode(['success' => false, 'message' => 'Order ID is required']);
        exit;
    }

    $pdo->beginTransaction();

    foreach ($target_ids as $oid) {
        // 1. Get order details & verify ownership
        $stmt = $pdo->prepare("SELECT id, product_id, farmer_id, quantity, status, payment_status FROM orders WHERE id = ? AND customer_id = ? FOR UPDATE");
        $stmt->execute([$oid, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            continue;
        }

        // Allow cancellation only if ordered (not yet shipped)
        if (!in_array($order['status'], ['ordered'])) {
            throw new Exception("Order #$oid cannot be cancelled (Status: {$order['status']})");
        }

        // 2. Action based on payment status
        if ($order['payment_status'] === 'pending') {
            // Delete unpaid orders completely
            $deleteStmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
            $deleteStmt->execute([$oid]);
        } else {
            // Mark paid orders as cancelled
            $updateStmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
            $updateStmt->execute([$oid]);

            // --- Log to Order Tracking ---
            $trackStmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment) VALUES (?, 'cancelled', 'Order cancelled by customer')");
            $trackStmt->execute([$oid]);
            // -----------------------------
        }

        // 3. Restore Stock
        $stockStmt = $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
        $stockStmt->execute([$order['quantity'], $order['product_id']]);

        // 4. Restore to Cart (if requested)
        if ($restore_to_cart) {
            // Check if item already exists in cart, update quantity if so, else insert
            $checkCart = $pdo->prepare("SELECT id, quantity FROM cart WHERE customer_id = ? AND product_id = ?");
            $checkCart->execute([$user_id, $order['product_id']]);
            $existing = $checkCart->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $newQty = $existing['quantity'] + $order['quantity'];
                $updateCart = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
                $updateCart->execute([$newQty, $existing['id']]);
            } else {
                $insertCart = $pdo->prepare("INSERT INTO cart (customer_id, product_id, quantity) VALUES (?, ?, ?)");
                $insertCart->execute([$user_id, $order['product_id'], $order['quantity']]);
            }
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Orders cancelled and stock restored']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>