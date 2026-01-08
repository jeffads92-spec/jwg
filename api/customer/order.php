<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';

$action = $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($action === 'submit') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!$data || empty($data['items'])) {
            throw new Exception('No items in order');
        }
        
        // Generate order number
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Calculate totals
        $subtotal = $data['subtotal'] ?? 0;
        $tax = $data['tax'] ?? 0;
        $service = $data['service_charge'] ?? 0;
        $total = $data['total_amount'] ?? ($subtotal + $tax + $service);
        
        // Insert order
        $stmt = $conn->prepare("
            INSERT INTO orders (
                order_number, order_type, customer_name, customer_phone, customer_email,
                table_number, delivery_address, notes, subtotal, tax, service_charge,
                total_amount, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $stmt->execute([
            $orderNumber,
            $data['order_type'] ?? 'dine-in',
            $data['customer_name'],
            $data['customer_phone'] ?? '',
            $data['customer_email'] ?? '',
            $data['table_number'] ?? null,
            $data['delivery_address'] ?? '',
            $data['notes'] ?? '',
            $subtotal,
            $tax,
            $service,
            $total
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // Insert order items
        $stmt = $conn->prepare("
            INSERT INTO order_items (order_id, menu_item_id, quantity, price, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($data['items'] as $item) {
            $stmt->execute([
                $orderId,
                $item['id'],
                $item['quantity'],
                $item['price'],
                $item['notes'] ?? ''
            ]);
        }
        
        $response['success'] = true;
        $response['message'] = 'Order placed successfully';
        $response['order_number'] = $orderNumber;
        $response['order_id'] = $orderId;
        
    } elseif ($action === 'track') {
        $orderNumber = $_GET['order_number'] ?? '';
        
        if (!$orderNumber) {
            throw new Exception('Order number required');
        }
        
        // Get order details
        $stmt = $conn->prepare("
            SELECT * FROM orders WHERE order_number = ?
        ");
        $stmt->execute([$orderNumber]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        // Get order items
        $stmt = $conn->prepare("
            SELECT oi.*, mi.name, mi.image
            FROM order_items oi
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$order['id']]);
        $order['items'] = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        
        $response['success'] = true;
        $response['data'] = $order;
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Customer Order API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
