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

    // Fetch Admin Logs (Security Audit)
    // admin_logs table: id, admin_id, action_type, target_table, target_id, description, created_at
    $sql = "
        SELECT 
            al.id, 
            al.action_type, 
            al.target_table, 
            al.description, 
            al.created_at,
            u.full_name as admin_name
        FROM admin_logs al
        JOIN users u ON al.admin_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $logs]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>