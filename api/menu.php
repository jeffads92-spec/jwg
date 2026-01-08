<?php
/**
 * Digital by Jeff - Menu Management API
 * Handles: Get Menu, Create, Update, Delete, Categories
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

class MenuAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'list':
                    return $this->getMenuItems();
                    
                case 'get':
                    return $this->getMenuItem();
                    
                case 'create':
                    return $this->createMenuItem();
                    
                case 'update':
                    return $this->updateMenuItem();
                    
                case 'delete':
                    return $this->deleteMenuItem();
                    
                case 'toggle-availability':
                    return $this->toggleAvailability();
                    
                case 'categories':
                    return $this->getCategories();
                    
                case 'category-create':
                    return $this->createCategory();
                    
                case 'category-update':
                    return $this->updateCategory();
                    
                case 'category-delete':
                    return $this->deleteCategory();
                    
                case 'search':
                    return $this->searchMenu();
                    
                case 'featured':
                    return $this->getFeaturedItems();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Menu API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get all menu items with filters
     */
    private function getMenuItems() {
        $categoryId = $_GET['category_id'] ?? null;
        $available = $_GET['available'] ?? null;
        $featured = $_GET['featured'] ?? null;
        $limit = $_GET['limit'] ?? 100;
        $offset = $_GET['offset'] ?? 0;
        
        $sql = "SELECT m.*, c.name as category_name, c.icon as category_icon
                FROM menu_items m
                LEFT JOIN categories c ON m.category_id = c.id
                WHERE 1=1";
        
        $params = [];
        
        if ($categoryId) {
            $sql .= " AND m.category_id = ?";
            $params[] = $categoryId;
        }
        
        if ($available !== null) {
            $sql .= " AND m.is_available = ?";
            $params[] = $available;
        }
        
        if ($featured !== null) {
            $sql .= " AND m.is_featured = ?";
            $params[] = $featured;
        }
        
        $sql .= " ORDER BY c.sort_order, m.name LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        
        $items = $this->db->fetchAll($sql, $params);
        
        // Parse JSON fields
        foreach ($items as &$item) {
            $item['allergens'] = json_decode($item['allergens'] ?? '[]');
            $item['tags'] = json_decode($item['tags'] ?? '[]');
            $item['price'] = (float)$item['price'];
            $item['cost_price'] = (float)$item['cost_price'];
        }
        
        return $this->success($items);
    }
    
    /**
     * Get single menu item
     */
    private function getMenuItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Menu item ID is required', 400);
        }
        
        $sql = "SELECT m.*, c.name as category_name 
                FROM menu_items m
                LEFT JOIN categories c ON m.category_id = c.id
                WHERE m.id = ?";
        
        $item = $this->db->fetchOne($sql, [$id]);
        
        if (!$item) {
            return $this->error('Menu item not found', 404);
        }
        
        // Parse JSON fields
        $item['allergens'] = json_decode($item['allergens'] ?? '[]');
        $item['tags'] = json_decode($item['tags'] ?? '[]');
        
        // Get recipe ingredients
        $sql = "SELECT ri.*, i.item_name, i.unit as inventory_unit
                FROM recipe_ingredients ri
                LEFT JOIN inventory i ON ri.inventory_item_id = i.id
                WHERE ri.menu_item_id = ?";
        
        $item['ingredients'] = $this->db->fetchAll($sql, [$id]);
        
        return $this->success($item);
    }
    
    /**
     * Create menu item
     */
    private function createMenuItem() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['category_id', 'name', 'price'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return $this->error("Field {$field} is required", 400);
            }
        }
        
        // Handle image upload
        $imagePath = null;
        if (isset($data['image_base64']) && !empty($data['image_base64'])) {
            $imagePath = $this->saveBase64Image($data['image_base64'], 'menu');
        }
        
        // Prepare data
        $menuData = [
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'cost_price' => $data['cost_price'] ?? null,
            'image' => $imagePath,
            'is_available' => $data['is_available'] ?? 1,
            'is_featured' => $data['is_featured'] ?? 0,
            'preparation_time' => $data['preparation_time'] ?? 15,
            'stock_quantity' => $data['stock_quantity'] ?? null,
            'calories' => $data['calories'] ?? null,
            'spicy_level' => $data['spicy_level'] ?? 'none',
            'allergens' => json_encode($data['allergens'] ?? []),
            'tags' => json_encode($data['tags'] ?? [])
        ];
        
        $menuId = $this->db->insert('menu_items', $menuData);
        
        // Add recipe ingredients if provided
        if (isset($data['ingredients']) && is_array($data['ingredients'])) {
            foreach ($data['ingredients'] as $ingredient) {
                $this->db->insert('recipe_ingredients', [
                    'menu_item_id' => $menuId,
                    'inventory_item_id' => $ingredient['inventory_item_id'],
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit']
                ]);
            }
        }
        
        return $this->success(['id' => $menuId], 'Menu item created successfully', 201);
    }
    
    /**
     * Update menu item
     */
    private function updateMenuItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Menu item ID is required', 400);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Check if item exists
        $existing = $this->db->fetchOne("SELECT * FROM menu_items WHERE id = ?", [$id]);
        if (!$existing) {
            return $this->error('Menu item not found', 404);
        }
        
        // Handle image upload
        if (isset($data['image_base64']) && !empty($data['image_base64'])) {
            $data['image'] = $this->saveBase64Image($data['image_base64'], 'menu');
            
            // Delete old image
            if ($existing['image'] && file_exists(__DIR__ . '/../uploads/' . $existing['image'])) {
                unlink(__DIR__ . '/../uploads/' . $existing['image']);
            }
        }
        
        // Remove non-updatable fields
        unset($data['image_base64'], $data['id'], $data['created_at'], $data['ingredients']);
        
        // Encode JSON fields
        if (isset($data['allergens'])) {
            $data['allergens'] = json_encode($data['allergens']);
        }
        if (isset($data['tags'])) {
            $data['tags'] = json_encode($data['tags']);
        }
        
        $this->db->update('menu_items', $data, 'id = ?', [$id]);
        
        return $this->success(null, 'Menu item updated successfully');
    }
    
    /**
     * Delete menu item
     */
    private function deleteMenuItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Menu item ID is required', 400);
        }
        
        // Check if item exists
        $item = $this->db->fetchOne("SELECT image FROM menu_items WHERE id = ?", [$id]);
        if (!$item) {
            return $this->error('Menu item not found', 404);
        }
        
        // Delete image
        if ($item['image'] && file_exists(__DIR__ . '/../uploads/' . $item['image'])) {
            unlink(__DIR__ . '/../uploads/' . $item['image']);
        }
        
        $this->db->delete('menu_items', 'id = ?', [$id]);
        
        return $this->success(null, 'Menu item deleted successfully');
    }
    
    /**
     * Toggle menu item availability
     */
    private function toggleAvailability() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Menu item ID is required', 400);
        }
        
        $item = $this->db->fetchOne("SELECT is_available FROM menu_items WHERE id = ?", [$id]);
        if (!$item) {
            return $this->error('Menu item not found', 404);
        }
        
        $newStatus = $item['is_available'] ? 0 : 1;
        $this->db->update('menu_items', ['is_available' => $newStatus], 'id = ?', [$id]);
        
        return $this->success(['is_available' => $newStatus], 'Availability updated');
    }
    
    /**
     * Get all categories
     */
    private function getCategories() {
        $sql = "SELECT c.*, 
                COUNT(m.id) as item_count,
                SUM(CASE WHEN m.is_available = 1 THEN 1 ELSE 0 END) as available_count
                FROM categories c
                LEFT JOIN menu_items m ON c.id = m.category_id
                WHERE c.is_active = 1
                GROUP BY c.id
                ORDER BY c.sort_order, c.name";
        
        $categories = $this->db->fetchAll($sql);
        
        return $this->success($categories);
    }
    
    /**
     * Create category
     */
    private function createCategory() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['name'])) {
            return $this->error('Category name is required', 400);
        }
        
        $categoryData = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? 1
        ];
        
        $categoryId = $this->db->insert('categories', $categoryData);
        
        return $this->success(['id' => $categoryId], 'Category created successfully', 201);
    }
    
    /**
     * Update category
     */
    private function updateCategory() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Category ID is required', 400);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        unset($data['id'], $data['created_at']);
        
        $this->db->update('categories', $data, 'id = ?', [$id]);
        
        return $this->success(null, 'Category updated successfully');
    }
    
    /**
     * Delete category
     */
    private function deleteCategory() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Category ID is required', 400);
        }
        
        // Check if category has items
        $itemCount = $this->db->fetchOne("SELECT COUNT(*) as count FROM menu_items WHERE category_id = ?", [$id]);
        
        if ($itemCount['count'] > 0) {
            return $this->error('Cannot delete category with menu items', 400);
        }
        
        $this->db->delete('categories', 'id = ?', [$id]);
        
        return $this->success(null, 'Category deleted successfully');
    }
    
    /**
     * Search menu items
     */
    private function searchMenu() {
        $query = $_GET['q'] ?? '';
        
        if (strlen($query) < 2) {
            return $this->error('Search query too short', 400);
        }
        
        $sql = "SELECT m.*, c.name as category_name
                FROM menu_items m
                LEFT JOIN categories c ON m.category_id = c.id
                WHERE m.name LIKE ? OR m.description LIKE ?
                AND m.is_available = 1
                LIMIT 20";
        
        $searchTerm = "%{$query}%";
        $items = $this->db->fetchAll($sql, [$searchTerm, $searchTerm]);
        
        return $this->success($items);
    }
    
    /**
     * Get featured menu items
     */
    private function getFeaturedItems() {
        $sql = "SELECT m.*, c.name as category_name
                FROM menu_items m
                LEFT JOIN categories c ON m.category_id = c.id
                WHERE m.is_featured = 1 AND m.is_available = 1
                ORDER BY RAND()
                LIMIT 10";
        
        $items = $this->db->fetchAll($sql);
        
        return $this->success($items);
    }
    
    /**
     * Save base64 image
     */
    private function saveBase64Image($base64String, $folder) {
        // Remove data:image part
        if (strpos($base64String, ',') !== false) {
            list($type, $data) = explode(',', $base64String);
            $data = base64_decode($data);
        } else {
            $data = base64_decode($base64String);
        }
        
        // Generate filename
        $filename = uniqid() . '_' . time() . '.jpg';
        $uploadPath = __DIR__ . "/../uploads/{$folder}/";
        
        // Create directory if not exists
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }
        
        $filePath = $uploadPath . $filename;
        file_put_contents($filePath, $data);
        
        return "{$folder}/{$filename}";
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

$api = new MenuAPI();
$api->handleRequest();
?>
