<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../services/CountryService.php';

$query = $_GET['q'] ?? '';

if (strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

$results = CountryService::searchCountries($query);
echo json_encode($results);
?>