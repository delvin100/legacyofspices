<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/session.php';

// Strict Admin Check
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['from']) || !isset($data['to'])) {
        throw new Exception('Missing date range parameters');
    }

    $today = date('Y-m-d');
    if ($data['from'] > $today || $data['to'] > $today) {
        throw new Exception('Future dates are not allowed');
    }

    if ($data['from'] > $data['to']) {
        throw new Exception('Invalid date range');
    }

    $from = $data['from'];
    $to = $data['to'] . ' 23:59:59'; // Include the entire end day

    $pdo = getDBConnection();

    // 1. Record the cleared range to hide primary data (Users, Reviews, etc.)
    $rangeStmt = $pdo->prepare("INSERT INTO activity_log_cleared_ranges (from_date, to_date, cleared_by) VALUES (?, ?, ?)");
    $rangeStmt->execute([$from, $data['to'], $_SESSION['user_id']]);

    // 2. Delete from admin_logs
    $stmt1 = $pdo->prepare("DELETE FROM admin_logs WHERE created_at BETWEEN ? AND ?");
    $stmt1->execute([$from, $to]);
    $count1 = $stmt1->rowCount();

    // 3. Delete from order_tracking (Status updates, etc.)
    $stmt2 = $pdo->prepare("DELETE FROM order_tracking WHERE updated_at BETWEEN ? AND ?");
    $stmt2->execute([$from, $to]);
    $count2 = $stmt2->rowCount();

    // 4. Delete from product_tracking (Product edit history)
    $stmt3 = $pdo->prepare("DELETE FROM product_tracking WHERE updated_at BETWEEN ? AND ?");
    $stmt3->execute([$from, $to]);
    $count3 = $stmt3->rowCount();
    
    $totalDeleted = $count1 + $count2 + $count3;

    // Log this clearing action itself
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, 'logs_cleared', 'admin_logs', 0, ?)");
    $description = "Cleared activity logs from {$from} to {$data['to']} (Data hidden for primary entities).";
    $logStmt->execute([$_SESSION['user_id'], $description]);

    echo json_encode([
        'success' => true,
        'message' => "Activity logs for the selected range have been cleared successfully.",
        'deleted_count' => $totalDeleted
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

