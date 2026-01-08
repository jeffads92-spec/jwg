<?php
/**
 * Digital by Jeff - Customer Order API
 * Public API for customer order submission (QR ordering)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

class CustomerOrderAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'submit':
                    if ($method !== 'POST') {
                        return $this->error('Method not allowed', 405);
                    }
                    return $this->submitOrder();
                    
                case 'track':
                    return $this->trackOrder();
                    
                case 'call-waiter':
                    if ($method !== 'POST') {
                        return $this->error('Method not allowed', 405);
                    }
                    return $this->callWaiter();
                    
                case 'rate':
                    if ($method !== 'POST') {
                        return $this->error('Method not allowed', 405);
                    }
                    return $this->rateOrder();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Customer Order API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Submit customer order
     */
    private function submitOrder() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $tableId = $data['table_id'] ?? null;
        $items = $data['items'] ?? [];
        
        if (!$tableId) {
            return $this->error('Table ID is required', 400);
        }
        
        if (empty($items) || !is_array($items)) {
            return $this->error('Order items are required', 400);
        }
        
        // Validate table
        $table = $this->db->fetchOne("SELECT * FROM tables WHERE id = ?", [$tableId]);
        
        if (!$table) {
            return $this->error('Table not found', 404);
        }
        
        if ($table['status'] === 'cleaning') {
            return $this->error('Table is not available for ordering', 400);
        }
        
        $this->db->beginTransaction();
        
        try {
            // Generate order number
            $orderNumber = 'QR-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // Calculate totals
            $subtotal = 0;
            $itemsData = [];
            
            foreach ($items as $item) {
                // Validate menu item
                $menuItem = $this->db->fetchOne(
                    "SELECT id, name, price, is_available FROM menu_items WHERE id = ?",
                    [$item['menu_item_id']]
                );
                
                if (!$menuItem) {
                    throw new Exception("Menu item ID {$item['menu_item_id']} not found");
                }
                
                if (!$menuItem['is_available']) {
                    throw new Exception("{$menuItem['name']} is currently unavailable");
                }
                
                $quantity = max(1, (int)($item['quantity'] ?? 1));
                $price = $menuItem['price'];
                $itemSubtotal = $price * $quantity;
                $subtotal += $itemSubtotal;
                
                $itemsData[] = [
                    'menu_item_id' => $item['menu_item_id'],
                    'menu_name' => $menuItem['name'],
                    'quantity' => $quantity,
                    'price' => $price,
                    'subtotal' => $itemSubtotal,
                    'notes' => $item['notes'] ?? null
                ];
            }
            
            // Get settings for tax and service charge
            $settings = $this->getSettings();
            $taxPercentage = $settings['tax_percentage'] ?? 10;
            $serviceChargePercentage = $settings['service_charge_percentage'] ?? 5;
            
            // Calculate tax and service charge
            $tax = ($subtotal * $taxPercentage) / 100;
            $serviceCharge = ($subtotal * $serviceChargePercentage) / 100;
            $total = $subtotal + $tax + $serviceCharge;
            
            // Create order
            $orderData = [
                'order_number' => $orderNumber,
                'table_id' => $tableId,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'order_type' => 'dine_in',
                'order_source' => 'customer_qr',
                'status' => 'pending',
                'subtotal' => $subtotal,
                'tax' => $tax,
                'tax_percentage' => $taxPercentage,
                'service_charge' => $serviceCharge,
                'service_charge_percentage' => $serviceChargePercentage,
                'discount' => 0,
                'total' => $total,
                'special_requests' => $data['special_requests'] ?? null
            ];
            
            $orderId = $this->db->insert('orders', $orderData);
            
            // Add order items
            foreach ($itemsData as $item) {
                $item['order_id'] = $orderId;
                $this->db->insert('order_items', $item);
                
                // Auto-deduct inventory
                $this->autoDeductInventory($item['menu_item_id'], $item['quantity']);
            }
            
            // Update table status
            $this->db->update('tables',
                [
                    'status' => 'occupied',
                    'current_order_id' => $orderId
                ],
                'id = ?',
                [$tableId]
            );
            
            $this->db->commit();
            
            // Send notification (you can integrate with email/SMS/push notification here)
            $this->sendOrderNotification($orderId, $orderNumber, $table['table_number']);
            
            return $this->success([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'table_number' => $table['table_number'],
                'subtotal' => $subtotal,
                'tax' => $tax,
                'service_charge' => $serviceCharge,
                'total' => $total,
                'items_count' => count($itemsData),
                'estimated_time' => 20 // minutes
            ], 'Order submitted successfully! Your order is being prepared.', 201);
            
        } catch (Exception $e) {
            $this->db->rollback();
            return $this->error($e->getMessage(), 400);
        }
    }
    
    /**
     * Track order status
     */
    private function trackOrder() {
        $orderNumber = $_GET['order_number'] ?? null;
        $orderId = $_GET['order_id'] ?? null;
        
        if (!$orderNumber && !$orderId) {
            return $this->error('Order number or ID is required', 400);
        }
        
        $whereClause = $orderNumber ? "order_number = ?" : "id = ?";
        $whereValue = $orderNumber ?? $orderId;
        
        $sql = "SELECT o.*, t.table_number
                FROM orders o
                LEFT JOIN tables t ON o.table_id = t.id
                WHERE {$whereClause}";
        
        $order = $this->db->fetchOne($sql, [$whereValue]);
        
        if (!$order) {
            return $this->error('Order not found', 404);
        }
        
        // Get order items with status
        $sql = "SELECT oi.*, m.name as menu_name, m.image
                FROM order_items oi
                JOIN menu_items m ON oi.menu_item_id = m.id
                WHERE oi.order_id = ?
                ORDER BY oi.created_at";
        
        $order['items'] = $this->db->fetchAll($sql, [$order['id']]);
        
        // Calculate progress
        $totalItems = count($order['items']);
        $preparedItems = 0;
        $readyItems = 0;
        
        foreach ($order['items'] as &$item) {
            $item['price'] = (float)$item['price'];
            $item['subtotal'] = (float)$item['subtotal'];
            
            if ($item['image']) {
                $item['image_url'] = $this->getImageUrl($item['image']);
            }
            
            if (in_array($item['status'], ['ready', 'served'])) {
                $readyItems++;
            }
            if ($item['status'] !== 'pending') {
                $preparedItems++;
            }
        }
        
        $order['progress'] = [
            'total_items' => $totalItems,
            'prepared_items' => $preparedItems,
            'ready_items' => $readyItems,
            'progress_percentage' => $totalItems > 0 ? round(($readyItems / $totalItems) * 100) : 0
        ];
        
        // Format amounts
        $order['subtotal'] = (float)$order['subtotal'];
        $order['tax'] = (float)$order['tax'];
        $order['service_charge'] = (float)$order['service_charge'];
        $order['total'] = (float)$order['total'];
        
        // Status message
        $statusMessages = [
            'pending' => 'Your order has been received and is being confirmed',
            'confirmed' => 'Your order has been confirmed',
            'preparing' => 'Your order is being prepared in the kitchen',
            'ready' => 'Your order is ready! Please wait for service',
            'served' => 'Your order has been served. Enjoy your meal!',
            'completed' => 'Order completed. Thank you!',
            'cancelled' => 'This order has been cancelled'
        ];
        
        $order['status_message'] = $statusMessages[$order['status']] ?? 'Processing';
        
        return $this->success($order);
    }
    
    /**
     * Call waiter
     */
    private function callWaiter() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $tableId = $data['table_id'] ?? null;
        $request = $data['request'] ?? 'Call waiter';
        
        if (!$tableId) {
            return $this->error('Table ID is required', 400);
        }
        
        $table = $this->db->fetchOne("SELECT * FROM tables WHERE id = ?", [$tableId]);
        
        if (!$table) {
            return $this->error('Table not found', 404);
        }
        
        // Log the request (you can send real-time notification here)
        $this->db->insert('activity_logs', [
            'user_id' => null,
            'action' => 'call_waiter',
            'entity_type' => 'table',
            'entity_id' => $tableId,
            'details' => json_encode([
                'table_number' => $table['table_number'],
                'request' => $request
            ])
        ]);
        
        // Send notification to staff
        $this->sendWaiterNotification($table['table_number'], $request);
        
        return $this->success(null, 'Waiter has been notified and will be with you shortly');
    }
    
    /**
     * Rate order (optional feature)
     */
    private function rateOrder() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $orderId = $data['order_id'] ?? null;
        $rating = $data['rating'] ?? null;
        $feedback = $data['feedback'] ?? null;
        
        if (!$orderId || !$rating) {
            return $this->error('Order ID and rating are required', 400);
        }
        
        if ($rating < 1 || $rating > 5) {
            return $this->error('Rating must be between 1 and 5', 400);
        }
        
        // Store rating (you can create a ratings table for this)
        $this->db->insert('activity_logs', [
            'user_id' => null,
            'action' => 'customer_rating',
            'entity_type' => 'order',
            'entity_id' => $orderId,
            'details' => json_encode([
                'rating' => $rating,
                'feedback' => $feedback
            ])
        ]);
        
        return $this->success(null, 'Thank you for your feedback!');
    }
    
    /**
     * Get settings
     */
    private function getSettings() {
        $sql = "SELECT setting_key, setting_value FROM settings";
        $settings = $this->db->fetchAll($sql);
        
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['setting_value'];
        }
        
        return $result;
    }
    
    /**
     * Auto-deduct inventory
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
            
            $this->db->query(
                "UPDATE inventory SET current_stock = current_stock - ? WHERE id = ?",
                [$deductQty, $ingredient['inventory_item_id']]
            );
            
            $this->db->insert('inventory_movements', [
                'inventory_item_id' => $ingredient['inventory_item_id'],
                'movement_type' => 'out',
                'quantity' => $deductQty,
                'unit' => $ingredient['unit'],
                'reference_type' => 'auto_deduct',
                'reason' => "Customer order - Menu item: {$menuItemId}"
            ]);
        }
    }
    
    /**
     * Send order notification
     */
    private function sendOrderNotification($orderId, $orderNumber, $tableNumber) {
        // Log notification (implement actual notification system)
        error_log("New customer order: {$orderNumber} for Table {$tableNumber}");
        
        // Here you can integrate with:
        // - Email notification
        // - SMS notification
        // - Push notification to admin app
        // - WebSocket for real-time updates
    }
    
    /**
     * Send waiter notification
     */
    private function sendWaiterNotification($tableNumber, $request) {
        error_log("Waiter called at Table {$tableNumber}: {$request}");
        
        // Implement real-time notification system
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

$api = new CustomerOrderAPI();
$api->handleRequest();
?>
