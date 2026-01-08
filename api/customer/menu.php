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
    
    if ($action === 'list') {
        $category = $_GET['category'] ?? 'all';
        
        $sql = "SELECT m.*, c.name as category 
                FROM menu_items m 
                LEFT JOIN categories c ON m.category_id = c.id 
                WHERE m.is_available = 1";
        
        if ($category !== 'all') {
            $sql .= " AND m.category_id = :category";
        }
        
        $sql .= " ORDER BY c.name, m.name";
        
        $stmt = $conn->prepare($sql);
        if ($category !== 'all') {
            $stmt->bindValue(':category', $category);
        }
        $stmt->execute();
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format image URLs
        foreach ($items as &$item) {
            if ($item['image'] && strpos($item['image'], 'http') !== 0) {
                // If image is relative path, make it absolute
                $item['image'] = $item['image'];
            }
        }
        
        $response['success'] = true;
        $response['data'] = $items;
        
    } elseif ($action === 'categories') {
        $stmt = $conn->query("
            SELECT c.*, COUNT(m.id) as item_count
            FROM categories c
            LEFT JOIN menu_items m ON c.id = m.category_id AND m.is_available = 1
            WHERE c.is_active = 1
            GROUP BY c.id
            HAVING item_count > 0
            ORDER BY c.name
        ");
        
        $response['success'] = true;
        $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        $response['message'] = 'Invalid action';
        http_response_code(400);
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Customer Menu API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
