<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['export_id']) || empty($input['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    $export_id = (int) $input['export_id'];
    $new_status = $input['status'];
    $admin_notes = trim($input['admin_notes'] ?? '');

    $allowed_statuses = ['under_review', 'approved', 'rejected', 'quality_testing', 'documentation', 'shipped', 'delivered', 'cancelled'];
    if (!in_array($new_status, $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    $pdo = getDBConnection();

    // Get export info for notifications
    $stmt = $pdo->prepare("
        SELECT er.id, er.status, er.customer_id, er.farmer_id, p.product_name
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        WHERE er.id = ?
    ");
    $stmt->execute([$export_id]);
    $export = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$export) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export request not found']);
        exit;
    }

    // Update
    $stmt = $pdo->prepare("
        UPDATE export_requests 
        SET status = ?, admin_notes = ?, admin_reviewed_by = ?, admin_reviewed_at = NOW(), updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_status, $admin_notes, $admin_id, $export_id]);

    // Log
    $logStmt = $pdo->prepare("INSERT INTO export_tracking (export_request_id, status, notes, updated_by) VALUES (?, ?, ?, ?)");
    $logStmt->execute([$export_id, $new_status, $admin_notes ?: "Status updated to $new_status by admin", $admin_id]);

    // Notify both farmer and customer
    $msg = "Export request for {$export['product_name']} has been updated to status: " . strtoupper($new_status);
    if ($admin_notes) $msg .= " — Admin note: $admin_notes";

    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'export')");
    $notifStmt->execute([$export['customer_id'], 'Export Request Updated by Admin', $msg]);
    $notifStmt->execute([$export['farmer_id'], 'Export Request Updated by Admin', $msg]);

    echo json_encode(['success' => true, 'message' => "Status updated to '$new_status'"]);

} catch (PDOException $e) {
    error_log("Admin update export error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
