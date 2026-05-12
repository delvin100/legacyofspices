<?php
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
$pdo = getDBConnection();

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$customer_id = $_SESSION['user_id'];

try {
    // 1. Total spent (Orders + Won Auctions - Completed Refunds)
    $stmtSpent = $pdo->prepare("
        SELECT (
            (SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE customer_id = ? AND status != 'cancelled' AND payment_status = 'paid') +
            (SELECT COALESCE(SUM(current_bid), 0) FROM auctions WHERE winner_id = ? AND shipping_status != 'cancelled' AND payment_status = 'paid') -
            (SELECT COALESCE(SUM(refund_amount), 0) FROM returns WHERE customer_id = ? AND status = 'refund_completed')
        ) as total
    ");
    $stmtSpent->execute([$customer_id, $customer_id, $customer_id]);
    $totalSpent = $stmtSpent->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // 2. Active Shipments count (Orders 'shipped' + Auctions 'shipped')
    $stmtActive = $pdo->prepare("
        SELECT (
            SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status = 'shipped'
        ) + (
            SELECT COUNT(*) FROM auctions WHERE winner_id = ? AND shipping_status = 'shipped'
        ) as count
    ");
    $stmtActive->execute([$customer_id, $customer_id]);
    $activeShipments = $stmtActive->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

    // 3. Active Orders List (Catalog Only)
    $stmtActiveList = $pdo->prepare("
        SELECT o.id, o.status, o.total_price, o.currency_code, o.exchange_rate, o.order_date, o.quantity, p.product_name, p.image_url, 'catalog' as source
        FROM orders o 
        JOIN products p ON o.product_id = p.id 
        WHERE o.customer_id = ? AND o.status NOT IN ('delivered', 'cancelled')
        ORDER BY order_date DESC 
        LIMIT 2
    ");
    $stmtActiveList->execute([$customer_id]);
    $activeOrdersList = $stmtActiveList->fetchAll(PDO::FETCH_ASSOC);

    // 4. Transaction History (Catalog Only)
    $stmtHistory = $pdo->prepare("
        SELECT o.id, o.status, o.total_price, o.currency_code, o.exchange_rate, o.order_date, o.quantity, p.product_name, 'catalog' as source
        FROM orders o 
        JOIN products p ON o.product_id = p.id 
        WHERE o.customer_id = ?
        ORDER BY order_date DESC 
        LIMIT 5
    ");
    $stmtHistory->execute([$customer_id]);
    $transactionHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

    // 5. User data
    $stmtUser = $pdo->prepare("SELECT full_name, profile_image FROM users WHERE id = ?");
    $stmtUser->execute([$customer_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_spent' => (float) $totalSpent,
            'active_orders' => (int) $activeShipments, // Now strictly 'shipped' count for the card
            'spice_points' => (int) ($totalSpent * 0.5), // 5 points per $10 = 0.5 pts per $1
            'full_name' => $user['full_name'],
            'profile_image' => $user['profile_image'] ?? null
        ],
        'recent_orders' => $activeOrdersList,
        'transaction_history' => $transactionHistory
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
