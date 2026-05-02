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

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDBConnection();
processExpiredAuctions($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as farmer_name,
               CASE 
                 WHEN a.shipping_status = 'delivered' THEN 'delivered'
                 WHEN a.shipping_status = 'shipped' THEN 'shipped'
                 ELSE a.status 
               END as status
        FROM auctions a
        JOIN users u ON a.farmer_id = u.id
        WHERE a.winner_id = ? AND (a.status IN ('completed', 'shipped', 'delivered') OR a.shipping_status IN ('shipped', 'delivered'))
        ORDER BY a.updated_at DESC
    ");
    $stmt->execute([$user_id]);
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targetCurrency = $_SESSION['user_currency_code'] ?? 'USD';
    $targetSymbol = $_SESSION['user_currency_symbol'] ?? '$';

    foreach ($auctions as &$auction) {
        $baseCurrentBid = (float) $auction['current_bid'];
        $baseCurrency = $auction['base_currency'];

        $convCurrent = CurrencyService::convert($baseCurrentBid, $baseCurrency, $targetCurrency);

        $auction['display_current_bid'] = $convCurrent;
        $auction['display_currency_code'] = $targetCurrency;
        $auction['display_currency_symbol'] = $targetSymbol;
        $auction['formatted_current_bid'] = CurrencyService::formatPrice($convCurrent, $targetSymbol, $targetCurrency);
    }

    echo json_encode(['success' => true, 'auctions' => $auctions]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>