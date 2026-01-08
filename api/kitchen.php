<?php
/**
 * Digital by Jeff - Kitchen Display API
 * Handles: Kitchen orders, item status updates
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

class KitchenAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'pending':
                    return $this->getPendingOrders();
                    
                case 'preparing':
                    return $this->getPreparingOrders();
                    
                case 'ready':
                    return $this->getReadyOrders();
                    
                case 'start-preparing':
                    return $this->startPreparing();
                    
                case 'mark-ready':
                    return $this->markReady();
                    
                case 'statistics':
                    return $this->getStatistics();
                    
                case 'alerts':
                    return $this->getAlerts();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Kitchen API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get pending orders (not started)
     */
    private function getPendingOrders() {
        $sql = "SELECT 
                oi.id, oi.quantity, oi.notes, oi.created_at,
                o.id as order_id, o.order_number, o.order_type, o.special_requests,
                m.name as item_name, m.preparation_time,
                t.table_number,
                c.name as category_name,
                TIMESTAMPDIFF(MINUTE, oi.created_at, NOW()) as wait_time
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN tables t ON o.table_id = t.id
                WHERE oi.status = 'pending'
                AND o.status NOT IN ('cancelled', 'completed')
                ORDER BY oi.created_at ASC";
        
        $items = $this->db->fetchAll($sql);
        
        // Group by order
        $grouped = [];
        foreach ($items as $item) {
            $orderId = $item['order_id'];
            if (!isset($grouped[$orderId])) {
                $grouped[$orderId] = [
                    'order_id' => $item['order_id'],
                    'order_number' => $item['order_number'],
                    'order_type' => $item['order_type'],
                    'table_number' => $item['table_number'],
                    'special_requests' => $item['special_requests'],
                    'items' => []
                ];
            }
            
            $grouped[$orderId]['items'][] = [
                'id' => $item['id'],
                'item_name' => $item['item_name'],
                'category_name' => $item['category_name'],
                'quantity' => $item['quantity'],
                'notes' => $item['notes'],
                'preparation_time' => $item['preparation_time'],
                'wait_time' => $item['wait_time'],
                'created_at' => $item['created_at']
            ];
        }
        
        return $this->success(array_values($grouped));
    }
    
    /**
     * Get orders currently being prepared
     */
    private function getPreparingOrders() {
        $sql = "SELECT 
                oi.id, oi.quantity, oi.notes, oi.created_at,
                o.id as order_id, o.order_number, o.order_type,
                m.name as item_name, m.preparation_time,
                t.table_number,
                c.name as category_name,
                u.full_name as chef_name,
                TIMESTAMPDIFF(MINUTE, oi.created_at, NOW()) as wait_time
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN users u ON oi.prepared_by = u.id
                WHERE oi.status = 'preparing'
                AND o.status NOT IN ('cancelled', 'completed')
                ORDER BY oi.created_at ASC";
        
        $items = $this->db->fetchAll($sql);
        
        // Group by order
        $grouped = [];
        foreach ($items as $item) {
            $orderId = $item['order_id'];
            if (!isset($grouped[$orderId])) {
                $grouped[$orderId] = [
                    'order_id' => $item['order_id'],
                    'order_number' => $item['order_number'],
                    'order_type' => $item['order_type'],
                    'table_number' => $item['table_number'],
                    'items' => []
                ];
            }
            
            $grouped[$orderId]['items'][] = [
                'id' => $item['id'],
                'item_name' => $item['item_name'],
                'category_name' => $item['category_name'],
                'quantity' => $item['quantity'],
                'notes' => $item['notes'],
                'preparation_time' => $item['preparation_time'],
                'wait_time' => $item['wait_time'],
                'chef_name' => $item['chef_name'],
                'created_at' => $item['created_at']
            ];
        }
        
        return $this->success(array_values($grouped));
    }
    
    /**
     * Get ready orders (waiting to be served)
     */
    private function getReadyOrders() {
        $sql = "SELECT 
                oi.id, oi.quantity, oi.prepared_at,
                o.id as order_id, o.order_number, o.order_type,
                m.name as item_name,
                t.table_number,
                u.full_name as chef_name,
                TIMESTAMPDIFF(MINUTE, oi.prepared_at, NOW()) as ready_time
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN users u ON oi.prepared_by = u.id
                WHERE oi.status = 'ready'
                AND o.status NOT IN ('cancelled', 'completed')
                ORDER BY oi.prepared_at ASC";
        
        $items = $this->db->fetchAll($sql);
        
        // Group by order
        $grouped = [];
        foreach ($items as $item) {
            $orderId = $item['order_id'];
            if (!isset($grouped[$orderId])) {
                $grouped[$orderId] = [
                    'order_id' => $item['order_id'],
                    'order_number' => $item['order_number'],
                    'order_type' => $item['order_type'],
                    'table_number' => $item['table_number'],
                    'items' => []
                ];
            }
            
            $grouped[$orderId]['items'][] = [
                'id' => $item['id'],
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'chef_name' => $item['chef_name'],
                'ready_time' => $item['ready_time'],
                'prepared_at' => $item['prepared_at']
            ];
        }
        
        return $this->success(array_values($grouped));
    }
    
    /**
     * Start preparing an item
     */
    private function startPreparing() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $itemId = $data['item_id'] ?? null;
        $chefId = $data['chef_id'] ?? null;
        
        if (!$itemId) {
            return $this->error('Item ID is required', 400);
        }
        
        $updateData = [
            'status' => 'preparing'
        ];
        
        if ($chefId) {
            $updateData['prepared_by'] = $chefId;
        }
        
        $this->db->update('order_items', $updateData, 'id = ?', [$itemId]);
        
        // Update order status
        $item = $this->db->fetchOne("SELECT order_id FROM order_items WHERE id = ?", [$itemId]);
        $this->db->update('orders', ['status' => 'preparing'], 'id = ?', [$item['order_id']]);
        
        return $this->success(null, 'Item marked as preparing');
    }
    
    /**
     * Mark item as ready
     */
    private function markReady() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $itemId = $data['item_id'] ?? null;
        $chefId = $data['chef_id'] ?? null;
        
        if (!$itemId) {
            return $this->error('Item ID is required', 400);
        }
        
        $updateData = [
            'status' => 'ready',
            'prepared_at' => date('Y-m-d H:i:s')
        ];
        
        if ($chefId) {
            $updateData['prepared_by'] = $chefId;
        }
        
        $this->db->update('order_items', $updateData, 'id = ?', [$itemId]);
        
        // Check if all items in order are ready
        $item = $this->db->fetchOne("SELECT order_id FROM order_items WHERE id = ?", [$itemId]);
        $this->checkAllItemsReady($item['order_id']);
        
        return $this->success(null, 'Item marked as ready');
    }
    
    /**
     * Get kitchen statistics
     */
    private function getStatistics() {
        $today = date('Y-m-d');
        
        $sql = "SELECT 
                COUNT(DISTINCT oi.id) as total_items,
                COUNT(DISTINCT CASE WHEN oi.status = 'pending' THEN oi.id END) as pending_items,
                COUNT(DISTINCT CASE WHEN oi.status = 'preparing' THEN oi.id END) as preparing_items,
                COUNT(DISTINCT CASE WHEN oi.status = 'ready' THEN oi.id END) as ready_items,
                COUNT(DISTINCT CASE WHEN oi.status = 'served' THEN oi.id END) as served_items,
                AVG(TIMESTAMPDIFF(MINUTE, oi.created_at, oi.prepared_at)) as avg_preparation_time
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE DATE(oi.created_at) = ?
                AND o.status NOT IN ('cancelled')";
        
        $stats = $this->db->fetchOne($sql, [$today]);
        
        // Get oldest pending order
        $oldestSql = "SELECT 
                    TIMESTAMPDIFF(MINUTE, MIN(oi.created_at), NOW()) as oldest_wait_time
                    FROM order_items oi
                    JOIN orders o ON oi.order_id = o.id
                    WHERE oi.status = 'pending'
                    AND o.status NOT IN ('cancelled', 'completed')";
        
        $oldest = $this->db->fetchOne($oldestSql);
        $stats['oldest_wait_time'] = $oldest['oldest_wait_time'] ?? 0;
        
        return $this->success($stats);
    }
    
    /**
     * Get kitchen alerts (delayed orders, long wait times)
     */
    private function getAlerts() {
        $alerts = [];
        
        // Check items waiting more than 20 minutes
        $sql = "SELECT 
                oi.id, 
                o.order_number,
                m.name as item_name,
                t.table_number,
                TIMESTAMPDIFF(MINUTE, oi.created_at, NOW()) as wait_time
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN tables t ON o.table_id = t.id
                WHERE oi.status IN ('pending', 'preparing')
                AND TIMESTAMPDIFF(MINUTE, oi.created_at, NOW()) > 20
                AND o.status NOT IN ('cancelled', 'completed')
                ORDER BY wait_time DESC";
        
        $delayedItems = $this->db->fetchAll($sql);
        
        foreach ($delayedItems as $item) {
            $alerts[] = [
                'type' => 'delayed',
                'severity' => $item['wait_time'] > 30 ? 'critical' : 'warning',
                'message' => "Order {$item['order_number']} - {$item['item_name']} waiting for {$item['wait_time']} minutes",
                'order_number' => $item['order_number'],
                'table_number' => $item['table_number'],
                'wait_time' => $item['wait_time']
            ];
        }
        
        // Check ready items waiting to be served more than 5 minutes
        $sql = "SELECT 
                o.order_number,
                t.table_number,
                COUNT(oi.id) as items_count,
                TIMESTAMPDIFF(MINUTE, MIN(oi.prepared_at), NOW()) as ready_time
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                LEFT JOIN tables t ON o.table_id = t.id
                WHERE oi.status = 'ready'
                AND TIMESTAMPDIFF(MINUTE, oi.prepared_at, NOW()) > 5
                AND o.status NOT IN ('cancelled', 'completed')
                GROUP BY o.id
                ORDER BY ready_time DESC";
        
        $readyItems = $this->db->fetchAll($sql);
        
        foreach ($readyItems as $item) {
            $alerts[] = [
                'type' => 'ready',
                'severity' => 'info',
                'message' => "Order {$item['order_number']} ready for {$item['ready_time']} minutes - needs to be served",
                'order_number' => $item['order_number'],
                'table_number' => $item['table_number'],
                'items_count' => $item['items_count']
            ];
        }
        
        return $this->success($alerts);
    }
    
    /**
     * Check if all items in order are ready
     */
    private function checkAllItemsReady($orderId) {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_count
                FROM order_items
                WHERE order_id = ?";
        
        $stats = $this->db->fetchOne($sql, [$orderId]);
        
        if ($stats['total'] == $stats['ready_count']) {
            $this->db->update('orders', ['status' => 'ready'], 'id = ?', [$orderId]);
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

$api = new KitchenAPI();
$api->handleRequest();
?>
