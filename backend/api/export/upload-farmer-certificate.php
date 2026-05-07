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
    // ── Validate uploaded file ───────────────────────────────────────────────
    if (empty($_FILES['certificate']) || $_FILES['certificate']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $errCode = $_FILES['certificate']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg  = $uploadErrors[$errCode] ?? 'Upload error. Please try again.';
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit;
    }

    // ── Validate cert_type ────────────────────────────────────────────────────
    $cert_type = trim($_POST['cert_type'] ?? '');
    $allowed_types = [
        'fssai_registration',
        'aadhaar_card',
        'pan_card',
        'bank_proof',
        'farmer_proof',
        'gst_certificate',
        'organic_certification',
        'quality_testing_report'
    ];
    if (!in_array($cert_type, $allowed_types)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid certificate type.']);
        exit;
    }

    // ── Validate file extension ──────────────────────────────────────────────
    $file = $_FILES['certificate'];
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File type not allowed. Please upload Image, PDF, DOC, or DOCX.']);
        exit;
    }

    // ── Validate file size (max 10 MB) ───────────────────────────────────────
    if ($file['size'] > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 10 MB.']);
        exit;
    }

    // ── Save file ────────────────────────────────────────────────────────────
    require_once '../services/CloudinaryService.php';
    $dbPath = CloudinaryService::upload($file['tmp_name'], 'farmer_certificates');
    
    if (!$dbPath) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to upload certificate to Cloudinary.']);
        exit;
    }

    // ── Save to DB ───────────────────────────────────────────────────────────
    $pdo = getDBConnection();

    // Auto-create table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS farmer_certificates (
            id INT PRIMARY KEY AUTO_INCREMENT,
            farmer_id INT NOT NULL,
            cert_type ENUM(
                'fssai_registration',
                'aadhaar_card',
                'pan_card',
                'bank_proof',
                'farmer_proof',
                'gst_certificate',
                'organic_certification',
                'quality_testing_report'
            ) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
            admin_notes TEXT DEFAULT NULL,
            verified_by INT DEFAULT NULL,
            verified_at TIMESTAMP NULL DEFAULT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_farmer_cert_type (farmer_id, cert_type),
            INDEX idx_farmer (farmer_id),
            INDEX idx_status (verification_status)
        ) ENGINE=InnoDB
    ");

    // Auto-create tracking table for historical logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS farmer_certificate_tracking (
            id INT PRIMARY KEY AUTO_INCREMENT,
            farmer_id INT NOT NULL,
            cert_type VARCHAR(100) NOT NULL,
            action ENUM('uploaded', 'reuploaded', 'verified', 'rejected') NOT NULL,
            actor_id INT NOT NULL,
            actor_role ENUM('farmer', 'admin') NOT NULL,
            details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_farmer (farmer_id)
        ) ENGINE=InnoDB
    ");

    // Delete old file if replacing
    $old = $pdo->prepare("SELECT file_path FROM farmer_certificates WHERE farmer_id = ? AND cert_type = ?");
    $old->execute([$farmer_id, $cert_type]);
    $oldRow = $old->fetch(PDO::FETCH_ASSOC);
    if ($oldRow) {
        $oldFullPath = __DIR__ . '/../../../../' . $oldRow['file_path'];
        if (file_exists($oldFullPath)) @unlink($oldFullPath);
    }

    // UPSERT — one cert per type per farmer, resets verification to 'pending' on re-upload
    $stmt = $pdo->prepare("
        INSERT INTO farmer_certificates
            (farmer_id, cert_type, original_filename, file_path, verification_status, admin_notes, verified_by, verified_at, uploaded_at, updated_at)
        VALUES (?, ?, ?, ?, 'pending', NULL, NULL, NULL, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            original_filename = VALUES(original_filename),
            file_path = VALUES(file_path),
            verification_status = 'pending',
            admin_notes = NULL,
            verified_by = NULL,
            verified_at = NULL,
            uploaded_at = NOW(),
            updated_at = NOW()
    ");
    $stmt->execute([$farmer_id, $cert_type, $originalName, $dbPath]);

    // Mandatory certificates required for full verification
    $mandatoryTypes = ['fssai_registration', 'aadhaar_card', 'pan_card', 'bank_proof', 'farmer_proof'];
    $placeholders = implode(',', array_fill(0, count($mandatoryTypes), '?'));
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM farmer_certificates
        WHERE farmer_id = ? AND verification_status = 'verified' AND cert_type IN ($placeholders)
    ");
    $checkStmt->execute(array_merge([$farmer_id], $mandatoryTypes));
    $isFullyVerified = ((int)$checkStmt->fetchColumn() === count($mandatoryTypes));

    // Sync user verification status: if any mandatory cert is pending/rejected, they are not verified
    $pdo->prepare("UPDATE users SET is_verified = ? WHERE id = ?")->execute([$isFullyVerified ? 1 : 0, $farmer_id]);

    // Log the activity
    $action = $oldRow ? 'reuploaded' : 'uploaded';
    $trackStmt = $pdo->prepare("
        INSERT INTO farmer_certificate_tracking (farmer_id, cert_type, action, actor_id, actor_role, details)
        VALUES (?, ?, ?, ?, 'farmer', ?)
    ");
    $trackStmt->execute([$farmer_id, $cert_type, $action, $farmer_id, str_replace('_', ' ', $cert_type)]);

    echo json_encode([
        'success'    => true,
        'message'    => 'Certificate uploaded successfully. Pending admin verification.',
        'cert_type'  => $cert_type,
        'filename'   => $originalName,
        'path'       => $dbPath,
        'status'     => 'pending'
    ]);

} catch (PDOException $e) {
    error_log("Upload farmer certificate error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
