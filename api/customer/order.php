<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

try {
    if (!$data || empty($data['items'])) {
        throw new Exception('Order items kosong');
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO orders (total_amount, status, created_at)
        VALUES (?, 'pending', NOW())
    ");
    $stmt->execute([$data['total_amount']]);
    $orderId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO order_items 
        (order_id, menu_id, quantity, price)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($data['items'] as $item) {
        $itemStmt->execute([
            $orderId,
            $item['id'],
            $item['quantity'],
            $item['price']
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'order_id' => $orderId
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
