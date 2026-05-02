<?php
/**
 * Admin Stats API
 * Returns global aggregated stats for the admin dashboard
 */


// Error handling for JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); // Crucial for JSON responses

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Handle Fatal Errors


ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/database.php';

// Check if user is logged in and is an admin
// For now, if role check is not strictly implemented in session for 'admin', 
// we might skip strict check or assume a specific role. 
// However, looking at other files, we should check for role.
if (!isset($_SESSION['user_id']) || (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'admin')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getDBConnection();
    // 1. Total Users Breakdown
    $stmt = $pdo->prepare("SELECT role, COUNT(*) as count FROM users WHERE role != 'admin' GROUP BY role");
    $stmt->execute();
    $userStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['customer' => 10, 'farmer' => 5]

    $totalUsers = array_sum($userStats);
    $totalCustomers = $userStats['customer'] ?? 0;
    $totalFarmers = $userStats['farmer'] ?? 0;

    // 2. Total Global Revenue
    // Combined Orders (Normalized to Payment Currency) + Auctions (Paid)
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE status != 'cancelled' AND payment_status = 'paid') +
            (SELECT COALESCE(SUM(current_bid), 0) FROM auctions WHERE status IN ('completed', 'shipped', 'paid', 'delivered') AND payment_status = 'paid')
        as total_revenue
    ");
    $stmt->execute();
    $totalRevenue = $stmt->fetchColumn() ?: 0;

    // 3. Active Orders Count (Global)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_payment', 'processing', 'shipped')");
    $stmt->execute();
    $activeOrders = $stmt->fetchColumn() ?: 0;

    // 5. Recent System Activity (Global Orders mix)
    // 5. Recent System Activity (Global Orders & Products mix)
    // Sourcing from order_tracking to get EVERY status change, not just the current one.
    $stmt = $pdo->prepare("
        (SELECT 
            'order' as type,
            ot.updated_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            CASE 
                WHEN ot.status = 'ordered' THEN 'ordered an item'
                WHEN ot.status = 'pending' THEN 'placed a new order'
                WHEN ot.status = 'confirmed' THEN 'confirmed the order'
                WHEN ot.status = 'paid' THEN 'payment confirmed'
                WHEN ot.status = 'processing' THEN 'processing the order'
                WHEN ot.status = 'shipped' THEN 'shipped the order'
                WHEN ot.status = 'delivered' THEN 'delivered the order'
                WHEN ot.status = 'cancelled' THEN 'cancelled the order'
                WHEN ot.status = 'rejected' THEN 'rejected the order'
                ELSE CONCAT('updated order status to ', ot.status)
            END as action,
            CONCAT(p.product_name, ' (', 0 + o.quantity, ' ', p.unit, ') from farmer ', f.full_name) as details,
            o.id as reference_id,
            o.total_price as amount
        FROM order_tracking ot
        JOIN orders o ON ot.order_id = o.id
        JOIN users u ON o.customer_id = u.id
        JOIN products p ON o.product_id = p.id
        JOIN users f ON o.farmer_id = f.id
        WHERE u.email != 'admin@gmail.com' AND (ot.type IS NULL OR ot.type = 'order'))

        UNION ALL

        (SELECT 
            'product' as type,
            pt.updated_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            CASE WHEN pt.action = 'listed' THEN 'listed a new product' ELSE 'updated product details' END as action,
            CASE 
                WHEN pt.action = 'listed' THEN CONCAT(p.product_name, ' in ', pt.category, ' - ', 0 + pt.quantity, ' ', pt.unit, ' @ ', pt.price, '/', pt.unit)
                ELSE CONCAT(p.product_name, ' (', 0 + pt.quantity, ' ', pt.unit, ') @ ', pt.price, '/', pt.unit)
            END as details,
            pt.id as reference_id,
            pt.price as amount
        FROM product_tracking pt
        JOIN products p ON pt.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        WHERE u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'user' as type,
            created_at as timestamp,
            full_name as user_name,
            role as user_role,
            CONCAT('registered as a ', role) as action,
            CONCAT('Email: ', email) as details,
            id as reference_id,
            0 as amount
        FROM users
        WHERE role != 'admin' AND email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'review' as type,
            r.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'wrote a review' as action,
            CONCAT('On ', p.product_name, ': \"', LEFT(r.review_text, 50), '...\" (', r.rating, '/5 stars)') as details,
            r.id as reference_id,
            CAST(r.rating AS DECIMAL(10,2)) as amount
        FROM reviews r
        JOIN users u ON r.customer_id = u.id
        JOIN products p ON r.product_id = p.id
        WHERE u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'auction' as type,
            a.start_time as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'started an auction' as action,
            CONCAT(a.product_name, ' - Starting Price: ', a.starting_price) as details,
            a.id as reference_id,
            a.starting_price as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'auction' as type,
            a.end_time as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'auction completed' as action,
            CONCAT(a.product_name, ' - Winning Bid: ', a.current_bid) as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE a.status IN ('completed', 'shipped', 'paid', 'delivered') AND a.winner_id IS NOT NULL AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'auction' as type,
            a.paid_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'payment confirmed' as action,
            CONCAT(a.product_name, ' paid by winner') as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.winner_id = u.id
        WHERE a.payment_status = 'paid' AND a.paid_at IS NOT NULL AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'auction' as type,
            a.shipped_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'shipped auction item' as action,
            CONCAT(a.product_name, ' shipped to winner') as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE a.shipping_status = 'shipped' AND a.shipped_at IS NOT NULL AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'auction' as type,
            a.delivered_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'delivered auction item' as action,
            CONCAT(a.product_name, ' delivered to winner') as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE a.shipping_status = 'delivered' AND a.delivered_at IS NOT NULL AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'bid' as type,
            b.bid_time as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'placed a bid' as action,
            CONCAT('Bid ', b.bid_amount, ' on ', a.product_name) as details,
            b.id as reference_id,
            b.bid_amount as amount
        FROM bids b
        JOIN users u ON b.customer_id = u.id
        JOIN auctions a ON b.auction_id = a.id
        WHERE u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'product' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'deleted a product' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'product_deleted' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'delivery' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'assigned staff' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'staff_assigned' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'delivery' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'assigned agent' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'agent_assigned' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'user' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'created agent' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'create_user' AND al.description LIKE '%delivery agent%' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'user' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'created staff' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'staff_created' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'user' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'updated staff status' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'staff_status_updated' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'user' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'deleted staff' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'staff_deleted' AND u.email != 'admin@gmail.com')

        UNION ALL

        (SELECT 
            'delivery' as type,
            al.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'completed delivery' as action,
            al.description as details,
            al.target_id as reference_id,
            0 as amount
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        WHERE al.action_type = 'delivery_completed' AND u.email != 'admin@gmail.com')

        ORDER BY timestamp DESC
        LIMIT 6
    ");
    $stmt->execute();
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Global Sales Performance
    $period = isset($_GET['period']) ? $_GET['period'] : '12_months';

    if ($period === '30_days') {
        // Daily sales for last 30 days
        $stmt = $pdo->prepare("
            SELECT time_label, SUM(total_sales) as total_sales FROM (
                SELECT 
                    DATE_FORMAT(order_date, '%Y-%m-%d') as time_label,
                    total_price as total_sales
                FROM orders 
                WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status != 'cancelled' AND payment_status = 'paid'
                
                UNION ALL
                
                SELECT 
                    DATE_FORMAT(updated_at, '%Y-%m-%d') as time_label,
                    current_bid as total_sales
                FROM auctions
                WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                  AND status IN ('completed', 'shipped', 'paid') 
                  AND payment_status = 'paid'
            ) as combined_sales
            GROUP BY time_label
            ORDER BY time_label ASC
        ");
    } else {
        // Monthly sales for last 12 months
        $stmt = $pdo->prepare("
            SELECT time_label, SUM(total_sales) as total_sales FROM (
                SELECT 
                    DATE_FORMAT(order_date, '%Y-%m') as time_label,
                    total_price as total_sales
                FROM orders 
                WHERE order_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR) AND status != 'cancelled' AND payment_status = 'paid'
                
                UNION ALL
                
                SELECT 
                    DATE_FORMAT(updated_at, '%Y-%m') as time_label,
                    current_bid as total_sales
                FROM auctions
                WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) 
                  AND status IN ('completed', 'shipped', 'paid') 
                  AND payment_status = 'paid'
            ) as combined_sales
            GROUP BY time_label
            ORDER BY time_label ASC
        ");
    }
    $stmt->execute();
    $rawSalesStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process for chart: Fill in missing dates with 0
    $salesLabels = [];
    $salesData = [];
    $currentMonthRevenue = 0;
    $lastMonthRevenue = 0;
    $currentMonthKey = date('Y-m');
    $lastMonthKey = date('Y-m', strtotime('-1 month'));

    // Convert raw stats to map for easy lookup
    $salesDataMap = [];
    foreach ($rawSalesStats as $stat) {
        $salesDataMap[$stat['time_label']] = (float) $stat['total_sales'];
    }

    if ($period === '30_days') {
        // Generate last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $label = date('M j', strtotime($date));
            $salesLabels[] = $label;

            $amount = isset($salesDataMap[$date]) ? $salesDataMap[$date] : 0;
            $salesData[] = $amount;
        }
    } else {
        // Generate last 12 months using the first of the month to avoid "30th/31st" skipping issues
        $firstOfCurrentMonth = strtotime(date('Y-m-01'));
        for ($i = 11; $i >= 0; $i--) {
            // Subtract months from the 1st of the current month
            $date = date('Y-m', strtotime("-$i months", $firstOfCurrentMonth));
            $label = date('M Y', strtotime($date));
            $salesLabels[] = $label;

            $amount = isset($salesDataMap[$date]) ? $salesDataMap[$date] : 0;
            $salesData[] = $amount;

            // Capture growth metrics
            if ($date === $currentMonthKey)
                $currentMonthRevenue = $amount;
            if ($date === $lastMonthKey)
                $lastMonthRevenue = $amount;
        }
        // Fallback for growth (if using 30 days view, we might want to query separately or just hide growth)
        // For now, growth is calculated based on the loop above, which works for 12_months period. 
        // If 30_days, growth might be 0 unless we fetch monthly data separately. 
        // As an optimization for "Huge Data", we won't double query.
    }

    // Calculate Growth
    $growthPercentage = 0;
    if ($lastMonthRevenue > 0) {
        $growthPercentage = (($currentMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100;
    } elseif ($currentMonthRevenue > 0) {
        $growthPercentage = 100;
    }

    // 7. Top Selling Products Global
    $stmt = $pdo->prepare("
        SELECT p.product_name, SUM(o.total_price) as revenue
        FROM products p
        JOIN orders o ON p.id = o.product_id
        WHERE o.status != 'cancelled' AND o.payment_status = 'paid'
        GROUP BY p.id
        ORDER BY revenue DESC
        LIMIT 3
    ");
    $stmt->execute();
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Order Status Distribution Global (Combined Orders + Auctions - Breakdown)
    $stmt = $pdo->prepare("
        SELECT 
            status, 
            SUM(count) as count,
            SUM(order_count) as order_count,
            SUM(auction_count) as auction_count
        FROM (
            SELECT 
                o.status, 
                COUNT(*) as count,
                COUNT(*) as order_count,
                0 as auction_count
            FROM orders o
            WHERE o.status IN ('awaiting_payment', 'processing', 'shipped', 'delivered', 'cancelled')
            GROUP BY o.status
            
            UNION ALL
            
            SELECT 
                CASE 
                    WHEN a.shipping_status = 'delivered' THEN 'delivered'
                    WHEN a.shipping_status = 'shipped' THEN 'shipped'
                    WHEN a.status = 'paid' THEN 'processing'
                    ELSE a.status
                END as status,
                COUNT(*) as count,
                0 as order_count,
                COUNT(*) as auction_count
            FROM auctions a
            WHERE (a.status IN ('completed', 'shipped', 'paid', 'delivered') OR a.shipping_status IN ('shipped', 'delivered')) AND a.winner_id IS NOT NULL
            GROUP BY 
                CASE 
                    WHEN a.shipping_status = 'delivered' THEN 'delivered'
                    WHEN a.shipping_status = 'shipped' THEN 'shipped'
                    WHEN a.status = 'completed' AND (a.shipping_status IS NULL OR a.shipping_status = '') THEN 'completed'
                    ELSE a.status
                END
        ) combined
        GROUP BY status
    ");
    $stmt->execute();
    $statusDist = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 9. Inventory Status
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN available_stock <= 0 THEN 1 END) as out_of_stock,
            COUNT(CASE WHEN available_stock > 0 AND available_stock < 10 THEN 1 END) as low_stock,
            COUNT(*) as total_products
        FROM inventory
    ");
    $stmt->execute();
    $inventoryStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // 10. Top Farmers (Revenue Board)
    $stmt = $pdo->prepare("
        SELECT 
            u.full_name, 
            u.profile_image, 
            SUM(total_sales) as total_sales
        FROM (
            SELECT farmer_id, total_price as total_sales 
            FROM orders 
            WHERE status != 'cancelled' AND payment_status = 'paid'
            
            UNION ALL
            
            SELECT farmer_id, current_bid as total_sales 
            FROM auctions 
            WHERE status IN ('completed', 'shipped', 'paid') AND payment_status = 'paid'
        ) as all_sales
        JOIN users u ON all_sales.farmer_id = u.id
        GROUP BY u.id
        ORDER BY total_sales DESC
        LIMIT 3
    ");
    $stmt->execute();
    $topFarmers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 11. Pending Verifications (Removed as per requirements)
    $pendingVerifications = 0;

    // 12. Recent Registrations
    $stmt = $pdo->prepare("SELECT full_name, role, created_at FROM users WHERE role != 'admin' ORDER BY created_at DESC LIMIT 3");
    $stmt->execute();
    $recentRegistrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Initialize if empty to prevent errors
    $salesLabels = $salesLabels ?? [];
    $salesData = $salesData ?? [];

    ob_end_clean();
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_users' => $totalUsers,
            'total_customers' => $totalCustomers,
            'total_farmers' => $totalFarmers,
            'revenue' => (float) $totalRevenue,
            'active_orders' => (int) $activeOrders
        ],
        'recent_activity' => $recentActivity,
        'sales_chart' => [
            'labels' => $salesLabels,
            'data' => $salesData
        ],
        'top_products' => $topProducts,
        'top_farmers' => $topFarmers,
        'recent_registrations' => $recentRegistrations,
        'status_distribution' => array_map(function ($item) {
            return [
                'status' => $item['status'],
                'count' => (int) $item['count'],
                'order_count' => (int) ($item['order_count'] ?? 0),
                'auction_count' => (int) ($item['auction_count'] ?? 0)
            ];
        }, $statusDist),
        'growth' => round($growthPercentage, 1),
        'inventory' => $inventoryStats
    ]);

} catch (Exception $e) {
    ob_clean();

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>