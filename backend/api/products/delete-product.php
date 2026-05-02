<?php
/**
 * Delete Product API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$product_id = $data['product_id'] ?? null;
$farmer_id = $_SESSION['user_id'];

if (!$product_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Ensure the product belongs to the farmer
    $stmt = $pdo->prepare("SELECT product_name FROM products WHERE id = ? AND farmer_id = ?");
    $stmt->execute([$product_id, $farmer_id]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new Exception('Product not found or unauthorized');
    }

    $product_name = $product['product_name'];

    // Delete the product
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND farmer_id = ?");

    if ($stmt->execute([$product_id, $farmer_id])) {
        // Log to admin_logs so it persists
        // We use the farmer (current user) as the 'admin_id' context for this action
        // action_type = 'product_deleted'
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, 'product_deleted', 'products', ?, ?)");
        $logStmt->execute([$farmer_id, $product_id, "Deleted product: " . $product_name]);

        echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
    } else {
        throw new Exception('Failed to delete product');
    }


} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>