<?php
/**
 * Process Payment API (Simulation)
 * Updates order payment status to 'paid'
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

// Allow any origin for demo purposes, or restrict as needed
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $pdo = getDBConnection(); // Moved up

    if ((empty($data['order_id']) && empty($data['order_ids'])) || empty($data['address']) || empty($data['payment_method'])) {
        throw new Exception('Order ID(s), Address, and Payment Method are required');
    }

    $order_ids_input = $data['order_ids'] ?? $data['order_id'];
    $address = $data['address'];
    $phone = isset($data['phone']) ? $data['phone'] : '';
    $payment_method = $data['payment_method'];

    // Append phone to address for storage
    if ($phone) {
        $address .= "\nContact: " . $phone;
    }

    // --- Auto-Save to User Profile ---
    if (session_status() === PHP_SESSION_NONE)
        session_start();
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];

        // Always update the user's profile with the latest phone and address used for delivery
        $updateUserStmt = $pdo->prepare("UPDATE users SET phone = ?, address = ? WHERE id = ?");
        $updateUserStmt->execute([$phone, $data['address'], $userId]);
    }
    // --------------------------------------------

    $transaction_details = isset($data['transaction_details']) ? $data['transaction_details'] : null;

    // $pdo is already initialized above

    // Prepare notes update if transaction details exist
    $transaction_note = "";
    if ($transaction_details) {
        if (isset($transaction_details['payment_id'])) {
            $transaction_note = " | Ref: " . $transaction_details['payment_id'];
        } else if (isset($transaction_details['id'])) { // PayPal
            $transaction_note = " | Ref: " . $transaction_details['id'];
        }
    }

    // Convert to array of IDs
    $ids_array = explode(',', (string) $order_ids_input);
    $placeholders = implode(',', array_fill(0, count($ids_array), '?'));

    // Update orders with payment status, method, delivery address, and append transaction ref to notes
    // We need to construct parameters: address, method, transaction_note, then all IDs
    // ONLY SET payment_status = 'paid'
    $sqlPromise = "UPDATE orders SET payment_status = 'paid', delivery_address = ?, payment_method = ?, notes = CONCAT(IFNULL(notes, ''), ?) WHERE id IN ($placeholders)";

    // Params: address, method, note, [ids...]
    $params = [$address, $payment_method, $transaction_note];
    $params = array_merge($params, $ids_array);

    $stmt = $pdo->prepare($sqlPromise);
    $stmt->execute($params);

    // --- Log 'paid' status to order_tracking for Activity Feed ---
    if ($stmt->rowCount() > 0) {
        $trackingValues = [];
        $trackingParams = [];
        foreach ($ids_array as $oid) {
            // Log payment
            $trackingValues[] = "(?, 'paid', 'Payment Received')";
            $trackingParams[] = $oid;
        }
        if (!empty($trackingValues)) {
            // Need to flatten values string in SQL
            $valueString = implode(", ", $trackingValues);
            $sqlTracking = "INSERT INTO order_tracking (order_id, status, comment) VALUES $valueString";
            $stmtTracking = $pdo->prepare($sqlTracking);
            $stmtTracking->execute($trackingParams);
        }
    }
    // -------------------------------------------------------------

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Payment processed successfully']);
    } else {
        // Use a select to check if it was already paid or orders don't exist
        $check = $pdo->prepare("SELECT id, payment_status FROM orders WHERE id IN ($placeholders)");
        $check->execute($ids_array);
        $orders = $check->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            throw new Exception('Orders not found');
        }

        // If at least one is already paid, we consider it a success state (idempotency)
        echo json_encode(['success' => true, 'message' => 'Payment processed (orders updated or already paid)']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>