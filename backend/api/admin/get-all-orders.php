<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

// Enable error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    // http_response_code(403);
    // echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    // exit;
}

try {
    $pdo = getDBConnection();

    // Fetch ALL orders with Customer and Farmer details (Unified with Auctions)
    $sql = "
        SELECT 
            id, order_date, quantity, total_price, status, payment_status, payment_method, 
            product_name, product_image, customer_name, farmer_name, type
        FROM (
            SELECT 
                o.id, 
                o.order_date, 
                o.quantity, 
                o.total_price as total_price, 
                o.status, 
                o.payment_status,
                o.payment_method,
                p.product_name, 
                p.image_url as product_image,
                c.full_name as customer_name,
                f.full_name as farmer_name,
                'order' as type
            FROM orders o
            JOIN products p ON o.product_id = p.id
            JOIN users c ON o.customer_id = c.id
            JOIN users f ON o.farmer_id = f.id

            UNION ALL

            SELECT 
                a.id, 
                COALESCE(a.paid_at, a.updated_at) as order_date, 
                a.quantity, 
                a.current_bid as total_price, 
                CASE 
                    WHEN a.shipping_status = 'delivered' THEN 'delivered'
                    WHEN a.shipping_status = 'shipped' THEN 'shipped'
                    ELSE a.status 
                END as status, 
                a.payment_status,
                'wallet' as payment_method,
                a.product_name, 
                a.image_url as product_image,
                c.full_name as customer_name,
                f.full_name as farmer_name,
                'auction' as type
            FROM auctions a
            JOIN users c ON a.winner_id = c.id
            JOIN users f ON a.farmer_id = f.id
            WHERE a.status IN ('completed', 'shipped', 'paid', 'delivered') AND a.winner_id IS NOT NULL
        ) as combined_transactions
        ORDER BY order_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $orders]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>