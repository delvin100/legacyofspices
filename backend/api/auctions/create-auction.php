<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';
$pdo = getDBConnection();

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$farmer_id = $_SESSION['user_id'];
$product_name = $_POST['product_name'] ?? '';
// $description = $_POST['description'] ?? ''; // Removed
$starting_price = $_POST['starting_price'] ?? 0;
$quantity = $_POST['quantity'] ?? 1.0;
$unit = $_POST['unit'] ?? 'kg';
$start_time = $_POST['start_time'] ?? '';
$end_time = $_POST['end_time'] ?? '';
$image_url = '';

if (empty($product_name) || !isset($_POST['starting_price']) || $_POST['starting_price'] === '' || empty($start_time) || empty($end_time) || !isset($_POST['quantity']) || $_POST['quantity'] === '') {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Ensure image is uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Product image is required']);
    exit;
}

// Handle Image Upload
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    // Use absolute path for upload directory (Root/uploads)
    $upload_dir = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'auctions' . DIRECTORY_SEPARATOR;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_extension = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $file_name = uniqid('auction_') . '.' . $file_extension;
    $target_file = $upload_dir . $file_name;

    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
        // URL relative to root: uploads/auctions/filename
        // Since frontend is served from root or subfolder, relative links might depend on how it's served.
        // Usually 'uploads/...' from the root is standard.
        $image_url = 'uploads/auctions/' . $file_name;
    }
}

try {
    require_once '../services/CurrencyService.php';

    $base_currency = $_SESSION['user_currency_code'] ?? CurrencyService::BASE_CURRENCY;
    $farmer_country = $_SESSION['user_country'] ?? 'Unknown';

    // Convert starting price to INR
    $startingPriceVal = (float) $starting_price;
    if ($base_currency !== CurrencyService::BASE_CURRENCY) {
        $startingPriceVal = CurrencyService::convert($startingPriceVal, $base_currency, CurrencyService::BASE_CURRENCY);
    }

    // Determine initial status based on start time
    $current_time = time();
    $scheduled_start = strtotime($start_time);
    $initial_status = ($scheduled_start > $current_time) ? 'scheduled' : 'active';

    // Store with INR as base currency
    $stmt = $pdo->prepare("INSERT INTO auctions (farmer_id, product_name, starting_price, base_currency, farmer_country, current_bid, quantity, unit, start_time, end_time, image_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$farmer_id, $product_name, $startingPriceVal, CurrencyService::BASE_CURRENCY, $farmer_country, $startingPriceVal, $quantity, $unit, $start_time, $end_time, $image_url, $initial_status]);

    echo json_encode(['success' => true, 'message' => 'Auction created successfully', 'auction_id' => $pdo->lastInsertId()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>