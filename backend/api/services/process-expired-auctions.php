<?php
function processExpiredAuctions($pdo)
{
    try {
        $pdo->beginTransaction();

        // 1a. Transition 'scheduled' auctions to 'active' if their start time has passed
        $activateStmt = $pdo->prepare("UPDATE auctions SET status = 'active' WHERE status = 'scheduled' AND start_time <= NOW()");
        $activateStmt->execute();

        // 1b. Find all active auctions that have already ended
        $stmt = $pdo->prepare("
            SELECT id, farmer_id, product_name, current_bid 
            FROM auctions 
            WHERE status = 'active' AND end_time <= NOW()
            FOR UPDATE
        ");
        $stmt->execute();
        $expiredAuctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [
            'processed_count' => count($expiredAuctions),
            'auctions' => []
        ];

        foreach ($expiredAuctions as $auction) {
            // 2. Find the highest bidder for this auction
            $bidStmt = $pdo->prepare("
                SELECT customer_id, bid_amount 
                FROM bids 
                WHERE auction_id = ? 
                ORDER BY bid_amount DESC, bid_time ASC 
                LIMIT 1
            ");
            $bidStmt->execute([$auction['id']]);
            $winner = $bidStmt->fetch(PDO::FETCH_ASSOC);

            if ($winner) {
                // Found a winner
                $winner_id = $winner['customer_id'];

                // 3. Update auction status and record winner
                $updateStmt = $pdo->prepare("
                    UPDATE auctions 
                    SET status = 'completed', winner_id = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$winner_id, $auction['id']]);

                // 4. Create Notification for Farmer
                $notifyFarmer = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type) 
                    VALUES (?, 'Auction Completed!', ?, 'system')
                ");
                $notifyFarmer->execute([
                    $auction['farmer_id'],
                    "Your auction for {$auction['product_name']} has ended. Winner ID: #USR-{$winner_id}. Final bid: \${$auction['current_bid']}"
                ]);

                // 5. Create Notification for Winner
                $notifyWinner = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type) 
                    VALUES (?, 'You Won the Auction!', ?, 'system')
                ");
                $notifyWinner->execute([
                    $winner_id,
                    "Congratulations! You won the auction for {$auction['product_name']} with a bid of \${$auction['current_bid']}. Please proceed to payment."
                ]);

                $results['auctions'][] = [
                    'auction_id' => $auction['id'],
                    'status' => 'won',
                    'winner_id' => $winner_id
                ];
            } else {
                // No bids were placed
                $updateStmt = $pdo->prepare("UPDATE auctions SET status = 'cancelled' WHERE id = ?");
                $updateStmt->execute([$auction['id']]);

                $results['auctions'][] = [
                    'auction_id' => $auction['id'],
                    'status' => 'no_bids'
                ];
            }
        }

        $pdo->commit();
        return ['success' => true, 'data' => $results];

    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => 'Service error: ' . $e->getMessage()];
    }
}

// Only execute and echo if this script is called directly via URL
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json");
    require_once __DIR__ . '/../../config/database.php';
    $pdo = getDBConnection();
    echo json_encode(processExpiredAuctions($pdo));
}
?>