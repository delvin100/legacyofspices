<?php
require_once '../../config/cors.php';
/**
 * Assign Delivery Agent API
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

require_admin_access('delivery');

try {
    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->order_id) || !isset($data->agent_id)) {
        throw new Exception('Order ID and Agent ID are required');
    }

    $id = (int)$data->order_id;
    $agent_id = (int)$data->agent_id;
    $type = isset($data->type) ? $data->type : 'order';

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    // 1. Verify Agent
    $agentStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'delivery_agent'");
    $agentStmt->execute([$agent_id]);
    $agent = $agentStmt->fetch();
    if (!$agent) {
        throw new Exception('Selected agent is invalid or not a delivery agent');
    }

    // 2. Verify Record Exists
    $checkSql = ($type === 'auction') ? "SELECT id FROM auctions WHERE id = ?" : "SELECT id FROM orders WHERE id = ?";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        throw new Exception("The " . $type . " with ID #$id was not found in the database.");
    }

    // 3. Update
    if ($type === 'auction') {
        $sql = "UPDATE auctions SET delivery_agent_id = ? WHERE id = ?";
    } else {
        $sql = "UPDATE orders SET delivery_agent_id = ? WHERE id = ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agent_id, $id]);

    // 4. Log
    $formattedId = ($type === 'auction') ? 'AUC-' . str_pad($id, 5, '0', STR_PAD_LEFT) : 'ORD-' . str_pad($id, 5, '0', STR_PAD_LEFT);
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action_type, target_table, target_id, description) VALUES (?, ?, ?, ?, ?)");
    $logStmt->execute([
        $_SESSION['user_id'], 
        'assign_delivery', 
        ($type === 'auction' ? 'auctions' : 'orders'), 
        $id, 
        "Assigned agent " . $agent['full_name'] . " to $formattedId"
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Successfully assigned " . $agent['full_name'] . " to $formattedId",
        'agent_name' => $agent['full_name']
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400); 
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
