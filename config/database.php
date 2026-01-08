<?php
/**
 * Database Configuration for Railway Deployment
 * Supports both local development and production
 */

class Database {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        // Railway Environment Variables (Production)
        // Fallback ke .env atau default values untuk local development
        
        // Priority: Environment Variables > .env file > defaults
        $this->host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'localhost');
        $this->port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
        $this->db_name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'jwg_resto');
        $this->username = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'root');
        $this->password = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');

        // Railway biasanya provide MYSQL_URL juga, bisa dipakai sebagai fallback
        $mysql_url = getenv('MYSQL_URL');
        if ($mysql_url && !getenv('DB_HOST')) {
            $this->parseConnectionString($mysql_url);
        }
    }

    /**
     * Parse MySQL URL format: mysql://user:pass@host:port/database
     */
    private function parseConnectionString($url) {
        $parsed = parse_url($url);
        if ($parsed) {
            $this->host = $parsed['host'] ?? 'localhost';
            $this->port = $parsed['port'] ?? '3306';
            $this->username = $parsed['user'] ?? 'root';
            $this->password = $parsed['pass'] ?? '';
            $this->db_name = ltrim($parsed['path'] ?? '/jwg_resto', '/');
        }
    }

    /**
     * Get database connection
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_TIMEOUT => 5 // 5 second timeout
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // Log successful connection (hanya untuk debugging)
            error_log("✅ Database connected successfully to {$this->host}:{$this->port}/{$this->db_name}");
            
        } catch(PDOException $e) {
            // Enhanced error logging
            error_log("❌ DATABASE CONNECTION FAILED!");
            error_log("Error: " . $e->getMessage());
            error_log("Host: {$this->host}");
            error_log("Port: {$this->port}");
            error_log("Database: {$this->db_name}");
            error_log("User: {$this->username}");
            error_log("Password length: " . strlen($this->password) . " chars");
            
            // Throw exception untuk handling di level atas
            throw new Exception("Database connection failed: " . $e->getMessage());
        }

        return $this->conn;
    }

    /**
     * Get connection info (for debugging)
     */
    public function getConnectionInfo() {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->db_name,
            'user' => $this->username,
            'password_set' => !empty($this->password)
            // NEVER return actual password!
        ];
    }

    /**
     * Test connection
     */
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            if ($conn) {
                // Test with a simple query
                $stmt = $conn->query("SELECT 1");
                return $stmt !== false;
            }
            return false;
        } catch (Exception $e) {
            error_log("Connection test failed: " . $e->getMessage());
            return false;
        }
    }
}

// Load .env file jika ada (untuk local development)
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        // Remove quotes if present
        $value = trim($value, '"\'');
        
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
    
    error_log("✅ .env file loaded");
}
?>
