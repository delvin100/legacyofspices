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
    if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        ];
        $errCode = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg  = $uploadErrors[$errCode] ?? 'Upload error. Please try again.';
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit;
    }

    // ── Validate doc_type ────────────────────────────────────────────────────
    $doc_type = $_POST['doc_type'] ?? '';
    $allowed_types = [
        'iec_certificate', 'spices_board_cert', 'fssai_license',
        'quality_certificate', 'phytosanitary_certificate',
        'commercial_invoice', 'packing_list', 'bill_of_lading',
        'certificate_of_origin', 'insurance_certificate', 'other'
    ];
    if (!in_array($doc_type, $allowed_types)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid document type.']);
        exit;
    }

    // ── Validate file extension ──────────────────────────────────────────────
    $file = $_FILES['document'];
    $originalName = basename($file['name']);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File type not allowed. Use PDF, JPG, PNG, DOC or DOCX.']);
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
    $dbPath = CloudinaryService::upload($file['tmp_name'], 'compliance_docs');
    
    if (!$dbPath) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to upload document to Cloudinary.']);
        exit;
    }

    // ── Save to DB (UPSERT — one doc per type per farmer) ───────────────────
    $pdo = getDBConnection();

    // Make sure table exists (auto-create if not)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS farmer_compliance_docs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            farmer_id INT NOT NULL,
            document_type ENUM(
                'iec_certificate','spices_board_cert','fssai_license',
                'quality_certificate','phytosanitary_certificate',
                'commercial_invoice','packing_list','bill_of_lading',
                'certificate_of_origin','insurance_certificate','other'
            ) NOT NULL,
            document_name VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            is_verified BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_farmer_doc_type (farmer_id, document_type),
            INDEX idx_farmer (farmer_id)
        ) ENGINE=InnoDB
    ");

    // Delete old file if replacing
    $old = $pdo->prepare("SELECT file_path FROM farmer_compliance_docs WHERE farmer_id = ? AND document_type = ?");
    $old->execute([$farmer_id, $doc_type]);
    $oldRow = $old->fetch(PDO::FETCH_ASSOC);
    if ($oldRow) {
        $oldFullPath = __DIR__ . '/../../../../' . $oldRow['file_path'];
        if (file_exists($oldFullPath)) @unlink($oldFullPath);
    }

    // UPSERT
    $stmt = $pdo->prepare("
        INSERT INTO farmer_compliance_docs
            (farmer_id, document_type, document_name, original_filename, file_path, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            document_name = VALUES(document_name),
            original_filename = VALUES(original_filename),
            file_path = VALUES(file_path),
            is_verified = 0,
            updated_at = NOW()
    ");
    $stmt->execute([$farmer_id, $doc_type, $newFilename, $originalName, $dbPath]);

    echo json_encode([
        'success'   => true,
        'message'   => 'Document uploaded successfully.',
        'doc_type'  => $doc_type,
        'filename'  => $originalName,
        'path'      => $dbPath
    ]);

} catch (PDOException $e) {
    error_log("Upload compliance doc error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
