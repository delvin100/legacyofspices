<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
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

$auction_id = $_GET['auction_id'] ?? null;
$customer_id = $_SESSION['user_id'];

if (!$auction_id) {
    echo json_encode(['success' => false, 'message' => 'Auction ID is required']);
    exit;
}

$pdo = getDBConnection();

try {
    // Fetch auction details if the user is the winner
    $stmt = $pdo->prepare("
        SELECT a.id, a.product_name, a.current_bid as total_price, a.payment_status,
               u.full_name as customer_name, u.email as customer_email,
               a.base_currency as currency_code
        FROM auctions a
        JOIN users u ON u.id = ?
        WHERE a.id = ? AND a.winner_id = ? AND a.status = 'completed'
    ");
    $stmt->execute([$customer_id, $auction_id, $customer_id]);
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        echo json_encode(['success' => false, 'message' => 'Auction not found or you are not the winner.']);
        exit;
    }

    echo json_encode(['success' => true, 'order' => $auction]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>