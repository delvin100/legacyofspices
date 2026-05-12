<?php
/**
 * Export Request Email Notification Service
 * Called internally (or from other PHP scripts) to send status-change emails.
 * Can also be called via GET/POST for manual trigger.
 */
require_once '../../config/cors.php';
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once '../../config/database.php';
require_once '../../config/session.php';

// Allow internal calls (from other PHP files) by testing if called as a function
if (!function_exists('sendExportStatusEmail')) {
    function sendExportStatusEmail(array $params): bool {
        $to      = $params['to']       ?? '';
        $name    = $params['name']     ?? 'User';
        $role    = $params['role']     ?? 'customer';
        $product = $params['product']  ?? '';
        $status  = $params['status']   ?? '';
        $expId   = $params['export_id']?? '';
        $notes   = $params['notes']    ?? '';

        if (!$to) return false;

        $statusLabels = [
            'pending'          => 'Pending Review',
            'under_review'     => 'Under Review',
            'approved'         => 'Approved ✅',
            'rejected'         => 'Rejected ❌',
            'quality_testing'  => 'Quality Testing 🔬',
            'documentation'    => 'Documentation 📑',
            'shipped'          => 'Shipped 🚢',
            'delivered'        => 'Delivered 🏠',
            'cancelled'        => 'Cancelled 🚫',
        ];
        $statusColors = [
            'approved'   => '#10b981',
            'rejected'   => '#ef4444',
            'shipped'    => '#3b82f6',
            'delivered'  => '#166534',
            'pending'    => '#a16207',
            'under_review' => '#7c3aed',
        ];

        $label = $statusLabels[$status] ?? strtoupper($status);
        $color = $statusColors[$status] ?? '#FF7E21';
        $expRef = '#EXP-' . str_pad($expId, 5, '0', STR_PAD_LEFT);
        $notesHtml = $notes ? "<p style='background:#fef9c3;padding:12px 16px;border-radius:8px;margin-top:12px;font-size:14px;color:#7c2d12;'><strong>Note:</strong> $notes</p>" : '';
        $roleLabel = $role === 'farmer' ? 'Farmer' : 'Buyer';

        $subject = "[$expRef] Export Request Updated — $label | Legacy of Spices";

        $html = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
<body style='margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;'>
  <div style='max-width:560px;margin:32px auto;background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);'>
    <!-- Header -->
    <div style='background:linear-gradient(135deg,#FF7E21 0%,#e65c00 100%);padding:32px;text-align:center;'>
      <h1 style='color:white;margin:0;font-size:22px;letter-spacing:-0.02em;'>🌍 Legacy of Spices</h1>
      <p style='color:rgba(255,255,255,0.85);margin:6px 0 0;font-size:13px;'>Export Management System</p>
    </div>
    <!-- Body -->
    <div style='padding:32px;'>
      <p style='font-size:15px;color:#1e293b;margin:0 0 16px;'>Hello, <strong>$name</strong> ($roleLabel)</p>
      <p style='font-size:14px;color:#475569;margin:0 0 24px;line-height:1.6;'>
        Your export request <strong>$expRef</strong> for <strong>$product</strong> has been updated.
      </p>
      <!-- Status Badge -->
      <div style='background:#f8fafc;border-radius:12px;padding:20px;text-align:center;border:2px solid $color;margin-bottom:24px;'>
        <div style='font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;'>New Status</div>
        <div style='font-size:22px;font-weight:700;color:$color;'>$label</div>
      </div>
      $notesHtml
      <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
      <p style='font-size:12px;color:#94a3b8;line-height:1.6;margin:0;'>
        You can log in to the Legacy of Spices platform to view the full details and track the complete history of this export request.
      </p>
    </div>
    <!-- Footer -->
    <div style='background:#f8fafc;padding:16px 32px;border-top:1px solid #e2e8f0;text-align:center;'>
      <p style='margin:0;font-size:11px;color:#94a3b8;'>© " . date('Y') . " Legacy of Spices. Automated notification — please do not reply.</p>
    </div>
  </div>
</body>
</html>";

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Legacy of Spices <noreply@legacyofspices.com>\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

        return mail($to, $subject, $html, $headers);
    }
}

// Allow standalone HTTP calls for testing / manual trigger
if (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $exportId = (int)($input['export_id'] ?? $_GET['export_id'] ?? 0);

    if (!$exportId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing export_id']);
        exit;
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT 
                er.id, er.status, er.admin_notes, er.farmer_notes,
                p.product_name,
                buyer.full_name as buyer_name, buyer.email as buyer_email,
                farmer.full_name as farmer_name, farmer.email as farmer_email
            FROM export_requests er
            LEFT JOIN products p ON er.product_id = p.id
            LEFT JOIN users buyer  ON er.customer_id = buyer.id
            LEFT JOIN users farmer ON er.farmer_id = farmer.id
            WHERE er.id = ?
        ");
        $stmt->execute([$exportId]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$req) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Export request not found']);
            exit;
        }

        $notes = $req['admin_notes'] ?: $req['farmer_notes'] ?: '';
        $sentBuyer  = sendExportStatusEmail(['to' => $req['buyer_email'],  'name' => $req['buyer_name'],  'role' => 'customer', 'product' => $req['product_name'], 'status' => $req['status'], 'export_id' => $req['id'], 'notes' => $notes]);
        $sentFarmer = sendExportStatusEmail(['to' => $req['farmer_email'], 'name' => $req['farmer_name'], 'role' => 'farmer',   'product' => $req['product_name'], 'status' => $req['status'], 'export_id' => $req['id'], 'notes' => $notes]);

        echo json_encode([
            'success' => true,
            'message' => 'Notification emails sent',
            'buyer_sent'  => $sentBuyer,
            'farmer_sent' => $sentFarmer
        ]);

    } catch (PDOException $e) {
        error_log("Export email notify error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}
?>

