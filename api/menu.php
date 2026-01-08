<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    switch ($action) {
        case 'list':
            $category = $_GET['category'] ?? 'all';
            $search = $_GET['search'] ?? '';
            
            $sql = "SELECT m.*, c.name as category_name 
                    FROM menu_items m 
                    LEFT JOIN categories c ON m.category_id = c.id 
                    WHERE 1=1";
            
            if ($category !== 'all') {
                $sql .= " AND m.category_id = :category";
            }
            if ($search) {
                $sql .= " AND m.name LIKE :search";
            }
            $sql .= " ORDER BY m.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            if ($category !== 'all') {
                $stmt->bindValue(':category', $category);
            }
            if ($search) {
                $stmt->bindValue(':search', "%$search%");
            }
            $stmt->execute();
            
            $response['success'] = true;
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'categories':
            $stmt = $conn->query("SELECT * FROM categories ORDER BY name");
            $response['success'] = true;
            $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'get':
            $id = $_GET['id'] ?? 0;
            $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
            $response['success'] = true;
            $response['data'] = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'create':
            $data = json_decode(file_get_contents("php://input"), true);
            
            $stmt = $conn->prepare("
                INSERT INTO menu_items (name, description, category_id, price, preparation_time, spicy_level, is_available, image, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ");
            
            $stmt->execute([
                $data['name'],
                $data['description'] ?? '',
                $data['category_id'],
                $data['price'],
                $data['preparation_time'] ?? 15,
                $data['spicy_level'] ?? 'none',
                $data['image'] ?? ''
            ]);
            
            $response['success'] = true;
            $response['message'] = 'Menu item created successfully';
            $response['data'] = ['id' => $conn->lastInsertId()];
            break;
            
        case 'update':
            $id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);
            
            $stmt = $conn->prepare("
                UPDATE menu_items 
                SET name = ?, description = ?, category_id = ?, price = ?, 
                    preparation_time = ?, spicy_level = ?, image = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['name'],
                $data['description'] ?? '',
                $data['category_id'],
                $data['price'],
                $data['preparation_time'] ?? 15,
                $data['spicy_level'] ?? 'none',
                $data['image'] ?? '',
                $id
            ]);
            
            $response['success'] = true;
            $response['message'] = 'Menu item updated successfully';
            break;
            
        case 'delete':
            $id = $_GET['id'] ?? 0;
            $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
            $stmt->execute([$id]);
            
            $response['success'] = true;
            $response['message'] = 'Menu item deleted successfully';
            break;
            
        case 'toggle-availability':
            $id = $_GET['id'] ?? 0;
            $stmt = $conn->prepare("UPDATE menu_items SET is_available = NOT is_available WHERE id = ?");
            $stmt->execute([$id]);
            
            $response['success'] = true;
            $response['message'] = 'Availability updated';
            break;
            
        default:
            $response['message'] = 'Invalid action';
            http_response_code(400);
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Menu API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
