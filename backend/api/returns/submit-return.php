<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('customer');
$customer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Handle multipart form data (with image upload) or JSON
    $isMultipart = isset($_POST['order_id']);
    
    if ($isMultipart) {
        $order_id   = (int)($_POST['order_id'] ?? 0);
        $reason     = $_POST['reason'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $refund_method = $_POST['refund_method'] ?? 'wallet';
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        $order_id   = (int)($input['order_id'] ?? 0);
        $reason     = $input['reason'] ?? '';
        $description = trim($input['description'] ?? '');
        $refund_method = $input['refund_method'] ?? 'wallet';
    }

    // Validate
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order ID is required.']);
        exit;
    }

    $allowed_reasons = ['damaged', 'wrong_item', 'expired', 'quality_issue', 'other'];
    if (!in_array($reason, $allowed_reasons) && strpos($reason, 'Other: ') !== 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid return reason.']);
        exit;
    }

    if (!in_array($refund_method, ['wallet', 'original'])) {
        $refund_method = 'wallet';
    }

    // Verify order belongs to customer and is delivered
    $stmt = $pdo->prepare("
        SELECT o.id, o.farmer_id, o.product_id, o.total_price, o.currency_code,
               o.delivered_at, o.status, p.product_name
        FROM orders o
        JOIN products p ON o.product_id = p.id
        WHERE o.id = ? AND o.customer_id = ?
    ");
    $stmt->execute([$order_id, $customer_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    if ($order['status'] !== 'delivered') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Returns are only allowed for delivered orders.']);
        exit;
    }

    // 7-day window check
    if ($order['delivered_at']) {
        $deliveredAt = new DateTime($order['delivered_at']);
        $now = new DateTime();
        $daysDiff = $now->diff($deliveredAt)->days;
        if ($daysDiff > 7) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Return window has expired. Returns must be requested within 7 days of delivery.']);
            exit;
        }
    }

    // Check for duplicate return request
    $dup = $pdo->prepare("SELECT id FROM returns WHERE order_id = ? AND status NOT IN ('rejected')");
    $dup->execute([$order_id]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'A return request already exists for this order.']);
        exit;
    }

    // Handle image upload
    $image_path = null;
    if ($isMultipart && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../../uploads/returns/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (in_array($ext, $allowed)) {
            $filename = 'return_' . $order_id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                $image_path = 'uploads/returns/' . $filename;
            }
        }
    }

    // Insert return request
    $stmt = $pdo->prepare("
        INSERT INTO returns (order_id, customer_id, farmer_id, product_id, product_name, reason, description, image_path, refund_method, refund_amount, currency_code, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'requested')
    ");
    $stmt->execute([
        $order_id,
        $customer_id,
        $order['farmer_id'],
        $order['product_id'],
        $order['product_name'],
        $reason,
        $description,
        $image_path,
        $refund_method,
        $order['total_price'],
        $order['currency_code'] ?? 'INR'
    ]);

    $return_id = $pdo->lastInsertId();

    // Notify admin
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            SELECT id, 'New Return Request', ?, 'return'
            FROM users WHERE role = 'admin' LIMIT 1
        ")->execute(["Customer submitted a return request #RET-" . str_pad($return_id, 5, '0', STR_PAD_LEFT) . " for order #ORD-" . str_pad($order_id, 5, '0', STR_PAD_LEFT)]);
    } catch (Exception $e) { /* skip if notifications table issue */ }

    echo json_encode([
        'success' => true,
        'message' => 'Return request submitted successfully. Admin will review within 2-3 business days.',
        'return_id' => $return_id
    ]);

} catch (PDOException $e) {
    error_log('Return submit error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
