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

require_role('farmer');

$farmer_id = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['export_id']) || empty($input['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields: export_id, status']);
        exit;
    }

    $export_id = (int) $input['export_id'];
    $new_status = $input['status'];
    $farmer_notes = trim($input['farmer_notes'] ?? '');

    // Allowed statuses that a farmer can set
    $allowed_statuses = ['under_review', 'approved', 'rejected', 'quality_testing', 'documentation', 'shipped'];
    if (!in_array($new_status, $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    $pdo = getDBConnection();

    // Verify this export request belongs to this farmer
    $stmt = $pdo->prepare("
        SELECT er.id, er.status, er.customer_id, p.product_name
        FROM export_requests er 
        LEFT JOIN products p ON er.product_id = p.id
        WHERE er.id = ? AND er.farmer_id = ?
    ");
    $stmt->execute([$export_id, $farmer_id]);
    $export = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$export) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Export request not found or access denied']);
        exit;
    }

    // Check valid transitions
    $current = $export['status'];
    $validTransitions = [
        'pending' => ['under_review', 'approved', 'rejected'],
        'under_review' => ['approved', 'rejected', 'quality_testing'],
        'approved' => ['quality_testing', 'documentation', 'shipped'],
        'quality_testing' => ['documentation', 'approved', 'rejected'],
        'documentation' => ['shipped', 'rejected'],
        'shipped' => []
    ];

    if (!isset($validTransitions[$current]) || !in_array($new_status, $validTransitions[$current])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Cannot change status from '$current' to '$new_status'"]);
        exit;
    }

    // ── Certificate Gate: Block 'shipped' unless farmer is fully verified ────
    if ($new_status === 'shipped') {
        $allCertTypes = [
            'organic_certificate',
            'fssai_license',
            'spice_board_registration',
            'gst_certificate',
            'farm_ownership_proof',
            'quality_testing_report'
        ];

        // Check if the farmer_certificates table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'farmer_certificates'")->fetch();
        $isFullyVerified = false;

        if ($tableCheck) {
            $certStmt = $pdo->prepare("
                SELECT cert_type FROM farmer_certificates
                WHERE farmer_id = ? AND verification_status = 'verified'
            ");
            $certStmt->execute([$farmer_id]);
            $verifiedTypes = array_column($certStmt->fetchAll(PDO::FETCH_ASSOC), 'cert_type');
            $verifiedCount = count(array_intersect($allCertTypes, $verifiedTypes));
            $isFullyVerified = ($verifiedCount === count($allCertTypes));
        }

        if (!$isFullyVerified) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Export shipping blocked: All 6 compliance certificates must be uploaded and verified by an admin before you can ship. Go to Certificates → upload your documents.',
                'error_code' => 'CERTIFICATES_NOT_VERIFIED'
            ]);
            exit;
        }
    }
    // ── End Certificate Gate ─────────────────────────────────────────────────

    // Update status
    $stmt = $pdo->prepare("
        UPDATE export_requests 
        SET status = ?, farmer_notes = ?, updated_at = NOW()
        WHERE id = ? AND farmer_id = ?
    ");
    $stmt->execute([$new_status, $farmer_notes, $export_id, $farmer_id]);

    // Log to tracking
    $logStmt = $pdo->prepare("INSERT INTO export_tracking (export_request_id, status, notes, updated_by) VALUES (?, ?, ?, ?)");
    $logStmt->execute([$export_id, $new_status, $farmer_notes ?: "Status updated to $new_status by farmer", $farmer_id]);

    // Status message map for notification
    $statusMessages = [
        'under_review' => 'is currently under review by the farmer',
        'approved' => 'has been APPROVED by the farmer 🎉',
        'rejected' => 'has been rejected by the farmer',
        'quality_testing' => 'is undergoing quality testing',
        'documentation' => 'documentation is being prepared',
        'shipped' => 'has been shipped! 🚢'
    ];

    // Notify customer
    $notifTitle = $new_status === 'approved' ? 'Export Request Approved!' : ($new_status === 'rejected' ? 'Export Request Update' : 'Export Request Update');
    $notifMsg = "Your export request for {$export['product_name']} " . ($statusMessages[$new_status] ?? "status changed to $new_status");
    if ($farmer_notes) {
        $notifMsg .= " — Farmer's note: $farmer_notes";
    }

    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'export')");
    $notifStmt->execute([$export['customer_id'], $notifTitle, $notifMsg]);

    echo json_encode([
        'success' => true,
        'message' => "Export request status updated to '$new_status' successfully"
    ]);

} catch (PDOException $e) {
    error_log("Update export status error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
