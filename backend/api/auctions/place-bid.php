<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';
$pdo = getDBConnection();


require_once '../services/CurrencyService.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a customer to bid.']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['auction_id']) || !isset($data['bid_amount'])) {
    echo json_encode(['success' => false, 'message' => 'Missing bid parameters.']);
    exit;
}

$auction_id = (int) $data['auction_id'];
$customer_id = $_SESSION['user_id'];
$customer_bid_amount = (float) $data['bid_amount'];

// Fetch customer's currency details from DB for accuracy
$customerStmt = $pdo->prepare("SELECT currency_code FROM users WHERE id = ?");
$customerStmt->execute([$customer_id]);
$customerCurrencyCode = $customerStmt->fetchColumn() ?: 'USD';

$customer_currency = $customerCurrencyCode;

try {
    $pdo->beginTransaction();

    // 1. Fetch current auction state with a lock to prevent race conditions
    $stmt = $pdo->prepare("SELECT * FROM auctions WHERE id = ? FOR UPDATE");
    $stmt->execute([$auction_id]);
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        throw new Exception("Auction not found.");
    }

    $currentTime = time();
    $startTime = strtotime($auction['start_time']);
    $endTime = strtotime($auction['end_time']);

    if ($auction['status'] === 'scheduled') {
        if ($currentTime >= $startTime) {
            // Auto-activate if time has arrived
            $auction['status'] = 'active';
            $pdo->prepare("UPDATE auctions SET status = 'active' WHERE id = ?")->execute([$auction_id]);
        } else {
            throw new Exception("This auction has not started yet. You can only bid after " . date('Y-m-d H:i:s', $startTime));
        }
    }

    if ($auction['status'] !== 'active' || $currentTime > $endTime) {
        throw new Exception("This auction is no longer active.");
    }

    // Convert customer's bid to auction's base currency
    $base_bid_amount = CurrencyService::convert($customer_bid_amount, $customer_currency, $auction['base_currency']);

    if ($base_bid_amount <= $auction['current_bid']) {
        throw new Exception("Your bid must be higher than the current bid (" . $auction['base_currency'] . " " . number_format($auction['current_bid'], 2) . ").");
    }

    // 2. Insert the bid (Store in auction's base currency for consistency?)
    // Actually, we should store exactly what the user bid in their currency or convert it.
    // Standardizing on base currency is safer for math.
    $bidStmt = $pdo->prepare("INSERT INTO bids (auction_id, customer_id, bid_amount) VALUES (?, ?, ?)");
    $bidStmt->execute([$auction_id, $customer_id, $base_bid_amount]);

    // 3. Update the auction's current bid
    $updateStmt = $pdo->prepare("UPDATE auctions SET current_bid = ? WHERE id = ?");
    $updateStmt->execute([$base_bid_amount, $auction_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Bid placed successfully!']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>