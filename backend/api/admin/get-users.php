<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');
require_once '../../config/database.php';

// Enable error reporting for debugging (Remove in production)
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../../config/session.php';

// Strict Admin Check
require_role('admin');

try {
    $pdo = getDBConnection();

    // Fetch users with extended info for detail view
    // For delivery_staff, hub_id points to the delivery_agent who created them
    $stmt = $pdo->prepare("
        SELECT 
            u.*, 
            h.full_name as hub_name 
        FROM users u 
        LEFT JOIN users h ON u.hub_id = h.id 
        WHERE u.role != 'admin' 
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
