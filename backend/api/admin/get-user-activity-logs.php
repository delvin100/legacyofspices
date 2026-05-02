<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$userId = $_GET['user_id'];



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

    // Complex Union Query filtered by User ID
    // We check both customer_id and farmer_id where applicable to capture all interactions
    $stmt = $pdo->prepare("
        (SELECT 
            'order' as type,
            ot.updated_at as timestamp,
            CASE 
                WHEN actor.id IS NOT NULL THEN actor.full_name
                WHEN ot.status = 'shipped' THEN COALESCE(inferred_actor.full_name, da.full_name, 'Unknown Agent')
                WHEN ot.status IN ('shipped', 'delivered') THEN COALESCE(da.full_name, 'Unknown Agent')
                WHEN ot.status IN ('confirmed', 'processing', 'shipped_pending', 'rejected') THEN COALESCE(fk.full_name, 'Unknown Farmer')
                ELSE u.full_name 
            END as user_name,
            CASE 
                WHEN actor.id IS NOT NULL THEN actor.role
                WHEN ot.status = 'shipped' THEN COALESCE(inferred_actor.role, da.role, 'delivery_agent')
                WHEN ot.status IN ('shipped', 'delivered') THEN COALESCE(da.role, 'delivery_agent')
                WHEN ot.status IN ('confirmed', 'processing', 'shipped_pending', 'rejected') THEN COALESCE(fk.role, 'farmer')
                ELSE u.role 
            END as user_role,
            CASE 
                WHEN (ot.comment LIKE '%transferred%' OR ot.comment LIKE '%reassigned%') THEN 'task transferred'
                WHEN ot.status = 'ordered' THEN 'ordered an item'
                WHEN ot.status = 'pending' THEN 'placed a new order'
                WHEN ot.status = 'confirmed' THEN 'confirmed the order'
                WHEN ot.status = 'paid' THEN 'payment confirmed'
                WHEN ot.status = 'processing' THEN 'processing the order'
                WHEN ot.status = 'shipped' THEN 'shipment confirmed'
                WHEN ot.status = 'delivered' THEN 'delivered the order'
                WHEN ot.status = 'cancelled' THEN 'cancelled the order'
                WHEN ot.status = 'rejected' THEN 'rejected the order'
                WHEN ot.status = 'shipped_pending' THEN 'assigned order task'
                ELSE CONCAT('updated order status to ', ot.status)
            END as action,
             CASE 
                WHEN ot.status = 'shipped_pending' AND ot.comment IS NOT NULL THEN REPLACE(ot.comment, 'receipt confirmation', 'confirmation')
                WHEN ot.status = 'shipped' AND ot.comment IS NOT NULL THEN REPLACE(ot.comment, 'receipt of the item', 'shipment of the item')
                WHEN ot.comment IS NOT NULL AND ot.comment != '' THEN ot.comment
                ELSE CONCAT(p.product_name, ' (', 0 + o.quantity, ' ', p.unit, ')')
            END as details,
            o.id as reference_id,
            o.total_price as amount
        FROM order_tracking ot
        JOIN orders o ON ot.order_id = o.id
        LEFT JOIN users actor ON ot.created_by = actor.id
        LEFT JOIN users inferred_actor ON inferred_actor.id = (
            SELECT created_by FROM order_tracking 
            WHERE order_id = ot.order_id 
            AND id > ot.id 
            AND (comment LIKE 'Task transferred%' OR comment LIKE 'Task reassigned%') 
            ORDER BY id ASC LIMIT 1
        )
        JOIN users u ON o.customer_id = u.id
        LEFT JOIN users fk ON o.farmer_id = fk.id
        LEFT JOIN users da ON o.delivery_agent_id = da.id
        JOIN products p ON o.product_id = p.id
        WHERE (
            (o.customer_id = ? AND (ot.status IN ('ordered', 'pending', 'paid', 'cancelled') OR ot.comment LIKE '%transferred%' OR ot.comment LIKE '%reassigned%')) OR 
            (o.farmer_id = ? AND (ot.status IN ('confirmed', 'processing', 'shipped_pending', 'rejected', 'cancelled') OR ot.comment LIKE '%transferred%' OR ot.comment LIKE '%reassigned%')) OR 
            (o.delivery_agent_id = ? AND ot.status IN ('shipped')) OR 
            ot.comment LIKE CONCAT('%HUB-', ?, '%')
        ) AND (ot.type IS NULL OR ot.type = 'order'))

        /* REASSIGNED ORDER TASK REMOVED FOR AGENTS to reduce noise */
        /*
        UNION ALL

        (SELECT 
            'order' as type,
            ot.updated_at as timestamp,
            COALESCE(u.full_name, 'Unknown Agent') as user_name,
            COALESCE(u.role, 'delivery_agent') as user_role,
            'reassigned order task' as action,
             CASE 
                WHEN ot.comment IS NOT NULL AND ot.comment != '' THEN ot.comment
                ELSE CONCAT(p.product_name, ' (', o.quantity, ' ', p.unit, ')')
            END as details,
            o.id as reference_id,
            o.total_price as amount
        FROM order_tracking ot
        JOIN orders o ON ot.order_id = o.id
        LEFT JOIN users u ON ot.created_by = u.id
        JOIN products p ON o.product_id = p.id
        WHERE o.delivery_agent_id = ? AND ot.status = 'shipped_pending' AND (ot.type IS NULL OR ot.type = 'order'))
        */

        UNION ALL

        (SELECT 
            'auction' as type,
            ot.updated_at as timestamp,
            CASE 
                WHEN actor.id IS NOT NULL THEN actor.full_name
                WHEN ot.status = 'shipped' THEN COALESCE(inferred_actor.full_name, da.full_name, 'Unknown Agent')
                WHEN ot.status IN ('shipped', 'delivered') THEN COALESCE(da.full_name, 'Unknown Agent')
                ELSE u.full_name 
            END as user_name,
            CASE 
                WHEN actor.id IS NOT NULL THEN actor.role
                WHEN ot.status = 'shipped' THEN COALESCE(inferred_actor.role, da.role, 'delivery_agent')
                WHEN ot.status IN ('shipped', 'delivered') THEN COALESCE(da.role, 'delivery_agent')
                ELSE u.role 
            END as user_role,
            CASE 
                WHEN (ot.comment LIKE '%transferred%' OR ot.comment LIKE '%reassigned%') THEN 'task transferred'
                WHEN ot.status = 'shipped_pending' THEN 'assigned auction task'
                WHEN ot.status = 'shipped' THEN 'shipment confirmed'
                ELSE CONCAT('updated auction status to ', ot.status)
            END as action,
            CASE 
                WHEN ot.status = 'shipped_pending' AND ot.comment IS NOT NULL THEN REPLACE(ot.comment, 'receipt confirmation', 'confirmation')
                WHEN ot.status = 'shipped' AND ot.comment IS NOT NULL THEN REPLACE(ot.comment, 'receipt of the item', 'shipment of the item')
                WHEN ot.comment IS NOT NULL AND ot.comment != '' THEN ot.comment
                ELSE CONCAT(a.product_name, ' (Auction ID: ', a.id, ')')
            END as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM order_tracking ot
        JOIN auctions a ON ot.order_id = a.id
        LEFT JOIN users actor ON ot.created_by = actor.id
        LEFT JOIN users inferred_actor ON inferred_actor.id = (
            SELECT created_by FROM order_tracking 
            WHERE order_id = ot.order_id 
            AND id > ot.id 
            AND (comment LIKE 'Task transferred%' OR comment LIKE 'Task reassigned%') 
            ORDER BY id ASC LIMIT 1
        )
        LEFT JOIN users u ON u.id = a.farmer_id
        LEFT JOIN users da ON a.delivery_agent_id = da.id
        WHERE (ot.type = 'auction') AND (
            (a.farmer_id = ? AND (ot.status IN ('shipped_pending', 'cancelled') OR ot.comment LIKE '%transferred%' OR ot.comment LIKE '%reassigned%')) OR 
            (a.delivery_agent_id = ? AND ot.status IN ('shipped')) OR 
            ot.comment LIKE CONCAT('%HUB-', ?, '%')
        ))

        /* REASSIGNED AUCTION TASK REMOVED FOR AGENTS */
        /*
        UNION ALL

        (SELECT 
            'auction' as type,
            ot.updated_at as timestamp,
            COALESCE(u.full_name, 'Unknown Agent') as user_name,
            COALESCE(u.role, 'delivery_agent') as user_role,
            'reassigned auction task' as action,
            CASE 
                WHEN ot.comment IS NOT NULL AND ot.comment != '' THEN ot.comment
                ELSE CONCAT(a.product_name, ' (Auction ID: ', a.id, ')')
            END as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM order_tracking ot
        JOIN auctions a ON ot.order_id = a.id
        LEFT JOIN users u ON ot.created_by = u.id
        WHERE (ot.type = 'auction') AND ot.status = 'shipped_pending' AND a.delivery_agent_id = ?)
        */

        UNION ALL

        (SELECT 
            'product' as type,
            pt.updated_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            CASE WHEN pt.action = 'listed' THEN 'listed a new product' ELSE 'updated product details' END as action,
            p.product_name as details,
            p.id as reference_id,
            p.price as amount
        FROM product_tracking pt
        JOIN products p ON pt.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        WHERE p.farmer_id = ?)

        UNION ALL

        (SELECT 
            'review' as type,
            r.created_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'wrote a review' as action,
            CONCAT('On ', p.product_name, ': ', LEFT(r.review_text, 30), '...') as details,
            r.id as reference_id,
            CAST(r.rating AS DECIMAL(10,2)) as amount
        FROM reviews r
        JOIN users u ON r.customer_id = u.id
        JOIN products p ON r.product_id = p.id
        WHERE r.customer_id = ? OR p.farmer_id = ?)

        UNION ALL

        /* Auctions: Start */
        (SELECT 
            'auction' as type,
            a.start_time as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'started an auction' as action,
            CONCAT(a.product_name) as details,
            a.id as reference_id,
            a.starting_price as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE a.farmer_id = ?)

        UNION ALL

        /* Auctions: Completed */
        (SELECT 
            'auction' as type,
            a.end_time as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'auction completed' as action,
            CONCAT(a.product_name, ' won') as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE (a.farmer_id = ? OR a.winner_id = ?) 
          AND a.status IN ('completed', 'shipped', 'paid') AND a.winner_id IS NOT NULL)

        UNION ALL

        /* Auctions: Paid */
        (SELECT 
            'auction' as type,
            a.paid_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'payment confirmed' as action,
            CONCAT(a.product_name, ' paid') as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.winner_id = u.id
        WHERE (a.winner_id = ?)
          AND a.payment_status = 'paid' AND a.paid_at IS NOT NULL)

        /*
        UNION ALL

        (SELECT 
            'auction' as type,
            a.shipped_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'shipped auction item' as action,
            CONCAT(a.product_name) as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE (a.farmer_id = ? OR a.winner_id = ?)
          AND a.shipping_status = 'shipped' AND a.shipped_at IS NOT NULL)

        UNION ALL
        
        (SELECT 
            'auction' as type,
            a.delivered_at as timestamp,
            u.full_name as user_name,
            u.role as user_role,
            'delivered auction item' as action,
            CONCAT(a.product_name) as details,
            a.id as reference_id,
            a.current_bid as amount
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE (a.farmer_id = ? OR a.winner_id = ?)
          AND a.shipping_status = 'delivered' AND a.delivered_at IS NOT NULL)
        */

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
        WHERE b.customer_id = ?)

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
        WHERE al.action_type = 'product_deleted' AND al.admin_id = ?)

        UNION ALL

        /* Delivery: Staff Assigned (for delivery agents) */
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
        WHERE al.action_type = 'staff_assigned' AND al.admin_id = ?)

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
        WHERE al.action_type = 'agent_assigned' AND al.admin_id = ?)

        UNION ALL

        /* Delivery: Completed (for delivery agents and staff) */
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
        WHERE al.action_type = 'delivery_completed' AND al.admin_id = ?)

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
        WHERE al.action_type = 'create_user' AND al.description LIKE '%delivery agent%' AND al.admin_id = ?)

        UNION ALL

        /* User: Staff Created (for delivery agents) */
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
        WHERE al.action_type = 'staff_created' AND al.admin_id = ?)

        UNION ALL

        /* User: Staff Status Updated (for delivery agents) */
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
        WHERE al.action_type = 'staff_status_updated' AND al.admin_id = ?)

        UNION ALL

        /* User: Staff Deleted (for delivery agents) */
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
        WHERE al.action_type = 'staff_deleted' AND al.admin_id = ?)

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
        WHERE fct.farmer_id = ? OR fct.actor_id = ?)

        ORDER BY timestamp DESC
        LIMIT 50
    ");

    /*
    Param mapping:
    ...
    17: Bids (cust)
    18: Product Deletion (admin)
    19: Staff Assigned (admin)
    20: Agent Assigned (admin)
    21: Delivery Completed (admin)
    22: Agent Created (admin)
    23: Staff Created (admin)
    24: Staff Status Updated (admin)
    25: Staff Deleted (admin)
    26: Certificate Tracking (farmer/admin)
    27: Certificate Tracking (farmer/admin)
    */

    $stmt->execute([
        $userId, // Orders (1)
        $userId, // Orders (2)
        $userId, // Orders (3)
        $userId, // Orders (4 - Comment wildcard)
        $userId, // Auction Tracking (1 - Farmer)
        $userId, // Auction Tracking (2 - Agent)
        $userId, // Auction Tracking (3 - Comment wildcard)
        $userId, // Product Tracking (1)
        $userId, // Reviews (1)
        $userId, // Reviews (2)
        $userId, // Auction Start (1)
        $userId, // Auction End (1)
        $userId, // Auction End (2)
        $userId, // Auction Pay (1 - Winner Only)
        $userId, // Bids (1)
        $userId, // Product Deletion (1)
        $userId, // Staff Assigned (1)
        $userId, // Agent Assigned (1)
        $userId, // Delivery Completed (1)
        $userId, // Agent Created (1)
        $userId, // Staff Created (1)
        $userId, // Staff Status Updated (1)
        $userId, // Staff Deleted (1)
        $userId, // Certificate Tracking (1)
        $userId  // Certificate Tracking (2)
    ]);

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $logs]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>