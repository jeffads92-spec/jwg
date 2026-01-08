<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

$action = $_GET['action'] ?? 'summary';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $period = $_GET['period'] ?? 'month';
    
    // Determine date range
    switch ($period) {
        case 'today':
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d');
            break;
        case 'yesterday':
            $startDate = date('Y-m-d', strtotime('-1 day'));
            $endDate = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'week':
            $startDate = date('Y-m-d', strtotime('-7 days'));
            $endDate = date('Y-m-d');
            break;
        case 'month':
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-d');
            break;
        case 'year':
            $startDate = date('Y-01-01');
            $endDate = date('Y-m-d');
            break;
        default:
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
    }
    
    if ($action === 'summary') {
        // Total Revenue
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total
            FROM orders 
            WHERE DATE(created_at) BETWEEN ? AND ? 
            AND status = 'completed'
        ");
        $stmt->execute([$startDate, $endDate]);
        $totalRevenue = $stmt->fetch()['total'];
        
        // Total Orders
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total
            FROM orders 
            WHERE DATE(created_at) BETWEEN ? AND ? 
            AND status = 'completed'
        ");
        $stmt->execute([$startDate, $endDate]);
        $totalOrders = $stmt->fetch()['total'];
        
        // Average Order Value
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        
        // Total Customers
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT customer_phone) as total
            FROM orders 
            WHERE DATE(created_at) BETWEEN ? AND ?
            AND customer_phone IS NOT NULL AND customer_phone != ''
        ");
        $stmt->execute([$startDate, $endDate]);
        $totalCustomers = $stmt->fetch()['total'];
        
        // Sales Trend (last 7 days)
        $stmt = $conn->prepare("
            SELECT DATE(created_at) as date, COALESCE(SUM(total_amount), 0) as revenue
            FROM orders
            WHERE DATE(created_at) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
            AND status = 'completed'
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stmt->execute([$endDate, $endDate]);
        $salesTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $trendLabels = [];
        $trendValues = [];
        foreach ($salesTrend as $day) {
            $trendLabels[] = date('D', strtotime($day['date']));
            $trendValues[] = (float)$day['revenue'];
        }
        
        // Order Types Distribution
        $stmt = $conn->prepare("
            SELECT order_type, COUNT(*) as count
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            AND status = 'completed'
            GROUP BY order_type
        ");
        $stmt->execute([$startDate, $endDate]);
        $orderTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $typeLabels = [];
        $typeValues = [];
        foreach ($orderTypes as $type) {
            $typeLabels[] = ucfirst(str_replace('-', ' ', $type['order_type']));
            $typeValues[] = (int)$type['count'];
        }
        
        // Revenue by Category
        $stmt = $conn->prepare("
            SELECT c.name, COALESCE(SUM(oi.price * oi.quantity), 0) as revenue
            FROM categories c
            LEFT JOIN menu_items mi ON c.id = mi.category_id
            LEFT JOIN order_items oi ON mi.id = oi.menu_item_id
            LEFT JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            AND o.status = 'completed'
            GROUP BY c.id, c.name
            ORDER BY revenue DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $catLabels = [];
        $catValues = [];
        foreach ($categories as $cat) {
            $catLabels[] = $cat['name'];
            $catValues[] = (float)$cat['revenue'];
        }
        
        // Peak Hours
        $stmt = $conn->prepare("
            SELECT HOUR(created_at) as hour, COUNT(*) as count
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            AND status = 'completed'
            GROUP BY HOUR(created_at)
            ORDER BY hour
        ");
        $stmt->execute([$startDate, $endDate]);
        $peakHours = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $hourCounts = array_fill(0, 24, 0);
        foreach ($peakHours as $hour) {
            $hourCounts[(int)$hour['hour']] = (int)$hour['count'];
        }
        
        // Top Selling Items
        $stmt = $conn->prepare("
            SELECT mi.name, c.name as category, 
                   SUM(oi.quantity) as quantity,
                   SUM(oi.price * oi.quantity) as revenue
            FROM order_items oi
            JOIN menu_items mi ON oi.menu_item_id = mi.id
            JOIN categories c ON mi.category_id = c.id
            JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            AND o.status = 'completed'
            GROUP BY mi.id, mi.name, c.name
            ORDER BY revenue DESC
            LIMIT 10
        ");
        $stmt->execute([$startDate, $endDate]);
        $topItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Payment Methods
        $stmt = $conn->prepare("
            SELECT payment_method as method, 
                   COUNT(*) as count,
                   SUM(total_amount) as amount
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            AND status = 'completed'
            AND payment_method IS NOT NULL
            GROUP BY payment_method
        ");
        $stmt->execute([$startDate, $endDate]);
        $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If no payment methods, create dummy data
        if (empty($paymentMethods)) {
            $paymentMethods = [
                ['method' => 'Cash', 'count' => 0, 'amount' => 0],
                ['method' => 'Card', 'count' => 0, 'amount' => 0],
                ['method' => 'QRIS', 'count' => 0, 'amount' => 0]
            ];
        }
        
        $response['success'] = true;
        $response['data'] = [
            'totalRevenue' => (float)$totalRevenue,
            'totalOrders' => (int)$totalOrders,
            'avgOrderValue' => (float)$avgOrderValue,
            'totalCustomers' => (int)$totalCustomers,
            'revenueChange' => 0,
            'ordersChange' => 0,
            'avgChange' => 0,
            'salesTrend' => [
                'labels' => $trendLabels,
                'values' => $trendValues
            ],
            'orderTypes' => [
                'labels' => empty($typeLabels) ? ['Dine In', 'Takeaway', 'Delivery'] : $typeLabels,
                'values' => empty($typeValues) ? [0, 0, 0] : $typeValues
            ],
            'categories' => [
                'labels' => $catLabels,
                'values' => $catValues
            ],
            'peakHours' => [
                'values' => array_slice($hourCounts, 6, 18) // 6AM to 11PM
            ],
            'topItems' => $topItems,
            'paymentMethods' => $paymentMethods
        ];
    }
    
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Reports API Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
