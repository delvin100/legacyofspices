<?php
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../services/CurrencyService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();

try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            u.full_name as farmer_name,
            u.is_verified as is_verified,
            COALESCE(AVG(r.rating), 0) as average_rating,
            COUNT(r.id) as review_count
        FROM products p
        JOIN users u ON p.farmer_id = u.id
        LEFT JOIN reviews r ON p.id = r.product_id
        WHERE p.is_available = TRUE
        GROUP BY p.id
        ORDER BY p.id DESC
    ");
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targetCurrency = $_SESSION['user_currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $targetSymbol = $_SESSION['user_currency_symbol'] ?? '₹';

    foreach ($products as &$product) {
        $basePrice = (float) $product['price'];
        $baseCurrency = $product['base_currency'];

        // Skip conversion if target currency matches stored currency
        $convertedPrice = ($targetCurrency === $baseCurrency) ? $basePrice : CurrencyService::convert($basePrice, $baseCurrency, $targetCurrency);

        $product['display_price'] = $convertedPrice;
        $product['display_currency_code'] = $targetCurrency;
        $product['display_currency_symbol'] = $targetSymbol;
        $product['formatted_price'] = CurrencyService::formatPrice($convertedPrice, $targetSymbol, $targetCurrency);

        // Also keep original for reference if needed
        $product['base_price_formatted'] = CurrencyService::formatPrice($basePrice, "", $baseCurrency);
    }

    echo json_encode(['success' => true, 'products' => $products]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
