<?php
/**
 * Authentication API - Digital by Jeff POS
 * Handles login, register, logout, verify token
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database configuration
require_once __DIR__ . '/../config/database.php';

// Get action from query parameter
$action = $_GET['action'] ?? '';

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Get database connection
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    switch ($action) {
        case 'login':
            handleLogin($conn);
            break;
            
        case 'register':
            handleRegister($conn);
            break;
            
        case 'logout':
            handleLogout($conn);
            break;
            
        case 'verify':
            handleVerify($conn);
            break;
            
        default:
            $response['message'] = 'Invalid action';
            http_response_code(400);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log("Auth API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
exit;

/**
 * Handle Login
 */
function handleLogin($conn) {
    global $response;
    
    try {
        // Get POST data
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!$data) {
            $data = $_POST;
        }
        
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        
        // Validate input
        if (empty($username) || empty($password)) {
            $response['message'] = 'Username and password are required';
            http_response_code(400);
            return;
        }
        
        // Query user
        $stmt = $conn->prepare("
            SELECT id, username, password, full_name, role, is_active 
            FROM users 
            WHERE username = ? 
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if user exists
        if (!$user) {
            $response['message'] = 'Invalid username or password';
            http_response_code(401);
            return;
        }
        
        // Check if user is active
        if (!$user['is_active']) {
            $response['message'] = 'Account is disabled';
            http_response_code(403);
            return;
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            $response['message'] = 'Invalid username or password';
            http_response_code(401);
            return;
        }
        
        // Generate JWT token
        $token = generateJWT($user);
        
        // Update last login
        $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Log activity
        logActivity($conn, $user['id'], 'login', 'User logged in');
        
        // Success response
        $response['success'] = true;
        $response['message'] = 'Login successful';
        $response['data'] = [
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ];
        http_response_code(200);
        
    } catch (Exception $e) {
        $response['message'] = 'Login failed: ' . $e->getMessage();
        error_log("Login Error: " . $e->getMessage());
        http_response_code(500);
    }
}

/**
 * Handle Register (Admin only)
 */
function handleRegister($conn) {
    global $response;
    
    try {
        // Verify admin token
        $user = verifyToken();
        if (!$user || $user['role'] !== 'admin') {
            $response['message'] = 'Unauthorized';
            http_response_code(403);
            return;
        }
        
        // Get POST data
        $data = json_decode(file_get_contents("php://input"), true);
        
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $full_name = trim($data['full_name'] ?? '');
        $role = $data['role'] ?? 'cashier';
        
        // Validate input
        if (empty($username) || empty($password) || empty($full_name)) {
            $response['message'] = 'All fields are required';
            http_response_code(400);
            return;
        }
        
        // Check if username exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $response['message'] = 'Username already exists';
            http_response_code(409);
            return;
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        
        // Insert user
        $stmt = $conn->prepare("
            INSERT INTO users (username, password, full_name, role, is_active, created_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$username, $hashedPassword, $full_name, $role]);
        
        $newUserId = $conn->lastInsertId();
        
        // Log activity
        logActivity($conn, $user['id'], 'create_user', "Created new user: $username");
        
        $response['success'] = true;
        $response['message'] = 'User registered successfully';
        $response['data'] = ['user_id' => $newUserId];
        http_response_code(201);
        
    } catch (Exception $e) {
        $response['message'] = 'Registration failed: ' . $e->getMessage();
        error_log("Register Error: " . $e->getMessage());
        http_response_code(500);
    }
}

/**
 * Handle Logout
 */
function handleLogout($conn) {
    global $response;
    
    try {
        $user = verifyToken();
        if ($user) {
            logActivity($conn, $user['id'], 'logout', 'User logged out');
        }
        
        $response['success'] = true;
        $response['message'] = 'Logout successful';
        http_response_code(200);
        
    } catch (Exception $e) {
        $response['message'] = 'Logout failed';
        http_response_code(500);
    }
}

/**
 * Handle Verify Token
 */
function handleVerify($conn) {
    global $response;
    
    try {
        $user = verifyToken();
        
        if (!$user) {
            $response['message'] = 'Invalid or expired token';
            http_response_code(401);
            return;
        }
        
        // Get fresh user data
        $stmt = $conn->prepare("
            SELECT id, username, full_name, role, is_active 
            FROM users 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$userData) {
            $response['message'] = 'User not found or inactive';
            http_response_code(401);
            return;
        }
        
        $response['success'] = true;
        $response['message'] = 'Token is valid';
        $response['data'] = [
            'user' => $userData
        ];
        http_response_code(200);
        
    } catch (Exception $e) {
        $response['message'] = 'Verification failed';
        error_log("Verify Error: " . $e->getMessage());
        http_response_code(401);
    }
}

/**
 * Generate JWT Token
 */
function generateJWT($user) {
    $secret = getenv('JWT_SECRET') ?: 'your-secret-key-change-this';
    
    $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    
    $payload = base64_encode(json_encode([
        'id' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'iat' => time(),
        'exp' => time() + (86400 * 7) // 7 days
    ]));
    
    $signature = base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    
    return "$header.$payload.$signature";
}

/**
 * Verify JWT Token
 */
function verifyToken() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (empty($authHeader)) {
        return null;
    }
    
    $token = str_replace('Bearer ', '', $authHeader);
    $parts = explode('.', $token);
    
    if (count($parts) !== 3) {
        return null;
    }
    
    list($header, $payload, $signature) = $parts;
    
    $secret = getenv('JWT_SECRET') ?: 'your-secret-key-change-this';
    $validSignature = base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    
    if ($signature !== $validSignature) {
        return null;
    }
    
    $payloadData = json_decode(base64_decode($payload), true);
    
    if ($payloadData['exp'] < time()) {
        return null;
    }
    
    return $payloadData;
}

/**
 * Log Activity
 */
function logActivity($conn, $userId, $action, $description) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $userId, 
            $action, 
            $description, 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Log Activity Error: " . $e->getMessage());
    }
}
?>
