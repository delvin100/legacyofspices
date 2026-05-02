<?php
/**
 * Get Delivery Agents API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/session.php';

// Strict Admin Check
require_admin_access('delivery');

try {
    $pdo = getDBConnection();

    // Fetch all delivery agents
    $stmt = $pdo->prepare("SELECT id, full_name, email, role, phone, address, is_active, is_verified, created_at, country, currency_code FROM users WHERE role = 'delivery_agent' ORDER BY created_at DESC");
    $stmt->execute();
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'agents' => $agents
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>