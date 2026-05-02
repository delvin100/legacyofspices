<?php
/**
 * Add Product API
 * Handles product creation and image upload for farmers
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Farmer access required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $farmer_id = $_SESSION['user_id'];
    $product_name = $_POST['product_name'] ?? '';
    $price = $_POST['price'] ?? 0;
    // $description = $_POST['description'] ?? ''; // Removed
    $category = 'Spices';
    $quantity = $_POST['quantity'] ?? 0;
    $unit = $_POST['unit'] ?? 'kg';

    // Validation: Use strlen check for strings and isset for numbers to allow "0"
    if (strlen(trim($product_name)) === 0 || $price === '') {
        throw new Exception('Product name and price are required.');
    }

    if ($price <= 0) {
        throw new Exception('Price must be greater than zero.');
    }

    if ($quantity < 0) {
        throw new Exception('Quantity cannot be negative.');
    }

    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Product image is required.');
    }

    // Handle Image Upload
    $image_url = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];

        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File is too large. Max size is 10MB.');
        }

        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Invalid file type. Only JPG, PNG, WEBP, GIF, and SVG are allowed.');
        }

        $uploadDir = '../../../uploads/products/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'prod_' . uniqid() . '.' . $extension;
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $image_url = 'uploads/products/' . $fileName; // Path relative to root
        } else {
            throw new Exception('Failed to upload image.');
        }
    }

    $pdo = getDBConnection();

    // Check if product name already exists (globally across all farmers)
    $checkStmt = $pdo->prepare("SELECT id FROM products WHERE LOWER(product_name) = LOWER(?) LIMIT 1");
    $checkStmt->execute([$product_name]);
    if ($checkStmt->fetch()) {
        throw new Exception("A product with the name '$product_name' already exists. Please use a unique name.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO products (farmer_id, product_name, category, price, base_currency, farmer_country, quantity, unit, image_url, is_available) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");

    require_once '../services/CurrencyService.php';

    $base_currency = $_SESSION['user_currency_code'] ?? 'INR';
    $farmer_country = $_SESSION['user_country'] ?? 'Unknown';

    // Convert price to INR for storage
    $priceVal = (float) $price;
    if ($base_currency !== CurrencyService::BASE_CURRENCY) {
        $priceVal = CurrencyService::convert($priceVal, $base_currency, CurrencyService::BASE_CURRENCY);
    }

    // Always store as INR (Base Currency) to ensure consistency across analytics and orders
    if ($stmt->execute([$farmer_id, $product_name, $category, $priceVal, CurrencyService::BASE_CURRENCY, $farmer_country, $quantity, $unit, $image_url])) {
        $product_id = $pdo->lastInsertId();

        // [HISTORICAL LOG] Save snapshot of listing state
        $trackStmt = $pdo->prepare("INSERT INTO product_tracking (product_id, action, quantity, price, unit, category, comment) VALUES (?, 'listed', ?, ?, ?, ?, 'Product initially listed')");
        $trackStmt->execute([$product_id, $quantity, $priceVal, $unit, $category]);

        echo json_encode([
            'success' => true,
            'message' => 'Product added successfully!',
            'rates' => CurrencyService::getExchangeRates()
        ]);
    } else {
        throw new Exception('Failed to save product to database.');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>