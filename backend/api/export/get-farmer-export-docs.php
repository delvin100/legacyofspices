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

    // Check if the table exists first — silently return empty if not yet created
    $check = $pdo->query("SHOW TABLES LIKE 'farmer_compliance_docs'")->fetch();
    if (!$check) {
        echo json_encode(['success' => true, 'documents' => (object)[]]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT document_type, document_name, original_filename, file_path, is_verified, created_at
        FROM farmer_compliance_docs
        WHERE farmer_id = ?
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$farmer_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Key by document_type for easy frontend lookup
    $byType = [];
    foreach ($rows as $row) {
        $byType[$row['document_type']] = $row;
    }

    echo json_encode(['success' => true, 'documents' => $byType]);

} catch (PDOException $e) {
    error_log("Get farmer compliance docs error: " . $e->getMessage());
    // Return empty silently — guide will still open without checkmarks
    echo json_encode(['success' => true, 'documents' => (object)[]]);
}
?>

