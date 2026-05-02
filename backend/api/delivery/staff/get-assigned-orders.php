<?php
/**
 * Get Staff Assigned Orders API
 * Returns orders/auctions assigned to the logged-in delivery staff member
 */

header('Content-Type: application/json');
require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery staff can access this
require_role('delivery_staff');

try {
    $pdo = getDBConnection();
    $staff_id = $_SESSION['user_id'];

    // Fetch assigned orders with customer and product details
    $ordersStmt = $pdo->prepare("
        SELECT 
            o.*, 
            p.product_name, 
            p.image_url, 
            p.unit,
            u_customer.full_name as customer_name,
            u_customer.phone as customer_phone,
            u_customer.email as customer_email,
            u_farmer.full_name as farmer_name
        FROM orders o
        JOIN products p ON o.product_id = p.id
        JOIN users u_customer ON o.customer_id = u_customer.id
        JOIN users u_farmer ON o.farmer_id = u_farmer.id
        WHERE o.delivery_staff_id = ?
        ORDER BY o.order_date DESC
    ");
    $ordersStmt->execute([$staff_id]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch assigned auctions with winner (customer) details
    $auctionsStmt = $pdo->prepare("
        SELECT 
            a.*, 
            u_winner.full_name as customer_name,
            u_winner.phone as customer_phone,
            u_winner.email as customer_email,
            u_farmer.full_name as farmer_name
        FROM auctions a
        LEFT JOIN users u_winner ON a.winner_id = u_winner.id
        JOIN users u_farmer ON a.farmer_id = u_farmer.id
        WHERE a.delivery_staff_id = ?
        ORDER BY a.created_at DESC
    ");
    $auctionsStmt->execute([$staff_id]);
    $auctions = $auctionsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'auctions' => $auctions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>