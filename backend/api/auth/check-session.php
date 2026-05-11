header('Content-Type: application/json');
require_once '../../config/cors.php';
require_once '../../config/database.php';

require_once '../../config/session.php';

if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'customer';
    $redirectMap = [
        'farmer' => '../farmer/farmer-dashboard.html',
        'customer' => '../customer/customer-dashboard.html',
        'admin' => '../admin/admin-dashboard.html',
        'delivery_agent' => '../delivery/delivery-dashboard.html',
        'delivery_staff' => '../delivery/staff-dashboard.html'
    ];
    $redirect = $redirectMap[$role] ?? '../customer/customer-dashboard.html';

    // Fetch fresh status and permissions from DB
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT is_verified, currency_code, currency_symbol, admin_access, is_active FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (isset($user['is_active']) && $user['is_active'] == 0)) {
        session_destroy();
        echo json_encode(['logged_in' => false, 'message' => 'Account disabled or not found']);
        exit;
    }

    $is_verified = $user['is_verified'] ?? 0;
    $admin_access = $user['admin_access'] ?? 'all';
    
    // Update session with fresh values
    $_SESSION['is_verified'] = $is_verified;
    $_SESSION['admin_access'] = $admin_access;

    echo json_encode([
        'logged_in' => true, 
        'redirect' => $redirect, 
        'role' => $role, 
        'email' => $_SESSION['user_email'] ?? '',
        'admin_access' => $admin_access,
        'is_verified' => $is_verified,
        'currency_code' => $user['currency_code'] ?? 'INR',
        'currency_symbol' => $user['currency_symbol'] ?? '₹'
    ]);
} else {
    echo json_encode(['logged_in' => false]);
}
?>