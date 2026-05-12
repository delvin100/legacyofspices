<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');
require_once '../../config/database.php';

// Enable error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../../config/session.php';
require_admin_access('delivery');

try {
    $pdo = getDBConnection();

    // Fetch orders that are ready for delivery or in progress
    // Statuses: 'shipped', 'out_for_delivery', 'delivered'
    // Note: 'out_for_delivery' might not be in ENUM yet based on schema, but 'shipped' is.
    // Schema Enum: 'pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'rejected'

    $sql = "
        SELECT 
            'order' as type,
            o.id, 
            o.status, 
            o.delivery_address,
            o.delivery_agent_id,
            c.full_name as customer_name,
            c.phone as customer_phone,
            da.full_name as agent_name,
            p.product_name,
            p.image_url as product_image,
            o.order_date as created_at
        FROM orders o
        JOIN users c ON o.customer_id = c.id
        JOIN products p ON o.product_id = p.id
        LEFT JOIN users da ON o.delivery_agent_id = da.id
        WHERE o.status IN ('shipped', 'delivered')
        
        UNION ALL
        
        SELECT 
            'auction' as type,
            a.id, 
            a.shipping_status as status, 
            a.shipping_address as delivery_address,
            a.delivery_agent_id,
            u.full_name as customer_name,
            u.phone as customer_phone,
            da2.full_name as agent_name,
            a.product_name,
            a.image_url as product_image,
            a.updated_at as created_at
        FROM auctions a
        JOIN users u ON a.winner_id = u.id
        LEFT JOIN users da2 ON a.delivery_agent_id = da2.id
        WHERE a.shipping_status IN ('shipped', 'delivered')
        
        ORDER BY created_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $items]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
