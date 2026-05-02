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
    $activity = [];

    // 1. Sales (Orders)
    $stmt = $pdo->prepare("SELECT id, 'order' AS type, order_date AS created_at, status, total_price AS amount, 
                          (SELECT product_name FROM products WHERE id=o.product_id) as description 
                          FROM orders o WHERE farmer_id = ?");
    $stmt->execute([$farmer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 2. Incoming Returns
    $stmt = $pdo->prepare("SELECT id, 'return' AS type, created_at, status, refund_amount AS amount, 
                          CONCAT('Return Received: ', product_name) as description FROM returns WHERE farmer_id = ?");
    $stmt->execute([$farmer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 3. Wallet Transactions
    $stmt = $pdo->prepare("SELECT id, 'wallet' AS type, created_at, type as status, amount, description FROM wallet_transactions WHERE user_id = ?");
    $stmt->execute([$farmer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 4. Auctions Created
    $stmt = $pdo->prepare("SELECT id, 'auction' AS type, created_at, status, starting_price AS amount, 
                          CONCAT('Auction: ', product_name) as description FROM auctions WHERE farmer_id = ?");
    $stmt->execute([$farmer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 5. Incoming Export Requests
    $stmt = $pdo->prepare("SELECT id, 'export' AS type, created_at, status, offered_price AS amount, 
                          (SELECT product_name FROM products WHERE id=e.product_id) as description 
                          FROM export_requests e WHERE farmer_id = ?");
    $stmt->execute([$farmer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // 6. Certificates Uploaded
    // Correcting table name if it's farmer_certificates
    $stmt = $pdo->prepare("SELECT id, 'certificate' AS type, uploaded_at AS created_at, verification_status as status, NULL as amount, 
                          REPLACE(cert_type, '_', ' ') as description FROM farmer_certificates WHERE farmer_id = ?");
    $stmt->execute([$farmer_id]);
    $activity = array_merge($activity, $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Sort by date desc
    usort($activity, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    // Limit to latest 30 actions
    $activity = array_slice($activity, 0, 30);

    echo json_encode(['success' => true, 'activity' => $activity]);
} catch (PDOException $e) {
    error_log('Farmer activity error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

