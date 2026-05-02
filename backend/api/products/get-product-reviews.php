<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';

$product_id = $_GET['product_id'] ?? null;

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
    exit;
}

$pdo = getDBConnection();

try {
    $stmt = $pdo->prepare("
        SELECT 
            r.rating, 
            r.review_text as comment, 
            r.created_at,
            u.full_name as customer_name
        FROM reviews r
        JOIN users u ON r.customer_id = u.id
        WHERE r.product_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$product_id]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'reviews' => $reviews]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>