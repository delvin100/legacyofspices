<?php
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('farmer');

$farmer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // ── Ensure new columns exist (safe migration) ─────────────────────────────
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

    $stmt = $pdo->prepare("
        SELECT 
            er.id,
            er.quantity,
            er.unit,
            er.target_country,
            er.shipping_port,
            er.preferred_shipping_mode,
            er.business_name,
            er.business_registration_no,
            er.importer_license_no,
            er.offered_price,
            er.currency_code,
            er.payment_terms,
            er.requires_organic_cert,
            er.requires_phytosanitary,
            er.requires_quality_test,
            er.packaging_requirements,
            er.special_notes,
            er.farmer_notes,
            er.status,
            er.created_at,
            er.updated_at,
            er.contact_person,
            er.contact_email,
            er.contact_phone,
            er.delivery_street,
            er.delivery_city,
            er.delivery_postal_code,
            er.incoterms,
            er.order_type,
            er.required_delivery_date,
            p.product_name,
            p.image_url,
            p.category,
            p.price as product_price,
            u.full_name as buyer_name,
            u.email as buyer_email,
            u.phone as buyer_phone,
            u.country as buyer_country
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        LEFT JOIN users u ON er.customer_id = u.id
        WHERE er.farmer_id = ?
        ORDER BY er.created_at DESC
    ");
    $stmt->execute([$farmer_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get stats
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered
        FROM export_requests WHERE farmer_id = ?
    ");
    $statsStmt->execute([$farmer_id]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'requests' => $requests, 'stats' => $stats]);

} catch (PDOException $e) {
    error_log("Get farmer export requests error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>

