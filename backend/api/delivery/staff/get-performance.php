<?php
/**
 * Get Staff Performance API
 * Provides daily delivery counts for the last 7 days for the logged-in staff member
 */

header('Content-Type: application/json');

require_once '../../../config/session.php';
require_once '../../../config/database.php';

// Only delivery staff can access this
require_role('delivery_staff');

try {
    $staff_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    // Get last 7 days of deliveries (Orders + Auctions) for this staff member
    // Using delivered_at for accurate completion date
    $stmt = $pdo->prepare("
        SELECT date, COUNT(*) as count FROM (
            SELECT DATE(delivered_at) as date FROM orders 
            WHERE delivery_staff_id = ? AND status = 'delivered' AND delivered_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY)
            
            UNION ALL
            
            SELECT DATE(delivered_at) as date FROM auctions 
            WHERE delivery_staff_id = ? AND shipping_status = 'delivered' AND delivered_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY)
        ) as combined_deliveries
        GROUP BY date
        ORDER BY date ASC
    ");

    $stmt->execute([$staff_id, $staff_id]);
    $performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for Chart.js
    $labels = [];
    $dataPoints = [];
    $today = new DateTime();

    // Generate last 7 days (including today)
    for ($i = 6; $i >= 0; $i--) {
        $dateObj = (clone $today)->modify("-$i days");
        $dateStr = $dateObj->format('Y-m-d');
        $label = $dateObj->format('D'); // Mon, Tue, etc.

        $labels[] = $label;

        $count = 0;
        foreach ($performance as $p) {
            if ($p['date'] === $dateStr) {
                $count = (int) $p['count'];
                break;
            }
        }
        $dataPoints[] = $count;
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