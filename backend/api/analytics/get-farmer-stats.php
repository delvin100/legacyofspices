<?php
require_once '../../config/cors.php';
/**
 * Farmer Analytics API
 * Returns aggregated stats, recent activity, and sales performance data
 */

header('Content-Type: application/json');
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../services/CurrencyService.php';

// Check authorization
require_role('farmer');

$farmer_id = $_SESSION['user_id'];
$pdo = getDBConnection();

// Initializers for Aggregation
$totalRevenue = 0.0;
$weeklyData = []; // key: week_num (0-52), value: sum
$productData = []; // key: product_name, value: {revenue, count}
$customerData = []; // key: customer_id, value: {name, revenue, count}

try {
    // 1. Process Orders (Revenue + Aggregations)
    $stmtOrders = $pdo->prepare("
        SELECT o.total_price, o.currency_code, o.order_date, o.customer_id, u.full_name as customer_name, p.product_name
        FROM orders o
        JOIN users u ON o.customer_id = u.id
        JOIN products p ON o.product_id = p.id
        WHERE o.farmer_id = ? AND o.status != 'cancelled' AND o.payment_status = 'paid'
    ");
    $stmtOrders->execute([$farmer_id]);

    while ($row = $stmtOrders->fetch(PDO::FETCH_ASSOC)) {
        // Orders are stored in INR (base currency)
        $currency = CurrencyService::BASE_CURRENCY;
        // Normalize to BASE_CURRENCY for global stats
        $amountBase = (float) $row['total_price'];

        // Total Revenue
        $totalRevenue += $amountBase;

        // Weekly Stats (Simple Week Number)
        $weekNum = (int) date('W', strtotime($row['order_date']));
        if (strtotime($row['order_date']) >= strtotime('-4 weeks')) {
            if (!isset($weeklyData[$weekNum]))
                $weeklyData[$weekNum] = 0.0;
            $weeklyData[$weekNum] += $amountBase;
        }

        // Product Stats
        $pName = $row['product_name'];
        if (!isset($productData[$pName]))
            $productData[$pName] = ['product_name' => $pName, 'revenue' => 0.0, 'sales_count' => 0];
        $productData[$pName]['revenue'] += $amountBase;
        $productData[$pName]['sales_count']++;

        // Customer Stats
        $cId = $row['customer_id'];
        if (!isset($customerData[$cId]))
            $customerData[$cId] = ['full_name' => $row['customer_name'], 'total_spent' => 0.0, 'order_count' => 0];
        $customerData[$cId]['total_spent'] += $amountBase;
        $customerData[$cId]['order_count']++;
    }

    // 1c. Subtract Refunds from Revenue and weekly stats
    $stmtRefunds = $pdo->prepare("
        SELECT ret.refund_amount, ret.updated_at, p.product_name, o.customer_id
        FROM returns ret
        JOIN orders o ON ret.order_id = o.id
        JOIN products p ON o.product_id = p.id
        WHERE o.farmer_id = ? AND ret.status = 'refund_completed'
    ");
    $stmtRefunds->execute([$farmer_id]);

    while ($row = $stmtRefunds->fetch(PDO::FETCH_ASSOC)) {
        $amountBase = (float) $row['refund_amount'];
        $totalRevenue -= $amountBase;

        // Subtract from weekly stats
        $weekNum = (int) date('W', strtotime($row['updated_at']));
        if (strtotime($row['updated_at']) >= strtotime('-4 weeks')) {
            if (isset($weeklyData[$weekNum])) {
                $weeklyData[$weekNum] -= $amountBase;
            }
        }

        // Subtract from product stats
        $pName = $row['product_name'];
        if (isset($productData[$pName])) {
            $productData[$pName]['revenue'] -= $amountBase;
            // Optionally decrement sales_count if we want "successful sales" only
            // $productData[$pName]['sales_count']--; 
        }

        // Subtract from customer stats
        $cId = $row['customer_id'];
        if (isset($customerData[$cId])) {
            $customerData[$cId]['total_spent'] -= $amountBase;
            $customerData[$cId]['order_count']--;
        }
    }

    // 2. Process Auctions (Revenue + Aggregations)
    $stmtAuctions = $pdo->prepare("
        SELECT a.current_bid, a.base_currency, a.updated_at, a.winner_id, u.full_name as customer_name, a.product_name
        FROM auctions a
        JOIN users u ON a.winner_id = u.id
        WHERE a.farmer_id = ? AND a.payment_status = 'paid'
    ");
    $stmtAuctions->execute([$farmer_id]);

    while ($row = $stmtAuctions->fetch(PDO::FETCH_ASSOC)) {
        $currency = $row['base_currency'] ?: CurrencyService::BASE_CURRENCY;
        // Normalize to BASE_CURRENCY for global stats
        $amountBase = CurrencyService::convert((float) $row['current_bid'], $currency, CurrencyService::BASE_CURRENCY);

        // Total Revenue
        $totalRevenue += $amountBase;

        // Weekly Stats
        $weekNum = (int) date('W', strtotime($row['updated_at']));
        if (strtotime($row['updated_at']) >= strtotime('-4 weeks')) {
            if (!isset($weeklyData[$weekNum]))
                $weeklyData[$weekNum] = 0.0;
            $weeklyData[$weekNum] += $amountBase;
        }

        // Product Stats
        $pName = $row['product_name'];
        if (!isset($productData[$pName]))
            $productData[$pName] = ['product_name' => $pName, 'revenue' => 0.0, 'sales_count' => 0];
        $productData[$pName]['revenue'] += $amountBase;
        $productData[$pName]['sales_count']++;

        // Customer Stats
        $cId = $row['winner_id'];
        if (!isset($customerData[$cId]))
            $customerData[$cId] = ['full_name' => $row['customer_name'], 'total_spent' => 0.0, 'order_count' => 0];
        $customerData[$cId]['total_spent'] += $amountBase;
        $customerData[$cId]['order_count']++;
    }

    // 1b. Revenue Target
    $stmt = $pdo->prepare("SELECT revenue_target FROM users WHERE id = ?");
    $stmt->execute([$farmer_id]);
    $revenueTarget = $stmt->fetchColumn() ?: 500.00;

    // 2. Total Valid Orders (Excluding cancelled and refunded)
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM orders o 
             LEFT JOIN returns ret ON o.id = ret.order_id
             WHERE o.farmer_id = ? 
             AND o.status != 'cancelled' 
             AND (ret.status IS NULL OR ret.status != 'refund_completed'))
            +
            (SELECT COUNT(*) FROM auctions 
             WHERE farmer_id = ? AND winner_id IS NOT NULL AND payment_status = 'paid')
        as total_count
    ");
    $stmt->execute([$farmer_id, $farmer_id]);
    $activeOrders = $stmt->fetchColumn() ?: 0;

    // 3. Total Customers (Unified: Order Buyers + Auction Winners)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT customer_id) 
        FROM (
            SELECT customer_id FROM orders WHERE farmer_id = ?
            UNION 
            SELECT winner_id as customer_id FROM auctions WHERE farmer_id = ? AND winner_id IS NOT NULL
        ) as all_customers
    ");
    $stmt->execute([$farmer_id, $farmer_id]);
    $totalCustomers = $stmt->fetchColumn() ?: 0;

    // 4. Total Products (Replaced Avg Rating)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ?");
    $stmt->execute([$farmer_id]);
    $totalProducts = $stmt->fetchColumn() ?: 0;

    // Get Farmer's Low Stock Threshold
    $stmt = $pdo->prepare("SELECT low_stock_threshold FROM users WHERE id = ?");
    $stmt->execute([$farmer_id]);
    $lowStockThreshold = $stmt->fetchColumn() ?: 100;

    // 4b. Out of Stock Products
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ? AND quantity <= 0");
    $stmt->execute([$farmer_id]);
    $outOfStockCount = $stmt->fetchColumn() ?: 0;

    // 4c. Low Stock Products
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE farmer_id = ? AND quantity < ? AND quantity > 0");
    $stmt->execute([$farmer_id, $lowStockThreshold]);
    $lowStockCount = $stmt->fetchColumn() ?: 0;

    // 5. Recent Activity (Last 5 orders or paid auctions)
    $stmt = $pdo->prepare("
        SELECT id, status, total_price, order_date, customer_name, product_name, currency, exchange_rate, type
        FROM (
            SELECT o.id, o.status, o.total_price, o.order_date, u.full_name as customer_name, p.product_name, o.currency_code as currency, o.exchange_rate, 'order' as type
            FROM orders o
            JOIN users u ON o.customer_id = u.id
            JOIN products p ON o.product_id = p.id
            WHERE o.farmer_id = ?
            UNION ALL
            SELECT a.id, a.shipping_status as status, a.current_bid as total_price, a.updated_at as order_date, u.full_name as customer_name, a.product_name, a.base_currency as currency, 1.0 as exchange_rate, 'auction' as type
            FROM auctions a
            JOIN users u ON a.winner_id = u.id
            WHERE a.farmer_id = ? AND a.payment_status = 'paid'
        ) as combined_activity
        ORDER BY order_date DESC
        LIMIT 5
    ");
    $stmt->execute([$farmer_id, $farmer_id]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Sales Performance (Unified Weekly for last 4 weeks)
    // 6. Sales Performance (Processed in PHP from Loop Data)
    $weeklyStats = [];
    // Sort logic handled below in filling missing weeks
    // Just mapping raw data?
    // We need to match the previous structure: week_num, weekly_total (USD)
    $rawWeeklyStats = [];
    foreach ($weeklyData as $week => $total) {
        $rawWeeklyStats[] = ['week_num' => $week, 'weekly_total' => $total];
    }

    // Fill in missing weeks to ensure 4 bars
    $weeklyStats = [];
    $currentWeek = (int) date('W');
    // We want last 4 weeks: current, current-1, current-2, current-3
    // Note: This logic handles simple week subtraction. For edge cases (year crossover), 
    // using timestamps or specific dates is more robust, but this suffices for the 'Last 4 Weeks' visual.
    // A better approach is to simply iterate 0 to 3:
    for ($i = 3; $i >= 0; $i--) {
        $timestamp = strtotime("-$i weeks");
        $targetWeek = (int) date('W', $timestamp);

        // Calculate start of that week for label
        // If today is Monday, -1 week is last Monday. 
        // We want the label to represent the week start.
        // Approximate for simplicity: just use the date of (Now - i weeks) or proper week start calculation.
        // Let's use proper week start (Monday) for that week number in current year.
        $dto = new DateTime();
        $dto->setISODate((int) date('Y'), $targetWeek);
        $label = $dto->format('M j');

        $found = false;
        foreach ($rawWeeklyStats as $stat) {
            if ((int) $stat['week_num'] === $targetWeek) {
                $weeklyStats[] = [
                    'week_num' => $targetWeek,
                    'weekly_total' => (float) $stat['weekly_total'],
                    'label' => $label
                ];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $weeklyStats[] = [
                'week_num' => $targetWeek,
                'weekly_total' => 0,
                'label' => $label
            ];
        }
    }

    // 7. Low Stock Products
    $stmt = $pdo->prepare("SELECT id, product_name, quantity, unit FROM products WHERE farmer_id = ? AND quantity < 20 ORDER BY quantity ASC LIMIT 3");
    $stmt->execute([$farmer_id]);
    $lowStock = $stmt->fetchAll();

    // 8. Top Selling Products (Unified by Product Name)
    // 8. Top Selling Products (Processed in PHP)
    usort($productData, function ($a, $b) {
        return $b['revenue'] <=> $a['revenue'];
    });
    $topProducts = [];
    foreach (array_slice($productData, 0, 5) as $data) {
        $topProducts[] = [
            'product_name' => $data['product_name'],
            'revenue' => $data['revenue'],
            'sales_count' => $data['sales_count']
        ];
    }

    // 9. Order Status Distribution (Unified Orders + Paid Auctions + Refunded)
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count 
        FROM (
            SELECT 
                CASE 
                    WHEN ret.status = 'refund_completed' THEN 'refunded'
                    ELSE o.status 
                END as status
            FROM orders o
            LEFT JOIN returns ret ON o.id = ret.order_id
            WHERE o.farmer_id = ?
            
            UNION ALL
            
            SELECT shipping_status as status FROM auctions 
            WHERE farmer_id = ? AND winner_id IS NOT NULL AND payment_status = 'paid'
        ) as combined_statuses
        GROUP BY status
    ");
    $stmt->execute([$farmer_id, $farmer_id]);
    $statusDist = $stmt->fetchAll();

    // 10. Rating Distribution (Customer Satisfaction)
    $ratingDist = [];
    try {
        $stmt = $pdo->prepare("
            SELECT r.rating, COUNT(*) as count 
            FROM reviews r 
            JOIN products p ON r.product_id = p.id 
            WHERE p.farmer_id = ? 
            GROUP BY r.rating 
            ORDER BY r.rating DESC
        ");
        $stmt->execute([$farmer_id]);
        $ratingDist = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Log error but continue
        file_put_contents(__DIR__ . '/../../farmer_debug.log', date('[Y-m-d H:i:s] ') . "Warning: Reviews table issue: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    // 11. Top Customers (Loyalty)
    // 11. Top Customers (Loyalty - Unified)
    // 11. Top Customers (Processed in PHP)
    usort($customerData, function ($a, $b) {
        return $b['total_spent'] <=> $a['total_spent'];
    });
    $topCustomers = [];
    foreach (array_slice($customerData, 0, 3) as $data) {
        $topCustomers[] = [
            'full_name' => $data['full_name'],
            'total_spent' => $data['total_spent'],
            'order_count' => $data['order_count']
        ];
    }

    // 12. Process expired auctions (Trigger)
    require_once __DIR__ . '/../services/process-expired-auctions.php';
    processExpiredAuctions($pdo);

    // 13. Get Completed Auctions for this farmer
    $stmt = $pdo->prepare("
        SELECT a.id, a.product_name, a.current_bid, a.status, u.full_name as winner_name, a.base_currency
        FROM auctions a
        LEFT JOIN users u ON a.winner_id = u.id
        WHERE a.farmer_id = ? AND a.status IN ('completed', 'shipped', 'cancelled')
        ORDER BY a.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$farmer_id]);
    $auctionResults = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'stats' => [
            'revenue' => (float) $totalRevenue,
            'target' => (float) $revenueTarget,
            'active_orders' => (int) $activeOrders,
            'customers' => (int) $totalCustomers,
            'total_products' => (int) $totalProducts,
            'out_of_stock_count' => (int) $outOfStockCount,
            'low_stock_count' => (int) $lowStockCount,
            'low_stock_threshold' => (int) $lowStockThreshold
        ],
        'recent_activity' => $recentActivity,
        'weekly_performance' => $weeklyStats,
        'low_stock' => $lowStock,
        'top_products' => $topProducts,
        'status_distribution' => $statusDist,
        'rating_distribution' => $ratingDist,
        'top_customers' => $topCustomers,
        'auction_results' => $auctionResults
    ]);

} catch (PDOException $e) {
    file_put_contents(__DIR__ . '/../../farmer_debug.log', date('[Y-m-d H:i:s] ') . "Error in get-farmer-stats.php: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
