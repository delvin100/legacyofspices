<?php
/**
 * Advanced Admin Analysis API
 * Provides meaningful metrics for platform growth and performance
 */

header('Content-Type: application/json');
ob_start();
ini_set('display_errors', 0);
require_once '../../config/session.php';
require_once '../../config/database.php';

// Strict Admin Check
require_role('admin');



try {
    $pdo = getDBConnection();

    // 1. User Growth (Last 30 Days)
    // Counts signups per day for farmers and customers
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) as date, role, COUNT(*) as count 
        FROM users 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND role IN ('farmer', 'customer')
        GROUP BY DATE(created_at), role
        ORDER BY date ASC
    ");
    $stmt->execute();
    $userGrowth = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Farmer Leaderboard (Top 5 by Revenue)
    $stmt = $pdo->prepare("
        SELECT 
            u.full_name as farmer_name, 
            SUM(total_revenue) as total_revenue
        FROM (
            SELECT farmer_id, total_price as total_revenue
            FROM orders
            WHERE payment_status = 'paid' AND status != 'cancelled'
            
            UNION ALL
            
            SELECT farmer_id, current_bid as total_revenue
            FROM auctions
            WHERE status IN ('completed', 'shipped', 'paid') AND payment_status = 'paid'
        ) as all_sales
        JOIN users u ON all_sales.farmer_id = u.id
        GROUP BY u.id
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    $stmt->execute();
    $farmerLeaderboard = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Revenue Trend (Last 30 Days)
    $stmt = $pdo->prepare("
        SELECT date, SUM(daily_revenue) as daily_revenue FROM (
            SELECT DATE(order_date) as date, total_price as daily_revenue
            FROM orders
            WHERE payment_status = 'paid' AND status != 'cancelled'
            AND order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            
            UNION ALL
            
            SELECT DATE(updated_at) as date, current_bid as daily_revenue
            FROM auctions
            WHERE status IN ('completed', 'shipped', 'paid') AND payment_status = 'paid'
            AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ) as combined_revenue
        GROUP BY date
        ORDER BY date ASC
    ");
    $stmt->execute();
    $revenueTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Order Status Distribution (Orders + Auctions)
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count FROM (
            SELECT status FROM orders
            UNION ALL
            SELECT 
                CASE 
                    WHEN shipping_status = 'delivered' THEN 'delivered'
                    WHEN shipping_status = 'shipped' THEN 'shipped'
                    ELSE status 
                END as status
            FROM auctions 
            WHERE (status IN ('completed', 'shipped', 'paid', 'delivered') OR shipping_status IN ('shipped', 'delivered')) AND winner_id IS NOT NULL
            ) as combined_statuses
        WHERE status NOT IN ('requested', 'rejected')
        GROUP BY status
        ORDER BY count DESC
    ");
    $stmt->execute();
    $orderStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'data' => [
            'user_growth' => $userGrowth,
            'farmer_leaderboard' => $farmerLeaderboard,
            'revenue_trend' => $revenueTrend,
            'order_status' => $orderStatus
        ]
    ]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>