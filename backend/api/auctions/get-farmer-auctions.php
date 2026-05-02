<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../services/CurrencyService.php';
require_once '../services/process-expired-auctions.php';
$pdo = getDBConnection();
processExpiredAuctions($pdo);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$farmer_id = $_SESSION['user_id'] ?? $_GET['farmer_id'] ?? 2; // Default to 2 for testing
$targetCurrency = $_SESSION['user_currency_code'] ?? 'USD';
$targetSymbol = $_SESSION['user_currency_symbol'] ?? '$';

try {
    // Fetch auctions with winner names
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as winner_name,
               CASE 
                 WHEN a.shipping_status = 'delivered' THEN 'delivered'
                 WHEN a.shipping_status = 'shipped' THEN 'shipped'
                 ELSE a.status 
               END as status
        FROM auctions a
        LEFT JOIN users u ON a.winner_id = u.id
        WHERE a.farmer_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$farmer_id]);
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 1. Active Auctions Count
    $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM auctions WHERE farmer_id = ? AND status = 'active'");
    $stmtActive->execute([$farmer_id]);
    $active_count = (int) $stmtActive->fetchColumn();

    // 2. Scheduled Auctions Count
    $stmtScheduled = $pdo->prepare("SELECT COUNT(*) FROM auctions WHERE farmer_id = ? AND status = 'scheduled'");
    $stmtScheduled->execute([$farmer_id]);
    $scheduled_count = (int) $stmtScheduled->fetchColumn();

    // 3. Completed Auctions Count ( auctions that have ended successfully with a winner )
    // We check for status 'completed'/'shipped' OR winner_id is set to be robust.
    $stmtCompleted = $pdo->prepare("SELECT COUNT(*) FROM auctions WHERE farmer_id = ? AND (status IN ('completed', 'shipped') OR winner_id IS NOT NULL)");
    $stmtCompleted->execute([$farmer_id]);
    $completed_count = (int) $stmtCompleted->fetchColumn();

    // 4. Cancelled Auctions Count
    $stmtCancelled = $pdo->prepare("SELECT COUNT(*) FROM auctions WHERE farmer_id = ? AND status = 'cancelled'");
    $stmtCancelled->execute([$farmer_id]);
    $cancelled_count = (int) $stmtCancelled->fetchColumn();

    // 5. Total Auctions display count (Completed + Cancelled as requested)
    $total_auctions_display = $completed_count + $cancelled_count;

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

    echo json_encode([
        'success' => true,
        'auctions' => $auctions,
        'active_count' => $active_count,
        'scheduled_count' => $scheduled_count,
        'completed_count' => $completed_count,
        'cancelled_count' => $cancelled_count,
        'total_count' => $total_auctions_display
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>