<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

// Enable error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $pdo = getDBConnection();

    $sql = "
        SELECT 
            r.id, 
            r.rating, 
            r.review_text as comment, 
            r.created_at,
            p.product_name,
            u.full_name as customer_name,
            u.id as user_id
        FROM reviews r
        JOIN products p ON r.product_id = p.id
        JOIN users u ON r.customer_id = u.id
        ORDER BY r.created_at DESC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $reviews]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>