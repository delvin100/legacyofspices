<?php
/**
 * Get Live Exchange Rates API
 * Fetches latest rates from open.er-api.com (Free, no key required)
 * Base: USD
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$cacheFile = __DIR__ . '/../../data/exchange_rates.json';
$cacheTime = 24 * 60 * 60; // 24 hours

// 1. Check Cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
    echo file_get_contents($cacheFile);
    exit;
}

// 2. Fetch Live Rates
try {
    // Base: INR
    $url = "https://open.er-api.com/v6/latest/INR";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if (!$data || !isset($data['rates'])) {
        throw new Exception("Invalid API response");
    }

    $result = [
        'success' => true,
        'base' => 'INR',
        'date' => date('Y-m-d'),
        'rates' => $data['rates']
    ];

    // 3. Save to Cache
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }

    $json = json_encode($result);
    file_put_contents($cacheFile, $json);

    echo $json;

} catch (Exception $e) {
    // Fallback if API fails
    // Try to load old cache if exists even if expired
    if (file_exists($cacheFile)) {
        echo file_get_contents($cacheFile);
    } else {
        // Hard fallback
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'rates' => ['INR' => 1] // Minimal fallback
        ]);
    }
}
?>