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
    // strict check for production
}

if (!isset($_GET['id']) || !isset($_GET['role'])) {
    echo json_encode(['success' => false, 'message' => 'Missing ID or Role']);
    exit;
}

$userId = $_GET['id'];
$role = $_GET['role'];

try {
    $pdo = getDBConnection();
    $data = [];

    if ($role === 'farmer') {
        // 1. Total Earnings (Unified)
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT IFNULL(SUM(total_price), 0) FROM orders WHERE farmer_id = ? AND payment_status = 'paid' AND status != 'cancelled') +
                (SELECT IFNULL(SUM(current_bid), 0) FROM auctions WHERE farmer_id = ? AND payment_status = 'paid')
        ");
        $stmt->execute([$userId, $userId]);
        $data['total_earnings'] = (float) ($stmt->fetchColumn() ?: 0);

        // 2. Total Products Listed
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ?");
        $stmt->execute([$userId]);
        $data['total_products'] = (int) ($stmt->fetchColumn() ?: 0);

        // 3. Top Products (Unified)
        $stmt = $pdo->prepare("
            SELECT product_name, SUM(revenue) as revenue
            FROM (
                SELECT p.product_name, SUM(o.total_price) as revenue 
                FROM products p
                JOIN orders o ON p.id = o.product_id
                WHERE p.farmer_id = ? AND o.payment_status = 'paid' AND o.status != 'cancelled'
                GROUP BY p.id
                UNION ALL
                SELECT product_name, SUM(current_bid) as revenue
                FROM auctions
                WHERE farmer_id = ? AND payment_status = 'paid'
                GROUP BY product_name
            ) as combined
            GROUP BY product_name
            ORDER BY revenue DESC
            LIMIT 5
        ");
        $stmt->execute([$userId, $userId]);
        $data['top_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Recent Sales Activity (Unified)
        $stmt = $pdo->prepare("
            SELECT id, product_name, customer_name, total_price, order_date, status, type
            FROM (
                SELECT o.id, p.product_name, u.full_name as customer_name, o.total_price as total_price, o.order_date, o.status, 'order' as type
                FROM orders o
                JOIN products p ON o.product_id = p.id
                JOIN users u ON o.customer_id = u.id
                WHERE o.farmer_id = ? AND o.status != 'cancelled'
                
                UNION ALL
                
                SELECT a.id, a.product_name, u.full_name as customer_name, a.current_bid as total_price, a.updated_at as order_date, a.status, 'auction' as type
                FROM auctions a
                LEFT JOIN users u ON a.winner_id = u.id
                WHERE a.farmer_id = ? AND a.status IN ('completed', 'shipped', 'active')
            ) as combined_history
            ORDER BY order_date DESC
            LIMIT 10
        ");
        $stmt->execute([$userId, $userId]);
        $data['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($role === 'customer') {
        // 1. Total Spending (Unified)
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT IFNULL(SUM(total_price), 0) FROM orders WHERE customer_id = ? AND payment_status = 'paid' AND status != 'cancelled') +
                (SELECT IFNULL(SUM(current_bid), 0) FROM auctions WHERE winner_id = ? AND payment_status = 'paid')
        ");
        $stmt->execute([$userId, $userId]);
        $data['total_spending'] = (float) ($stmt->fetchColumn() ?: 0);

        // 2. Total Orders
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status != 'cancelled'");
        $stmt->execute([$userId]);
        $data['total_orders'] = (int) ($stmt->fetchColumn() ?: 0);

        // 3. Recent Purchases (Unified)
        $stmt = $pdo->prepare("
            SELECT id, product_name, farmer_name, total_price, order_date, status, type
            FROM (
                SELECT o.id, p.product_name, u.full_name as farmer_name, o.total_price as total_price, o.order_date, o.status, 'order' as type
                FROM orders o
                JOIN products p ON o.product_id = p.id
                JOIN users u ON o.farmer_id = u.id
                WHERE o.customer_id = ? AND o.status != 'cancelled'
                
                UNION ALL
                
                SELECT a.id, a.product_name, u.full_name as farmer_name, a.current_bid as total_price, a.updated_at as order_date, a.status, 'auction' as type
                FROM auctions a
                JOIN users u ON a.farmer_id = u.id
                WHERE a.winner_id = ? AND a.status IN ('completed', 'shipped')
            ) as combined_history
            ORDER BY order_date DESC
            LIMIT 10
        ");
        $stmt->execute([$userId, $userId]);
        $data['history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'delivery_agent' || $role === 'delivery_staff') {
        // 1. Total Deliveries Assigned / Completed
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM orders WHERE delivery_agent_id = ? AND status = 'delivered') +
                (SELECT COUNT(*) FROM auctions WHERE delivery_agent_id = ? AND shipping_status = 'delivered')
        ");
        $stmt->execute([$userId, $userId]);
        $data['total_deliveries'] = (int) ($stmt->fetchColumn() ?: 0);

        // 2. Active Tasks
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM orders WHERE delivery_agent_id = ? AND status IN ('ordered', 'shipped')) +
                (SELECT COUNT(*) FROM auctions WHERE delivery_agent_id = ? AND shipping_status = 'shipped')
        ");
        $stmt->execute([$userId, $userId]);
        $data['active_tasks'] = (int) ($stmt->fetchColumn() ?: 0);

        // Recent History for agents
        $data['history'] = [];
    } else {
        // Default empty state for other roles
        $data = [
            'total_earnings' => 0,
            'total_products' => 0,
            'total_spending' => 0,
            'total_orders' => 0,
            'history' => []
        ];
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>