<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require_once '../../config/database.php';
require_once '../../config/session.php';

// Only admins can see all returns
require_role(['admin']);

try {
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            p.product_name,
            p.image_url as product_image,
            o.order_date,
            o.delivered_at as delivered_date,
            cu.full_name as customer_name,
            cu.email as customer_email,
            fa.full_name as farmer_name,
            fa.email as farmer_email,
            rv.full_name as reviewer_name
        FROM returns r
        JOIN products p ON r.product_id = p.id
        JOIN orders o ON r.order_id = o.id
        JOIN users cu ON r.customer_id = cu.id
        JOIN users fa ON r.farmer_id = fa.id
        LEFT JOIN users rv ON r.reviewed_by = rv.id
        ORDER BY r.created_at DESC
    ");
    $stmt->execute();
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $returns
    ]);

} catch (PDOException $e) {
    error_log('Get all returns error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
