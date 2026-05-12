<?php
header('Content-Type: application/json');
require_once '../config/cors.php';
echo json_encode([
    'status' => 'ok',
    'time' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
]);
?>

