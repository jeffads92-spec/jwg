<?php
/**
 * Digital by Jeff - Inventory Management API
 * Handles: Stock management, movements, alerts, recipes
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

class InventoryAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'list':
                    return $this->getInventory();
                    
                case 'get':
                    return $this->getItem();
                    
                case 'create':
                    return $this->createItem();
                    
                case 'update':
                    return $this->updateItem();
                    
                case 'delete':
                    return $this->deleteItem();
                    
                case 'adjust-stock':
                    return $this->adjustStock();
                    
                case 'restock':
                    return $this->restock();
                    
                case 'low-stock':
                    return $this->getLowStock();
                    
                case 'movements':
                    return $this->getMovements();
                    
                case 'recipe':
                    return $this->getRecipe();
                    
                case 'recipe-save':
                    return $this->saveRecipe();
                    
                case 'statistics':
                    return $this->getStatistics();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Inventory API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get all inventory items
     */
    private function getInventory() {
        $category = $_GET['category'] ?? null;
        $lowStock = $_GET['low_stock'] ?? null;
        $search = $_GET['search'] ?? null;
        
        $sql = "SELECT * FROM inventory WHERE 1=1";
        $params = [];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        if ($lowStock) {
            $sql .= " AND current_stock <= minimum_stock";
        }
        
        if ($search) {
            $sql .= " AND item_name LIKE ?";
            $params[] = "%{$search}%";
        }
        
        $sql .= " ORDER BY item_name";
        
        $items = $this->db->fetchAll($sql, $params);
        
        // Calculate stock status
        foreach ($items as &$item) {
            $item['current_stock'] = (float)$item['current_stock'];
            $item['minimum_stock'] = (float)$item['minimum_stock'];
            $item['unit_price'] = (float)$item['unit_price'];
            
            if ($item['current_stock'] <= 0) {
                $item['stock_status'] = 'out_of_stock';
            } elseif ($item['current_stock'] <= $item['minimum_stock']) {
                $item['stock_status'] = 'low_stock';
            } else {
                $item['stock_status'] = 'in_stock';
            }
        }
        
        return $this->success($items);
    }
    
    /**
     * Get single inventory item
     */
    private function getItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Item ID is required', 400);
        }
        
        $item = $this->db->fetchOne("SELECT * FROM inventory WHERE id = ?", [$id]);
        
        if (!$item) {
            return $this->error('Item not found', 404);
        }
        
        // Get recent movements
        $sql = "SELECT m.*, u.full_name as created_by_name
                FROM inventory_movements m
                LEFT JOIN users u ON m.created_by = u.id
                WHERE m.inventory_item_id = ?
                ORDER BY m.created_at DESC
                LIMIT 20";
        
        $item['recent_movements'] = $this->db->fetchAll($sql, [$id]);
        
        return $this->success($item);
    }
    
    /**
     * Create inventory item
     */
    private function createItem() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['item_name', 'unit', 'current_stock', 'minimum_stock'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                return $this->error("Field {$field} is required", 400);
            }
        }
        
        $itemData = [
            'item_name' => $data['item_name'],
            'category' => $data['category'] ?? null,
            'unit' => $data['unit'],
            'current_stock' => $data['current_stock'],
            'minimum_stock' => $data['minimum_stock'],
            'unit_price' => $data['unit_price'] ?? null,
            'supplier' => $data['supplier'] ?? null,
            'supplier_phone' => $data['supplier_phone'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'storage_location' => $data['storage_location'] ?? null,
            'notes' => $data['notes'] ?? null
        ];
        
        $itemId = $this->db->insert('inventory', $itemData);
        
        // Log initial stock
        $this->db->insert('inventory_movements', [
            'inventory_item_id' => $itemId,
            'movement_type' => 'in',
            'quantity' => $data['current_stock'],
            'unit' => $data['unit'],
            'reference_type' => 'manual',
            'reason' => 'Initial stock',
            'created_by' => $data['created_by'] ?? null
        ]);
        
        return $this->success(['id' => $itemId], 'Item created successfully', 201);
    }
    
    /**
     * Update inventory item
     */
    private function updateItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Item ID is required', 400);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        unset($data['id'], $data['created_at'], $data['current_stock']); // Don't allow direct stock update
        
        $this->db->update('inventory', $data, 'id = ?', [$id]);
        
        return $this->success(null, 'Item updated successfully');
    }
    
    /**
     * Delete inventory item
     */
    private function deleteItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Item ID is required', 400);
        }
        
        // Check if used in recipes
        $usedInRecipes = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM recipe_ingredients WHERE inventory_item_id = ?",
            [$id]
        );
        
        if ($usedInRecipes['count'] > 0) {
            return $this->error('Cannot delete item used in recipes', 400);
        }
        
        $this->db->delete('inventory', 'id = ?', [$id]);
        
        return $this->success(null, 'Item deleted successfully');
    }
    
    /**
     * Adjust stock (manual adjustment)
     */
    private function adjustStock() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $itemId = $data['item_id'] ?? null;
        $quantity = $data['quantity'] ?? null;
        $type = $data['type'] ?? 'adjustment'; // adjustment, waste, return
        $reason = $data['reason'] ?? null;
        $userId = $data['user_id'] ?? null;
        
        if (!$itemId || $quantity === null) {
            return $this->error('Item ID and quantity are required', 400);
        }
        
        $item = $this->db->fetchOne("SELECT * FROM inventory WHERE id = ?", [$itemId]);
        
        if (!$item) {
            return $this->error('Item not found', 404);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update stock
            if ($quantity > 0) {
                $sql = "UPDATE inventory SET current_stock = current_stock + ? WHERE id = ?";
            } else {
                $sql = "UPDATE inventory SET current_stock = current_stock - ? WHERE id = ?";
                $quantity = abs($quantity);
            }
            
            $this->db->query($sql, [$quantity, $itemId]);
            
            // Log movement
            $this->db->insert('inventory_movements', [
                'inventory_item_id' => $itemId,
                'movement_type' => $quantity > 0 ? 'in' : 'out',
                'quantity' => $quantity,
                'unit' => $item['unit'],
                'reference_type' => 'manual',
                'reason' => $reason,
                'created_by' => $userId
            ]);
            
            $this->db->commit();
            
            return $this->success(null, 'Stock adjusted successfully');
            
        } catch (Exception $e) {
            $this->db->rollback();
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Restock item
     */
    private function restock() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $itemId = $data['item_id'] ?? null;
        $quantity = $data['quantity'] ?? null;
        $cost = $data['cost'] ?? null;
        $userId = $data['user_id'] ?? null;
        
        if (!$itemId || !$quantity) {
            return $this->error('Item ID and quantity are required', 400);
        }
        
        $item = $this->db->fetchOne("SELECT * FROM inventory WHERE id = ?", [$itemId]);
        
        if (!$item) {
            return $this->error('Item not found', 404);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Update stock
            $this->db->query(
                "UPDATE inventory SET current_stock = current_stock + ?, last_restock_date = CURDATE(), restock_quantity = ? WHERE id = ?",
                [$quantity, $quantity, $itemId]
            );
            
            // Log movement
            $this->db->insert('inventory_movements', [
                'inventory_item_id' => $itemId,
                'movement_type' => 'in',
                'quantity' => $quantity,
                'unit' => $item['unit'],
                'reference_type' => 'purchase',
                'reason' => 'Restock',
                'cost' => $cost,
                'created_by' => $userId
            ]);
            
            $this->db->commit();
            
            return $this->success(null, 'Item restocked successfully');
            
        } catch (Exception $e) {
            $this->db->rollback();
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get low stock items
     */
    private function getLowStock() {
        $sql = "SELECT * FROM inventory 
                WHERE current_stock <= minimum_stock
                ORDER BY (current_stock / minimum_stock) ASC";
        
        $items = $this->db->fetchAll($sql);
        
        foreach ($items as &$item) {
            $item['shortage'] = $item['minimum_stock'] - $item['current_stock'];
            $item['reorder_suggestion'] = ceil($item['shortage'] * 1.5); // Suggest 150% of shortage
        }
        
        return $this->success($items);
    }
    
    /**
     * Get inventory movements
     */
    private function getMovements() {
        $itemId = $_GET['item_id'] ?? null;
        $type = $_GET['type'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $limit = $_GET['limit'] ?? 100;
        
        $sql = "SELECT m.*, 
                i.item_name, i.unit as item_unit,
                u.full_name as created_by_name
                FROM inventory_movements m
                JOIN inventory i ON m.inventory_item_id = i.id
                LEFT JOIN users u ON m.created_by = u.id
                WHERE 1=1";
        
        $params = [];
        
        if ($itemId) {
            $sql .= " AND m.inventory_item_id = ?";
            $params[] = $itemId;
        }
        
        if ($type) {
            $sql .= " AND m.movement_type = ?";
            $params[] = $type;
        }
        
        if ($dateFrom) {
            $sql .= " AND DATE(m.created_at) >= ?";
            $params[] = $dateFrom;
        }
        
        if ($dateTo) {
            $sql .= " AND DATE(m.created_at) <= ?";
            $params[] = $dateTo;
        }
        
        $sql .= " ORDER BY m.created_at DESC LIMIT ?";
        $params[] = (int)$limit;
        
        $movements = $this->db->fetchAll($sql, $params);
        
        return $this->success($movements);
    }
    
    /**
     * Get recipe for menu item
     */
    private function getRecipe() {
        $menuItemId = $_GET['menu_item_id'] ?? null;
        
        if (!$menuItemId) {
            return $this->error('Menu item ID is required', 400);
        }
        
        $sql = "SELECT ri.*, 
                i.item_name, i.unit as inventory_unit, i.current_stock
                FROM recipe_ingredients ri
                JOIN inventory i ON ri.inventory_item_id = i.id
                WHERE ri.menu_item_id = ?
                ORDER BY i.item_name";
        
        $ingredients = $this->db->fetchAll($sql, [$menuItemId]);
        
        return $this->success($ingredients);
    }
    
    /**
     * Save recipe for menu item
     */
    private function saveRecipe() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $menuItemId = $data['menu_item_id'] ?? null;
        $ingredients = $data['ingredients'] ?? [];
        
        if (!$menuItemId || !is_array($ingredients)) {
            return $this->error('Menu item ID and ingredients are required', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Delete existing recipe
            $this->db->delete('recipe_ingredients', 'menu_item_id = ?', [$menuItemId]);
            
            // Add new ingredients
            foreach ($ingredients as $ingredient) {
                $this->db->insert('recipe_ingredients', [
                    'menu_item_id' => $menuItemId,
                    'inventory_item_id' => $ingredient['inventory_item_id'],
                    'quantity' => $ingredient['quantity'],
                    'unit' => $ingredient['unit'],
                    'notes' => $ingredient['notes'] ?? null
                ]);
            }
            
            $this->db->commit();
            
            return $this->success(null, 'Recipe saved successfully');
            
        } catch (Exception $e) {
            $this->db->rollback();
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get inventory statistics
     */
    private function getStatistics() {
        // Total items and value
        $sql = "SELECT 
                COUNT(*) as total_items,
                SUM(current_stock * unit_price) as total_value,
                COUNT(CASE WHEN current_stock <= minimum_stock THEN 1 END) as low_stock_count,
                COUNT(CASE WHEN current_stock <= 0 THEN 1 END) as out_of_stock_count
                FROM inventory";
        
        $stats = $this->db->fetchOne($sql);
        
        // Movements today
        $sql = "SELECT 
                COUNT(*) as total_movements,
                SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) as total_in,
                SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) as total_out,
                SUM(CASE WHEN movement_type = 'waste' THEN cost ELSE 0 END) as waste_value
                FROM inventory_movements
                WHERE DATE(created_at) = CURDATE()";
        
        $movements = $this->db->fetchOne($sql);
        
        $stats = array_merge($stats, $movements);
        $stats['total_value'] = (float)$stats['total_value'];
        $stats['waste_value'] = (float)$stats['waste_value'];
        
        return $this->success($stats);
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

$api = new InventoryAPI();
$api->handleRequest();
?>
