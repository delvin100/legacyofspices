<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();

    $mandatoryTypes = ['fssai_registration', 'aadhaar_card', 'pan_card', 'bank_proof', 'farmer_proof'];
    $placeholders = implode(',', array_fill(0, count($mandatoryTypes), '?'));

    // Fetch all farmers
    $stmt = $pdo->query("SELECT id, full_name, is_verified FROM users WHERE role = 'farmer'");
    $farmers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Checking " . count($farmers) . " farmers...\n";

    foreach ($farmers as $farmer) {
        $checkStmt = $pdo->prepare("
            SELECT COUNT(*) as cnt FROM farmer_certificates
            WHERE farmer_id = ? AND verification_status = 'verified' AND cert_type IN ($placeholders)
        ");
        $checkStmt->execute(array_merge([$farmer['id']], $mandatoryTypes));
        $verifiedCount = (int)$checkStmt->fetchColumn();
        $shouldBeVerified = ($verifiedCount === count($mandatoryTypes)) ? 1 : 0;

        if ($farmer['is_verified'] != $shouldBeVerified) {
            $update = $pdo->prepare("UPDATE users SET is_verified = ? WHERE id = ?");
            $update->execute([$shouldBeVerified, $farmer['id']]);
            echo "Updated farmer {$farmer['full_name']} (ID: {$farmer['id']}): is_verified {$farmer['is_verified']} -> $shouldBeVerified (Verified Docs: $verifiedCount/5)\n";
        } else {
            echo "Farmer {$farmer['full_name']} (ID: {$farmer['id']}) is already correct (is_verified: {$farmer['is_verified']}, Docs: $verifiedCount/5)\n";
        }
    }

    echo "Sync complete.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
