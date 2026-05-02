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
    $data = [];

    // 1. Low Stock Alert (Stock < 20)
    $stmt = $pdo->prepare("
        SELECT p.id, p.product_name, p.quantity as stock_quantity, u.full_name as farmer_name
        FROM products p
        JOIN users u ON p.farmer_id = u.id
        WHERE p.quantity < 20
        ORDER BY p.quantity ASC
        LIMIT 10
    ");
    $stmt->execute();
    $data['low_stock'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Category Distribution
    $stmt = $pdo->prepare("
        SELECT category, COUNT(*) as count 
        FROM products 
        GROUP BY category
    ");
    $stmt->execute();
    $data['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Most Expensive Products (Premium)
    $stmt = $pdo->prepare("
        SELECT product_name, price, image_url 
        FROM products 
        ORDER BY price DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $data['premium_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>