<?php
/**
 * Database Configuration
 * Legacy of Spices - Backend Configuration
 */

require_once 'env.php';

// Prioritize Railway Internal Environment Variables
define('DB_HOST', getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_PORT', getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: '3306'));
define('DB_USER', getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'));
define('DB_PASS', getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''));
define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'caravan_db'));
define('DB_CHARSET', 'utf8mb4');

// Create database connection
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
            PDO::ATTR_CASE => PDO::CASE_LOWER,
            PDO::ATTR_TIMEOUT => 5, // 5 second timeout
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // Log details (safely) to help debug 502s
        error_log("Database Connection Error. Host: " . DB_HOST . ", Port: " . DB_PORT . ", DB: " . DB_NAME);
        error_log("PDO Message: " . $e->getMessage());
        throw new Exception("Database connection failed. Please check your Railway environment variables.");
    }
}

// Test connection function
function testConnection()
{
    try {
        $pdo = getDBConnection();
        return ['success' => true, 'message' => 'Database connected successfully'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>
