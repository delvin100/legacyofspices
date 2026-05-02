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

    if (empty($input['export_ids']) || !is_array($input['export_ids']) || empty($input['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields: export_ids (array), status']);
        exit;
    }

    $export_ids = array_map('intval', $input['export_ids']);
    $new_status  = $input['status'];
    $admin_notes = trim($input['admin_notes'] ?? '');

    $allowed_statuses = ['under_review', 'approved', 'rejected', 'quality_testing', 'documentation', 'shipped', 'delivered', 'cancelled'];
    if (!in_array($new_status, $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit;
    }

    if (empty($export_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid export IDs provided']);
        exit;
    }

    $pdo = getDBConnection();

    // Fetch all target requests (for notification & tracking)
    $placeholders = implode(',', array_fill(0, count($export_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT er.id, er.customer_id, er.farmer_id, p.product_name
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        WHERE er.id IN ($placeholders)
    ");
    $stmt->execute($export_ids);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($requests)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No matching export requests found']);
        exit;
    }

    // Bulk UPDATE status
    $updateStmt = $pdo->prepare("
        UPDATE export_requests
        SET status = ?, admin_notes = ?, admin_reviewed_by = ?, admin_reviewed_at = NOW(), updated_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $updateStmt->execute(array_merge([$new_status, $admin_notes, $admin_id], $export_ids));
    $updated = $updateStmt->rowCount();

    // Insert tracking + notification for each request
    $trackStmt  = $pdo->prepare("INSERT INTO export_tracking (export_request_id, status, notes, updated_by) VALUES (?, ?, ?, ?)");
    $notifStmt  = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'export')");

    foreach ($requests as $req) {
        $note = $admin_notes ?: "Status bulk-updated to " . strtoupper($new_status) . " by admin";
        $trackStmt->execute([$req['id'], $new_status, $note, $admin_id]);

        $msg = "Your export request for {$req['product_name']} has been updated to: " . strtoupper(str_replace('_', ' ', $new_status));
        if ($admin_notes) $msg .= " — Admin note: $admin_notes";

        $notifStmt->execute([$req['customer_id'], 'Export Status Updated', $msg]);
        $notifStmt->execute([$req['farmer_id'],   'Export Status Updated', $msg]);
    }

    echo json_encode([
        'success' => true,
        'message' => "$updated export request(s) updated to '$new_status'",
        'updated_count' => $updated
    ]);

} catch (PDOException $e) {
    error_log("Bulk export status update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
