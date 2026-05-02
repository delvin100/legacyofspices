<?php
/**
 * Get Farmer Products API
 */

header('Content-Type: application/json');

require_once '../../config/database.php';
require_once '../services/CurrencyService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $farmer_id = $_SESSION['user_id'];
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT * FROM products WHERE farmer_id = ? ORDER BY id DESC");
    $stmt->execute([$farmer_id]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targetCurrency = $_SESSION['user_currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $targetSymbol = $_SESSION['user_currency_symbol'] ?? '₹';

    foreach ($products as &$product) {
        $basePrice = (float) $product['price'];
        $baseCurrency = $product['base_currency'];

        // Skip conversion if target currency matches stored currency
        if ($targetCurrency === $baseCurrency) {
            $convertedPrice = $basePrice;
        } else {
            $convertedPrice = CurrencyService::convert($basePrice, $baseCurrency, $targetCurrency);
        }

        $product['display_price'] = $convertedPrice;
        $product['display_currency_code'] = $targetCurrency;
        $product['display_currency_symbol'] = $targetSymbol;
        $product['formatted_price'] = CurrencyService::formatPrice($convertedPrice, $targetSymbol, $targetCurrency);
    }

    echo json_encode(['success' => true, 'data' => $products]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>