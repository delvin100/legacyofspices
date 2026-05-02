<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('customer');

$customer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // ── Safe migration: ensure new columns exist ──────────────────────────────
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
        "farmer_notes"           => "TEXT DEFAULT NULL",
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

    $stmt = $pdo->prepare("
        SELECT 
            er.id,
            er.quantity,
            er.unit,
            er.target_country,
            er.shipping_port,
            er.preferred_shipping_mode,
            er.business_name,
            er.offered_price,
            er.currency_code,
            er.payment_terms,
            er.requires_organic_cert,
            er.requires_phytosanitary,
            er.requires_quality_test,
            er.packaging_requirements,
            er.special_notes,
            er.status,
            er.farmer_notes,
            er.created_at,
            er.updated_at,
            p.product_name,
            p.image_url,
            p.category,
            u.full_name as farmer_name,
            u.email as farmer_email,
            u.phone as farmer_phone
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        LEFT JOIN users u ON er.farmer_id = u.id
        WHERE er.customer_id = ?
        ORDER BY er.created_at DESC
    ");
    $stmt->execute([$customer_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'requests' => $requests]);

} catch (PDOException $e) {
    error_log("Get customer export requests error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
