<?php
/**
 * Digital by Jeff - QR Code Generator API
 * Handles: Generate QR codes for tables
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';

class QRGeneratorAPI {
    private $db;
    private $qrCodePath;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->qrCodePath = __DIR__ . '/../qr-codes/';
        
        // Create directory if not exists
        if (!file_exists($this->qrCodePath)) {
            mkdir($this->qrCodePath, 0755, true);
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'generate':
                    return $this->generateQRCode();
                    
                case 'generate-all':
                    return $this->generateAllQRCodes();
                    
                case 'download':
                    return $this->downloadQRCode();
                    
                case 'delete':
                    return $this->deleteQRCode();
                    
                case 'list':
                    return $this->listQRCodes();
                    
                default:
                    return $this->error('Invalid action', 400);
            }
        } catch (Exception $e) {
            error_log("QR Generator Error: " . $e->getMessage());
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * Generate QR code for a table
     */
    private function generateQRCode() {
        $tableId = $_GET['table_id'] ?? null;
        
        if (!$tableId) {
            return $this->error('Table ID is required', 400);
        }
        
        // Get table info
        $table = $this->db->fetchOne("SELECT * FROM tables WHERE id = ?", [$tableId]);
        
        if (!$table) {
            return $this->error('Table not found', 404);
        }
        
        // Generate QR code URL
        $baseUrl = $this->getBaseUrl();
        $qrUrl = "{$baseUrl}/customer/index.html?table={$tableId}";
        
        // Generate QR code image
        $qrCodeFile = $this->generateQRCodeImage($qrUrl, $table['table_number']);
        
        // Update table with QR code path
        $this->db->update('tables',
            ['qr_code' => $qrCodeFile],
            'id = ?',
            [$tableId]
        );
        
        return $this->success([
            'table_id' => $tableId,
            'table_number' => $table['table_number'],
            'qr_code' => $qrCodeFile,
            'qr_url' => $qrUrl,
            'qr_image_url' => "{$baseUrl}/qr-codes/{$qrCodeFile}"
        ], 'QR code generated successfully');
    }
    
    /**
     * Generate QR codes for all tables
     */
    private function generateAllQRCodes() {
        $tables = $this->db->fetchAll("SELECT * FROM tables ORDER BY table_number");
        
        $baseUrl = $this->getBaseUrl();
        $generated = [];
        
        foreach ($tables as $table) {
            $qrUrl = "{$baseUrl}/customer/index.html?table={$table['id']}";
            $qrCodeFile = $this->generateQRCodeImage($qrUrl, $table['table_number']);
            
            // Update table
            $this->db->update('tables',
                ['qr_code' => $qrCodeFile],
                'id = ?',
                [$table['id']]
            );
            
            $generated[] = [
                'table_id' => $table['id'],
                'table_number' => $table['table_number'],
                'qr_code' => $qrCodeFile
            ];
        }
        
        return $this->success($generated, count($generated) . ' QR codes generated successfully');
    }
    
    /**
     * Download QR code
     */
    private function downloadQRCode() {
        $tableId = $_GET['table_id'] ?? null;
        
        if (!$tableId) {
            return $this->error('Table ID is required', 400);
        }
        
        $table = $this->db->fetchOne("SELECT * FROM tables WHERE id = ?", [$tableId]);
        
        if (!$table || !$table['qr_code']) {
            return $this->error('QR code not found', 404);
        }
        
        $filePath = $this->qrCodePath . $table['qr_code'];
        
        if (!file_exists($filePath)) {
            return $this->error('QR code file not found', 404);
        }
        
        // Send file for download
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="table-' . $table['table_number'] . '-qr.png"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit();
    }
    
    /**
     * Delete QR code
     */
    private function deleteQRCode() {
        $tableId = $_GET['table_id'] ?? null;
        
        if (!$tableId) {
            return $this->error('Table ID is required', 400);
        }
        
        $table = $this->db->fetchOne("SELECT qr_code FROM tables WHERE id = ?", [$tableId]);
        
        if (!$table) {
            return $this->error('Table not found', 404);
        }
        
        if ($table['qr_code']) {
            $filePath = $this->qrCodePath . $table['qr_code'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Update table
        $this->db->update('tables', ['qr_code' => null], 'id = ?', [$tableId]);
        
        return $this->success(null, 'QR code deleted successfully');
    }
    
    /**
     * List all QR codes
     */
    private function listQRCodes() {
        $sql = "SELECT id, table_number, capacity, location, qr_code
                FROM tables
                ORDER BY table_number";
        
        $tables = $this->db->fetchAll($sql);
        
        $baseUrl = $this->getBaseUrl();
        
        foreach ($tables as &$table) {
            if ($table['qr_code']) {
                $table['qr_image_url'] = "{$baseUrl}/qr-codes/{$table['qr_code']}";
                $table['customer_url'] = "{$baseUrl}/customer/index.html?table={$table['id']}";
            }
        }
        
        return $this->success($tables);
    }
    
    /**
     * Generate QR code image using Google Charts API
     */
    private function generateQRCodeImage($data, $tableNumber) {
        // Use Google Charts API for QR code generation
        $size = '300x300';
        $qrUrl = "https://chart.googleapis.com/chart?chs={$size}&cht=qr&chl=" . urlencode($data) . "&choe=UTF-8";
        
        // Download QR code image
        $imageData = file_get_contents($qrUrl);
        
        if (!$imageData) {
            throw new Exception("Failed to generate QR code");
        }
        
        // Save to file
        $filename = "table-{$tableNumber}-" . time() . ".png";
        $filePath = $this->qrCodePath . $filename;
        
        file_put_contents($filePath, $imageData);
        
        // Add branding to QR code (optional)
        $this->addBrandingToQRCode($filePath, $tableNumber);
        
        return $filename;
    }
    
    /**
     * Add branding/text to QR code
     */
    private function addBrandingToQRCode($imagePath, $tableNumber) {
        // Check if GD library is available
        if (!function_exists('imagecreatefrompng')) {
            return;
        }
        
        try {
            $image = imagecreatefrompng($imagePath);
            $width = imagesx($image);
            $height = imagesy($image);
            
            // Create new image with extra space for text
            $newHeight = $height + 80;
            $newImage = imagecreatetruecolor($width, $newHeight);
            
            // White background
            $white = imagecolorallocate($newImage, 255, 255, 255);
            $black = imagecolorallocate($newImage, 0, 0, 0);
            imagefill($newImage, 0, 0, $white);
            
            // Copy QR code
            imagecopy($newImage, $image, 0, 0, 0, 0, $width, $height);
            
            // Add text
            $text1 = "Scan to Order";
            $text2 = "Table: " . $tableNumber;
            $text3 = "Digital by Jeff";
            
            // Use default font
            imagestring($newImage, 5, ($width - strlen($text1) * 9) / 2, $height + 5, $text1, $black);
            imagestring($newImage, 5, ($width - strlen($text2) * 9) / 2, $height + 25, $text2, $black);
            imagestring($newImage, 3, ($width - strlen($text3) * 6) / 2, $height + 50, $text3, $black);
            
            // Save
            imagepng($newImage, $imagePath);
            
            // Cleanup
            imagedestroy($image);
            imagedestroy($newImage);
            
        } catch (Exception $e) {
            error_log("Failed to add branding to QR code: " . $e->getMessage());
        }
    }
    
    /**
     * Get base URL
     */
    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Remove /api/qr-generator.php from path
        $path = dirname(dirname($_SERVER['SCRIPT_NAME']));
        
        return "{$protocol}://{$host}{$path}";
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

$api = new QRGeneratorAPI();
$api->handleRequest();
?>
