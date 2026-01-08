<?php
/**
 * Digital by Jeff - Customer Menu API
 * Public API for customer-facing menu (QR ordering)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

class CustomerMenuAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? 'list';
        
        try {
            switch ($action) {
                case 'list':
                    return $this->getMenu();
                    
                case 'categories':
                    return $this->getCategories();
                    
                case 'item':
                    return $this->getMenuItem();
                    
                case 'featured':
                    return $this->getFeatured();
                    
                case 'search':
                    return $this->searchMenu();
                    
                case 'table-info':
                    return $this->getTableInfo();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Customer Menu API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get all available menu items
     */
    private function getMenu() {
        $categoryId = $_GET['category_id'] ?? null;
        
        $sql = "SELECT m.*, c.name as category_name, c.icon as category_icon
                FROM menu_items m
                JOIN categories c ON m.category_id = c.id
                WHERE m.is_available = 1 AND c.is_active = 1";
        
        $params = [];
        
        if ($categoryId) {
            $sql .= " AND m.category_id = ?";
            $params[] = $categoryId;
        }
        
        $sql .= " ORDER BY c.sort_order, m.name";
        
        $items = $this->db->fetchAll($sql, $params);
        
        // Format data for customer display
        foreach ($items as &$item) {
            $item['price'] = (float)$item['price'];
            $item['allergens'] = json_decode($item['allergens'] ?? '[]');
            $item['tags'] = json_decode($item['tags'] ?? '[]');
            
            // Add image URL
            if ($item['image']) {
                $item['image_url'] = $this->getImageUrl($item['image']);
            }
            
            // Remove internal fields
            unset($item['cost_price'], $item['stock_quantity']);
        }
        
        // Group by category
        $grouped = [];
        foreach ($items as $item) {
            $categoryId = $item['category_id'];
            if (!isset($grouped[$categoryId])) {
                $grouped[$categoryId] = [
                    'category_id' => $categoryId,
                    'category_name' => $item['category_name'],
                    'category_icon' => $item['category_icon'],
                    'items' => []
                ];
            }
            $grouped[$categoryId]['items'][] = $item;
        }
        
        return $this->success(array_values($grouped));
    }
    
    /**
     * Get all categories
     */
    private function getCategories() {
        $sql = "SELECT c.*, COUNT(m.id) as available_items
                FROM categories c
                LEFT JOIN menu_items m ON c.id = m.category_id AND m.is_available = 1
                WHERE c.is_active = 1
                GROUP BY c.id
                HAVING available_items > 0
                ORDER BY c.sort_order";
        
        $categories = $this->db->fetchAll($sql);
        
        return $this->success($categories);
    }
    
    /**
     * Get single menu item
     */
    private function getMenuItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Item ID is required', 400);
        }
        
        $sql = "SELECT m.*, c.name as category_name
                FROM menu_items m
                JOIN categories c ON m.category_id = c.id
                WHERE m.id = ? AND m.is_available = 1";
        
        $item = $this->db->fetchOne($sql, [$id]);
        
        if (!$item) {
            return $this->error('Item not found or unavailable', 404);
        }
        
        // Format data
        $item['price'] = (float)$item['price'];
        $item['allergens'] = json_decode($item['allergens'] ?? '[]');
        $item['tags'] = json_decode($item['tags'] ?? '[]');
        
        if ($item['image']) {
            $item['image_url'] = $this->getImageUrl($item['image']);
        }
        
        // Remove internal fields
        unset($item['cost_price'], $item['stock_quantity']);
        
        return $this->success($item);
    }
    
    /**
     * Get featured items
     */
    private function getFeatured() {
        $sql = "SELECT m.*, c.name as category_name
                FROM menu_items m
                JOIN categories c ON m.category_id = c.id
                WHERE m.is_featured = 1 AND m.is_available = 1 AND c.is_active = 1
                ORDER BY RAND()
                LIMIT 6";
        
        $items = $this->db->fetchAll($sql);
        
        foreach ($items as &$item) {
            $item['price'] = (float)$item['price'];
            $item['allergens'] = json_decode($item['allergens'] ?? '[]');
            $item['tags'] = json_decode($item['tags'] ?? '[]');
            
            if ($item['image']) {
                $item['image_url'] = $this->getImageUrl($item['image']);
            }
            
            unset($item['cost_price'], $item['stock_quantity']);
        }
        
        return $this->success($items);
    }
    
    /**
     * Search menu
     */
    private function searchMenu() {
        $query = $_GET['q'] ?? '';
        
        if (strlen($query) < 2) {
            return $this->error('Search query too short', 400);
        }
        
        $sql = "SELECT m.*, c.name as category_name
                FROM menu_items m
                JOIN categories c ON m.category_id = c.id
                WHERE (m.name LIKE ? OR m.description LIKE ?)
                AND m.is_available = 1 AND c.is_active = 1
                LIMIT 20";
        
        $searchTerm = "%{$query}%";
        $items = $this->db->fetchAll($sql, [$searchTerm, $searchTerm]);
        
        foreach ($items as &$item) {
            $item['price'] = (float)$item['price'];
            if ($item['image']) {
                $item['image_url'] = $this->getImageUrl($item['image']);
            }
            unset($item['cost_price'], $item['stock_quantity']);
        }
        
        return $this->success($items);
    }
    
    /**
     * Get table information
     */
    private function getTableInfo() {
        $tableId = $_GET['table_id'] ?? null;
        
        if (!$tableId) {
            return $this->error('Table ID is required', 400);
        }
        
        $sql = "SELECT id, table_number, capacity, location, status
                FROM tables
                WHERE id = ?";
        
        $table = $this->db->fetchOne($sql, [$tableId]);
        
        if (!$table) {
            return $this->error('Table not found', 404);
        }
        
        // Check if table is available for ordering
        if ($table['status'] === 'cleaning') {
            return $this->error('Table is currently being cleaned', 400);
        }
        
        // Get restaurant info
        $settings = $this->getRestaurantSettings();
        
        return $this->success([
            'table' => $table,
            'restaurant' => $settings
        ]);
    }
    
    /**
     * Get restaurant settings
     */
    private function getRestaurantSettings() {
        $sql = "SELECT setting_key, setting_value 
                FROM settings 
                WHERE setting_key IN ('restaurant_name', 'restaurant_phone', 'currency', 'tax_percentage', 'service_charge_percentage')";
        
        $settings = $this->db->fetchAll($sql);
        
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['setting_value'];
        }
        
        return $result;
    }
    
    /**
     * Get image URL
     */
    private function getImageUrl($imagePath) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
        
        return "{$protocol}://{$host}{$basePath}/uploads/{$imagePath}";
    }
    
    /**
     * Success response
     */
    private function success($data, $message = 'Success', $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
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
            'data' => null,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit();
    }
}

$api = new CustomerMenuAPI();
$api->handleRequest();
?>
