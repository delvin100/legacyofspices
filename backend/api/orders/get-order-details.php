<?php
header("Content-Type: application/json");
require_once '../../config/database.php';

try {
    $order_ids = $_GET['order_ids'] ?? null;
    $order_id = $_GET['order_id'] ?? null;

    if (!$order_id && !$order_ids) {
        throw new Exception('Order ID is required');
    }

    $pdo = getDBConnection();

    // Support multiple order IDs
    if ($order_ids) {
        $ids_array = explode(',', $order_ids);
        $placeholders = implode(',', array_fill(0, count($ids_array), '?'));

        $stmt = $pdo->prepare("
            SELECT o.id, o.status, o.total_price, o.payment_status, o.currency_code, o.exchange_rate, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone
            FROM orders o
            JOIN users u ON o.customer_id = u.id
            WHERE o.id IN ($placeholders)
        ");
        $stmt->execute($ids_array);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            throw new Exception('Orders not found');
        }

        // Aggregate data
        $total_price = 0;
        $order_ids_list = [];
        $first_order = $orders[0];

        foreach ($orders as $o) {
            $total_price += $o['total_price'];
            $order_ids_list[] = $o['id'];
        }

        // Construct composite order object
        $order = [
            'id' => implode(',', $order_ids_list), // Composite ID
            'status' => $first_order['status'],
            'total_price' => $total_price,
            'payment_status' => $first_order['payment_status'], // Assuming all have same status initially
            'currency_code' => $first_order['currency_code'] ?? 'INR',
            'exchange_rate' => $first_order['exchange_rate'] ?? 1.0,
            'customer_name' => $first_order['customer_name'],
            'customer_email' => $first_order['customer_email'],
            'customer_phone' => $first_order['customer_phone']
        ];
    } else {
        // Single order fallback
        $stmt = $pdo->prepare("
            SELECT o.id, o.status, o.total_price, o.payment_status, o.currency_code, o.exchange_rate, u.full_name as customer_name, u.email as customer_email, u.phone as customer_phone
            FROM orders o
            JOIN users u ON o.customer_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found');
        }
    }

    echo json_encode(['success' => true, 'order' => $order]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>