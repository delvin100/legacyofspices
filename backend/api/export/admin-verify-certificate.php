<?php
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../../config/database.php';
require_once '../../config/session.php';

require_admin_access('cert');

$admin_id = $_SESSION['user_id'];

$body = json_decode(file_get_contents('php://input'), true);

$farmer_id  = (int)($body['farmer_id']  ?? 0);
$cert_type  = trim($body['cert_type']   ?? '');
$action     = trim($body['action']      ?? ''); // 'verify' or 'reject'
$admin_notes = trim($body['admin_notes'] ?? '');

$allowed_cert_types = [
    'fssai_registration', 'aadhaar_card', 'pan_card', 'bank_proof', 'farmer_proof',
    'gst_certificate', 'organic_certification', 'quality_testing_report'
];

if (!$farmer_id || !in_array($cert_type, $allowed_cert_types) || !in_array($action, ['verify', 'reject'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
    exit;
}

try {
    $pdo = getDBConnection();

    $newStatus  = ($action === 'verify') ? 'verified' : 'rejected';
    $verifiedAt = ($action === 'verify') ? 'NOW()' : 'NULL';

    $stmt = $pdo->prepare("
        UPDATE farmer_certificates
        SET verification_status = ?,
            admin_notes         = ?,
            verified_by         = ?,
            verified_at         = " . ($action === 'verify' ? 'NOW()' : 'NULL') . ",
            updated_at          = NOW()
        WHERE farmer_id = ? AND cert_type = ?
    ");
    $stmt->execute([$newStatus, $admin_notes ?: null, $admin_id, $farmer_id, $cert_type]);

    // Log the activity
    $trackStmt = $pdo->prepare("
        INSERT INTO farmer_certificate_tracking (farmer_id, cert_type, action, actor_id, actor_role, details)
        VALUES (?, ?, ?, ?, 'admin', ?)
    ");
    $trackStmt->execute([
        $farmer_id, 
        $cert_type, 
        $newStatus === 'verified' ? 'verified' : 'rejected', 
        $admin_id, 
        str_replace('_', ' ', $cert_type) . ($admin_notes ? ' (Notes: ' . $admin_notes . ')' : '')
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Certificate record not found.']);
        exit;
    }

    // Mandatory certificates required for full verification
    $mandatoryTypes = [
        'fssai_registration', 'aadhaar_card', 'pan_card', 'bank_proof', 'farmer_proof'
    ];
    $placeholders = implode(',', array_fill(0, count($mandatoryTypes), '?'));
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM farmer_certificates
        WHERE farmer_id = ? AND verification_status = 'verified' AND cert_type IN ($placeholders)
    ");
    $checkStmt->execute(array_merge([$farmer_id], $mandatoryTypes));
    $verifiedCount = (int)$checkStmt->fetchColumn();
    $isFullyVerified = $verifiedCount === count($mandatoryTypes);

    // Sync user verification status: must have all mandatory certificates verified
    $pdo->prepare("UPDATE users SET is_verified = ? WHERE id = ?")->execute([$isFullyVerified ? 1 : 0, $farmer_id]);

    echo json_encode([
        'success'          => true,
        'message'          => $action === 'verify' ? 'Certificate verified successfully.' : 'Certificate rejected.',
        'new_status'       => $newStatus,
        'is_fully_verified' => $isFullyVerified,
        'verified_count'   => $verifiedCount
    ]);

} catch (PDOException $e) {
    error_log("Admin verify certificate error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>

