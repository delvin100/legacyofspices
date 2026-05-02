<?php
/**
 * Public Config API
 * Responds with public environment variables like GOOGLE_CLIENT_ID
 */

header('Content-Type: application/json');
require_once '../../config/env.php';

echo json_encode([
    'google_client_id' => getenv('GOOGLE_CLIENT_ID'),
    'firebase' => [
        'apiKey' => getenv('FIREBASE_API_KEY'),
        'authDomain' => getenv('FIREBASE_AUTH_DOMAIN'),
        'projectId' => getenv('FIREBASE_PROJECT_ID'),
        'storageBucket' => getenv('FIREBASE_STORAGE_BUCKET'),
        'messagingSenderId' => getenv('FIREBASE_MESSAGING_SENDER_ID'),
        'appId' => getenv('FIREBASE_APP_ID'),
        'measurementId' => getenv('FIREBASE_MEASUREMENT_ID')
    ]
]);
?>