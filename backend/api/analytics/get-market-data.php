<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');
require_once '../../config/database.php';

try {
    $pdo = getDBConnection();

    // 1. Card Stats
    // Total Orders (Orders Only)
    // Total Orders (Excluding Cancelled and Refunded)
    $stmtTotal = $pdo->query("
        SELECT COUNT(*) as val FROM orders o
        LEFT JOIN returns ret ON o.id = ret.order_id
        WHERE o.status != 'cancelled' AND (ret.status IS NULL OR ret.status != 'refund_completed')
    ");
    $totalOrders = $stmtTotal->fetch(PDO::FETCH_ASSOC)['val'] ?? 0;

    // Shipped Orders (Orders + Auctions)
    $stmtShipped = $pdo->query("
        SELECT (SELECT COUNT(*) FROM orders WHERE status = 'shipped') + 
               (SELECT COUNT(*) FROM auctions WHERE shipping_status = 'shipped') as val
    ");
    $shippedOrders = $stmtShipped->fetch(PDO::FETCH_ASSOC)['val'] ?? 0;

    // Delivered Orders (Orders + Auctions)
    // Auctions don't have explicit 'delivered' status column usually, but let's check if we track it.
    // Based on previous files, auctions have 'shipping_status'. If 'delivered' is a valid shipping_status:
    // Delivered Orders (Orders + Auctions - excluding refunded)
    $stmtDelivered = $pdo->query("
        SELECT (
            SELECT COUNT(*) FROM orders o
            LEFT JOIN returns ret ON o.id = ret.order_id
            WHERE o.status = 'delivered' AND (ret.status IS NULL OR ret.status != 'refund_completed')
        ) + (
            SELECT COUNT(*) FROM auctions WHERE shipping_status = 'delivered'
        ) as val
    ");
    $deliveredOrders = $stmtDelivered->fetch(PDO::FETCH_ASSOC)['val'] ?? 0;

    // 2. Chart: Orders by Status (Pie) - Unified including Refunded
    $stmtStatus = $pdo->query("
        SELECT status, COUNT(*) as count FROM (
            SELECT 
                CASE 
                    WHEN ret.status = 'refund_completed' THEN 'refunded'
                    ELSE o.status 
                END as status
            FROM orders o
            LEFT JOIN returns ret ON o.id = ret.order_id
            
            UNION ALL
            
            SELECT CASE 
                WHEN shipping_status IS NOT NULL THEN shipping_status
                WHEN status = 'completed' THEN 'processing' 
                ELSE status 
            END as status 
            FROM auctions 
            WHERE status IN ('completed', 'shipped') AND payment_status = 'paid'
        ) as combined
        GROUP BY status
    ");
    $statusData = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

    // 3. Chart: Top Ordered Products (Bar)
    // 3. Chart: Top Ordered Products (Bar) - Unified
    $stmtTop = $pdo->query("
        SELECT product_name, COUNT(*) as count FROM (
            SELECT p.product_name 
            FROM orders o
            JOIN products p ON o.product_id = p.id
            WHERE o.status != 'cancelled'
            
            UNION ALL
            
            SELECT product_name 
            FROM auctions 
            WHERE status IN ('completed', 'shipped') AND payment_status = 'paid'
        ) as combined_products
        GROUP BY product_name
        ORDER BY count DESC
        LIMIT 5
    ");
    $topProducts = $stmtTop->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'cards' => [
                'total' => $totalOrders,
                'shipped' => $shippedOrders,
                'delivered' => $deliveredOrders
            ],
            'charts' => [
                'status_distribution' => $statusData,
                'top_products' => $topProducts
            ]
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
