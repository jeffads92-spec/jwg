<?php
require_once __DIR__ . '/config/database.php';
$cloudinary = require __DIR__ . '/config/cloudinary.php';

header('Content-Type: application/json');

try {
    if (!isset($_FILES['image'])) {
        throw new Exception('No image uploaded');
    }

    $file = $_FILES['image']['tmp_name'];

    $upload = $cloudinary->uploadApi()->upload($file, [
        'folder' => 'restaurant/menu',
        'resource_type' => 'image'
    ]);

    echo json_encode([
        'success' => true,
        'url' => $upload['secure_url']
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
