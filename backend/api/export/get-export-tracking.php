<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

// Any authenticated role can call this – we enforce ownership below
session_start();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$export_id = (int)($_GET['export_id'] ?? 0);

if (!$export_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing export_id']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Verify access: admin sees all, others only their own
    if ($user_role !== 'admin') {
        $check = $pdo->prepare("
            SELECT id FROM export_requests
            WHERE id = ? AND (customer_id = ? OR farmer_id = ?)
        ");
        $check->execute([$export_id, $user_id, $user_id]);
        if (!$check->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        SELECT 
            et.status,
            et.notes,
            et.created_at,
            u.full_name as updated_by_name,
            u.role    as updated_by_role
        FROM export_tracking et
        LEFT JOIN users u ON et.updated_by = u.id
        WHERE et.export_request_id = ?
        ORDER BY et.created_at ASC
    ");
    $stmt->execute([$export_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'history' => $history]);

} catch (PDOException $e) {
    error_log("Get export tracking error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
