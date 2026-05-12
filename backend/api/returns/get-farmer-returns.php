<?php
header("Content-Type: application/json");
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('farmer');
$farmer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    $status = $_GET['status'] ?? 'all';
    $where = "WHERE r.farmer_id = ?";
    $params = [$farmer_id];

    if ($status !== 'all') {
        $where .= " AND r.status = ?";
        $params[] = $status;
    }

    $stmt = $pdo->prepare("
        SELECT r.*,
               cu.full_name AS customer_name, cu.email AS customer_email,
               p.image_url,
               o.order_date, o.delivered_at, o.total_price AS order_total
        FROM returns r
        JOIN users cu ON r.customer_id = cu.id
        LEFT JOIN products p ON r.product_id = p.id
        JOIN orders o ON r.order_id = o.id
        $where
        ORDER BY r.created_at DESC
    ");
    $stmt->execute($params);
    $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get counts for summary
    $countStmt = $pdo->prepare("
        SELECT status, COUNT(*) as count FROM returns WHERE farmer_id = ? GROUP BY status
    ");
    $countStmt->execute([$farmer_id]);
    $counts = $countStmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = ['total' => 0];
    foreach ($counts as $c) {
        $summary[$c['status']] = (int)$c['count'];
        $summary['total'] += (int)$c['count'];
    }

    echo json_encode(['success' => true, 'returns' => $returns, 'summary' => $summary]);
} catch (PDOException $e) {
    error_log('Get farmer returns error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>

