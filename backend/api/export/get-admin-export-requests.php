<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('admin');

try {
    $pdo = getDBConnection();

    // Get all export requests with full info
    $stmt = $pdo->prepare("
        SELECT 
            er.id,
            er.quantity,
            er.unit,
            er.target_country,
            er.shipping_port,
            er.preferred_shipping_mode,
            er.business_name,
            er.business_registration_no,
            er.offered_price,
            er.currency_code,
            er.payment_terms,
            er.requires_organic_cert,
            er.requires_phytosanitary,
            er.requires_quality_test,
            er.special_notes,
            er.farmer_notes,
            er.admin_notes,
            er.status,
            er.created_at,
            er.updated_at,
            p.product_name,
            p.category,
            p.image_url,
            buyer.full_name as buyer_name,
            buyer.email as buyer_email,
            buyer.country as buyer_country,
            farmer.full_name as farmer_name,
            farmer.email as farmer_email
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        LEFT JOIN users buyer ON er.customer_id = buyer.id
        LEFT JOIN users farmer ON er.farmer_id = farmer.id
        ORDER BY er.created_at DESC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Aggregate stats
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM export_requests
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'requests' => $requests, 'stats' => $stats]);

} catch (PDOException $e) {
    error_log("Admin export requests error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
