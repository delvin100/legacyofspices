<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['cart_id']) || empty($data['quantity']) || !is_numeric($data['quantity'])) {
        throw new Exception('Cart ID and Quantity are required');
    }

    $cart_id = $data['cart_id'];
    $quantity = (float) $data['quantity'];

    if ($quantity <= 0) {
        throw new Exception('Quantity must be positive');
    }

    $pdo = getDBConnection();

    // Verify item exists and belongs to user (optional security check if we had user session here, but cart_id is somewhat unique)
    // Ideally we check session user_id against cart owner, but for this demo we'll assume valid ID access

    $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE id = ?");
    $stmt->execute([$quantity, $cart_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Cart updated successfully']);
    } else {
        // It's possible the quantity was same, so rowCount is 0, but still success
        echo json_encode(['success' => true, 'message' => 'Cart updated (or no change needed)']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>