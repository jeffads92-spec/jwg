<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT 
            m.id,
            m.name,
            m.price,
            m.image,
            c.name AS category
        FROM menu_items m
        JOIN categories c ON m.category_id = c.id
        WHERE m.is_available = 1
        AND c.is_active = 1
        ORDER BY c.name, m.name
    ");

    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
