<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('customer');
$customer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT r.*,
               o.order_date, o.delivered_at,
               p.image_url
        FROM returns r
        JOIN orders o ON r.order_id = o.id
        LEFT JOIN products p ON r.product_id = p.id
        WHERE r.customer_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$customer_id]);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'returns' => $returns]);
} catch (PDOException $e) {
    error_log('Get returns error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
