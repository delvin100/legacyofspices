<?php
require_once '../../config/cors.php';
/**
 * Update Delivery Status API
 * Changes the status of an order and logs tracking info
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['delivery_agent', 'delivery_staff'])) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id = $data['order_id'] ?? null;
    $new_status = $data['status'] ?? null;
    $comment = $data['comment'] ?? 'Status updated';
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['user_role'];

    $type = $data['type'] ?? 'order';

    if (!$order_id || !$new_status) {
        throw new Exception('ID and status are required');
    }

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    if ($type === 'auction') {
        // --- Auction Logic ---
        if ($user_role === 'delivery_staff') {
            $stmt = $pdo->prepare("SELECT id FROM auctions WHERE id = ? AND delivery_staff_id = ?");
            $stmt->execute([$order_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id FROM auctions WHERE id = ? AND delivery_agent_id = ?");
            $stmt->execute([$order_id, $user_id]);
        }
        if (!$stmt->fetch())
            throw new Exception('Unauthorized to update this auction');

        // Update auctions table
        if ($new_status === 'delivered') {
            $otp = $data['otp'] ?? null;
            if (!$otp) {
                throw new Exception('OTP is required for delivery confirmation');
            }

            $otpStmt = $pdo->prepare("SELECT delivery_otp, delivery_otp_sent_at FROM auctions WHERE id = ?");
            $otpStmt->execute([$order_id]);
            $otpData = $otpStmt->fetch();

            if (!$otpData || $otpData['delivery_otp'] !== $otp) {
                throw new Exception('Invalid OTP. Please try again.');
            }

            // Check expiry (10 minutes)
            $sentAt = strtotime($otpData['delivery_otp_sent_at']);
            if (time() - $sentAt > 600) {
                throw new Exception('OTP has expired (10 min limit). Please request a new one.');
            }

            $updateSql = "UPDATE auctions SET shipping_status = ?, delivery_otp = NULL, delivered_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP";
        } else {
            $updateSql = "UPDATE auctions SET shipping_status = ?, updated_at = CURRENT_TIMESTAMP";
        }

        $stmt = $pdo->prepare($updateSql . " WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);

        // Add to order_tracking for auctions too
        $stmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment, type, created_by) VALUES (?, ?, ?, 'auction', ?)");
        $stmt->execute([$order_id, $new_status, $comment, $user_id]);

    } else {
        // --- Standard Order Logic ---
        if ($user_role === 'delivery_staff') {
            $stmt = $pdo->prepare("SELECT id, farmer_id, total_price FROM orders WHERE id = ? AND delivery_staff_id = ?");
            $stmt->execute([$order_id, $user_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, farmer_id, total_price FROM orders WHERE id = ? AND delivery_agent_id = ?");
            $stmt->execute([$order_id, $user_id]);
        }
        
        $orderData = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$orderData)
            throw new Exception('Unauthorized to update this order');

        $updateSql = "UPDATE orders SET status = ?, updated_at = CURRENT_TIMESTAMP";
        if ($new_status === 'delivered') {
            $otp = $data['otp'] ?? null;
            if (!$otp) {
                throw new Exception('OTP is required for delivery confirmation');
            }

            $otpStmt = $pdo->prepare("SELECT delivery_otp, delivery_otp_sent_at FROM orders WHERE id = ?");
            $otpStmt->execute([$order_id]);
            $otpData = $otpStmt->fetch();

            if (!$otpData || $otpData['delivery_otp'] !== $otp) {
                throw new Exception('Invalid OTP. Please try again.');
            }

            // Check expiry (10 minutes)
            $sentAt = strtotime($otpData['delivery_otp_sent_at']);
            if (time() - $sentAt > 600) {
                throw new Exception('OTP has expired (10 min limit). Please request a new one.');
            }

            $updateSql = "UPDATE orders SET status = ?, delivered_at = CURRENT_TIMESTAMP, delivery_otp = NULL, updated_at = CURRENT_TIMESTAMP";
        } elseif ($new_status === 'shipped') {
            $updateSql = "UPDATE orders SET status = ?, shipped_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP";
        }

        $stmt = $pdo->prepare($updateSql . " WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);

        // Process farmer wallet earnings on successful delivery
        if ($new_status === 'delivered') {
            $farmer_id = $orderData['farmer_id'];
            $total_price = (float)$orderData['total_price'];

            // Update farmer wallet
            $updateWallet = $pdo->prepare("
                INSERT INTO wallet (user_id, balance) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + ?, updated_at = NOW()
            ");
            $updateWallet->execute([$farmer_id, $total_price, $total_price]);

            // Add wallet transaction log
            $logTx = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, reference_type)
                VALUES (?, ?, 'credit', ?, ?, 'order')
            ");
            $logTx->execute([
                $farmer_id, 
                $total_price, 
                'Earnings for Order #ORD-' . str_pad($order_id, 5, '0', STR_PAD_LEFT), 
                $order_id
            ]);
        }

        // Add to order_tracking
        $stmt = $pdo->prepare("INSERT INTO order_tracking (order_id, status, comment, created_by, type) VALUES (?, ?, ?, ?, 'order')");
        $stmt->execute([$order_id, $new_status, $comment, $user_id]);
    }

    $pdo->commit();

    // Log if delivered
    if ($new_status === 'delivered') {
        try {
            // Re-connect or use same pdo? Transaction committed, so safe.
            $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, 'delivery_completed', ?, ?, ?)");
            $formattedId = ($type === 'auction') ? 'AUC-' . str_pad($order_id, 5, '0', STR_PAD_LEFT) : 'ORD-' . str_pad($order_id, 5, '0', STR_PAD_LEFT);

            // Get user name (staff or agent)
            $uStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $uStmt->execute([$user_id]);
            $userName = $uStmt->fetchColumn();

            $description = "{$userName} marked {$formattedId} as delivered";
            $logStmt->execute([$user_id, $type . 's', $order_id, $description]); // target_table: orders or auctions
        } catch (Exception $e) {
            // Ignore logging errors to not fail the main action
        }
    }

    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
