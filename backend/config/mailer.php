<?php
/**
 * Brevo Mailer Helper
 * Sends emails via Brevo (Sendinblue) API v3
 */

require_once __DIR__ . '/env.php';

/**
 * Send an email using Brevo API
 * 
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $htmlContent HTML body
 * @return array ['success' => bool, 'message' => string]
 */
function sendEmail($toEmail, $toName, $subject, $htmlContent) {
    $apiKey = getenv('BREVO_API_KEY');
    $senderEmail = getenv('BREVO_SENDER_EMAIL');
    $senderName = getenv('BREVO_SENDER_NAME');

    if (!$apiKey || !$senderEmail) {
        return ['success' => false, 'message' => 'Brevo configuration missing in .env'];
    }

    $url = 'https://api.brevo.com/v3/smtp/email';

    $data = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => $toName
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local XAMPP environments

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'message' => 'cURL Error: ' . $error];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => 'Email sent successfully'];
    } else {
        $result = json_decode($response, true);
        return ['success' => false, 'message' => $result['message'] ?? 'Brevo API Error: ' . $response];
    }
}
?>
