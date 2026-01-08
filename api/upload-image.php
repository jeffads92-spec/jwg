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
    
    // Check if Cloudinary is configured
    $cloudinaryCloudName = getenv('CLOUDINARY_CLOUD_NAME');
    $cloudinaryApiKey = getenv('CLOUDINARY_API_KEY');
    $cloudinaryApiSecret = getenv('CLOUDINARY_API_SECRET');
    
    if ($cloudinaryCloudName && $cloudinaryApiKey && $cloudinaryApiSecret) {
        // Upload to Cloudinary
        $imageUrl = uploadToCloudinary($file, $cloudinaryCloudName, $cloudinaryApiKey, $cloudinaryApiSecret);
        
        $response['success'] = true;
        $response['message'] = 'Image uploaded successfully to cloud';
        $response['url'] = $imageUrl;
        $response['storage'] = 'cloudinary';
        
    } else {
        // Fallback: Local upload (WARNING: akan hilang saat redeploy di Railway!)
        $uploadDir = __DIR__ . '/../uploads/menu/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'menu_' . uniqid() . '_' . time() . '.' . $extension;
        $filepath = $uploadDir . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to move uploaded file');
        }
        
        $imageUrl = '/uploads/menu/' . $filename;
        
        $response['success'] = true;
        $response['message'] = 'Image uploaded successfully (local storage - will be deleted on redeploy!)';
        $response['url'] = $imageUrl;
        $response['filename'] = $filename;
        $response['storage'] = 'local';
        $response['warning'] = 'Local storage is not persistent on Railway. Please configure Cloudinary!';
    }
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    error_log("Upload Error: " . $e->getMessage());
    http_response_code(500);
}

echo json_encode($response);

/**
 * Upload to Cloudinary using API
 */
function uploadToCloudinary($file, $cloudName, $apiKey, $apiSecret) {
    $timestamp = time();
    $publicId = 'jwg-menu/' . uniqid();
    
    // Generate signature
    $paramsToSign = "public_id={$publicId}&timestamp={$timestamp}{$apiSecret}";
    $signature = sha1($paramsToSign);
    
    // Prepare upload data
    $uploadData = [
        'file' => new CURLFile($file['tmp_name'], $file['type'], $file['name']),
        'public_id' => $publicId,
        'timestamp' => $timestamp,
        'api_key' => $apiKey,
        'signature' => $signature,
        'folder' => 'jwg-menu'
    ];
    
    // Upload to Cloudinary
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $uploadData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception('Cloudinary upload failed: ' . $result);
    }
    
    $resultData = json_decode($result, true);
    
    if (!isset($resultData['secure_url'])) {
        throw new Exception('Cloudinary did not return image URL');
    }
    
    return $resultData['secure_url'];
}
?>
