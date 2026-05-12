<?php
require_once '../../config/cors.php';
/**
 * Update Product API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $pdo = getDBConnection();
    $farmer_id = $_SESSION['user_id'];

    $product_id = $_POST['product_id'] ?? null;
    $product_name = $_POST['product_name'] ?? '';
    $price = $_POST['price'] ?? 0;
    // $description = $_POST['description'] ?? ''; // Removed
    $quantity = $_POST['quantity'] ?? 0;
    $unit = $_POST['unit'] ?? 'kg';

    if (!$product_id || strlen(trim($product_name)) === 0 || $price === '') {
        throw new Exception('Product name and price are required.');
    }

    if ($price <= 0) {
        throw new Exception('Price must be greater than zero.');
    }

    if ($quantity < 0) {
        throw new Exception('Quantity cannot be negative.');
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT image_url FROM products WHERE id = ? AND farmer_id = ?");
    $stmt->execute([$product_id, $farmer_id]);
    $existingProduct = $stmt->fetch();

    if (!$existingProduct) {
        throw new Exception('Product not found or unauthorized');
    }

    // Check if new product name already exists (excluding current product)
    $checkStmt = $pdo->prepare("SELECT id FROM products WHERE LOWER(product_name) = LOWER(?) AND id != ? LIMIT 1");
    $checkStmt->execute([$product_name, $product_id]);
    if ($checkStmt->fetch()) {
        throw new Exception("A product with the name '$product_name' already exists. Please use a unique name.");
    }

    $image_url = $existingProduct['image_url'];

    // Handle Image Upload if provided
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        require_once '../services/CloudinaryService.php';
        $uploaded_url = CloudinaryService::upload($_FILES['image']['tmp_name'], 'products');
        if ($uploaded_url) {
            $image_url = $uploaded_url;
        }
    }

    $stmt = $pdo->prepare("
        UPDATE products 
        SET product_name = ?, price = ?, quantity = ?, unit = ?, image_url = ?
        WHERE id = ? AND farmer_id = ?
    ");

    require_once '../services/CurrencyService.php';

    // Convert price to INR for storage if needed
    $user_currency = $_SESSION['user_currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $priceVal = (float) $price;
    if ($user_currency !== CurrencyService::BASE_CURRENCY) {
        $priceVal = CurrencyService::convert($priceVal, $user_currency, CurrencyService::BASE_CURRENCY);
    }

    if ($stmt->execute([$product_name, $priceVal, $quantity, $unit, $image_url, $product_id, $farmer_id])) {
        // [HISTORICAL LOG] Save snapshot of update state
        $category = 'Spices'; // App default
        $trackStmt = $pdo->prepare("INSERT INTO product_tracking (product_id, action, quantity, price, unit, category, comment) VALUES (?, 'updated', ?, ?, ?, ?, 'Product details updated by farmer')");
        $trackStmt->execute([$product_id, $quantity, $priceVal, $unit, $category]);

        echo json_encode([
            'success' => true,
            'message' => 'Product updated successfully!',
            'rates' => CurrencyService::getExchangeRates()
        ]);
    } else {
        throw new Exception('Failed to update product.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
