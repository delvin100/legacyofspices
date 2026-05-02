<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

// Use centralized session check
require_role('customer');

$customer_id = $_SESSION['user_id'];
$pdo = getDBConnection();

try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.status, o.total_price, o.currency_code, o.exchange_rate, o.quantity, o.order_date, p.id as product_id, p.product_name, p.image_url, p.unit, u.full_name as farmer_name, o.shipped_at, o.delivered_at, o.payment_status, 'catalog' as source,
               CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as is_rated,
               CASE WHEN ret.id IS NOT NULL THEN 1 ELSE 0 END as has_return,
               ret.status as return_status,
               ret.refund_amount as refund_amount
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.id
        LEFT JOIN users u ON o.farmer_id = u.id
        LEFT JOIN reviews r ON o.id = r.order_id AND o.customer_id = r.customer_id
        LEFT JOIN returns ret ON o.id = ret.order_id
        WHERE o.customer_id = ?
        ORDER BY order_date DESC
    ");
    $stmt->execute([$customer_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>