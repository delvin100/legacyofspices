<?php
header("Content-Type: application/json");
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('farmer');
$farmer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Get all refund-completed returns for this farmer's products
    $stmt = $pdo->prepare("
        SELECT r.id, r.order_id, r.product_name, r.refund_amount, r.refund_method,
               r.status, r.created_at, r.updated_at,
               cu.full_name AS customer_name
        FROM returns r
        JOIN users cu ON r.customer_id = cu.id
        WHERE r.farmer_id = ? AND r.status = 'refund_completed'
        ORDER BY r.updated_at DESC
        LIMIT 20
    ");
    $stmt->execute([$farmer_id]);
    $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Total refunded
    $totalStmt = $pdo->prepare("
        SELECT COALESCE(SUM(refund_amount), 0) as total_refunded, COUNT(*) as count
        FROM returns WHERE farmer_id = ? AND status = 'refund_completed'
    ");
    $totalStmt->execute([$farmer_id]);
    $totals = $totalStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'refunds' => $refunds,
        'total_refunded' => (float)$totals['total_refunded'],
        'refund_count' => (int)$totals['count']
    ]);
} catch (PDOException $e) {
    error_log('Get farmer refund history error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>

