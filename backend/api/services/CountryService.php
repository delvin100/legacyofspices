<?php
/**
 * Country Service
 * Handles fetching list of countries and their currency data
 */

class CountryService
{
    private static $apiUrl = "https://restcountries.com/v3.1/all?fields=name,currencies,cca2";

    public static function getAllCountries()
    {
        $cacheFile = __DIR__ . '/../../data/countries_cache.json';

        // Cache for 24 hours to reduce API calls
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::$apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }

            curl_close($ch);

            $countries = json_decode($response, true);

            if (!$countries) {
                return [];
            }

            // Simplify and sort
            $processed = array_map(function ($country) {
                $currencyCode = "";
                $currencySymbol = "";

                if (!empty($country['currencies'])) {
                    $keys = array_keys($country['currencies']);
                    $firstKey = $keys[0];
                    $currencyCode = $firstKey;
                    $currencySymbol = $country['currencies'][$firstKey]['symbol'] ?? $firstKey;
                }

                return [
                    'name' => $country['name']['common'],
                    'code' => $country['cca2'],
                    'currency_code' => $currencyCode,
                    'currency_symbol' => $currencySymbol
                ];
            }, $countries);

            usort($processed, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });

            // Save to cache
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0777, true);
            }
            file_put_contents($cacheFile, json_encode($processed));

            return $processed;
        } catch (Exception $e) {
            error_log("CountryService Error: " . $e->getMessage());
            return [];
        }
    }

    public static function searchCountries($query)
    {
        $countries = self::getAllCountries();
        $query = strtolower(trim($query));

        if (empty($query))
            return [];

        return array_values(array_filter($countries, function ($country) use ($query) {
            return strpos(strtolower($country['name']), $query) === 0;
        }));
    }
}
?>