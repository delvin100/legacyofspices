<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$auction_id = $data['auction_id'] ?? null;
$address = $data['address'] ?? null;
$phone = $data['phone'] ?? null;
$customer_id = $_SESSION['user_id'];

if (!$auction_id || !$address) {
    echo json_encode(['success' => false, 'message' => 'Auction ID and shipping address are required']);
    exit;
}

$pdo = getDBConnection();

try {
    // Verify user is the winner of this auction
    $stmt = $pdo->prepare("SELECT id, payment_status, current_bid FROM auctions WHERE id = ? AND winner_id = ? AND status = 'completed'");
    $stmt->execute([$auction_id, $customer_id]);
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        echo json_encode(['success' => false, 'message' => 'Auction not found or you are not the winner.']);
        exit;
    }

    if ($auction['payment_status'] === 'paid') {
        echo json_encode(['success' => false, 'message' => 'This auction is already paid.']);
        exit;
    }

    // Update payment status, address and phone in auctions table
    $updateStmt = $pdo->prepare("
        UPDATE auctions 
        SET payment_status = 'paid', shipping_address = ?, phone = ?, paid_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$address, $phone, $auction_id]);

    // --- Auto-Save to User Profile ---
    $updateUserStmt = $pdo->prepare("UPDATE users SET phone = ?, address = ? WHERE id = ?");
    $updateUserStmt->execute([$phone, $address, $customer_id]);
    // --------------------------------

    // Create notification for farmer
    $notifyFarmer = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type) 
        SELECT farmer_id, 'Auction Paid!', CONCAT('The auction for ', product_name, ' has been paid. You can now ship the item.'), 'system'
        FROM auctions WHERE id = ?
    ");
    $notifyFarmer->execute([$auction_id]);

    echo json_encode(['success' => true, 'message' => 'Payment processed successfully!']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>