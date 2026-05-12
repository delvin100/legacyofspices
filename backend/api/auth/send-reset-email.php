<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/env.php';
require_once '../../config/mailer.php';
require '../../vendor/autoload.php';



// Get POST data
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->email)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email is required"]);
    exit;
}

$email = $data->email;

try {
    // 1. Verify User exists in MySQL 
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT id, full_name FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception("User check failed. Please contact support."); // Should be caught by frontend check first
    }

    // 2. Generate a secure random token
    $token = bin2hex(random_bytes(16)); // 32 characters
    
    // 3. Create the local reset link
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $localResetLink = $protocol . "://" . $host . "/Legacy%20of%20Spices/frontend/auth/reset-password.html?oobCode=" . $token;

    // 4. Store token and timestamp in MySQL
    $stmt = $db->prepare("UPDATE users SET reset_token = :token, reset_token_sent_at = CURRENT_TIMESTAMP WHERE email = :email");
    $stmt->execute([':token' => $token, ':email' => $email]);

    $htmlBody = '
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; background-color: #f9f9f9; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 40px auto; background: #ffffff; border: 1px solid #000000; border-radius: 12px; overflow: hidden; }
            .header { padding: 40px; text-align: center; border-bottom: 1px solid #000000; }
            .content { padding: 40px; color: #333333; line-height: 1.6; }
            .content p { font-size: 16px; margin-bottom: 20px; color: #555555; }
            .button-container { text-align: center; margin: 30px 0; }
            .button { display: inline-block; padding: 16px 32px; background-color: #FF7E21; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
            .footer { padding: 20px; text-align: center; color: #999999; font-size: 12px; }
            h1 { color: #000000; margin: 0; font-size: 24px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                 <h1>Caravan <span style="color: #000000">of Spices</span></h1>
            </div>
            <div class="content">
                <p>Hello <strong>' . htmlspecialchars($user['full_name']) . '</strong>,</p>
                <p>We received a request to reset the password for your account associated with <strong>' . htmlspecialchars($email) . '</strong>.</p>
                <div class="button-container">
                    <a href="' . $localResetLink . '" class="button">RESET PASSWORD</a>
                </div>
                <p>If you did not request a password reset, you can safely ignore this email. This link will expire in 10 minutes.</p>
            </div>
            <div class="footer">
                &copy; ' . date("Y") . ' Legacy of Spices. All rights reserved.
            </div>
        </div>
    </body>
    </html>';

    $result = sendEmail($email, $user['full_name'], 'Reset Your Password | Legacy of Spices', $htmlBody);
    
    if (!$result['success']) {
        throw new Exception($result['message']);
    }

    echo json_encode([
        "status" => "success",
        "message" => "Custom reset email sent"
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    // Log detailed error but show generic to user
    error_log("Mail/Firebase Error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Processing failed: " . $e->getMessage()]);
}
?>

