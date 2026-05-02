<?php
/**
 * Search Delivery Agents API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authorization: Farmer, Admin, or Delivery Agent
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['farmer', 'admin', 'delivery_agent'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $search = isset($_GET['q']) ? $_GET['q'] : '';
    $pdo = getDBConnection();

    $query = "SELECT id, full_name, address, country FROM users WHERE role = 'delivery_agent' AND is_active = 1";
    $params = [];

    if (!empty($search)) {
        $query .= " AND (full_name LIKE ? OR address LIKE ? OR country LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $query .= " LIMIT 20";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
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