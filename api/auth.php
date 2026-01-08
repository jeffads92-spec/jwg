<?php
/**
 * Digital by Jeff - Authentication API
 * Handles: Login, Logout, Register, Token Validation
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

// JWT Secret Key
define('JWT_SECRET', getenv('JWT_SECRET') ?? 'jwg_resto_secret_key_2024');
define('JWT_EXPIRY', 86400); // 24 hours

class AuthAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Handle incoming requests
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'login':
                    return $this->login();
                    
                case 'register':
                    return $this->register();
                    
                case 'logout':
                    return $this->logout();
                    
                case 'verify':
                    return $this->verifyToken();
                    
                case 'refresh':
                    return $this->refreshToken();
                    
                case 'change-password':
                    return $this->changePassword();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Login user
     */
    private function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            return $this->error('Username and password are required', 400);
        }
        
        // Find user
        $sql = "SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1";
        $user = $this->db->fetchOne($sql, [$username]);
        
        if (!$user) {
            return $this->error('Invalid credentials', 401);
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            // Log failed attempt
            $this->logActivity($user['id'], 'login_failed', 'user', $user['id']);
            return $this->error('Invalid credentials', 401);
        }
        
        // Update last login
        $this->db->update('users', 
            ['last_login' => date('Y-m-d H:i:s')],
            'id = ?',
            [$user['id']]
        );
        
        // Generate JWT token
        $token = $this->generateToken($user);
        
        // Log successful login
        $this->logActivity($user['id'], 'login', 'user', $user['id']);
        
        // Remove password from response
        unset($user['password']);
        
        return $this->success([
            'token' => $token,
            'user' => $user,
            'expires_in' => JWT_EXPIRY
        ], 'Login successful');
    }
    
    /**
     * Register new user
     */
    private function register() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['username', 'email', 'password', 'full_name'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->error("Field {$field} is required", 400);
            }
        }
        
        // Check if username exists
        $existingUser = $this->db->fetchOne(
            "SELECT id FROM users WHERE username = ? OR email = ?",
            [$data['username'], $data['email']]
        );
        
        if ($existingUser) {
            return $this->error('Username or email already exists', 409);
        }
        
        // Hash password
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        // Insert user
        $userId = $this->db->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $hashedPassword,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'] ?? 'cashier'
        ]);
        
        // Get user data
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
        unset($user['password']);
        
        // Log activity
        $this->logActivity($userId, 'register', 'user', $userId);
        
        return $this->success([
            'user' => $user
        ], 'Registration successful', 201);
    }
    
    /**
     * Logout user
     */
    private function logout() {
        $token = $this->getBearerToken();
        
        if ($token) {
            $payload = $this->verifyTokenString($token);
            if ($payload) {
                $this->logActivity($payload['user_id'], 'logout', 'user', $payload['user_id']);
            }
        }
        
        return $this->success(null, 'Logout successful');
    }
    
    /**
     * Verify JWT token
     */
    private function verifyToken() {
        $token = $this->getBearerToken();
        
        if (!$token) {
            return $this->error('Token not provided', 401);
        }
        
        $payload = $this->verifyTokenString($token);
        
        if (!$payload) {
            return $this->error('Invalid or expired token', 401);
        }
        
        // Get user data
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1", [$payload['user_id']]);
        
        if (!$user) {
            return $this->error('User not found', 404);
        }
        
        unset($user['password']);
        
        return $this->success([
            'user' => $user,
            'token' => $token
        ], 'Token is valid');
    }
    
    /**
     * Refresh token
     */
    private function refreshToken() {
        $token = $this->getBearerToken();
        
        if (!$token) {
            return $this->error('Token not provided', 401);
        }
        
        $payload = $this->verifyTokenString($token);
        
        if (!$payload) {
            return $this->error('Invalid or expired token', 401);
        }
        
        // Get user data
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1", [$payload['user_id']]);
        
        if (!$user) {
            return $this->error('User not found', 404);
        }
        
        // Generate new token
        $newToken = $this->generateToken($user);
        
        return $this->success([
            'token' => $newToken,
            'expires_in' => JWT_EXPIRY
        ], 'Token refreshed successfully');
    }
    
    /**
     * Change password
     */
    private function changePassword() {
        $token = $this->getBearerToken();
        $payload = $this->verifyTokenString($token);
        
        if (!$payload) {
            return $this->error('Unauthorized', 401);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $currentPassword = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            return $this->error('Current and new password are required', 400);
        }
        
        if (strlen($newPassword) < 6) {
            return $this->error('New password must be at least 6 characters', 400);
        }
        
        // Get user
        $user = $this->db->fetchOne("SELECT * FROM users WHERE id = ?", [$payload['user_id']]);
        
        if (!password_verify($currentPassword, $user['password'])) {
            return $this->error('Current password is incorrect', 400);
        }
        
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->update('users',
            ['password' => $hashedPassword],
            'id = ?',
            [$payload['user_id']]
        );
        
        // Log activity
        $this->logActivity($payload['user_id'], 'change_password', 'user', $payload['user_id']);
        
        return $this->success(null, 'Password changed successfully');
    }
    
    /**
     * Generate JWT token
     */
    private function generateToken($user) {
        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + JWT_EXPIRY
        ];
        
        return $this->encodeJWT($payload);
    }
    
    /**
     * Verify JWT token string
     */
    private function verifyTokenString($token) {
        try {
            $payload = $this->decodeJWT($token);
            
            if (!$payload || !isset($payload['exp'])) {
                return false;
            }
            
            if ($payload['exp'] < time()) {
                return false;
            }
            
            return $payload;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Simple JWT encode
     */
    private function encodeJWT($payload) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        
        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);
        
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }
    
    /**
     * Simple JWT decode
     */
    private function decodeJWT($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
        
        $signature = $this->base64UrlDecode($base64UrlSignature);
        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, JWT_SECRET, true);
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        $payload = json_decode($this->base64UrlDecode($base64UrlPayload), true);
        
        return $payload;
    }
    
    /**
     * Base64 URL encode
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     */
    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
    
    /**
     * Get Bearer token from header
     */
    private function getBearerToken() {
        $headers = $this->getAuthorizationHeader();
        
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Get Authorization header
     */
    private function getAuthorizationHeader() {
        $headers = null;
        
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER['Authorization']);
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
        } else if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        
        return $headers;
    }
    
    /**
     * Log activity
     */
    private function logActivity($userId, $action, $entityType = null, $entityId = null) {
        try {
            $this->db->insert('activity_logs', [
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
    
    /**
     * Success response
     */
    private function success($data, $message = 'Success', $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit();
    }
    
    /**
     * Error response
     */
    private function error($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data' => null
        ]);
        exit();
    }
}

// Initialize and handle request
$api = new AuthAPI();
$api->handleRequest();
?>
