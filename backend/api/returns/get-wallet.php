<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('customer');
$customer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Get or create wallet
    $wallet = $pdo->prepare("SELECT balance FROM wallet WHERE user_id = ?");
    $wallet->execute([$customer_id]);
    $row = $wallet->fetch(PDO::FETCH_ASSOC);
    $balance = $row ? (float)$row['balance'] : 0.0;

    // Get recent transactions
    $txStmt = $pdo->prepare("
        SELECT wt.*, o.id as linked_order_id 
        FROM wallet_transactions wt
        LEFT JOIN `returns` r ON wt.reference_id = r.id AND wt.reference_type = 'return'
        LEFT JOIN orders o ON r.order_id = o.id
        WHERE wt.user_id = ? 
        ORDER BY wt.created_at DESC LIMIT 10
    ");
    $txStmt->execute([$customer_id]);
    $transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total spent (sum of all orders not cancelled and not fully refunded)
    $spentStmt = $pdo->prepare("
        SELECT SUM(o.total_price) as total 
        FROM orders o
        LEFT JOIN `returns` ret ON o.id = ret.order_id
        WHERE o.customer_id = ? 
        AND o.status != 'cancelled'
        AND (ret.status IS NULL OR ret.status != 'refund_completed')
    ");
    $spentStmt->execute([$customer_id]);
    $spentRow = $spentStmt->fetch(PDO::FETCH_ASSOC);
    $totalSpent = $spentRow ? (float)$spentRow['total'] : 0.0;

    echo json_encode([
        'success' => true, 
        'balance' => $balance, 
        'total_spent' => $totalSpent,
        'transactions' => $transactions
    ]);
} catch (PDOException $e) {
    error_log('Get wallet error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
