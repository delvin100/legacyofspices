<?php
/**
 * Get Staff Details and Activities API
 * Returns full details and recent activities for a specific staff member
 */

header('Content-Type: application/json');
require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery agents can view their staff details
require_role('delivery_agent');

try {
    if (!isset($_GET['staff_id'])) {
        throw new Exception('Staff ID is required');
    }

    $pdo = getDBConnection();
    $hub_id = $_SESSION['user_id'];
    $staff_id = $_GET['staff_id'];

    // 1. Fetch Staff Info
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, phone, address, is_active, created_at 
        FROM users 
        WHERE id = ? AND role = 'delivery_staff' AND hub_id = ?
    ");
    $stmt->execute([$staff_id, $hub_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$staff) {
        throw new Exception('Staff member not found or access denied');
    }

    // 2. Fetch Recent Activities (Orders)
    $ordersStmt = $pdo->prepare("
        SELECT 'order' as type, o.id, o.status, o.order_date as date, p.product_name, p.image_url
        FROM orders o
        LEFT JOIN products p ON o.product_id = p.id
        WHERE o.delivery_staff_id = ? AND o.delivery_agent_id = ?
        ORDER BY o.order_date DESC
    ");
    $ordersStmt->execute([$staff_id, $hub_id]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Recent Activities (Auctions)
    $auctionsStmt = $pdo->prepare("
        SELECT 'auction' as type, a.id, a.shipping_status as status, a.created_at as date, a.product_name, a.image_url
        FROM auctions a
        WHERE a.delivery_staff_id = ? AND a.delivery_agent_id = ?
        ORDER BY a.created_at DESC
    ");
    $auctionsStmt->execute([$staff_id, $hub_id]);
    $auctions = $auctionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine and sort activities by date
    $activities = array_merge($orders, $auctions);
    usort($activities, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    echo json_encode([
        'success' => true,
        'staff' => $staff,
        'activities' => $activities
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>