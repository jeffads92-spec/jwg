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
            $status = $_GET['status'] ?? 'all';
            $type = $_GET['type'] ?? 'all';
            $date = $_GET['date'] ?? '';
            
            $sql = "SELECT o.*, 
                    GROUP_CONCAT(
                        CONCAT('{\"id\":', oi.id, ',\"name\":\"', mi.name, '\",\"quantity\":', oi.quantity, ',\"price\":', oi.price, '}')
                        SEPARATOR ','
                    ) as items_json
                    FROM orders o
                    LEFT JOIN order_items oi ON o.id = oi.order_id
                    LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id
                    WHERE 1=1";
            
            if ($status !== 'all') {
                $sql .= " AND o.status = :status";
            }
            if ($type !== 'all') {
                $sql .= " AND o.order_type = :type";
            }
            if ($date) {
                $sql .= " AND DATE(o.created_at) = :date";
            }
            
            $sql .= " GROUP BY o.id ORDER BY o.created_at DESC LIMIT 100";
            
            $stmt = $conn->prepare($sql);
            if ($status !== 'all') $stmt->bindValue(':status', $status);
            if ($type !== 'all') $stmt->bindValue(':type', $type);
            if ($date) $stmt->bindValue(':date', $date);
            $stmt->execute();
            
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format items as JSON array
            foreach ($orders as &$order) {
                if ($order['items_json']) {
                    $order['items'] = '[' . $order['items_json'] . ']';
                } else {
                    $order['items'] = '[]';
                }
                unset($order['items_json']);
            }
            
            $response['success'] = true;
            $response['data'] = $orders;
            break;
            
        case 'get':
            $id = $_GET['id'] ?? 0;
            
            $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                // Get order items
                $stmt = $conn->prepare("
                    SELECT oi.*, mi.name 
                    FROM order_items oi
                    JOIN menu_items mi ON oi.menu_item_id = mi.id
                    WHERE oi.order_id = ?
                ");
                $stmt->execute([$id]);
                $order['items'] = json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            
            $response['success'] = true;
            $response['data'] = $order;
            break;
            
        case 'create':
            $data = json_decode(file_get_contents("php://input"), true);
            
            // Generate order number
            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert order
            $stmt = $conn->prepare("
                INSERT INTO orders (
                    order_number, order_type, customer_name, customer_phone, 
                    table_number, total_amount, status, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            
            $stmt->execute([
                $orderNumber,
                $data['order_type'] ?? 'dine-in',
                $data['customer_name'],
                $data['customer_phone'] ?? '',
                $data['table_number'] ?? null,
                $data['total_amount'],
                $data['notes'] ?? ''
            ]);
            
            $orderId = $conn->lastInsertId();
            
            // Insert order items
            if (isset($data['items']) && is_array($data['items'])) {
                $stmt = $conn->prepare("
                    INSERT INTO order_items (order_id, menu_item_id, quantity, price, notes)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($data['items'] as $item) {
                    $stmt->execute([
                        $orderId,
                        $item['menu_item_id'],
                        $item['quantity'],
                        $item['price'],
                        $item['notes'] ?? ''
                    ]);
                }
            }
            
            $response['success'] = true;
            $response['message'] = 'Order created successfully';
            $response['data'] = [
                'order_id' => $orderId,
                'order_number' => $orderNumber
            ];
            break;
            
        case 'update-status':
            $id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);
            
            $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$data['status'], $id]);
            
            $response['success'] = true;
            $response['message'] = 'Order status updated';
            break;
            
        case 'cancel':
            $id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);
            
            $stmt = $conn->prepare("
                UPDATE orders 
                SET status = 'cancelled', notes = CONCAT(notes, '\nCancelled: ', ?), updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$data['reason'] ?? 'No reason', $id]);
            
            $response['success'] = true;
            $response['message'] = 'Order cancelled';
            break;
            
        case 'stats':
            // Active orders
            $stmt = $conn->query("
                SELECT COUNT(*) as count 
                FROM orders 
                WHERE status IN ('pending', 'confirmed', 'preparing', 'ready')
            ");
            $activeOrders = $stmt->fetch()['count'];
            
            // Today's orders
            $stmt = $conn->query("
                SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue
                FROM orders 
                WHERE DATE(created_at) = CURDATE() AND status = 'completed'
            ");
            $today = $stmt->fetch();
            
            // Average order
            $avgOrder = $today['count'] > 0 ? $today['revenue'] / $today['count'] : 0;
            
            $response['success'] = true;
            $response['data'] = [
                'active_orders' => $activeOrders,
                'today_orders' => $today['count'],
                'today_revenue' => $today['revenue'],
                'avg_order' => $avgOrder
            ];
            break;
            
        default:
            $response['message'] = 'Invalid action';
            http_response_code(400);
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Orders API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
