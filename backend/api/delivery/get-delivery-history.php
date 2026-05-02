<?php
/**
 * Get Delivery History API
 * Fetches completed/cancelled orders for the delivery agent
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'delivery_agent') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

try {
    $agent_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.status,
            o.total_price,
            o.delivered_at,
            p.product_name,
            u.full_name as customer_name,
            'order' as type
        FROM orders o
        JOIN users u ON o.customer_id = u.id
        JOIN products p ON o.product_id = p.id
        WHERE o.delivery_agent_id = ? AND o.status IN ('delivered', 'cancelled')

        UNION ALL

        SELECT 
            a.id,
            a.shipping_status as status,
            a.current_bid as total_price,
            a.updated_at as delivered_at, -- Use updated_at as proxy for delivered_at if not available
            a.product_name,
            u.full_name as customer_name,
            'auction' as type
        FROM auctions a
        JOIN users u ON a.winner_id = u.id
        WHERE a.delivery_agent_id = ? AND a.shipping_status IN ('delivered', 'cancelled')

        ORDER BY delivered_at DESC
    ");

    $stmt->execute([$agent_id, $agent_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $history]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>