<?php
require_once __DIR__ . '/config/database.php';
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $stmt = $pdo->query("
            SELECT m.*, c.name AS category_name
            FROM menu_items m
            LEFT JOIN categories c ON m.category_id = c.id
            ORDER BY m.id DESC
        ");
        echo json_encode($stmt->fetchAll());
        exit;
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        throw new Exception('Invalid JSON');
    }

    if ($method === 'POST') {
        $stmt = $pdo->prepare("
            INSERT INTO menu_items 
            (name, price, category_id, image, is_available)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $data['name'],
            $data['price'],
            $data['category_id'],
            $data['image'] ?? null
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'PUT') {
        $stmt = $pdo->prepare("
            UPDATE menu_items 
            SET name=?, price=?, category_id=?, image=?, is_available=?
            WHERE id=?
        ");
        $stmt->execute([
            $data['name'],
            $data['price'],
            $data['category_id'],
            $data['image'],
            $data['is_available'] ?? 1,
            $data['id']
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) throw new Exception('Missing ID');

        $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id=?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
