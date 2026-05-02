<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('farmer');

$farmer_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // Check if table exists
    $check = $pdo->query("SHOW TABLES LIKE 'farmer_certificates'")->fetch();
    if (!$check) {
        echo json_encode(['success' => true, 'certificates' => (object)[], 'is_fully_verified' => false]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT cert_type, original_filename, file_path, verification_status, admin_notes, uploaded_at, verified_at
        FROM farmer_certificates
        WHERE farmer_id = ?
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$farmer_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byType = [];
    foreach ($rows as $row) {
        $byType[$row['cert_type']] = $row;
    }

    // Count fully verified certs (all 6 must be 'verified')
    $allTypes = [
        'organic_certificate',
        'fssai_license',
        'spice_board_registration',
        'gst_certificate',
        'farm_ownership_proof',
        'quality_testing_report'
    ];
    $verifiedCount = 0;
    foreach ($allTypes as $type) {
        if (isset($byType[$type]) && $byType[$type]['verification_status'] === 'verified') {
            $verifiedCount++;
        }
    }
    $isFullyVerified = ($verifiedCount === count($allTypes));

    echo json_encode([
        'success'          => true,
        'certificates'     => $byType,
        'is_fully_verified' => $isFullyVerified,
        'verified_count'   => $verifiedCount,
        'total_required'   => count($allTypes)
    ]);

} catch (PDOException $e) {
    error_log("Get farmer certificates error: " . $e->getMessage());
    echo json_encode(['success' => true, 'certificates' => (object)[], 'is_fully_verified' => false]);
}
?>
