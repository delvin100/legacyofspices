<?php
/**
 * Currency Service
 * Handles live exchange rates and price conversion
 */

class CurrencyService
{
    private static $apiUrl = "https://open.er-api.com/v6/latest/"; // Base URL
    const BASE_CURRENCY = 'INR';

    public static function getExchangeRates($baseCurrency = self::BASE_CURRENCY)
    {
        $cacheFile = __DIR__ . '/../../data/exchange_rates.json';

        // 1. Try to load from shared file cache first (synced with frontend)
        if (file_exists($cacheFile)) {
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            // Check if file is valid, has rates, and matches the requested base
            if ($cachedData && isset($cachedData['rates']) && ($cachedData['base'] ?? '') === $baseCurrency) {
                // If it's within 24 hours, use it
                if (time() - filemtime($cacheFile) < 86400) {
                    return $cachedData['rates'];
                }
            }
        }

        // 2. Fallback to session if file is old or missing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $cacheKey = "rates_" . $baseCurrency;
        if (isset($_SESSION[$cacheKey]) && (time() - $_SESSION[$cacheKey . '_time'] < 86400)) {
            return $_SESSION[$cacheKey];
        }

        // 3. Last resort: Live API fetch
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::$apiUrl . $baseCurrency);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }

            curl_close($ch);

            $data = json_decode($response, true);

            if (!$data || $data['result'] !== 'success') {
                throw new Exception("API failed to return rates");
            }

            $_SESSION[$cacheKey] = $data['rates'];
            $_SESSION[$cacheKey . '_time'] = time();

            return $data['rates'];
        } catch (Exception $e) {
            error_log("CurrencyService Error: " . $e->getMessage());
            return [];
        }
    }

    public static function convert($amount, $fromCurrency, $toCurrency)
    {
        if ($fromCurrency === $toCurrency)
            return $amount;

        // Unified Base: Always fetch rates with INR as base to ensure symmetry with frontend
        $rates = self::getExchangeRates(self::BASE_CURRENCY);

        if (empty($rates)) {
            return $amount; // Default to no conversion if API fails
        }

        // 1. Convert source to INR
        $amountInInr = $amount;
        if ($fromCurrency !== self::BASE_CURRENCY) {
            if (!isset($rates[$fromCurrency]))
                return $amount;
            $amountInInr = $amount / $rates[$fromCurrency];
        }

        // 2. Convert INR to destination
        if ($toCurrency === self::BASE_CURRENCY) {
            return $amountInInr;
        }

        if (!isset($rates[$toCurrency]))
            return $amountInInr;
        return $amountInInr * $rates[$toCurrency];
    }

    public static function formatPrice($amount, $currencySymbol, $currencyCode)
    {
        return $currencySymbol . " " . number_format($amount, 2) . " " . $currencyCode;
    }
}
?>