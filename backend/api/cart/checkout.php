<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';
$pdo = getDBConnection();

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$customer_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents("php://input"), true);
$delivery_address = $data['delivery_address'] ?? 'Default Address';

try {
    $pdo->beginTransaction();

    // 0. Parse inputs
    $selected_cart_ids = $data['selected_cart_ids'] ?? [];

    // Default to INR as the base currency if missing
    require_once '../services/CurrencyService.php';
    $currency_code = $data['currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $exchange_rate = $data['exchange_rate'] ?? 1.0;

    // 1. Get cart items
    $query = "
        SELECT c.*, p.price, p.farmer_id, p.product_name, p.quantity as stock
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.customer_id = ?
    ";

    $params = [$customer_id];

    if (!empty($selected_cart_ids)) {
        // Create parameter placeholders
        $placeholders = implode(',', array_fill(0, count($selected_cart_ids), '?'));
        $query .= " AND c.id IN ($placeholders)";
        $params = array_merge($params, $selected_cart_ids);
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        throw new Exception("No items selected or cart is empty");
    }

    $created_order_ids = [];

    foreach ($cartItems as $item) {
        // Check stock
        if ($item['stock'] < $item['quantity']) {
            throw new Exception("Insufficient stock for " . $item['product_name']);
        }

        $total_price = $item['price'] * $item['quantity'];

        // 2. Create Order
        $orderStmt = $pdo->prepare("INSERT INTO orders (customer_id, product_id, farmer_id, quantity, unit_price, total_price, status, delivery_address, currency_code, exchange_rate) VALUES (?, ?, ?, ?, ?, ?, 'ordered', ?, ?, ?)");
        $orderStmt->execute([$customer_id, $item['product_id'], $item['farmer_id'], $item['quantity'], $item['price'], $total_price, $delivery_address, $currency_code, $exchange_rate]);
        $created_order_ids[] = $pdo->lastInsertId();

        // 3. Update Stock
        $updateStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $updateStmt->execute([$item['quantity'], $item['product_id']]);

        // 4. Notification
        $farmer_id = $item['farmer_id'];
        $farmerStmt = $pdo->prepare("SELECT currency_code, currency_symbol FROM users WHERE id = ?");
        $farmerStmt->execute([$farmer_id]);
        $farmer = $farmerStmt->fetch(PDO::FETCH_ASSOC);

        $farmerCurrency = $farmer['currency_code'] ?? CurrencyService::BASE_CURRENCY;
        $farmerSymbol = $farmer['currency_symbol'] ?? '₹';
        $displayTotal = CurrencyService::convert($total_price, CurrencyService::BASE_CURRENCY, $farmerCurrency);
        $formattedTotal = CurrencyService::formatPrice($displayTotal, $farmerSymbol, $farmerCurrency);

        $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'New Market Order!', ?, 'order')");
        $notifStmt->execute([$farmer_id, "New order received for {$item['quantity']}kg of {$item['product_name']}. Total: {$formattedTotal}"]);
    }

    // 5. Clear Cart (Only selected items or all if no selection)
    if (!empty($selected_cart_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_cart_ids), '?'));
        $clearStmt = $pdo->prepare("DELETE FROM cart WHERE id IN ($placeholders)");
        $clearStmt->execute($selected_cart_ids);
    } else {
        $clearStmt = $pdo->prepare("DELETE FROM cart WHERE customer_id = ?");
        $clearStmt->execute([$customer_id]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Checkout successful!', 'order_ids' => $created_order_ids]);

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>