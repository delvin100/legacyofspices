<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('farmer');
$farmer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Get or create wallet
    $wallet = $pdo->prepare("SELECT balance FROM wallet WHERE user_id = ?");
    $wallet->execute([$farmer_id]);
    $row = $wallet->fetch(PDO::FETCH_ASSOC);
    $balance = $row ? (float)$row['balance'] : 0.0;

    // Get recent transactions
    $txStmt = $pdo->prepare("
        SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5
    ");
    $txStmt->execute([$farmer_id]);
    $transactions = $txStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'balance' => $balance, 'transactions' => $transactions]);
} catch (PDOException $e) {
    error_log('Get farmer wallet error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
