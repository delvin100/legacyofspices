<?php
/**
 * Get Revenue Details API
 * Returns detailed breakdown of farmer earnings and customer spending
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json; charset=UTF-8");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';

// Simple security check (session-based)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getDBConnection();

    // 1. Farmer Earnings Breakdown
    $farmerStmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.full_name, 
            u.profile_image,
            (COALESCE(sales.gross_revenue, 0) - COALESCE(refunds.total_refunded, 0)) as net_revenue
        FROM users u
        LEFT JOIN (
            SELECT farmer_id, SUM(amount) as gross_revenue FROM (
                SELECT farmer_id, total_price as amount FROM orders WHERE status != 'cancelled' AND payment_status = 'paid'
                UNION ALL
                SELECT farmer_id, current_bid as amount FROM auctions WHERE status IN ('completed', 'shipped', 'paid') AND payment_status = 'paid'
            ) as combined_sales GROUP BY farmer_id
        ) as sales ON u.id = sales.farmer_id
        LEFT JOIN (
            SELECT farmer_id, SUM(refund_amount) as total_refunded FROM returns WHERE status = 'refund_completed' GROUP BY farmer_id
        ) as refunds ON u.id = refunds.farmer_id
        WHERE u.role = 'farmer'
        HAVING net_revenue > 0
        ORDER BY net_revenue DESC
    ");
    $farmerStmt->execute();
    $farmers = $farmerStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Customer Spending Breakdown
    $customerStmt = $pdo->prepare("
        SELECT 
            u.id, 
            u.full_name, 
            u.profile_image,
            (COALESCE(spending.gross_spend, 0) - COALESCE(refunds.total_refunded, 0)) as net_spend
        FROM users u
        LEFT JOIN (
            SELECT customer_id, SUM(amount) as gross_spend FROM (
                SELECT customer_id, total_price as amount FROM orders WHERE status != 'cancelled' AND payment_status = 'paid'
                UNION ALL
                SELECT winner_id as customer_id, current_bid as amount FROM auctions WHERE status IN ('completed', 'shipped', 'paid') AND payment_status = 'paid'
            ) as combined_spend GROUP BY customer_id
        ) as spending ON u.id = spending.customer_id
        LEFT JOIN (
            SELECT customer_id, SUM(refund_amount) as total_refunded FROM returns WHERE status = 'refund_completed' GROUP BY customer_id
        ) as refunds ON u.id = refunds.customer_id
        WHERE u.role = 'customer'
        HAVING net_spend > 0
        ORDER BY net_spend DESC
    ");
    $customerStmt->execute();
    $customers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Global Total (Re-calculate for consistency)
    $totalStmt = $pdo->prepare("
        SELECT 
            (SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE status != 'cancelled' AND payment_status = 'paid') +
            (SELECT COALESCE(SUM(current_bid), 0) FROM auctions WHERE status IN ('completed', 'shipped', 'paid') AND payment_status = 'paid') -
            (SELECT COALESCE(SUM(refund_amount), 0) FROM returns WHERE status = 'refund_completed')
        as total_revenue
    ");
    $totalStmt->execute();
    $totalRevenue = $totalStmt->fetchColumn() ?: 0;

    echo json_encode([
        'success' => true,
        'total_revenue' => (float)$totalRevenue,
        'farmers' => $farmers,
        'customers' => $customers
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
