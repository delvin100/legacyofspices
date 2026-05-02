<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Content-Type: application/json");

require_once '../../config/database.php';
$pdo = getDBConnection();

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'farmer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$auction_id = $data['auction_id'] ?? null;
$farmer_id = $_SESSION['user_id'];

if (!$auction_id) {
    echo json_encode(['success' => false, 'message' => 'Auction ID is required']);
    exit;
}

try {
    // Check if auction belongs to farmer and its status
    $stmt = $pdo->prepare("SELECT id, image_url, status FROM auctions WHERE id = ? AND farmer_id = ?");
    $stmt->execute([$auction_id, $farmer_id]);
    $auction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auction) {
        echo json_encode(['success' => false, 'message' => 'Auction not found or unauthorized']);
        exit;
    }

    if ($auction['status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Completed or cancelled auctions cannot be deleted for record-keeping purposes.']);
        exit;
    }

    // Delete associated bids first (if any)
    $delBids = $pdo->prepare("DELETE FROM bids WHERE auction_id = ?");
    $delBids->execute([$auction_id]);

    // Delete the auction
    $delAuction = $pdo->prepare("DELETE FROM auctions WHERE id = ?");
    $delAuction->execute([$auction_id]);

    // Delete the image file if it exists
    if ($auction['image_url']) {
        $img_path = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . $auction['image_url'];
        if (file_exists($img_path)) {
            unlink($img_path);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Auction deleted successfully']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>