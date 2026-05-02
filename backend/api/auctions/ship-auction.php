<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$auction_id = $data['auction_id'] ?? null;
$tracking_number = $data['tracking_number'] ?? null;
$farmer_id = $_SESSION['user_id'];

if (!$auction_id) {
    echo json_encode(['success' => false, 'message' => 'Auction ID is required']);
    exit;
}

if (!$tracking_number) {
    $tracking_number = 'N/A';
}

$pdo = getDBConnection();

try {
    // Verify farmer owns this auction and it is paid
    $stmt = $pdo->prepare("SELECT id, payment_status, shipping_status FROM auctions WHERE id = ? AND farmer_id = ? AND status = 'completed'");
    $stmt->execute([$auction_id, $farmer_id]);
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        echo json_encode(['success' => false, 'message' => 'Auction not found or unauthorized.']);
        exit;
    }

    if ($auction['payment_status'] !== 'paid') {
        echo json_encode(['success' => false, 'message' => 'Cannot ship unpaid auction.']);
        exit;
    }

    if ($auction['shipping_status'] === 'shipped') {
        echo json_encode(['success' => false, 'message' => 'Already shipped.']);
        exit;
    }

    // Update shipping status and tracking info (Keep top-level status as 'completed')
    $updateStmt = $pdo->prepare("
        UPDATE auctions 
        SET shipping_status = 'shipped', tracking_number = ?, shipped_at = NOW() 
        WHERE id = ?
    ");
    $updateStmt->execute([$tracking_number, $auction_id]);

    // Create notification for winner
    $notifyWinner = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type) 
        SELECT winner_id, 'Item Shipped!', CONCAT('Your won auction for ', product_name, ' has been shipped. Tracking: ', ?), 'system'
        FROM auctions WHERE id = ?
    ");
    $notifyWinner->execute([$tracking_number, $auction_id]);

    echo json_encode(['success' => true, 'message' => 'Shipping recorded successfully!']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>