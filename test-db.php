<?php
/**
 * Database Connection Test
 * Access: https://jwg-production.up.railway.app/test-connection.php
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';

$result = [
    'status' => 'unknown',
    'message' => '',
    'connection_info' => [],
    'database_check' => [],
    'timestamp' => date('Y-m-d H:i:s')
];

try {
    $database = new Database();
    
    // Get connection info
    $result['connection_info'] = $database->getConnectionInfo();
    
    // Test connection
    $conn = $database->getConnection();
    
    if ($conn) {
        $result['status'] = 'success';
        $result['message'] = 'Database connection successful!';
        
        // Check tables exist
        $tables = ['menu_items', 'categories', 'orders', 'order_items', 'users'];
        foreach ($tables as $table) {
            $stmt = $conn->query("SHOW TABLES LIKE '{$table}'");
            $exists = $stmt->rowCount() > 0;
            
            $result['database_check'][$table] = [
                'exists' => $exists,
                'status' => $exists ? '✅' : '❌'
            ];
            
            // If table exists, count rows
            if ($exists) {
                $stmt = $conn->query("SELECT COUNT(*) as count FROM {$table}");
                $count = $stmt->fetch()['count'];
                $result['database_check'][$table]['row_count'] = $count;
            }
        }
        
        http_response_code(200);
    } else {
        throw new Exception('Failed to get database connection');
    }
    
} catch (Exception $e) {
    $result['status'] = 'error';
    $result['message'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($result, JSON_PRETTY_PRINT);
?>
