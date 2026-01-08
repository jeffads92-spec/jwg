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
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // Log successful connection (hanya untuk debugging)
            error_log("Database connected successfully to {$this->host}:{$this->port}/{$this->db_name}");
            
        } catch(PDOException $e) {
            // Log error (jangan tampilkan password!)
            error_log("Connection Error: " . $e->getMessage());
            error_log("Host: {$this->host}, Port: {$this->port}, Database: {$this->db_name}, User: {$this->username}");
            
            // Throw exception untuk handling di level atas
            throw new Exception("Database connection failed. Please check configuration.");
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
            'user' => $this->username
            // NEVER return password!
        ];
    }

    /**
     * Test connection
     */
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            return $conn !== null;
        } catch (Exception $e) {
            return false;
        }
    }
}

// Load .env file jika ada (untuk local development)
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
        putenv(trim($key) . '=' . trim($value));
    }
}
?>
