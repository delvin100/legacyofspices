<?php
require_once '../../config/cors.php';
/**
 * Admin API: Get All Activity Logs
 * Aggregates activities from orders, products, users, and reviews with details.
 */

ini_set('display_errors', 0);
header('Content-Type: application/json');
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../../config/database.php';



try {
    $pdo = getDBConnection();

    // Ensure tracking table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS farmer_certificate_tracking (
            id INT PRIMARY KEY AUTO_INCREMENT,
            farmer_id INT NOT NULL,
            cert_type VARCHAR(100) NOT NULL,
            action ENUM('uploaded', 'reuploaded', 'verified', 'rejected') NOT NULL,
            actor_id INT NOT NULL,
            actor_role ENUM('farmer', 'admin') NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_farmer (farmer_id)
        ) ENGINE=InnoDB
    ");

    $from = isset($_GET['from']) ? $_GET['from'] : null;
    $to = isset($_GET['to']) ? $_GET['to'] . ' 23:59:59' : null;

    $query = "
        SELECT * FROM (
            (SELECT 
                'order' as type,
                ot.updated_at as timestamp,
                CASE 
                    WHEN ot.comment LIKE 'Task transferred%' OR ot.comment LIKE 'Task reassigned%' THEN COALESCE(actor.full_name, 'Unknown Agent')
                    WHEN ot.status = 'shipped' THEN COALESCE(actor.full_name, inferred_actor.full_name, da.full_name, 'Unknown Agent')
                    ELSE u.full_name 
                END as user_name,
                CASE 
                    WHEN ot.comment LIKE 'Task transferred%' OR ot.comment LIKE 'Task reassigned%' THEN COALESCE(actor.role, 'delivery_agent')
                    WHEN ot.status = 'shipped' THEN COALESCE(actor.role, inferred_actor.role, da.role, 'delivery_agent')
                    ELSE u.role 
                END as user_role,
                CASE 
                    WHEN ot.comment LIKE 'Task transferred%' OR ot.comment LIKE 'Task reassigned%' THEN 'task transferred'
                    WHEN ot.status = 'ordered' THEN 'ordered an item'
                    WHEN ot.status = 'pending' THEN 'placed a new order'
                    WHEN ot.status = 'confirmed' THEN 'confirmed the order'
                    WHEN ot.status = 'paid' THEN 'payment confirmed'
                    WHEN ot.status = 'processing' THEN 'processing the order'
                    WHEN ot.status = 'shipped' THEN 'shipment confirmed'
                    WHEN ot.status = 'delivered' THEN 'delivered the order'
                    WHEN ot.status = 'cancelled' THEN 'cancelled the order'
                    WHEN ot.status = 'rejected' THEN 'rejected the order'
                    ELSE CONCAT('updated order status to ', ot.status)
                END as action,
                CASE 
                    WHEN ot.status = 'shipped' AND ot.comment IS NOT NULL THEN REPLACE(ot.comment, 'receipt of the item', 'shipment of the item')
                    ELSE CONCAT(p.product_name, ' (', 0 + o.quantity, ' ', p.unit, ') from farmer ', f.full_name)
                END as details,
                o.id as reference_id,
                o.total_price as amount
            FROM order_tracking ot
            JOIN orders o ON ot.order_id = o.id
            JOIN users u ON o.customer_id = u.id
            JOIN products p ON o.product_id = p.id
            JOIN users f ON o.farmer_id = f.id
            LEFT JOIN users da ON o.delivery_agent_id = da.id
            LEFT JOIN users actor ON ot.created_by = actor.id
            LEFT JOIN users inferred_actor ON inferred_actor.id = (
                SELECT created_by FROM order_tracking 
                WHERE order_id = ot.order_id 
                AND id > ot.id 
                AND (comment LIKE 'Task transferred%' OR comment LIKE 'Task reassigned%') 
                ORDER BY id ASC LIMIT 1
            )
            WHERE u.email != 'admin@gmail.com' AND (ot.type IS NULL OR ot.type = 'order') AND ot.status NOT IN ('shipped_pending', 'delivered'))

            UNION ALL

            (SELECT 
                'order' as type,
                ot.updated_at as timestamp,
                CASE 
                    WHEN ot.comment LIKE 'Task reassigned%' OR ot.comment LIKE 'Task transferred%' THEN COALESCE(actor.full_name, f.full_name)
                    ELSE f.full_name 
                END as user_name,
                CASE 
                    WHEN ot.comment LIKE 'Task reassigned%' OR ot.comment LIKE 'Task transferred%' THEN COALESCE(actor.role, f.role)
                    ELSE f.role 
                END as user_role,
                CASE 
                    WHEN ot.comment LIKE 'Task reassigned%' OR ot.comment LIKE 'Task transferred%' THEN 'task transferred'
                    ELSE 'assigned order task' 
                END as action,
                CASE 
                    WHEN ot.comment IS NOT NULL AND ot.comment != '' THEN REPLACE(ot.comment, 'receipt confirmation', 'confirmation')
                    ELSE CONCAT(p.product_name, ' (', 0 + o.quantity, ' ', p.unit, ')')
                END as details,
                o.id as reference_id,
                o.total_price as amount
            FROM order_tracking ot
            JOIN orders o ON ot.order_id = o.id
            JOIN users f ON o.farmer_id = f.id
            JOIN products p ON o.product_id = p.id
            LEFT JOIN users actor ON ot.created_by = actor.id
            WHERE (ot.type IS NULL OR ot.type = 'order') AND ot.status = 'shipped_pending')

            UNION ALL

            (SELECT 
                'auction' as type,
                ot.updated_at as timestamp,
                CASE 
                    WHEN ot.comment LIKE 'Task transferred%' OR ot.comment LIKE 'Task reassigned%' THEN COALESCE(actor.full_name, 'Unknown Agent')
                    WHEN ot.status = 'shipped' THEN COALESCE(actor.full_name, da.full_name, 'Unknown Agent')
                    ELSE u.full_name 
                END as user_name,
                CASE 
                    WHEN ot.comment LIKE 'Task transferred%' OR ot.comment LIKE 'Task reassigned%' THEN COALESCE(actor.role, 'delivery_agent')
                    WHEN ot.status = 'shipped' THEN COALESCE(actor.role, da.role, 'delivery_agent')
                    ELSE u.role 
                END as user_role,
                CASE 
                    WHEN ot.comment LIKE 'Task transferred%' OR ot.comment LIKE 'Task reassigned%' THEN 'task transferred'
                    WHEN ot.status = 'shipped_pending' THEN 'assigned auction task'
                    WHEN ot.status = 'shipped' THEN 'shipment confirmed'
                    ELSE CONCAT('updated auction status to ', ot.status)
                END as action,
                CASE 
                    WHEN ot.status = 'shipped' AND ot.comment IS NOT NULL THEN REPLACE(ot.comment, 'receipt of the item', 'shipment of the item')
                    WHEN ot.comment IS NOT NULL AND ot.comment != '' THEN ot.comment
                    ELSE CONCAT(a.product_name, ' (Auction ID: ', a.id, ')')
                END as details,
                a.id as reference_id,
                a.current_bid as amount
            FROM order_tracking ot
            JOIN auctions a ON ot.order_id = a.id
            LEFT JOIN users u ON u.id = a.farmer_id
            LEFT JOIN users da ON a.delivery_agent_id = da.id
            LEFT JOIN users actor ON ot.created_by = actor.id
            WHERE (ot.type = 'auction') AND u.email != 'admin@gmail.com' AND ot.status != 'delivered')

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
                CONCAT('On ', p.product_name, ': \"', LEFT(r.review_text, 50), '\" (', r.rating, '/5 stars)') as details,
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

            /* RECENT ACTIVITY: AUCTION DELIVERED LOG REMOVED */
            
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

            UNION ALL

            (SELECT 
                'return' as type,
                r.created_at as timestamp,
                u.full_name as user_name,
                u.role as user_role,
                'requested a return' as action,
                CONCAT(p.product_name, ' (Reason: ', r.reason, ')') as details,
                r.id as reference_id,
                r.refund_amount as amount
            FROM returns r
            JOIN users u ON r.customer_id = u.id
            JOIN products p ON r.product_id = p.id
            WHERE u.email != 'admin@gmail.com')

            UNION ALL

            (SELECT 
                'return' as type,
                r.reviewed_at as timestamp,
                rv.full_name as user_name,
                rv.role as user_role,
                CASE 
                    WHEN r.status = 'approved' THEN 'approved return'
                    WHEN r.status = 'refund_completed' THEN 'processed refund'
                    WHEN r.status = 'rejected' THEN 'rejected return'
                    ELSE CONCAT('updated return to ', r.status)
                END as action,
                CONCAT(p.product_name, ' (Order #', r.order_id, ')') as details,
                r.id as reference_id,
                r.refund_amount as amount
            FROM returns r
            JOIN users rv ON r.reviewed_by = rv.id
            JOIN products p ON r.product_id = p.id
            WHERE r.reviewed_at IS NOT NULL AND rv.email != 'admin@gmail.com')

            UNION ALL

            (SELECT 
                'certificate' as type,
                fct.created_at as timestamp,
                u.full_name as user_name,
                u.role as user_role,
                CASE 
                    WHEN fct.action = 'uploaded' THEN 'uploaded certificate'
                    WHEN fct.action = 'reuploaded' THEN 're-uploaded certificate'
                    WHEN fct.action = 'verified' THEN 'verified certificate'
                    WHEN fct.action = 'rejected' THEN 'rejected certificate'
                    ELSE 'updated certificate'
                END as action,
                fct.details as details,
                fct.id as reference_id,
                0 as amount
            FROM farmer_certificate_tracking fct
            JOIN users u ON fct.actor_id = u.id
            WHERE u.email != 'admin@gmail.com')
        ) AS combined_logs
    ";

    $query .= " 
        WHERE NOT EXISTS (
            SELECT 1 FROM activity_log_cleared_ranges 
            WHERE DATE(combined_logs.timestamp) BETWEEN activity_log_cleared_ranges.from_date AND activity_log_cleared_ranges.to_date
        )
    ";

    if ($from && $to) {
        $query .= " AND timestamp BETWEEN ? AND ?";
    }

    $query .= " ORDER BY timestamp DESC";

    if (!$from) {
        $query .= " LIMIT 100";
    }

    $stmt = $pdo->prepare($query);
    
    if ($from && $to) {
        $stmt->execute([$from, $to]);
    } else {
        $stmt->execute();
    }
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $logs]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
