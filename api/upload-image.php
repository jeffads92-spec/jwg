<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$response = ['success' => false, 'message' => '', 'url' => null];

try {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No image uploaded or upload error');
    }
    
    $file = $_FILES['image'];
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP allowed');
    }
    
    // Max 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File too large. Maximum 5MB');
    }
    
    // Create uploads directory if not exists
    $uploadDir = __DIR__ . '/../uploads/menu/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'menu_' . uniqid() . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Return relative URL
    $imageUrl = '/uploads/menu/' . $filename;
    
    $response['success'] = true;
    $response['message'] = 'Image uploaded successfully';
    $response['url'] = $imageUrl;
    $response['filename'] = $filename;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Upload Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);
?>
