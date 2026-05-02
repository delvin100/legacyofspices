<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security Check
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    // http_response_code(403);
    // echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    // exit;
}

try {
    $pdo = getDBConnection();

    // Fetch ALL auctions with details
    $sql = "
        SELECT 
            a.*,
            f.full_name as farmer_name,
            w.full_name as winner_name,
            w.email as winner_email,
            (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) as bid_count
        FROM auctions a
        JOIN users f ON a.farmer_id = f.id
        LEFT JOIN users w ON a.winner_id = w.id
        ORDER BY a.end_time DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $auctions]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>