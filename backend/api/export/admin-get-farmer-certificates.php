<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once '../../config/database.php';
require_once '../../config/session.php';

require_admin_access('cert');

try {
    $pdo = getDBConnection();

    // Check table exists
    $check = $pdo->query("SHOW TABLES LIKE 'farmer_certificates'")->fetch();
    if (!$check) {
        echo json_encode(['success' => true, 'farmers' => [], 'stats' => ['total_pending' => 0, 'total_verified' => 0, 'total_rejected' => 0]]);
        exit;
    }

    $allTypes = [
        'fssai_registration',
        'aadhaar_card',
        'pan_card',
        'bank_proof',
        'farmer_proof',
        'gst_certificate',
        'organic_certification',
        'quality_testing_report'
    ];

    // Get all farmers (including those who haven't uploaded any certificates)
    $stmt = $pdo->prepare("
        SELECT u.id AS farmer_id, u.full_name, u.email, u.profile_image,
               COUNT(fc.id) AS total_uploaded,
               SUM(CASE WHEN fc.verification_status = 'verified' THEN 1 ELSE 0 END) AS total_verified,
               SUM(CASE WHEN fc.verification_status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
               SUM(CASE WHEN fc.verification_status = 'rejected' THEN 1 ELSE 0 END) AS total_rejected,
               MAX(fc.uploaded_at) AS last_uploaded
        FROM users u
        LEFT JOIN farmer_certificates fc ON u.id = fc.farmer_id
        WHERE u.role = 'farmer'
        GROUP BY u.id, u.full_name, u.email, u.profile_image
        ORDER BY last_uploaded DESC, u.full_name ASC
    ");
    $stmt->execute();
    $farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each farmer, fetch their certificates
    foreach ($farmers as &$farmer) {
        $cStmt = $pdo->prepare("
            SELECT cert_type, original_filename, file_path, verification_status, admin_notes, uploaded_at, verified_at
            FROM farmer_certificates
            WHERE farmer_id = ?
        ");
        $cStmt->execute([$farmer['farmer_id']]);
        $rows = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['cert_type']] = $row;
        }
        $mandatoryTypes = ['fssai_registration', 'aadhaar_card', 'pan_card', 'bank_proof', 'farmer_proof'];
        $verifiedMandatory = 0;
        foreach ($mandatoryTypes as $mType) {
            if (isset($byType[$mType]) && $byType[$mType]['verification_status'] === 'verified') {
                $verifiedMandatory++;
            }
        }
        
        $farmer['certificates']    = $byType;
        $farmer['total_required']  = count($allTypes);
        $farmer['is_fully_verified'] = $verifiedMandatory === count($mandatoryTypes);
    }

    // Global stats
    $statsStmt = $pdo->query("
        SELECT
            SUM(CASE WHEN verification_status='pending' THEN 1 ELSE 0 END) AS total_pending,
            SUM(CASE WHEN verification_status='verified' THEN 1 ELSE 0 END) AS total_verified,
            SUM(CASE WHEN verification_status='rejected' THEN 1 ELSE 0 END) AS total_rejected
        FROM farmer_certificates
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'farmers' => $farmers,
        'stats'   => $stats
    ]);

} catch (PDOException $e) {
    error_log("Admin get farmer certificates error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
