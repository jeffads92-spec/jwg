<?php
/**
 * Receipt Generator
 * JWG Resto POS System
 * Version: 1.0.0
 */

session_start();

// Include dependencies
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    die('Database connection failed');
}

// Get parameters
$payment_id = $_GET['payment_id'] ?? null;
$format = $_GET['format'] ?? 'html'; // html, print, pdf

if (!$payment_id) {
    die('Payment ID is required');
}

// Get payment data
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        o.order_number,
        o.table_id,
        o.customer_name,
        o.customer_phone,
        o.order_type,
        o.subtotal,
        o.tax,
        o.tax_percentage,
        o.service_charge,
        o.service_charge_percentage,
        o.discount,
        o.total,
        t.table_number,
        u.full_name as cashier_name
    FROM payments p
    LEFT JOIN orders o ON p.order_id = o.id
    LEFT JOIN tables t ON o.table_id = t.id
    LEFT JOIN users u ON p.processed_by = u.id
    WHERE p.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die('Payment not found');
}

// Get order items
$stmt = $pdo->prepare("
    SELECT 
        oi.*,
        m.name as menu_name
    FROM order_items oi
    LEFT JOIN menu_items m ON oi.menu_item_id = m.id
    WHERE oi.order_id = ?
");
$stmt->execute([$payment['order_id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get restaurant settings
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
$settings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$settings = [];
foreach ($settings_raw as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$restaurant_name = $settings['restaurant_name'] ?? 'Restaurant Name';
$restaurant_address = $settings['restaurant_address'] ?? '';
$restaurant_phone = $settings['restaurant_phone'] ?? '';

// Generate receipt HTML
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo $payment['payment_number']; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .receipt {
            max-width: 300px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .logo img {
            max-width: 120px;
            height: auto;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px dashed #333;
        }
        
        .header h1 {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .header p {
            font-size: 10px;
            margin: 2px 0;
        }
        
        .info-section {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #ccc;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
            font-size: 11px;
        }
        
        .info-label {
            font-weight: bold;
        }
        
        .items-section {
            margin-bottom: 15px;
        }
        
        .item {
            margin-bottom: 8px;
        }
        
        .item-header {
            display: flex;
            justify-content: space-between;
            font-weight: bold;
        }
        
        .item-details {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #666;
            margin-top: 2px;
        }
        
        .totals-section {
            margin-bottom: 15px;
            padding-top: 10px;
            border-top: 2px solid #333;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }
        
        .total-row.grand-total {
            font-size: 14px;
            font-weight: bold;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 2px solid #333;
        }
        
        .payment-section {
            margin-bottom: 15px;
            padding: 10px 0;
            border-top: 1px dashed #ccc;
            border-bottom: 1px dashed #ccc;
        }
        
        .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 2px dashed #333;
        }
        
        .footer p {
            margin: 3px 0;
        }
        
        .thank-you {
            font-weight: bold;
            font-size: 12px;
            margin: 10px 0;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .receipt {
                box-shadow: none;
                max-width: 100%;
            }
            
            .no-print {
                display: none;
            }
        }
        
        .print-btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: #00BCD4;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .print-btn:hover {
            background: #0097A7;
        }
    </style>
</head>
<body>
    <div class="receipt">
        <!-- Logo -->
        <div class="logo">
            <img src="../assets/logo.png" alt="Logo" onerror="this.style.display='none'">
        </div>
        
        <!-- Header -->
        <div class="header">
            <h1><?php echo htmlspecialchars($restaurant_name); ?></h1>
            <?php if ($restaurant_address): ?>
                <p><?php echo htmlspecialchars($restaurant_address); ?></p>
            <?php endif; ?>
            <?php if ($restaurant_phone): ?>
                <p>Tel: <?php echo htmlspecialchars($restaurant_phone); ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Order Info -->
        <div class="info-section">
            <div class="info-row">
                <span class="info-label">Order Number:</span>
                <span><?php echo htmlspecialchars($payment['order_number']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Payment Number:</span>
                <span><?php echo htmlspecialchars($payment['payment_number']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date:</span>
                <span><?php echo date('d/m/Y H:i', strtotime($payment['paid_at'])); ?></span>
            </div>
            <?php if ($payment['table_number']): ?>
            <div class="info-row">
                <span class="info-label">Table:</span>
                <span><?php echo htmlspecialchars($payment['table_number']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($payment['customer_name']): ?>
            <div class="info-row">
                <span class="info-label">Customer:</span>
                <span><?php echo htmlspecialchars($payment['customer_name']); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label">Cashier:</span>
                <span><?php echo htmlspecialchars($payment['cashier_name'] ?? 'System'); ?></span>
            </div>
        </div>
        
        <!-- Items -->
        <div class="items-section">
            <?php foreach ($items as $item): ?>
            <div class="item">
                <div class="item-header">
                    <span><?php echo htmlspecialchars($item['menu_name']); ?></span>
                    <span>Rp <?php echo number_format($item['subtotal'], 0, ',', '.'); ?></span>
                </div>
                <div class="item-details">
                    <span><?php echo $item['quantity']; ?> x Rp <?php echo number_format($item['price'], 0, ',', '.'); ?></span>
                </div>
                <?php if ($item['notes']): ?>
                <div class="item-details">
                    <span style="font-style: italic;">Note: <?php echo htmlspecialchars($item['notes']); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Totals -->
        <div class="totals-section">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>Rp <?php echo number_format($payment['subtotal'], 0, ',', '.'); ?></span>
            </div>
            
            <?php if ($payment['discount'] > 0): ?>
            <div class="total-row">
                <span>Discount:</span>
                <span>- Rp <?php echo number_format($payment['discount'], 0, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="total-row">
                <span>Tax (<?php echo $payment['tax_percentage']; ?>%):</span>
                <span>Rp <?php echo number_format($payment['tax'], 0, ',', '.'); ?></span>
            </div>
            
            <div class="total-row">
                <span>Service (<?php echo $payment['service_charge_percentage']; ?>%):</span>
                <span>Rp <?php echo number_format($payment['service_charge'], 0, ',', '.'); ?></span>
            </div>
            
            <div class="total-row grand-total">
                <span>TOTAL:</span>
                <span>Rp <?php echo number_format($payment['total'], 0, ',', '.'); ?></span>
            </div>
        </div>
        
        <!-- Payment Info -->
        <div class="payment-section">
            <div class="total-row">
                <span>Payment Method:</span>
                <span><?php echo strtoupper($payment['payment_method']); ?></span>
            </div>
            
            <?php if ($payment['payment_method'] === 'cash'): ?>
            <div class="total-row">
                <span>Paid:</span>
                <span>Rp <?php echo number_format($payment['paid_amount'], 0, ',', '.'); ?></span>
            </div>
            <div class="total-row">
                <span>Change:</span>
                <span>Rp <?php echo number_format($payment['change_amount'], 0, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="total-row">
                <span>Status:</span>
                <span><?php echo strtoupper($payment['payment_status']); ?></span>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p class="thank-you">THANK YOU!</p>
            <p>Please come again</p>
            <p style="margin-top: 10px; font-size: 9px;">
                Powered by <strong>JWG Digital</strong>
            </p>
        </div>
    </div>
    
    <!-- Print Button -->
    <button class="print-btn no-print" onclick="window.print()">
        🖨️ Print Receipt
    </button>
    
    <script>
        // Auto print if print parameter is set
        <?php if (isset($_GET['auto_print']) && $_GET['auto_print'] === '1'): ?>
        window.onload = function() {
            window.print();
        };
        <?php endif; ?>
    </script>
</body>
</html>
