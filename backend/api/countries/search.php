<?php
require_once '../../config/cors.php';
header('Content-Type: application/json');

require_once '../services/CountryService.php';

$query = $_GET['q'] ?? '';

if (strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

$results = CountryService::searchCountries($query);
echo json_encode($results);
?>