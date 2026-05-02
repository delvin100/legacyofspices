<?php
/**
 * Get Delivery Performance API
 * Provides daily delivery counts for the last 7 days
 */

header('Content-Type: application/json');

require_once '../../config/session.php';
require_once '../../config/database.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'delivery_agent') {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

try {
    $agent_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // Get last 7 days of deliveries (Orders + Auctions)
    $stmt = $pdo->prepare("
        SELECT date, COUNT(*) as count FROM (
            SELECT DATE(delivered_at) as date FROM orders 
            WHERE delivery_agent_id = ? AND status = 'delivered' AND delivered_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
            
            UNION ALL
            
            SELECT DATE(delivered_at) as date FROM auctions 
            WHERE delivery_agent_id = ? AND shipping_status = 'delivered' AND delivered_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
        ) as combined_deliveries
        GROUP BY date
        ORDER BY date ASC
    ");

    $stmt->execute([$agent_id, $agent_id]);
    $performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for Chart.js
    $labels = [];
    $dataPoints = [];
    $today = new DateTime();

    for ($i = 6; $i >= 0; $i--) {
        $date = (clone $today)->modify("-$i days")->format('Y-m-d');
        $labels[] = (clone $today)->modify("-$i days")->format('D');

        $found = false;
        foreach ($performance as $p) {
            if ($p['date'] === $date) {
                $dataPoints[] = (int) $p['count'];
                $found = true;
                break;
            }
        }
        if (!$found)
            $dataPoints[] = 0;
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'data' => $dataPoints
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>