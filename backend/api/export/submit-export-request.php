<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('customer');

$customer_id = $_SESSION['user_id'];

// ── Helper ──────────────────────────────────────────────────────────────────
function fail(int $code, string $msg, string $field = ''): never {
    http_response_code($code);
    $resp = ['success' => false, 'message' => $msg];
    if ($field) $resp['field'] = $field;
    echo json_encode($resp);
    exit;
}

// ── Parse Input ─────────────────────────────────────────────────────────────
try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) fail(400, 'Invalid JSON payload.');

    // ── Step 1: Required fields ──────────────────────────────────────────────
    $required = [
        'product_id'              => 'product-select',
        'quantity'                => 'quantity',
        'business_name'           => 'business-name',
        'contact_person'          => 'contact-person',
        'contact_email'           => 'contact-email',
        'target_country'          => 'target-country',
        'shipping_port'           => 'shipping-port',
        'preferred_shipping_mode' => 'shipping-mode',
        'incoterms'               => 'incoterms',
    ];
    foreach ($required as $key => $fieldId) {
        $val = $input[$key] ?? '';
        if ($val === '' || $val === null) {
            fail(400, ucwords(str_replace('_', ' ', $key)) . ' is required.', $fieldId);
        }
    }

    // Cast + sanitize
    $product_id              = (int)   $input['product_id'];
    $quantity                = (float) $input['quantity'];
    $business_name           = trim($input['business_name']);
    $business_registration_no = trim($input['business_registration_no'] ?? '');
    $importer_license_no     = trim($input['importer_license_no'] ?? '');
    $contact_person          = trim($input['contact_person']);
    $contact_email           = trim($input['contact_email']);
    $contact_phone           = trim($input['contact_phone'] ?? '');
    $delivery_street         = trim($input['delivery_street'] ?? '');
    $delivery_city           = trim($input['delivery_city'] ?? '');
    $delivery_postal_code    = trim($input['delivery_postal_code'] ?? '');
    $incoterms               = strtoupper(trim($input['incoterms']));
    $order_type              = in_array($input['order_type'] ?? 'bulk', ['bulk', 'sample']) ? $input['order_type'] : 'bulk';
    $required_delivery_date  = !empty($input['required_delivery_date']) ? $input['required_delivery_date'] : null;
    $target_country          = trim($input['target_country']);
    $shipping_port           = trim($input['shipping_port']);
    $preferred_shipping_mode = strtolower(trim($input['preferred_shipping_mode']));
    $payment_terms           = $input['payment_terms'] ?? 'advance';
    $packaging_requirements  = trim($input['packaging_requirements'] ?? '');
    $special_notes           = trim($input['special_notes'] ?? '');
    $currency_code           = $input['currency_code'] ?? 'INR';
    $requires_organic_cert   = !empty($input['requires_organic_cert'])  ? 1 : 0;
    $requires_phytosanitary  = !empty($input['requires_phytosanitary']) ? 1 : 0;
    $requires_quality_test   = !empty($input['requires_quality_test'])  ? 1 : 0;

    // ── Extra Validation ─────────────────────────────────────────────────────
    // Validate contact email format
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        fail(400, 'Please provide a valid contact email address.', 'contact-email');
    }

    // Validate incoterms
    $allowed_incoterms = ['FOB', 'CIF', 'EXW', 'DDP', 'CFR', 'DAP'];
    if (!in_array($incoterms, $allowed_incoterms)) {
        fail(400, 'Invalid Incoterm selected.', 'incoterms');
    }

    // Validate delivery date (must be in future if provided)
    if ($required_delivery_date !== null) {
        $today = date('Y-m-d');
        if ($required_delivery_date <= $today) {
            fail(400, 'Required delivery date must be in the future.', 'required-delivery-date');
        }
    }

    // ── Step 2: Quantity validation ──────────────────────────────────────────
    if ($quantity <= 0) {
        fail(400, 'Quantity must be greater than 0.', 'quantity');
    }

    // ── Step 3: Shipping mode ────────────────────────────────────────────────
    if (!in_array($preferred_shipping_mode, ['sea', 'air'])) {
        fail(400, 'Shipping mode must be Sea or Air.', 'shipping-mode');
    }

    // Sea freight minimum
    if ($preferred_shipping_mode === 'sea' && $quantity < 50) {
        fail(400, 'Sea freight requires a minimum of 50 kg.', 'quantity');
    }

    // ── Step 4: Offered price ────────────────────────────────────────────────
    $offered_price = null;
    if (!empty($input['offered_price']) && $input['offered_price'] !== '') {
        $offered_price = (float) $input['offered_price'];
        if ($offered_price <= 0) {
            fail(400, 'Offered price must be greater than 0.', 'offered-price');
        }
    }

    // ── Step 5: Payment terms enum ───────────────────────────────────────────
    $allowed_payment = ['advance', 'lc', 'dp', 'da'];
    if (!in_array($payment_terms, $allowed_payment)) {
        $payment_terms = 'advance';
    }

    // ── DB Checks ────────────────────────────────────────────────────────────
    $pdo = getDBConnection();

    // Step 6: Product exists & is active
    $stmt = $pdo->prepare("
        SELECT id, farmer_id, product_name, quantity, unit, is_available
        FROM products WHERE id = ?
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        fail(404, 'Product not found. Please select a valid product.', 'product-select');
    }
    if (!$product['is_available']) {
        fail(400, 'This product is currently unavailable for export.', 'product-select');
    }

    // Step 7: Stock check
    $stock = (float) $product['quantity'];
    if ($stock <= 0) {
        fail(400, 'This product is out of stock.', 'quantity');
    }
    if ($quantity > $stock) {
        fail(400, "Insufficient stock. Only {$stock} {$product['unit']} available.", 'quantity');
    }

    $farmer_id = $product['farmer_id'];

    // ── Auto-add new columns if they don't exist (ALTER TABLE migration) ─────
    $newCols = [
        "contact_person"         => "VARCHAR(150) DEFAULT NULL",
        "contact_email"          => "VARCHAR(200) DEFAULT NULL",
        "contact_phone"          => "VARCHAR(50) DEFAULT NULL",
        "delivery_street"        => "VARCHAR(255) DEFAULT NULL",
        "delivery_city"          => "VARCHAR(100) DEFAULT NULL",
        "delivery_postal_code"   => "VARCHAR(20) DEFAULT NULL",
        "incoterms"              => "VARCHAR(10) DEFAULT NULL",
        "order_type"             => "ENUM('bulk','sample') DEFAULT 'bulk'",
        "required_delivery_date" => "DATE DEFAULT NULL",
    ];
    $existingCols = [];
    $colRes = $pdo->query("SHOW COLUMNS FROM export_requests")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($colRes as $col) $existingCols[] = $col['field'];
    foreach ($newCols as $colName => $definition) {
        if (!in_array($colName, $existingCols)) {
            $pdo->exec("ALTER TABLE export_requests ADD COLUMN `$colName` $definition");
        }
    }
    // ── End migration ─────────────────────────────────────────────────────────

    // Step 8: Prevent duplicate active requests
    $stmt = $pdo->prepare("
        SELECT id FROM export_requests
        WHERE customer_id = ? AND product_id = ?
          AND status IN ('pending','under_review','approved','quality_testing','documentation')
    ");
    $stmt->execute([$customer_id, $product_id]);
    if ($stmt->fetch()) {
        fail(409, 'You already have an active export request for this product. Check "My Export Requests".');
    }

    // ── Insert ───────────────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        INSERT INTO export_requests (
            customer_id, farmer_id, product_id,
            quantity, unit, target_country,
            shipping_port, preferred_shipping_mode,
            business_name, business_registration_no, importer_license_no,
            contact_person, contact_email, contact_phone,
            delivery_street, delivery_city, delivery_postal_code,
            incoterms, order_type, required_delivery_date,
            offered_price, currency_code, payment_terms,
            requires_organic_cert, requires_phytosanitary, requires_quality_test,
            packaging_requirements, special_notes, status
        ) VALUES (?,?,?, ?,?,?, ?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?,?, ?,?, 'pending')
    ");
    $stmt->execute([
        $customer_id, $farmer_id, $product_id,
        $quantity, $product['unit'], $target_country,
        $shipping_port, $preferred_shipping_mode,
        $business_name, $business_registration_no, $importer_license_no,
        $contact_person, $contact_email, $contact_phone,
        $delivery_street, $delivery_city, $delivery_postal_code,
        $incoterms, $order_type, $required_delivery_date,
        $offered_price, $currency_code, $payment_terms,
        $requires_organic_cert, $requires_phytosanitary, $requires_quality_test,
        $packaging_requirements, $special_notes
    ]);

    $export_id = $pdo->lastInsertId();

    // Log initial tracking (optional — skip if table missing)
    try {
        $pdo->prepare("
            INSERT INTO export_tracking (export_request_id, status, notes, updated_by)
            VALUES (?, 'pending', 'Export request submitted by customer', ?)
        ")->execute([$export_id, $customer_id]);
    } catch (PDOException $trackErr) {
        error_log('export_tracking insert skipped: ' . $trackErr->getMessage());
    }

    // Notify farmer (optional — skip if table missing)
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type)
            VALUES (?, 'New Export Request', ?, 'export')
        ")->execute([
            $farmer_id,
            "A buyer has submitted an export request for {$product['product_name']} — {$quantity} {$product['unit']} to {$target_country}. Review in your Export panel."
        ]);
    } catch (PDOException $notifErr) {
        error_log('notifications insert skipped: ' . $notifErr->getMessage());
    }

    echo json_encode([
        'success'   => true,
        'message'   => 'Export request submitted successfully! The farmer will review your request.',
        'export_id' => $export_id
    ]);

} catch (PDOException $e) {
    error_log("Export request error: " . $e->getMessage());
    fail(500, 'Database error. Please try again.');
} catch (Exception $e) {
    error_log("Export request general error: " . $e->getMessage());
    fail(500, 'An unexpected error occurred. Please try again.');
}
?>
