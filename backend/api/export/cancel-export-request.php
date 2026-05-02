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

require_role('customer');

$customer_id = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['export_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing export_id']);
        exit;
    }

    $export_id = (int) $input['export_id'];
    $pdo = getDBConnection();

    // Verify ownership and cancellable status
    $stmt = $pdo->prepare("
        SELECT er.id, er.status, er.farmer_id, p.product_name
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        WHERE er.id = ? AND er.customer_id = ?
    ");
    $stmt->execute([$export_id, $customer_id]);
    $export = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$export) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export request not found']);
        exit;
    }

    $cancellable = ['pending', 'under_review'];
    if (!in_array($export['status'], $cancellable)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Cannot cancel a request that is already '{$export['status']}'."]);
        exit;
    }

    // Cancel it
    $stmt = $pdo->prepare("UPDATE export_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ? AND customer_id = ?");
    $stmt->execute([$export_id, $customer_id]);

    // Log it
    $logStmt = $pdo->prepare("INSERT INTO export_tracking (export_request_id, status, notes, updated_by) VALUES (?, 'cancelled', 'Cancelled by customer', ?)");
    $logStmt->execute([$export_id, $customer_id]);

    // Notify farmer
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Export Request Cancelled', ?, 'export')");
    $notifStmt->execute([$export['farmer_id'], "The buyer has cancelled the export request for {$export['product_name']}."]);

    echo json_encode(['success' => true, 'message' => 'Export request cancelled successfully.']);

} catch (PDOException $e) {
    error_log("Cancel export request error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
