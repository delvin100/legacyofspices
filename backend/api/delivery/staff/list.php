<?php
/**
 * List Delivery Staff API
 * Returns all staff members associated with the logged-in delivery agent's hub
 */

header('Content-Type: application/json');
require_once '../../../config/database.php';
require_once '../../../config/session.php';

// Only delivery agents can view their staff
require_role('delivery_agent');

try {
    $pdo = getDBConnection();
    $hub_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, phone, address, is_active, created_at 
        FROM users 
        WHERE role = 'delivery_staff' AND hub_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$hub_id]);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'staff' => $staff
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>