<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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
            
            $sql = "SELECT o.*, 
                    GROUP_CONCAT(
                        CONCAT('{\"id\":', oi.id, ',\"name\":\"', mi.name, '\",\"quantity\":', oi.quantity, ',\"notes\":\"', COALESCE(oi.notes, ''), '\"}')
                        SEPARATOR ','
                    ) as items_json
                    FROM orders o
                    LEFT JOIN order_items oi ON o.id = oi.order_id
                    LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id
                    WHERE o.status IN ('pending', 'confirmed', 'preparing', 'ready')";
            
            if ($status !== 'all') {
                $sql .= " AND o.status = :status";
            }
            
            $sql .= " GROUP BY o.id ORDER BY o.created_at ASC";
            
            $stmt = $conn->prepare($sql);
            if ($status !== 'all') {
                $stmt->bindValue(':status', $status);
            }
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
                
                // Add timestamps if available
                $order['timestamps'] = [
                    'pending' => $order['created_at'],
                    'confirmed' => $order['created_at'],
                    'preparing' => $order['updated_at'] ?? $order['created_at'],
                    'ready' => $order['updated_at'] ?? null
                ];
            }
            
            $response['success'] = true;
            $response['data'] = $orders;
            break;
            
        case 'update-status':
            $id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);
            
            $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$data['status'], $id]);
            
            $response['success'] = true;
            $response['message'] = 'Status updated successfully';
            break;
            
        case 'reject':
            $id = $_GET['id'] ?? 0;
            $data = json_decode(file_get_contents("php://input"), true);
            
            $stmt = $conn->prepare("
                UPDATE orders 
                SET status = 'cancelled', 
                    notes = CONCAT(COALESCE(notes, ''), '\nRejected by kitchen: ', ?) 
                WHERE id = ?
            ");
            $stmt->execute([$data['reason'] ?? 'No reason provided', $id]);
            
            $response['success'] = true;
            $response['message'] = 'Order rejected';
            break;
            
        default:
            $response['message'] = 'Invalid action';
            http_response_code(400);
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Kitchen API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
