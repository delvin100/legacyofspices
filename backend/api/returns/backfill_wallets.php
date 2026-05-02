<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();

    // Find all delivered orders
    $stmt = $pdo->query("SELECT id, farmer_id, total_price, status FROM orders WHERE status = 'delivered'");
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($orders as $order) {
        $farmer_id = $order['farmer_id'];
        $amount = $order['total_price'];
        $order_id = $order['id'];

        // Check if there is already a wallet_transaction for this order delivery
        $check = $pdo->prepare("SELECT id FROM wallet_transactions WHERE user_id = ? AND reference_id = ? AND reference_type = 'order' AND type = 'credit'");
        $check->execute([$farmer_id, $order_id]);
        
        if (!$check->fetch()) {
            $pdo->beginTransaction();
            // Upsert farmer wallet
            $updateWallet = $pdo->prepare("
                INSERT INTO wallet (user_id, balance) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + ?, updated_at = NOW()
            ");
            $updateWallet->execute([$farmer_id, $amount, $amount]);

            // Create transaction log
            $logTx = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, reference_type)
                VALUES (?, ?, 'credit', ?, ?, 'order')
            ");
            $logTx->execute([
                $farmer_id, 
                $amount, 
                'Earnings for Order #ORD-' . str_pad($order_id, 5, '0', STR_PAD_LEFT), 
                $order_id
            ]);
            $pdo->commit();
            $count++;
        }
    }
    
    echo "Successfully backfilled $count delivered orders to farmer wallets.\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage();
}
?>
