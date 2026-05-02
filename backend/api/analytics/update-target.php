<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../config/session.php';

// Strict Farmer Check
require_role('farmer');

$data = json_decode(file_get_contents("php://input"));

if (!isset($data->target) || !is_numeric($data->target)) {
    echo json_encode(['success' => false, 'message' => 'Invalid target amount']);
    exit;
}

$target = (float) $data->target;
$userId = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE users SET revenue_target = ? WHERE id = ?");
    $stmt->execute([$target, $userId]);

    echo json_encode(['success' => true, 'message' => 'Target updated successfully']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>