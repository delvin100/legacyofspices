<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../services/CurrencyService.php';

$pdo = getDBConnection();

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$customer_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("
        SELECT c.*, p.product_name, p.price, p.image_url, p.unit, p.quantity as stock, u.full_name as farmer_name
        FROM cart c
        JOIN products p ON c.product_id = p.id
        JOIN users u ON p.farmer_id = u.id
        WHERE c.customer_id = ?
    ");
    $stmt->execute([$customer_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targetCurrency = $_SESSION['user_currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $targetSymbol = $_SESSION['user_currency_symbol'] ?? '₹';

    foreach ($items as &$item) {
        $basePrice = (float) $item['price'];
        $baseCurrency = CurrencyService::BASE_CURRENCY;

        // Fetch directly if user is in India (base currency)
        if ($targetCurrency === CurrencyService::BASE_CURRENCY) {
            $convertedPrice = $basePrice;
        } else {
            $convertedPrice = CurrencyService::convert($basePrice, $baseCurrency, $targetCurrency);
        }

        $item['display_price'] = $convertedPrice;
        $item['formatted_price'] = CurrencyService::formatPrice($convertedPrice, $targetSymbol, $targetCurrency);
        $item['total_item_price'] = $convertedPrice * $item['quantity'];
        $item['formatted_total_item_price'] = CurrencyService::formatPrice($item['total_item_price'], $targetSymbol, $targetCurrency);
    }

    echo json_encode(['success' => true, 'items' => $items, 'currency_code' => $targetCurrency, 'currency_symbol' => $targetSymbol]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>