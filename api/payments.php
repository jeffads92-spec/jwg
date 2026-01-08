<?php
/**
 * Payment Processing API
 * JWG Resto POS System
 * Version: 1.0.0
 */

session_start();
header('Content-Type: application/json');

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'System error: ' . $errstr
    ]);
    exit;
});

// Include dependencies
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed'
    ]);
    exit;
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Route to appropriate handler
switch ($action) {
    case 'list':
        handleListPayments($pdo);
        break;
    case 'process':
        handleProcessPayment($pdo);
        break;
    case 'get':
        handleGetPayment($pdo);
        break;
    case 'split':
        handleSplitBill($pdo);
        break;
    case 'refund':
        handleRefund($pdo);
        break;
    case 'pending-orders':
        handlePendingOrders($pdo);
        break;
    case 'calculate':
        handleCalculateTotal($pdo);
        break;
    default:
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid action'
        ]);
        exit;
}

/**
 * List all payments
 */
function handleListPayments($pdo) {
    try {
        // Get filters
        $date = $_GET['date'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? 'all';
        $method = $_GET['method'] ?? 'all';
        
        $sql = "SELECT 
                    p.*,
                    o.order_number,
                    o.table_id,
                    t.table_number,
                    o.customer_name,
                    u.full_name as processed_by_name
                FROM payments p
                LEFT JOIN orders o ON p.order_id = o.id
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN users u ON p.processed_by = u.id
                WHERE DATE(p.created_at) = ?";
        
        $params = [$date];
        
        if ($status !== 'all') {
            $sql .= " AND p.payment_status = ?";
            $params[] = $status;
        }
        
        if ($method !== 'all') {
            $sql .= " AND p.payment_method = ?";
            $params[] = $method;
        }
        
        $sql .= " ORDER BY p.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate summary
        $total_amount = 0;
        $completed_count = 0;
        
        foreach ($payments as $payment) {
            if ($payment['payment_status'] === 'completed') {
                $total_amount += $payment['amount'];
                $completed_count++;
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => $payments,
            'summary' => [
                'total_payments' => count($payments),
                'completed_payments' => $completed_count,
                'total_amount' => $total_amount
            ]
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch payments: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get pending orders (unpaid)
 */
function handlePendingOrders($pdo) {
    try {
        $sql = "SELECT 
                    o.*,
                    t.table_number,
                    COUNT(oi.id) as total_items,
                    SUM(oi.quantity) as total_quantity
                FROM orders o
                LEFT JOIN tables t ON o.table_id = t.id
                LEFT JOIN order_items oi ON o.id = oi.order_id
                WHERE o.is_paid = 0 
                AND o.status NOT IN ('cancelled', 'completed')
                GROUP BY o.id
                ORDER BY o.created_at DESC";
        
        $stmt = $pdo->query($sql);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'data' => $orders
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch pending orders: ' . $e->getMessage()
        ]);
    }
}

/**
 * Calculate order total with tax and service charge
 */
function handleCalculateTotal($pdo) {
    try {
        $order_id = $_POST['order_id'] ?? null;
        
        if (!$order_id) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order ID is required'
            ]);
            return;
        }
        
        // Get order
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]);
            return;
        }
        
        // Get order items
        $stmt = $pdo->prepare("
            SELECT oi.*, m.name as menu_name
            FROM order_items oi
            LEFT JOIN menu_items m ON oi.menu_item_id = m.id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate subtotal
        $subtotal = 0;
        foreach ($items as $item) {
            $subtotal += $item['subtotal'];
        }
        
        // Get discount (if any)
        $discount = floatval($order['discount']);
        
        // Calculate after discount
        $after_discount = $subtotal - $discount;
        
        // Calculate tax
        $tax_percentage = floatval($order['tax_percentage']);
        $tax = round($after_discount * ($tax_percentage / 100), 2);
        
        // Calculate service charge
        $service_percentage = floatval($order['service_charge_percentage']);
        $service_charge = round($after_discount * ($service_percentage / 100), 2);
        
        // Calculate total
        $total = $after_discount + $tax + $service_charge;
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'order_id' => $order_id,
                'order_number' => $order['order_number'],
                'items' => $items,
                'calculation' => [
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'after_discount' => $after_discount,
                    'tax_percentage' => $tax_percentage,
                    'tax' => $tax,
                    'service_charge_percentage' => $service_percentage,
                    'service_charge' => $service_charge,
                    'total' => $total
                ]
            ]
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to calculate total: ' . $e->getMessage()
        ]);
    }
}

/**
 * Process payment
 */
function handleProcessPayment($pdo) {
    try {
        // Get input
        $order_id = $_POST['order_id'] ?? null;
        $payment_method = $_POST['payment_method'] ?? null;
        $paid_amount = $_POST['paid_amount'] ?? null;
        
        // Validate
        if (!$order_id || !$payment_method) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order ID and payment method are required'
            ]);
            return;
        }
        
        // Get order
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]);
            return;
        }
        
        // Check if already paid
        if ($order['is_paid']) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order already paid'
            ]);
            return;
        }
        
        $total_amount = floatval($order['total']);
        $paid_amount = $paid_amount ? floatval($paid_amount) : $total_amount;
        
        // Calculate change for cash payment
        $change = 0;
        if ($payment_method === 'cash') {
            if ($paid_amount < $total_amount) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Insufficient payment amount'
                ]);
                return;
            }
            $change = $paid_amount - $total_amount;
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Generate payment number
            $payment_number = generatePaymentNumber($pdo);
            
            // Get current user ID (from session)
            $user_id = $_SESSION['user_id'] ?? null;
            
            // Insert payment
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    payment_number, order_id, amount, payment_method,
                    payment_status, paid_amount, change_amount,
                    notes, processed_by, paid_at
                ) VALUES (?, ?, ?, ?, 'completed', ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $payment_number,
                $order_id,
                $total_amount,
                $payment_method,
                $paid_amount,
                $change,
                $_POST['notes'] ?? null,
                $user_id
            ]);
            
            $payment_id = $pdo->lastInsertId();
            
            // Update order status
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET is_paid = 1, paid_at = NOW(), status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$order_id]);
            
            // Update table status (if dine-in)
            if ($order['table_id']) {
                $stmt = $pdo->prepare("
                    UPDATE tables 
                    SET status = 'cleaning', current_order_id = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$order['table_id']]);
            }
            
            // Log activity
            logActivity($pdo, $user_id, 'process_payment', 'payment', $payment_id, [
                'order_id' => $order_id,
                'amount' => $total_amount,
                'method' => $payment_method
            ]);
            
            $pdo->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment processed successfully',
                'data' => [
                    'payment_id' => $payment_id,
                    'payment_number' => $payment_number,
                    'order_number' => $order['order_number'],
                    'amount' => $total_amount,
                    'paid_amount' => $paid_amount,
                    'change' => $change,
                    'payment_method' => $payment_method
                ]
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to process payment: ' . $e->getMessage()
        ]);
    }
}

/**
 * Split bill
 */
function handleSplitBill($pdo) {
    try {
        $order_id = $_POST['order_id'] ?? null;
        $splits = $_POST['splits'] ?? null; // Array of splits
        
        if (!$order_id || !$splits) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order ID and splits are required'
            ]);
            return;
        }
        
        // Decode splits if JSON
        if (is_string($splits)) {
            $splits = json_decode($splits, true);
        }
        
        // Get order
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Order not found'
            ]);
            return;
        }
        
        $total_amount = floatval($order['total']);
        
        // Validate splits total
        $splits_total = 0;
        foreach ($splits as $split) {
            $splits_total += floatval($split['amount']);
        }
        
        if (abs($splits_total - $total_amount) > 0.01) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Splits total does not match order total'
            ]);
            return;
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            $payment_number = generatePaymentNumber($pdo);
            $user_id = $_SESSION['user_id'] ?? null;
            
            // Create main payment record
            $stmt = $pdo->prepare("
                INSERT INTO payments (
                    payment_number, order_id, amount, payment_method,
                    payment_status, notes, processed_by, paid_at
                ) VALUES (?, ?, ?, 'split', 'completed', 'Split bill', ?, NOW())
            ");
            
            $stmt->execute([
                $payment_number,
                $order_id,
                $total_amount,
                $user_id
            ]);
            
            $payment_id = $pdo->lastInsertId();
            
            // Create split records
            $split_number = 1;
            foreach ($splits as $split) {
                $stmt = $pdo->prepare("
                    INSERT INTO payment_splits (
                        payment_id, split_number, amount, payment_method,
                        payment_status, paid_at
                    ) VALUES (?, ?, ?, ?, 'completed', NOW())
                ");
                
                $stmt->execute([
                    $payment_id,
                    $split_number,
                    $split['amount'],
                    $split['method']
                ]);
                
                $split_number++;
            }
            
            // Update order
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET is_paid = 1, paid_at = NOW(), status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$order_id]);
            
            // Update table
            if ($order['table_id']) {
                $stmt = $pdo->prepare("
                    UPDATE tables 
                    SET status = 'cleaning', current_order_id = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$order['table_id']]);
            }
            
            logActivity($pdo, $user_id, 'split_bill', 'payment', $payment_id, [
                'order_id' => $order_id,
                'splits_count' => count($splits)
            ]);
            
            $pdo->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Split bill processed successfully',
                'data' => [
                    'payment_id' => $payment_id,
                    'payment_number' => $payment_number,
                    'splits_count' => count($splits)
                ]
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to process split bill: ' . $e->getMessage()
        ]);
    }
}

/**
 * Refund payment
 */
function handleRefund($pdo) {
    try {
        $payment_id = $_POST['payment_id'] ?? null;
        $reason = $_POST['reason'] ?? null;
        
        if (!$payment_id) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Payment ID is required'
            ]);
            return;
        }
        
        // Get payment
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Payment not found'
            ]);
            return;
        }
        
        if ($payment['payment_status'] === 'refunded') {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Payment already refunded'
            ]);
            return;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update payment
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET payment_status = 'refunded', refunded_at = NOW(), refund_reason = ?
                WHERE id = ?
            ");
            $stmt->execute([$reason, $payment_id]);
            
            // Update order
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET is_paid = 0, paid_at = NULL, status = 'cancelled'
                WHERE id = ?
            ");
            $stmt->execute([$payment['order_id']]);
            
            $user_id = $_SESSION['user_id'] ?? null;
            logActivity($pdo, $user_id, 'refund_payment', 'payment', $payment_id, [
                'reason' => $reason
            ]);
            
            $pdo->commit();
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Payment refunded successfully'
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to refund payment: ' . $e->getMessage()
        ]);
    }
}

/**
 * Get single payment details
 */
function handleGetPayment($pdo) {
    try {
        $payment_id = $_GET['id'] ?? null;
        
        if (!$payment_id) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Payment ID is required'
            ]);
            return;
        }
        
        // Get payment with related data
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                o.order_number,
                o.table_id,
                t.table_number,
                o.customer_name,
                u.full_name as processed_by_name
            FROM payments p
            LEFT JOIN orders o ON p.order_id = o.id
            LEFT JOIN tables t ON o.table_id = t.id
            LEFT JOIN users u ON p.processed_by = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Payment not found'
            ]);
            return;
        }
        
        // Get splits if any
        $stmt = $pdo->prepare("SELECT * FROM payment_splits WHERE payment_id = ?");
        $stmt->execute([$payment_id]);
        $splits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $payment['splits'] = $splits;
        
        echo json_encode([
            'status' => 'success',
            'data' => $payment
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch payment: ' . $e->getMessage()
        ]);
    }
}

/**
 * Helper: Generate payment number
 */
function generatePaymentNumber($pdo) {
    $date = date('Ymd');
    $prefix = 'PAY-' . $date . '-';
    
    $stmt = $pdo->prepare("
        SELECT payment_number 
        FROM payments 
        WHERE payment_number LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetch();
    
    if ($last) {
        $lastNum = intval(substr($last['payment_number'], -4));
        $newNum = $lastNum + 1;
    } else {
        $newNum = 1;
    }
    
    return $prefix . str_pad($newNum, 4, '0', STR_PAD_LEFT);
}

/**
 * Helper: Log activity
 */
function logActivity($pdo, $user_id, $action, $entity_type, $entity_id, $details = []) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (
                user_id, action, entity_type, entity_id, ip_address, user_agent, details
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $user_id,
            $action,
            $entity_type,
            $entity_id,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            json_encode($details)
        ]);
    } catch (PDOException $e) {
        // Log error but don't fail the main operation
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>
