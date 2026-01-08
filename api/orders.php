<?php
/**
 * Digital by Jeff - Orders Management API
 * Handles: Create Order, Update Status, Get Orders, Cancel Order
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

class OrdersAPI {
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
                    return $this->getOrders();
                    
                case 'get':
                    return $this->getOrder();
                    
                case 'create':
                    return $this->createOrder();
                    
                case 'update-status':
                    return $this->updateOrderStatus();
                    
                case 'update-item-status':
                    return $this->updateOrderItemStatus();
                    
                case 'add-item':
                    return $this->addOrderItem();
                    
                case 'remove-item':
                    return $this->removeOrderItem();
                    
                case 'cancel':
                    return $this->cancelOrder();
                    
                case 'complete':
                    return $this->completeOrder();
                    
                case 'kitchen-display':
                    return $this->getKitchenOrders();
                    
                case 'active':
                    return $this->getActiveOrders();
                    
                case 'statistics':
                    return $this->getStatistics();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Orders API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get all orders with filters
     */
    private function getOrders() {
        $status = $_GET['status'] ?? null;
        $tableId = $_GET['table_id'] ?? null;
        $orderType = $_GET['order_type'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $limit = $_GET['limit'] ?? 50;
        $offset = $_GET['offset'] ?? 0;
        
        $sql = "SELECT o.*, 
                t.table_number, 
                u.full_name as created_by_name,
                COUNT(oi.id) as item_count
                FROM orders o
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN users u ON o.user_id = u.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE 1=1";
        
        $params = [];
        
        if ($status) {
            $sql .= " AND o.status = ?";
            $params[] = $status;
        }
        
        if ($tableId) {
            $sql .= " AND o.table_id = ?";
            $params[] = $tableId;
        }
        
        if ($orderType) {
            $sql .= " AND o.order_type = ?";
            $params[] = $orderType;
        }
        
        if ($dateFrom) {
            $sql .= " AND DATE(o.created_at) >= ?";
            $params[] = $dateFrom;
        }
        
        if ($dateTo) {
            $sql .= " AND DATE(o.created_at) <= ?";
            $params[] = $dateTo;
        }
        
        $sql .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        
        $orders = $this->db->fetchAll($sql, $params);
        
        // Format amounts
        foreach ($orders as &$order) {
            $order['subtotal'] = (float)$order['subtotal'];
            $order['tax'] = (float)$order['tax'];
            $order['service_charge'] = (float)$order['service_charge'];
            $order['discount'] = (float)$order['discount'];
            $order['total'] = (float)$order['total'];
        }
        
        return $this->success($orders);
    }
    
    /**
     * Get single order with details
     */
    private function getOrder() {
        $id = $_GET['id'] ?? null;
        $orderNumber = $_GET['order_number'] ?? null;
        
        if (!$id && !$orderNumber) {
            return $this->error('Order ID or order number is required', 400);
        }
        
        $sql = "SELECT o.*, 
                t.table_number, t.capacity, t.location,
                u.full_name as created_by_name
                FROM orders o
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN users u ON o.user_id = u.id
                WHERE " . ($id ? "o.id = ?" : "o.order_number = ?");
        
        $order = $this->db->fetchOne($sql, [$id ?? $orderNumber]);
        
        if (!$order) {
            return $this->error('Order not found', 404);
        }
        
        // Get order items
        $sql = "SELECT oi.*, 
                m.name as menu_name, 
                m.image as menu_image,
                c.name as category_name,
                u.full_name as prepared_by_name
                FROM order_items oi
                LEFT JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN categories c ON m.category_id = c.id
                LEFT JOIN users u ON oi.prepared_by = u.id
                WHERE oi.order_id = ?
                ORDER BY oi.created_at";
        
        $order['items'] = $this->db->fetchAll($sql, [$order['id']]);
        
        // Format amounts
        $order['subtotal'] = (float)$order['subtotal'];
        $order['tax'] = (float)$order['tax'];
        $order['service_charge'] = (float)$order['service_charge'];
        $order['discount'] = (float)$order['discount'];
        $order['total'] = (float)$order['total'];
        
        foreach ($order['items'] as &$item) {
            $item['price'] = (float)$item['price'];
            $item['subtotal'] = (float)$item['subtotal'];
        }
        
        // Get payment info if paid
        if ($order['is_paid']) {
            $sql = "SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
            $order['payment'] = $this->db->fetchOne($sql, [$order['id']]);
        }
        
        return $this->success($order);
    }
    
    /**
     * Create new order
     */
    private function createOrder() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['items']) || !is_array($data['items'])) {
            return $this->error('Order items are required', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Generate order number
            $orderNumber = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // Calculate totals
            $subtotal = 0;
            $itemsData = [];
            
            foreach ($data['items'] as $item) {
                // Get menu item price
                $menuItem = $this->db->fetchOne(
                    "SELECT id, name, price, is_available FROM menu_items WHERE id = ?", 
                    [$item['menu_item_id']]
                );
                
                if (!$menuItem) {
                    throw new Exception("Menu item ID {$item['menu_item_id']} not found");
                }
                
                if (!$menuItem['is_available']) {
                    throw new Exception("{$menuItem['name']} is not available");
                }
                
                $quantity = $item['quantity'];
                $price = $menuItem['price'];
                $itemSubtotal = $price * $quantity;
                $subtotal += $itemSubtotal;
                
                $itemsData[] = [
                    'menu_item_id' => $item['menu_item_id'],
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $itemSubtotal,
                    'notes' => $item['notes'] ?? null
                ];
            }
            
            // Calculate tax and service charge
            $taxPercentage = $data['tax_percentage'] ?? 10;
            $serviceChargePercentage = $data['service_charge_percentage'] ?? 5;
            
            $tax = ($subtotal * $taxPercentage) / 100;
            $serviceCharge = ($subtotal * $serviceChargePercentage) / 100;
            
            // Apply discount
            $discount = 0;
            if (!empty($data['discount_code'])) {
                $discountInfo = $this->calculateDiscount($data['discount_code'], $subtotal);
                $discount = $discountInfo['amount'];
            }
            
            $total = $subtotal + $tax + $serviceCharge - $discount;
            
            // Create order
            $orderData = [
                'order_number' => $orderNumber,
                'table_id' => $data['table_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'order_type' => $data['order_type'] ?? 'dine_in',
                'order_source' => $data['order_source'] ?? 'admin',
                'status' => 'pending',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_percentage' => $taxPercentage,
                'service_charge' => $serviceCharge,
                'service_charge_percentage' => $serviceChargePercentage,
                'discount' => $discount,
                'discount_code' => $data['discount_code'] ?? null,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'special_requests' => $data['special_requests'] ?? null
            ];
            
            $orderId = $this->db->insert('orders', $orderData);
            
            // Add order items
            foreach ($itemsData as $item) {
                $item['order_id'] = $orderId;
                $this->db->insert('order_items', $item);
                
                // Auto-deduct inventory if enabled
                $this->autoDeductInventory($item['menu_item_id'], $item['quantity']);
            }
            
            // Update table status
            if ($data['table_id']) {
                $this->db->update('tables', 
                    ['status' => 'occupied', 'current_order_id' => $orderId],
                    'id = ?',
                    [$data['table_id']]
                );
            }
            
            $this->db->commit();
            
            return $this->success([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'total' => $total
            ], 'Order created successfully', 201);
            
        } catch (Exception $e) {
            $this->db->rollback();
            return $this->error($e->getMessage(), 400);
        }
    }
    
    /**
     * Update order status
     */
    private function updateOrderStatus() {
        $id = $_GET['id'] ?? null;
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$id) {
            return $this->error('Order ID is required', 400);
        }
        
        $newStatus = $data['status'] ?? null;
        if (!$newStatus) {
            return $this->error('Status is required', 400);
        }
        
        $validStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'served', 'completed', 'cancelled'];
        if (!in_array($newStatus, $validStatuses)) {
            return $this->error('Invalid status', 400);
        }
        
        $updateData = ['status' => $newStatus];
        
        if ($newStatus === 'completed') {
            $updateData['completed_at'] = date('Y-m-d H:i:s');
        }
        
        $this->db->update('orders', $updateData, 'id = ?', [$id]);
        
        // Update all order items status
        if ($newStatus === 'preparing') {
            $this->db->update('order_items', ['status' => 'preparing'], 'order_id = ?', [$id]);
        } elseif ($newStatus === 'ready') {
            $this->db->update('order_items', ['status' => 'ready'], 'order_id = ?', [$id]);
        } elseif ($newStatus === 'served') {
            $this->db->update('order_items', 
                ['status' => 'served', 'served_at' => date('Y-m-d H:i:s')], 
                'order_id = ?', [$id]
            );
        }
        
        return $this->success(null, 'Order status updated');
    }
    
    /**
     * Update order item status
     */
    private function updateOrderItemStatus() {
        $id = $_GET['id'] ?? null;
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$id) {
            return $this->error('Order item ID is required', 400);
        }
        
        $status = $data['status'] ?? null;
        $preparedBy = $data['prepared_by'] ?? null;
        
        $updateData = ['status' => $status];
        
        if ($status === 'ready' && $preparedBy) {
            $updateData['prepared_by'] = $preparedBy;
            $updateData['prepared_at'] = date('Y-m-d H:i:s');
        }
        
        if ($status === 'served') {
            $updateData['served_at'] = date('Y-m-d H:i:s');
        }
        
        $this->db->update('order_items', $updateData, 'id = ?', [$id]);
        
        // Check if all items are ready/served, then update order status
        $orderItem = $this->db->fetchOne("SELECT order_id FROM order_items WHERE id = ?", [$id]);
        $this->checkAndUpdateOrderStatus($orderItem['order_id']);
        
        return $this->success(null, 'Order item status updated');
    }
    
    /**
     * Add item to existing order
     */
    private function addOrderItem() {
        $orderId = $_GET['order_id'] ?? null;
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$orderId) {
            return $this->error('Order ID is required', 400);
        }
        
        $menuItemId = $data['menu_item_id'] ?? null;
        $quantity = $data['quantity'] ?? 1;
        
        // Get menu item
        $menuItem = $this->db->fetchOne("SELECT * FROM menu_items WHERE id = ?", [$menuItemId]);
        
        if (!$menuItem) {
            return $this->error('Menu item not found', 404);
        }
        
        $subtotal = $menuItem['price'] * $quantity;
        
        // Add item
        $itemId = $this->db->insert('order_items', [
            'order_id' => $orderId,
            'menu_item_id' => $menuItemId,
            'quantity' => $quantity,
            'price' => $menuItem['price'],
            'subtotal' => $subtotal,
            'notes' => $data['notes'] ?? null
        ]);
        
        // Recalculate order total
        $this->recalculateOrderTotal($orderId);
        
        // Auto-deduct inventory
        $this->autoDeductInventory($menuItemId, $quantity);
        
        return $this->success(['item_id' => $itemId], 'Item added to order');
    }
    
    /**
     * Remove item from order
     */
    private function removeOrderItem() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Order item ID is required', 400);
        }
        
        $item = $this->db->fetchOne("SELECT * FROM order_items WHERE id = ?", [$id]);
        
        if (!$item) {
            return $this->error('Order item not found', 404);
        }
        
        $this->db->delete('order_items', 'id = ?', [$id]);
        
        // Recalculate order total
        $this->recalculateOrderTotal($item['order_id']);
        
        return $this->success(null, 'Item removed from order');
    }
    
    /**
     * Cancel order
     */
    private function cancelOrder() {
        $id = $_GET['id'] ?? null;
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$id) {
            return $this->error('Order ID is required', 400);
        }
        
        $order = $this->db->fetchOne("SELECT * FROM orders WHERE id = ?", [$id]);
        
        if (!$order) {
            return $this->error('Order not found', 404);
        }
        
        if ($order['is_paid']) {
            return $this->error('Cannot cancel paid order', 400);
        }
        
        $this->db->update('orders', [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancellation_reason' => $data['reason'] ?? 'Cancelled by user'
        ], 'id = ?', [$id]);
        
        // Update table status
        if ($order['table_id']) {
            $this->db->update('tables',
                ['status' => 'available', 'current_order_id' => null],
                'id = ?',
                [$order['table_id']]
            );
        }
        
        return $this->success(null, 'Order cancelled');
    }
    
    /**
     * Complete order
     */
    private function completeOrder() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            return $this->error('Order ID is required', 400);
        }
        
        $order = $this->db->fetchOne("SELECT * FROM orders WHERE id = ?", [$id]);
        
        if (!$order) {
            return $this->error('Order not found', 404);
        }
        
        if (!$order['is_paid']) {
            return $this->error('Order must be paid before completing', 400);
        }
        
        $this->db->update('orders', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$id]);
        
        // Free up table
        if ($order['table_id']) {
            $this->db->update('tables',
                ['status' => 'available', 'current_order_id' => null],
                'id = ?',
                [$order['table_id']]
            );
        }
        
        return $this->success(null, 'Order completed');
    }
    
    /**
     * Get kitchen display orders
     */
    private function getKitchenOrders() {
        $sql = "SELECT oi.*, 
                o.order_number, o.table_id, o.order_type, o.created_at as order_time,
                m.name as menu_name, m.preparation_time,
                t.table_number
                FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN tables t ON o.table_id = t.id
                WHERE oi.status IN ('pending', 'preparing')
                AND o.status NOT IN ('cancelled', 'completed')
                ORDER BY oi.created_at ASC";
        
        $items = $this->db->fetchAll($sql);
        
        return $this->success($items);
    }
    
    /**
     * Get active orders
     */
    private function getActiveOrders() {
        $sql = "SELECT o.*, 
                t.table_number,
                COUNT(oi.id) as total_items,
                SUM(CASE WHEN oi.status = 'ready' THEN 1 ELSE 0 END) as ready_items
                FROM orders o
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.status IN ('pending', 'confirmed', 'preparing', 'ready', 'served')
                GROUP BY o.id
                ORDER BY o.created_at DESC";
        
        $orders = $this->db->fetchAll($sql);
        
        return $this->success($orders);
    }
    
    /**
     * Get order statistics
     */
    private function getStatistics() {
        $date = $_GET['date'] ?? date('Y-m-d');
        
        $sql = "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as total_revenue,
                AVG(CASE WHEN status = 'completed' THEN total ELSE NULL END) as avg_order_value
                FROM orders
                WHERE DATE(created_at) = ?";
        
        $stats = $this->db->fetchOne($sql, [$date]);
        
        $stats['total_revenue'] = (float)$stats['total_revenue'];
        $stats['avg_order_value'] = (float)$stats['avg_order_value'];
        
        return $this->success($stats);
    }
    
    /**
     * Helper: Calculate discount
     */
    private function calculateDiscount($code, $subtotal) {
        $sql = "SELECT * FROM discounts 
                WHERE code = ? 
                AND is_active = 1 
                AND (start_date IS NULL OR start_date <= CURDATE())
                AND (end_date IS NULL OR end_date >= CURDATE())
                LIMIT 1";
        
        $discount = $this->db->fetchOne($sql, [$code]);
        
        if (!$discount) {
            throw new Exception("Invalid or expired discount code");
        }
        
        if ($subtotal < $discount['min_purchase']) {
            throw new Exception("Minimum purchase amount not met");
        }
        
        $amount = 0;
        if ($discount['discount_type'] === 'percentage') {
            $amount = ($subtotal * $discount['discount_value']) / 100;
            if ($discount['max_discount'] && $amount > $discount['max_discount']) {
                $amount = $discount['max_discount'];
            }
        } else {
            $amount = $discount['discount_value'];
        }
        
        // Update usage count
        $this->db->query("UPDATE discounts SET usage_count = usage_count + 1 WHERE id = ?", [$discount['id']]);
        
        return ['amount' => $amount, 'discount' => $discount];
    }
    
    /**
     * Helper: Auto-deduct inventory
     */
    private function autoDeductInventory($menuItemId, $quantity) {
        $autoDeduct = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'auto_deduct_inventory'");
        
        if (!$autoDeduct || $autoDeduct['setting_value'] != '1') {
            return;
        }
        
        $sql = "SELECT * FROM recipe_ingredients WHERE menu_item_id = ?";
        $ingredients = $this->db->fetchAll($sql, [$menuItemId]);
        
        foreach ($ingredients as $ingredient) {
            $deductQty = $ingredient['quantity'] * $quantity;
            
            // Update inventory stock
            $this->db->query(
                "UPDATE inventory SET current_stock = current_stock - ? WHERE id = ?",
                [$deductQty, $ingredient['inventory_item_id']]
            );
            
            // Log movement
            $this->db->insert('inventory_movements', [
                'inventory_item_id' => $ingredient['inventory_item_id'],
                'movement_type' => 'out',
                'quantity' => $deductQty,
                'unit' => $ingredient['unit'],
                'reference_type' => 'auto_deduct',
                'reason' => "Auto-deducted for menu item ID: {$menuItemId}"
            ]);
        }
    }
    
    /**
     * Helper: Recalculate order total
     */
    private function recalculateOrderTotal($orderId) {
        $sql = "SELECT SUM(subtotal) as new_subtotal FROM order_items WHERE order_id = ?";
        $result = $this->db->fetchOne($sql, [$orderId]);
        
        $order = $this->db->fetchOne("SELECT * FROM orders WHERE id = ?", [$orderId]);
        
        $subtotal = $result['new_subtotal'] ?? 0;
        $tax = ($subtotal * $order['tax_percentage']) / 100;
        $serviceCharge = ($subtotal * $order['service_charge_percentage']) / 100;
        $total = $subtotal + $tax + $serviceCharge - $order['discount'];
        
        $this->db->update('orders', [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service_charge' => $serviceCharge,
            'total' => $total
        ], 'id = ?', [$orderId]);
    }
    
    /**
     * Helper: Check and update order status based on items
     */
    private function checkAndUpdateOrderStatus($orderId) {
        $sql = "SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_items,
                SUM(CASE WHEN status = 'served' THEN 1 ELSE 0 END) as served_items
                FROM order_items 
                WHERE order_id = ?";
        
        $stats = $this->db->fetchOne($sql, [$orderId]);
        
        if ($stats['ready_items'] == $stats['total_items']) {
            $this->db->update('orders', ['status' => 'ready'], 'id = ?', [$orderId]);
        } elseif ($stats['served_items'] == $stats['total_items']) {
            $this->db->update('orders', ['status' => 'served'], 'id = ?', [$orderId]);
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

$api = new OrdersAPI();
$api->handleRequest();
?>
