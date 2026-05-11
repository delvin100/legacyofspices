<?php
/**
 * Database Configuration
 * Ultra-Internal Railway Optimization
 */

require_once 'env.php';

// 1. Try Internal Railway Variables FIRST (Highest Reliability)
if (getenv('MYSQLHOST')) {
    define('DB_HOST', getenv('MYSQLHOST'));
    define('DB_PORT', getenv('MYSQLPORT') ?: '3306');
    define('DB_USER', getenv('MYSQLUSER') ?: 'root');
    define('DB_PASS', getenv('MYSQLPASSWORD') ?: '');
    define('DB_NAME', getenv('MYSQL_DATABASE') ?: (getenv('MYSQLDATABASE') ?: 'railway'));
} 
// 2. Fallback to MYSQL_URL
else if (getenv('MYSQL_URL')) {
    $url = parse_url(getenv('MYSQL_URL'));
    define('DB_HOST', $url['host']);
    define('DB_PORT', $url['port'] ?? '3306');
    define('DB_USER', $url['user'] ?? 'root');
    define('DB_PASS', $url['pass'] ?? '');
    define('DB_NAME', ltrim($url['path'], '/') ?: 'railway');
}
// 3. Last Resort: Local/Env
else {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '3306');
    define('DB_USER', getenv('DB_USER') ?: 'root');
    define('DB_PASS', getenv('DB_PASS') ?: '');
    define('DB_NAME', getenv('DB_NAME') ?: 'railway');
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
            PDO::ATTR_TIMEOUT => 10,
        ];

        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        error_log("CRITICAL: Database Connection Failed!");
        error_log("TARGET -> Host: " . DB_HOST . ", Port: " . DB_PORT . ", DB: " . DB_NAME . ", User: " . DB_USER);
        error_log("REASON -> " . $e->getMessage());
        throw new Exception("Database connection failed. Please verify your Railway environment variables.");
    }
}
?>
