<?php
/**
 * Public Keys API
 * Returns non-sensitive public keys to the frontend
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../../config/env.php';

// Only return public keys, NEVER return SMTP passwords or DB credentials here!
echo json_encode([
    'success' => true,
    'keys' => [
        'razorpay_key_id' => getenv('RAZORPAY_KEY_ID') ?: 'rzp_test_YourKeyID',
        'paypal_client_id' => getenv('PAYPAL_CLIENT_ID') ?: 'sb'
    ]
]);
?>
