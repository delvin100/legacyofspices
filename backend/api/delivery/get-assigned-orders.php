<?php
/**
 * Get Assigned Orders API for Delivery Agent
 * Fetches all orders assigned to the logged-in delivery agent
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

// Check authorization
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'delivery_agent') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $agent_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // Fetch assigned orders with customer info and product info
    // Fetch assigned orders AND assigned auctions
    $sql = "
        SELECT 
            o.id,
            o.status,
            o.total_price,
            o.quantity,
            p.unit,
            p.product_name,
            p.image_url as product_image,
            o.delivery_address,
            o.order_date,
            u.full_name as customer_name,
            u.phone as customer_phone,
            o.delivery_agent_id,
            o.delivery_staff_id,
            s.full_name as staff_name,
            'order' as type,
            (SELECT comment FROM order_tracking WHERE order_id = o.id AND (type = 'order' OR type IS NULL) ORDER BY id DESC LIMIT 1) as latest_comment
        FROM orders o
        JOIN users u ON o.customer_id = u.id
        JOIN products p ON o.product_id = p.id
        LEFT JOIN users s ON o.delivery_staff_id = s.id
        WHERE o.delivery_agent_id = ? AND o.status IN ('ordered', 'shipped', 'shipped_pending')

        UNION ALL

        SELECT 
            a.id,
            a.shipping_status as status,
            a.current_bid as total_price,
            a.quantity,
            a.unit,
            a.product_name,
            a.image_url as product_image,
            a.shipping_address as delivery_address,
            a.end_time as order_date,
            u.full_name as customer_name,
            u.phone as customer_phone,
            a.delivery_agent_id,
            a.delivery_staff_id,
            s.full_name as staff_name,
            'auction' as type,
            (SELECT comment FROM order_tracking WHERE order_id = a.id AND type = 'auction' ORDER BY id DESC LIMIT 1) as latest_comment
        FROM auctions a
        JOIN users u ON a.winner_id = u.id
        LEFT JOIN users s ON a.delivery_staff_id = s.id
        WHERE a.delivery_agent_id = ? AND a.shipping_status IN ('shipped', 'shipped_pending')

        ORDER BY order_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id, $agent_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $orders]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>