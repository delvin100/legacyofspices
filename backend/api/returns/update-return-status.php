<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role(['admin', 'farmer']);
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

try {
    $pdo = getDBConnection();
    $input = json_decode(file_get_contents('php://input'), true);

    $return_id  = (int)($input['return_id'] ?? 0);
    $new_status = $input['status'] ?? '';
    $admin_notes = trim($input['admin_notes'] ?? '');

    if (!$return_id || !$new_status) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Return ID and status are required.']);
        exit;
    }

    // Farmers can only set these statuses (no refund control)
    $farmer_allowed = ['under_review', 'approved', 'rejected'];
    $admin_allowed  = ['under_review', 'approved', 'rejected', 'refund_processing', 'refund_completed'];

    $allowed = ($user_role === 'farmer') ? $farmer_allowed : $admin_allowed;
    if (!in_array($new_status, $allowed)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status for your role.']);
        exit;
    }

    // Fetch return record
    $ret = $pdo->prepare("SELECT * FROM returns WHERE id = ?");
    $ret->execute([$return_id]);
    $return = $ret->fetch(PDO::FETCH_ASSOC);

    if (!$return) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Return request not found.']);
        exit;
    }

    // Farmers can only update returns for their own products
    if ($user_role === 'farmer' && (int)$return['farmer_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You can only manage returns for your own products.']);
        exit;
    }

    // Auto-refund: when farmer approves, automatically escalate to refund_completed
    $final_status = $new_status;
    if ($new_status === 'approved' && $user_role === 'farmer') {
        $final_status = 'refund_completed';
    }

    // Update return status
    $stmt = $pdo->prepare("
        UPDATE returns SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$final_status, $admin_notes, $user_id, $return_id]);

    // Process refund logic when final status is refund_completed
    if ($final_status === 'refund_completed') {
        $amount = (float)$return['refund_amount'];
        $customer_id = (int)$return['customer_id'];
        $farmer_id = (int)$return['farmer_id'];

        if ($return['refund_method'] === 'wallet') {
            // Upsert CUSTOMER wallet
            $pdo->prepare("
                INSERT INTO wallet (user_id, balance) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance), updated_at = NOW()
            ")->execute([$customer_id, $amount]);

            // Log CUSTOMER wallet transaction
            $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, reference_type)
                VALUES (?, ?, 'credit', ?, ?, 'return')
            ")->execute([
                $customer_id,
                $amount,
                'Refund for Return #RET-' . str_pad($return_id, 5, '0', STR_PAD_LEFT),
                $return_id
            ]);

            // Notify customer — refund credited
            try {
                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type)
                    VALUES (?, 'Refund Credited to Wallet', ?, 'refund')
                ")->execute([
                    $customer_id,
                    'Your return #RET-' . str_pad($return_id, 5, '0', STR_PAD_LEFT) . ' has been approved and ₹' . number_format($amount, 2) . ' has been credited to your Caravan Wallet.'
                ]);
            } catch (Exception $e) {}
        } else {
            // Notify customer — refund processed to original payment method
            try {
                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type)
                    VALUES (?, 'Refund Processed', ?, 'refund')
                ")->execute([
                    $customer_id,
                    'Your return #RET-' . str_pad($return_id, 5, '0', STR_PAD_LEFT) . ' has been approved and a ₹' . number_format($amount, 2) . ' refund has been initiated to your original payment method.'
                ]);
            } catch (Exception $e) {}
        }

        // Always debit FARMER wallet for the refund amount
        $pdo->prepare("
            INSERT INTO wallet (user_id, balance) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE balance = balance - ?, updated_at = NOW()
        ")->execute([$farmer_id, -$amount, $amount]);

        // Log FARMER wallet transaction
        $pdo->prepare("
            INSERT INTO wallet_transactions (user_id, amount, type, description, reference_id, reference_type)
            VALUES (?, ?, 'debit', ?, ?, 'return')
        ")->execute([
            $farmer_id,
            $amount,
            'Refund Deduction for Return #RET-' . str_pad($return_id, 5, '0', STR_PAD_LEFT),
            $return_id
        ]);

        // Restock the product: Increase stock_quantity back in products table
        $pdo->prepare("
            UPDATE products p
            JOIN orders o ON p.id = o.product_id
            JOIN returns ret ON o.id = ret.order_id
            SET p.quantity = p.quantity + o.quantity
            WHERE ret.id = ?
        ")->execute([$return_id]);
    }

    // Notify customer of rejection
    if ($final_status === 'rejected') {
        $msg = 'Your return request #RET-' . str_pad($return_id, 5, '0', STR_PAD_LEFT) . ' was rejected.' . ($admin_notes ? ' Reason: ' . $admin_notes : '');
        try {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'return')")
                ->execute([$return['customer_id'], 'Return Request Rejected', $msg]);
        } catch (Exception $e) {}
    }

    // Build response message
    $response_msg = $final_status === 'refund_completed' && $new_status === 'approved'
        ? 'Return approved & ₹' . number_format((float)$return['refund_amount'], 2) . ' refunded to customer wallet!'
        : 'Return status updated to ' . str_replace('_', ' ', ucfirst($final_status)) . '.';

    echo json_encode([
        'success' => true,
        'message' => $response_msg
    ]);

} catch (PDOException $e) {
    error_log('Update return error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
