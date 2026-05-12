<?php
header("Content-Type: application/json");
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';
require_role('customer');

$customer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();
    $activity = [];

    // 1. Orders
    $stmt = $pdo->prepare("SELECT id, 'order' AS type, order_date AS created_at, status, total_price AS amount, 
                          (SELECT product_name FROM products WHERE id=o.product_id) as description 
                          FROM orders o WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 2. Returns
    $stmt = $pdo->prepare("SELECT id, 'return' AS type, created_at, status, refund_amount AS amount, 
                          CONCAT('Return Request: ', product_name) as description FROM returns WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 3. Wallet Transactions
    $stmt = $pdo->prepare("SELECT id, 'wallet' AS type, created_at, type as status, amount, description FROM wallet_transactions WHERE user_id = ?");
    $stmt->execute([$customer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 4. Auction Bids
    $stmt = $pdo->prepare("SELECT b.id, 'bid' AS type, b.bid_time AS created_at, 'placed' as status, b.bid_amount AS amount, 
                          CONCAT('Bid placed on: ', a.product_name) as description 
                          FROM bids b JOIN auctions a ON b.auction_id = a.id WHERE b.customer_id = ?");
    $stmt->execute([$customer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 5. Export Requests
    $stmt = $pdo->prepare("SELECT id, 'export' AS type, created_at, status, offered_price AS amount, 
                          (SELECT product_name FROM products WHERE id=e.product_id) as description 
                          FROM export_requests e WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Filter out duplicates and sort
    usort($activity, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    // Limit to latest 30 actions for dashboard/history view
    $activity = array_slice($activity, 0, 30);

    echo json_encode(['success' => true, 'activity' => $activity]);
} catch (PDOException $e) {
    error_log('Customer activity error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>


