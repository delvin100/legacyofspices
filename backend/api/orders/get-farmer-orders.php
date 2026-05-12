<?php
require_once '../../config/cors.php';
/**
 * Get Farmer Orders API
 * Fetches all orders received by the logged-in farmer
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

// Check authorization
require_role('farmer');

try {
    $farmer_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // Join with users (customer) and products
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.status,
            o.total_price,
            o.currency_code,
            o.exchange_rate,
            o.quantity,
            p.unit,
            o.order_date,
            o.payment_status,
            u.full_name as customer_name, 
            u.email as customer_email,
            p.product_name,
            p.image_url as product_image,
            ret.status as return_status,
            ret.refund_amount as refund_amount
        FROM orders o
        JOIN users u ON o.customer_id = u.id
        JOIN products p ON o.product_id = p.id
        LEFT JOIN returns ret ON o.id = ret.order_id
        WHERE o.farmer_id = ?
        ORDER BY o.order_date DESC
    ");

    $stmt->execute([$farmer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Debug logging (check server error logs)
    error_log("Farmer Orders API: Farmer ID $farmer_id found " . count($orders) . " orders");

    echo json_encode(['success' => true, 'data' => $orders, 'debug' => ['user_id' => $farmer_id, 'role' => $_SESSION['user_role'] ?? 'none']]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
