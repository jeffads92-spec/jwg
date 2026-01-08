<?php
/**
 * Digital by Jeff - Reports & Analytics API
 * Handles: Sales reports, analytics, statistics
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

class ReportsAPI {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'dashboard':
                    return $this->getDashboardStats();
                    
                case 'sales-today':
                    return $this->getSalesToday();
                    
                case 'sales-period':
                    return $this->getSalesPeriod();
                    
                case 'top-selling':
                    return $this->getTopSellingItems();
                    
                case 'sales-by-category':
                    return $this->getSalesByCategory();
                    
                case 'sales-by-hour':
                    return $this->getSalesByHour();
                    
                case 'payment-methods':
                    return $this->getPaymentMethods();
                    
                case 'order-sources':
                    return $this->getOrderSources();
                    
                case 'daily-summary':
                    return $this->getDailySummary();
                    
                case 'monthly-comparison':
                    return $this->getMonthlyComparison();
                    
                case 'export-sales':
                    return $this->exportSales();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("Reports API Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Get dashboard statistics
     */
    private function getDashboardStats() {
        $today = date('Y-m-d');
        
        // Today's sales
        $todaySales = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
                SUM(CASE WHEN status = 'completed' AND is_paid = 1 THEN total ELSE 0 END) as total_revenue,
                AVG(CASE WHEN status = 'completed' THEN total ELSE NULL END) as avg_order_value
            FROM orders
            WHERE DATE(created_at) = ?
        ", [$today]);
        
        // Active orders
        $activeOrders = $this->db->fetchOne("
            SELECT COUNT(*) as count
            FROM orders
            WHERE status IN ('pending', 'confirmed', 'preparing', 'ready', 'served')
        ");
        
        // Today's customers
        $todayCustomers = $this->db->fetchOne("
            SELECT COUNT(DISTINCT table_id) as count
            FROM orders
            WHERE DATE(created_at) = ? AND table_id IS NOT NULL
        ", [$today]);
        
        // Occupied tables
        $occupiedTables = $this->db->fetchOne("
            SELECT COUNT(*) as count
            FROM tables
            WHERE status = 'occupied'
        ");
        
        // Low stock alerts
        $lowStockCount = $this->db->fetchOne("
            SELECT COUNT(*) as count
            FROM inventory
            WHERE current_stock <= minimum_stock
        ");
        
        // Kitchen queue
        $kitchenQueue = $this->db->fetchOne("
            SELECT COUNT(*) as count
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE oi.status IN ('pending', 'preparing')
            AND o.status NOT IN ('cancelled', 'completed')
        ");
        
        // Yesterday comparison
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $yesterdaySales = $this->db->fetchOne("
            SELECT SUM(CASE WHEN status = 'completed' AND is_paid = 1 THEN total ELSE 0 END) as revenue
            FROM orders
            WHERE DATE(created_at) = ?
        ", [$yesterday]);
        
        $revenueChange = 0;
        if ($yesterdaySales['revenue'] > 0) {
            $revenueChange = (($todaySales['total_revenue'] - $yesterdaySales['revenue']) / $yesterdaySales['revenue']) * 100;
        }
        
        return $this->success([
            'today' => [
                'date' => $today,
                'total_orders' => (int)$todaySales['total_orders'],
                'completed_orders' => (int)$todaySales['completed_orders'],
                'total_revenue' => (float)$todaySales['total_revenue'],
                'avg_order_value' => (float)$todaySales['avg_order_value'],
                'revenue_change_percent' => round($revenueChange, 2)
            ],
            'current' => [
                'active_orders' => (int)$activeOrders['count'],
                'customers_today' => (int)$todayCustomers['count'],
                'occupied_tables' => (int)$occupiedTables['count'],
                'kitchen_queue' => (int)$kitchenQueue['count'],
                'low_stock_items' => (int)$lowStockCount['count']
            ]
        ]);
    }
    
    /**
     * Get today's sales detail
     */
    private function getSalesToday() {
        $today = date('Y-m-d');
        
        $sql = "
            SELECT 
                o.id, o.order_number, o.created_at, o.completed_at, o.total,
                o.order_type, o.order_source, o.status,
                t.table_number,
                COUNT(oi.id) as items_count
            FROM orders o
            LEFT JOIN tables t ON o.table_id = t.id
            LEFT JOIN order_items oi ON o.id = oi.order_id
            WHERE DATE(o.created_at) = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ";
        
        $orders = $this->db->fetchAll($sql, [$today]);
        
        foreach ($orders as &$order) {
            $order['total'] = (float)$order['total'];
        }
        
        return $this->success($orders);
    }
    
    /**
     * Get sales for a period
     */
    private function getSalesPeriod() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
                SUM(CASE WHEN status = 'completed' AND is_paid = 1 THEN total ELSE 0 END) as revenue,
                AVG(CASE WHEN status = 'completed' THEN total ELSE NULL END) as avg_order_value
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ";
        
        $sales = $this->db->fetchAll($sql, [$dateFrom, $dateTo]);
        
        foreach ($sales as &$row) {
            $row['revenue'] = (float)$row['revenue'];
            $row['avg_order_value'] = (float)$row['avg_order_value'];
        }
        
        // Calculate totals
        $totals = [
            'total_orders' => array_sum(array_column($sales, 'total_orders')),
            'completed_orders' => array_sum(array_column($sales, 'completed_orders')),
            'total_revenue' => array_sum(array_column($sales, 'revenue')),
            'avg_order_value' => count($sales) > 0 ? array_sum(array_column($sales, 'avg_order_value')) / count($sales) : 0
        ];
        
        return $this->success([
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ],
            'daily_sales' => $sales,
            'totals' => $totals
        ]);
    }
    
    /**
     * Get top selling items
     */
    private function getTopSellingItems() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        $limit = $_GET['limit'] ?? 10;
        
        $sql = "
            SELECT 
                m.id, m.name, m.price, m.image,
                c.name as category_name,
                COUNT(oi.id) as order_count,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.subtotal) as total_revenue
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN menu_items m ON oi.menu_item_id = m.id
            JOIN categories c ON m.category_id = c.id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            AND o.status = 'completed'
            GROUP BY m.id
            ORDER BY total_quantity DESC
            LIMIT ?
        ";
        
        $items = $this->db->fetchAll($sql, [$dateFrom, $dateTo, (int)$limit]);
        
        foreach ($items as &$item) {
            $item['price'] = (float)$item['price'];
            $item['total_revenue'] = (float)$item['total_revenue'];
        }
        
        return $this->success($items);
    }
    
    /**
     * Get sales by category
     */
    private function getSalesByCategory() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                c.id, c.name, c.icon,
                COUNT(DISTINCT oi.id) as items_sold,
                SUM(oi.quantity) as total_quantity,
                SUM(oi.subtotal) as total_revenue
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            JOIN menu_items m ON oi.menu_item_id = m.id
            JOIN categories c ON m.category_id = c.id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            AND o.status = 'completed'
            GROUP BY c.id
            ORDER BY total_revenue DESC
        ";
        
        $categories = $this->db->fetchAll($sql, [$dateFrom, $dateTo]);
        
        $totalRevenue = array_sum(array_column($categories, 'total_revenue'));
        
        foreach ($categories as &$cat) {
            $cat['total_revenue'] = (float)$cat['total_revenue'];
            $cat['percentage'] = $totalRevenue > 0 ? round(($cat['total_revenue'] / $totalRevenue) * 100, 2) : 0;
        }
        
        return $this->success($categories);
    }
    
    /**
     * Get sales by hour (peak hours analysis)
     */
    private function getSalesByHour() {
        $date = $_GET['date'] ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as order_count,
                SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as revenue
            FROM orders
            WHERE DATE(created_at) = ?
            GROUP BY HOUR(created_at)
            ORDER BY hour ASC
        ";
        
        $hours = $this->db->fetchAll($sql, [$date]);
        
        // Fill missing hours with 0
        $fullDay = [];
        for ($h = 0; $h < 24; $h++) {
            $found = false;
            foreach ($hours as $hour) {
                if ($hour['hour'] == $h) {
                    $fullDay[] = [
                        'hour' => $h,
                        'hour_label' => sprintf('%02d:00', $h),
                        'order_count' => (int)$hour['order_count'],
                        'revenue' => (float)$hour['revenue']
                    ];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $fullDay[] = [
                    'hour' => $h,
                    'hour_label' => sprintf('%02d:00', $h),
                    'order_count' => 0,
                    'revenue' => 0.0
                ];
            }
        }
        
        return $this->success($fullDay);
    }
    
    /**
     * Get payment methods breakdown
     */
    private function getPaymentMethods() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                payment_method,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount
            FROM payments
            WHERE DATE(paid_at) BETWEEN ? AND ?
            AND payment_status = 'completed'
            GROUP BY payment_method
            ORDER BY total_amount DESC
        ";
        
        $methods = $this->db->fetchAll($sql, [$dateFrom, $dateTo]);
        
        $totalAmount = array_sum(array_column($methods, 'total_amount'));
        
        foreach ($methods as &$method) {
            $method['total_amount'] = (float)$method['total_amount'];
            $method['percentage'] = $totalAmount > 0 ? round(($method['total_amount'] / $totalAmount) * 100, 2) : 0;
        }
        
        return $this->success($methods);
    }
    
    /**
     * Get order sources breakdown
     */
    private function getOrderSources() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                order_source,
                COUNT(*) as order_count,
                SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as revenue
            FROM orders
            WHERE DATE(created_at) BETWEEN ? AND ?
            GROUP BY order_source
            ORDER BY order_count DESC
        ";
        
        $sources = $this->db->fetchAll($sql, [$dateFrom, $dateTo]);
        
        foreach ($sources as &$source) {
            $source['revenue'] = (float)$source['revenue'];
        }
        
        return $this->success($sources);
    }
    
    /**
     * Get daily summary
     */
    private function getDailySummary() {
        $date = $_GET['date'] ?? date('Y-m-d');
        
        // Orders summary
        $orders = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_orders,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
                SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as revenue,
                SUM(CASE WHEN status = 'completed' THEN subtotal ELSE 0 END) as subtotal,
                SUM(CASE WHEN status = 'completed' THEN tax ELSE 0 END) as tax,
                SUM(CASE WHEN status = 'completed' THEN service_charge ELSE 0 END) as service_charge,
                SUM(CASE WHEN status = 'completed' THEN discount ELSE 0 END) as discount
            FROM orders
            WHERE DATE(created_at) = ?
        ", [$date]);
        
        // Payment summary
        $payments = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(amount) as total_collected
            FROM payments
            WHERE DATE(paid_at) = ?
            AND payment_status = 'completed'
        ", [$date]);
        
        // Items sold
        $items = $this->db->fetchOne("
            SELECT 
                COUNT(DISTINCT oi.id) as items_count,
                SUM(oi.quantity) as total_quantity
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.created_at) = ?
            AND o.status = 'completed'
        ", [$date]);
        
        return $this->success([
            'date' => $date,
            'orders' => [
                'total' => (int)$orders['total_orders'],
                'completed' => (int)$orders['completed'],
                'cancelled' => (int)$orders['cancelled'],
                'revenue' => (float)$orders['revenue'],
                'subtotal' => (float)$orders['subtotal'],
                'tax' => (float)$orders['tax'],
                'service_charge' => (float)$orders['service_charge'],
                'discount' => (float)$orders['discount']
            ],
            'payments' => [
                'transactions' => (int)$payments['total_transactions'],
                'total_collected' => (float)$payments['total_collected']
            ],
            'items' => [
                'unique_items' => (int)$items['items_count'],
                'total_quantity' => (int)$items['total_quantity']
            ]
        ]);
    }
    
    /**
     * Get monthly comparison
     */
    private function getMonthlyComparison() {
        $months = $_GET['months'] ?? 6;
        
        $sql = "
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as revenue
            FROM orders
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ";
        
        $data = $this->db->fetchAll($sql, [(int)$months]);
        
        foreach ($data as &$row) {
            $row['revenue'] = (float)$row['revenue'];
        }
        
        return $this->success($data);
    }
    
    /**
     * Export sales data (CSV)
     */
    private function exportSales() {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                o.order_number, o.created_at, o.completed_at,
                t.table_number, o.order_type, o.order_source,
                o.subtotal, o.tax, o.service_charge, o.discount, o.total,
                o.status, p.payment_method
            FROM orders o
            LEFT JOIN tables t ON o.table_id = t.id
            LEFT JOIN payments p ON o.id = p.order_id
            WHERE DATE(o.created_at) BETWEEN ? AND ?
            ORDER BY o.created_at DESC
        ";
        
        $orders = $this->db->fetchAll($sql, [$dateFrom, $dateTo]);
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="sales-report-' . $dateFrom . '-to-' . $dateTo . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'Order Number', 'Created At', 'Completed At', 'Table', 'Type', 'Source',
            'Subtotal', 'Tax', 'Service Charge', 'Discount', 'Total', 'Status', 'Payment Method'
        ]);
        
        // Data
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['order_number'],
                $order['created_at'],
                $order['completed_at'],
                $order['table_number'],
                $order['order_type'],
                $order['order_source'],
                $order['subtotal'],
                $order['tax'],
                $order['service_charge'],
                $order['discount'],
                $order['total'],
                $order['status'],
                $order['payment_method']
            ]);
        }
        
        fclose($output);
        exit();
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

$api = new ReportsAPI();
$api->handleRequest();
?>
