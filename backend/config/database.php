<?php
/**
 * Database Configuration
 * Ultra-Robust Railway + Local Configuration
 */

require_once 'env.php';

// 1. Try to parse MYSQL_URL (Railway's default connection string)
$mysql_url = getenv('MYSQL_URL');
if ($mysql_url) {
    $url = parse_url($mysql_url);
    define('DB_HOST', $url['host'] ?? 'localhost');
    define('DB_PORT', $url['port'] ?? '3306');
    define('DB_USER', $url['user'] ?? 'root');
    define('DB_PASS', $url['pass'] ?? '');
    define('DB_NAME', ltrim($url['path'], '/') ?: 'railway');
} else {
    // 2. Fallback to individual Railway variables or .env
    define('DB_HOST', getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: 'localhost'));
    define('DB_PORT', getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: '3306'));
    define('DB_USER', getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'));
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''));
    define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'caravan_db'));
}

define('DB_CHARSET', 'utf8mb4');

/**
 * Create database connection
 */
function getDBConnection()
{
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        if (DB_PORT && DB_PORT !== '3306') {
            $dsn .= ";port=" . DB_PORT;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10, // 10 second timeout
        ];

        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log details to Railway logs for debugging
        error_log("Database Connection Failed!");
        error_log("DSN: mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME);
        error_log("PDO Error: " . $e->getMessage());
        
        throw new Exception("Database connection failed. Please verify your Railway environment variables and ensure the MySQL service is running.");
    }
}
?>
