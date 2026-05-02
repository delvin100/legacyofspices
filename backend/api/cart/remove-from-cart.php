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
$cart_id = $data['cart_id'] ?? null;

if (!$cart_id) {
    echo json_encode(['success' => false, 'message' => 'Cart item ID required']);
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM cart WHERE id = ? AND customer_id = ?");
    $stmt->execute([$cart_id, $customer_id]);

    echo json_encode(['success' => true, 'message' => 'Item removed']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>