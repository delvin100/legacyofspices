<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../services/CurrencyService.php';

require_once '../services/process-expired-auctions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();
processExpiredAuctions($pdo);

try {
    $user_id = $_SESSION['user_id'] ?? null;

    // Fetch auctions that are either active or scheduled (upcoming)
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as farmer_name,
               (SELECT bid_amount FROM bids WHERE auction_id = a.id AND customer_id = ? ORDER BY bid_amount DESC LIMIT 1) as my_high_bid
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE (a.status = 'active' AND a.end_time > NOW()) 
           OR (a.status = 'scheduled' AND a.end_time > NOW())
        ORDER BY a.start_time ASC
    ");
    $stmt->execute([$user_id]);
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targetCurrency = $_SESSION['user_currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $targetSymbol = $_SESSION['user_currency_symbol'] ?? '₹';

    foreach ($auctions as &$auction) {
        $baseStartingPrice = (float) $auction['starting_price'];
        $baseCurrentBid = (float) $auction['current_bid'];
        $baseCurrency = $auction['base_currency'];

        // Skip conversion if target currency matches stored currency
        if ($targetCurrency === $baseCurrency) {
            $convStarting = $baseStartingPrice;
            $convCurrent = $baseCurrentBid;
        } else {
            $convStarting = CurrencyService::convert($baseStartingPrice, $baseCurrency, $targetCurrency);
            $convCurrent = CurrencyService::convert($baseCurrentBid, $baseCurrency, $targetCurrency);
        }

        $auction['display_starting_price'] = $convStarting;
        $auction['display_current_bid'] = $convCurrent;
        $auction['display_currency_code'] = $targetCurrency;
        $auction['display_currency_symbol'] = $targetSymbol;
        $auction['formatted_starting_price'] = CurrencyService::formatPrice($convStarting, $targetSymbol, $targetCurrency);
        $auction['formatted_current_bid'] = CurrencyService::formatPrice($convCurrent, $targetSymbol, $targetCurrency);

        // Add ISO 8601 formatted dates for global JS compatibility
        $auction['start_time_iso'] = date('c', strtotime($auction['start_time']));
        $auction['end_time_iso'] = date('c', strtotime($auction['end_time']));
    }

    echo json_encode(['success' => true, 'auctions' => $auctions]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>