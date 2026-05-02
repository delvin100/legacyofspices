<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

// Get POST data
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email is required"]);
    exit;
}

$email = $data->email;

try {
    // $database = new Database(); 
    // $db = $database->connect();
    $db = getDBConnection();

    $query = "SELECT id, google_id, password FROM users WHERE email = :email LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Determine if Google User
        // A user is a "Google User" if they have a google_id OR their password is null
        $is_google = !empty($user['google_id']) || is_null($user['password']);

        echo json_encode([
            "status" => "success",
            "exists" => true,
            "is_google" => $is_google
        ]);
    } else {
        echo json_encode([
            "status" => "success",
            "exists" => false
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>