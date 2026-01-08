<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

$action = $_GET['action'] ?? 'list';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    if ($action === 'list') {
        $category = $_GET['category'] ?? 'all';
        
        $sql = "SELECT m.*, c.name as category_name 
                FROM menu_items m 
                LEFT JOIN categories c ON m.category_id = c.id 
                WHERE m.is_available = 1";
        
        if ($category !== 'all' && !empty($category)) {
            $sql .= " AND m.category_id = :category";
        }
        
        $sql .= " ORDER BY c.name, m.name";
        
        $stmt = $conn->prepare($sql);
        if ($category !== 'all' && !empty($category)) {
            $stmt->bindValue(':category', $category);
        }
        $stmt->execute();
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log untuk debugging
        error_log("Menu items count: " . count($items));
        
        // Format data
        foreach ($items as &$item) {
            $item['price'] = floatval($item['price']);
            $item['is_available'] = (bool)$item['is_available'];
            
            // Fix image URL
            if (!empty($item['image'])) {
                if (strpos($item['image'], 'http') !== 0) {
                    // Relative path, keep as is
                    $item['image_url'] = $item['image'];
                } else {
                    $item['image_url'] = $item['image'];
                }
            } else {
                $item['image_url'] = '/assets/no-image.png';
            }
        }
        
        $response['success'] = true;
        $response['data'] = $items;
        $response['count'] = count($items);
        
    } elseif ($action === 'categories') {
        $stmt = $conn->query("
            SELECT c.*, COUNT(m.id) as item_count
            FROM categories c
            LEFT JOIN menu_items m ON c.id = m.category_id AND m.is_available = 1
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.name
        ");
        
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Categories count: " . count($categories));
        
        $response['success'] = true;
        $response['data'] = $categories;
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Customer Menu API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
}

echo json_encode($response);
?>
