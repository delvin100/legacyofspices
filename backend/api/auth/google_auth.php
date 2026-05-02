<?php
/**
 * Custom Google Authentication API
 * Verifies access_token with Google and manages local user accounts
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');

require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$accessToken = $data['access_token'] ?? '';
$role = $data['role'] ?? null;
// Extra registration details
$country = $data['country'] ?? null;
$currency_code = $data['currency_code'] ?? null;
$currency_symbol = $data['currency_symbol'] ?? null;

if (empty($accessToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Access token required']);
    exit;
}

try {
    // 1. Verify token with Google UserInfo API
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Invalid access token');
    }

    $googleUser = json_decode($response, true);
    $googleId = $googleUser['sub'];
    $email = $googleUser['email'];
    $fullName = $googleUser['name'];
    $profileImage = $googleUser['picture'];

    $pdo = getDBConnection();

    // 2. Check if user exists by google_id
    $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE google_id = ?");
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();

    if (!$user) {
        // 3. User not found by google_id, check by email
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $existingUserByEmail = $stmt->fetch();

        if ($existingUserByEmail) {
            // Found by email, link google_id
            $updateStmt = $pdo->prepare("UPDATE users SET google_id = ?, full_name = ?, profile_image = ? WHERE id = ?");
            $updateStmt->execute([$googleId, $fullName, $profileImage, $existingUserByEmail['id']]);
            // Re-fetch full user data to ensure we have country/currency if set
            $stmt = $pdo->prepare("SELECT id, role, country, currency_code, currency_symbol FROM users WHERE id = ?");
            $stmt->execute([$existingUserByEmail['id']]);
            $user = $stmt->fetch();
        }
    }

    // 4. If user still doesn't exist, we need a role to register
    if (!$user) {
        if (empty($role)) {
            // New user, but no role provided yet - tell frontend to ask for it
            echo json_encode([
                'success' => true,
                'role_needed' => true,
                'message' => 'New user detected. Role selection required.'
            ]);
            exit;
        }

        // Normalize provided role
        $normalizedRole = strtolower(trim($role));
        if (!in_array($normalizedRole, ['farmer', 'customer'])) {
            $normalizedRole = 'customer'; // Default fallback
        }

        // New user with role - perform registration
        $insertStmt = $pdo->prepare("INSERT INTO users (email, full_name, role, google_id, profile_image, country, currency_code, currency_symbol, is_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)");
        $insertStmt->execute([$email, $fullName, $normalizedRole, $googleId, $profileImage, $country, $currency_code, $currency_symbol]);
        $userId = $pdo->lastInsertId();
        $user = ['id' => $userId, 'role' => $normalizedRole, 'country' => $country, 'currency_code' => $currency_code, 'currency_symbol' => $currency_symbol];
    } else {
        // Existing user - update their info
        $updateStmt = $pdo->prepare("UPDATE users SET full_name = ?, profile_image = ? WHERE id = ?");
        $updateStmt->execute([$fullName, $profileImage, $user['id']]);
    }

    // 5. Setup session - EXPLICIT TRIMMING AND NORMALIZATION
    $finalRole = strtolower(trim($user['role']));

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_role'] = $finalRole;
    // Store country/currency in session if available (either from new reg or existing user)
    if (!empty($user['country']))
        $_SESSION['user_country'] = $user['country'];
    if (!empty($user['currency_code']))
        $_SESSION['user_currency_code'] = $user['currency_code'];
    if (!empty($user['currency_symbol']))
        $_SESSION['user_currency_symbol'] = $user['currency_symbol'];

    echo json_encode([
        'success' => true,
        'role_needed' => false,
        'message' => 'Authenticated successfully',
        'redirect' => getDashboardUrl($finalRole),
        'user' => [
            'id' => $user['id'],
            'full_name' => $fullName,
            'role' => $finalRole
        ]
    ]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Robust Dashboard URL Calculator
 */
function getDashboardUrl($role)
{
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return '../admin/admin-dashboard.html';
    }
    if ($role === 'farmer') {
        return '../farmer/farmer-dashboard.html';
    }
    // Default to customer dashboard for all other authenticated users
    return '../customer/customer-dashboard.html';
}
?>