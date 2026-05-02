<?php
/**
 * Update Profile API
 */

header('Content-Type: application/json');
require_once '../../config/database.php';

require_once '../../config/session.php';

// Strict Login Check
require_login();

try {
    $pdo = getDBConnection();
    $userId = $_SESSION['user_id'];

    // Check if user is a Google user
    $stmt = $pdo->prepare("SELECT google_id, email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    $isGoogleUser = !empty($user['google_id']);

    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $shop_name = $_POST['shop_name'] ?? null;
    $shop_description = $_POST['shop_description'] ?? null;

    if (empty($full_name)) {
        throw new Exception('Name is required');
    }

    // Prepare Update Fields
    $updateFields = ["full_name = ?"];
    $params = [$full_name];

    // Only update phone if explicitly provided in POST
    if (isset($_POST['phone'])) {
        $phone = $_POST['phone'];
        // Basic format validation only if NOT empty
        // if (!empty($phone) && !preg_match('/^\+?[0-9]{8,15}$/', $phone)) { throw new Exception('Invalid phone number format.'); }

        $updateFields[] = "phone = ?";
        $params[] = $phone;
    }

    // Only update address if explicitly provided in POST
    if (isset($_POST['address'])) {
        $address = $_POST['address'];
        $updateFields[] = "address = ?";
        $params[] = $address;
    }

    $country = $_POST['country'] ?? '';
    // Dynamic currency handling
    $currency_code = $_POST['currency_code'] ?? '';
    $currency_symbol = $_POST['currency_symbol'] ?? '';

    if (!empty($country)) {
        $updateFields[] = "country = ?";
        $params[] = $country;

        // Only update currency if provided
        if (!empty($currency_code) && !empty($currency_symbol)) {
            $updateFields[] = "currency_code = ?";
            $params[] = $currency_code;
            $updateFields[] = "currency_symbol = ?";
            $params[] = $currency_symbol;

            $_SESSION['user_currency_code'] = $currency_code;
            $_SESSION['user_currency_symbol'] = $currency_symbol;
        }

        $_SESSION['user_country'] = $country;
    }

    $low_stock_threshold = $_POST['low_stock_threshold'] ?? null;
    if ($low_stock_threshold !== null && $low_stock_threshold !== '') {
        $updateFields[] = "low_stock_threshold = ?";
        $params[] = (int) $low_stock_threshold;
    }

    if (!$isGoogleUser) {
        // Handle Email Update
        if (!empty($email) && $email !== $user['email']) {
            // Check if email is already taken
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $userId]);
            if ($checkStmt->fetch()) {
                throw new Exception('Email address is already in use.');
            }
            $updateFields[] = "email = ?";
            $params[] = $email;
            $_SESSION['email'] = $email;
        }

        // Handle Password Update
        if (!empty($password)) {
            if (strlen($password) < 8) {
                throw new Exception('Password must be at least 8 characters.');
            }
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $updateFields[] = "password = ?";
            $params[] = $hashedPassword;
        }
    }

    // Handle Shop Fields removed

    $profile_image = null;
    // Handle Image Upload
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_image'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Invalid file type.');
        }

        $uploadDir = '../../../uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'user_' . $userId . '_' . uniqid() . '.' . $extension;
        $targetPath = $uploadDir . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $profile_image = 'uploads/profiles/' . $fileName;
            $updateFields[] = "profile_image = ?";
            $params[] = $profile_image;
        }
    }

    $params[] = $userId;
    $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Update Session
    $_SESSION['full_name'] = $full_name;

    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>