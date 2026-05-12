<?php
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../config/database.php';
require_once '../../config/session.php';

require_role('farmer');

$farmer_id = $_SESSION['user_id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['export_ids']) || !is_array($input['export_ids']) || empty($input['status'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields: export_ids (array), status']);
        exit;
    }

    $export_ids  = array_map('intval', $input['export_ids']);
    $new_status  = $input['status'];
    $farmer_notes = trim($input['farmer_notes'] ?? '');

    // Farmer-allowed statuses
    $allowed_statuses = ['under_review', 'approved', 'rejected', 'quality_testing', 'documentation', 'shipped'];
    if (!in_array($new_status, $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit;
    }

    // Valid transitions map
    $validTransitions = [
        'pending'          => ['under_review', 'approved', 'rejected'],
        'under_review'     => ['approved', 'rejected', 'quality_testing'],
        'approved'         => ['quality_testing', 'documentation', 'shipped'],
        'quality_testing'  => ['documentation', 'approved', 'rejected'],
        'documentation'    => ['shipped', 'rejected'],
        'shipped'          => []
    ];

    $pdo = getDBConnection();

    // Fetch only requests owned by this farmer
    $placeholders = implode(',', array_fill(0, count($export_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT er.id, er.status, er.customer_id, p.product_name
        FROM export_requests er
        LEFT JOIN products p ON er.product_id = p.id
        WHERE er.id IN ($placeholders)
          AND er.farmer_id = ?
    ");
    $stmt->execute(array_merge($export_ids, [$farmer_id]));
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($requests)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No matching export requests found for your account']);
        exit;
    }

    // Filter: only update those with a valid transition to the target status
    $eligible   = [];
    $skipped    = [];
    foreach ($requests as $req) {
        $current = $req['status'];
        if (isset($validTransitions[$current]) && in_array($new_status, $validTransitions[$current])) {
            $eligible[] = $req;
        } else {
            $skipped[] = $req['id'];
        }
    }

    if (empty($eligible)) {
        echo json_encode([
            'success' => false,
            'message' => "None of the selected requests can transition to '$new_status'. Check their current statuses.",
            'skipped' => $skipped
        ]);
        exit;
    }

    // ── Certificate Gate for bulk 'shipped' ─────────────────────────────────
    if ($new_status === 'shipped') {
        $allCertTypes = [
            'organic_certificate', 'fssai_license', 'spice_board_registration',
            'gst_certificate', 'farm_ownership_proof', 'quality_testing_report'
        ];
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'farmer_certificates'")->fetch();
        $isFullyVerified = false;
        if ($tableCheck) {
            $certStmt = $pdo->prepare("SELECT cert_type FROM farmer_certificates WHERE farmer_id = ? AND verification_status = 'verified'");
            $certStmt->execute([$farmer_id]);
            $verifiedTypes = array_column($certStmt->fetchAll(PDO::FETCH_ASSOC), 'cert_type');
            $isFullyVerified = (count(array_intersect($allCertTypes, $verifiedTypes)) === count($allCertTypes));
        }
        if (!$isFullyVerified) {
            http_response_code(403);
            echo json_encode([
                'success'    => false,
                'message'    => 'Export shipping blocked: All 6 compliance certificates must be verified before you can ship. Visit the Certificates page to upload and get verified.',
                'error_code' => 'CERTIFICATES_NOT_VERIFIED'
            ]);
            exit;
        }
    }
    // ── End Certificate Gate ─────────────────────────────────────────────────

    // Build list of eligible IDs
    $eligibleIds     = array_column($eligible, 'id');
    $elPlaceholders  = implode(',', array_fill(0, count($eligibleIds), '?'));

    // Bulk UPDATE
    $updateStmt = $pdo->prepare("
        UPDATE export_requests
        SET status = ?, farmer_notes = ?, updated_at = NOW()
        WHERE id IN ($elPlaceholders) AND farmer_id = ?
    ");
    $updateStmt->execute(array_merge([$new_status, $farmer_notes], $eligibleIds, [$farmer_id]));
    $updatedCount = $updateStmt->rowCount();

    // Log tracking + notify each customer
    $trackStmt = $pdo->prepare("INSERT INTO export_tracking (export_request_id, status, notes, updated_by) VALUES (?, ?, ?, ?)");
    $notifStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'export')");

    $statusMessages = [
        'under_review'    => 'is currently under review by the farmer',
        'approved'        => 'has been APPROVED by the farmer 🎉',
        'rejected'        => 'has been rejected by the farmer',
        'quality_testing' => 'is undergoing quality testing',
        'documentation'   => 'documentation is being prepared',
        'shipped'         => 'has been shipped! 🚢'
    ];

    foreach ($eligible as $req) {
        $note    = $farmer_notes ?: "Status bulk-updated to " . strtoupper($new_status) . " by farmer";
        $trackStmt->execute([$req['id'], $new_status, $note, $farmer_id]);

        $msg  = "Your export request for {$req['product_name']} " . ($statusMessages[$new_status] ?? "status changed to $new_status");
        if ($farmer_notes) $msg .= " — Farmer's note: $farmer_notes";
        $notifStmt->execute([$req['customer_id'], 'Export Request Updated', $msg]);
    }

    $response = [
        'success'       => true,
        'message'       => "$updatedCount export request(s) updated to '" . str_replace('_', ' ', $new_status) . "'",
        'updated_count' => $updatedCount,
    ];
    if (!empty($skipped)) {
        $response['skipped_count']  = count($skipped);
        $response['skipped_ids']    = $skipped;
        $response['skip_reason']    = "Some requests were skipped because their current status cannot transition to '$new_status'";
    }

    echo json_encode($response);

} catch (PDOException $e) {
    error_log("Farmer bulk export update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>

