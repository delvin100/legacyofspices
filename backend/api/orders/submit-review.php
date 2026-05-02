<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';
$pdo = getDBConnection();

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$customer_id = $_SESSION['user_id'];
$order_id = $data['order_id'] ?? null;
$product_id = $data['product_id'] ?? null;
$rating = $data['rating'] ?? 5;
$review_text = $data['review_text'] ?? '';

if (!$order_id || !$product_id) {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

try {
    // Check if order exists and belongs to customer and is delivered
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND customer_id = ? AND status = 'delivered'");
    $stmt->execute([$order_id, $customer_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Order not found or not delivered yet.']);
        exit;
    }

    // Insert review
    $stmt = $pdo->prepare("INSERT INTO reviews (product_id, customer_id, order_id, rating, review_text) VALUES (?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text)");
    $stmt->execute([$product_id, $customer_id, $order_id, $rating, $review_text]);

    echo json_encode(['success' => true, 'message' => 'Review submitted successfully!']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>