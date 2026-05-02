<?php
/**
 * Get Admins API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/session.php';

// Strict Admin Check
require_role('admin');

try {
    $pdo = getDBConnection();

    // Fetch all admins (excluding the super admin)
    $stmt = $pdo->prepare("SELECT id, full_name, email, role, phone, address, is_active, created_at, admin_access, country, currency_code FROM users WHERE role = 'admin' AND email != 'admin@gmail.com' ORDER BY created_at DESC");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'admins' => $admins
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>