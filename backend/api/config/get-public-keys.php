<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');

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
