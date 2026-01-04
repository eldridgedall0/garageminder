<?php
/**
 * Secure File Download Handler - Multi-user Ready
 * Validates user access and serves attachment files
 * Supports both entry attachments and vehicle photos
 * Files are stored outside web root for security
 */

require __DIR__ . '/config.php';

// Determine download type
$downloadType = $_GET['type'] ?? 'attachment';

// ========================================
// MULTI-USER: Get current user ID
// ========================================
$userId = 'default';
if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
    $userId = gm_get_current_user_id();
    
    if (!$userId) {
        http_response_code(401);
        die('Authentication required. Please log in.');
    }
}

try {
    $pdo = db_get_pdo();
    
    // ========================================
    // VEHICLE PHOTO DOWNLOAD
    // ========================================
    if ($downloadType === 'vehicle') {
        $vehicleId = $_GET['id'] ?? '';
        
        if (empty($vehicleId)) {
            http_response_code(400);
            die('Vehicle ID required');
        }
        
        // Get vehicle photo path AND verify user owns the vehicle
        $stmt = $pdo->prepare("
            SELECT `id`, `name`, `photo_path`
            FROM `vehicles`
            WHERE `id` = :id AND `user_id` = :uid
        ");
        $stmt->execute([':id' => $vehicleId, ':uid' => $userId]);
        $vehicle = $stmt->fetch();
        
        if (!$vehicle) {
            http_response_code(404);
            die('Vehicle not found or access denied');
        }
        
        if (empty($vehicle['photo_path'])) {
            http_response_code(404);
            die('No photo set for this vehicle');
        }
        
        // Construct full file path
        $filePath = ATTACHMENTS_PATH . '/' . $vehicle['photo_path'];
        
        // Verify file exists
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            die('Photo file not found');
        }
        
        // Security: Verify the file is within the attachments directory (prevent path traversal)
        $realPath = realpath($filePath);
        $realAttachmentsPath = realpath(ATTACHMENTS_PATH);
        
        if ($realPath === false || strpos($realPath, $realAttachmentsPath) !== 0) {
            http_response_code(403);
            die('Access denied');
        }
        
        // Determine MIME type from file
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        // Only allow image types
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes)) {
            http_response_code(403);
            die('Invalid file type');
        }
        
        // Set headers for inline display (photos should display, not download)
        $filename = basename($vehicle['photo_path']);
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
        header('Cache-Control: public, max-age=86400'); // Cache for 24 hours
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
        
        // Output file contents
        readfile($filePath);
        exit;
    }
    
    // ========================================
    // ENTRY ATTACHMENT DOWNLOAD (default)
    // ========================================
    $attachmentId = $_GET['id'] ?? '';
    
    if (empty($attachmentId)) {
        http_response_code(400);
        die('Attachment ID required');
    }
    
    // Get attachment details AND verify user owns the entry
    $stmt = $pdo->prepare("
        SELECT a.`id`, a.`entry_id`, a.`name`, a.`mime_type`, a.`size`, a.`file_path`
        FROM `entry_attachments` a
        JOIN `entries` e ON a.`entry_id` = e.`id`
        JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
        WHERE a.`id` = :id AND v.`user_id` = :uid
    ");
    $stmt->execute([':id' => $attachmentId, ':uid' => $userId]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        http_response_code(404);
        die('Attachment not found or access denied');
    }
    
    // Construct full file path
    $filePath = ATTACHMENTS_PATH . '/' . $attachment['file_path'];
    
    // Verify file exists
    if (!file_exists($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        die('File not found or not readable');
    }
    
    // Security: Verify the file is within the attachments directory (prevent path traversal)
    $realPath = realpath($filePath);
    $realAttachmentsPath = realpath(ATTACHMENTS_PATH);
    
    if ($realPath === false || strpos($realPath, $realAttachmentsPath) !== 0) {
        http_response_code(403);
        die('Access denied');
    }
    
    // Set headers for file download
    header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="' . addslashes($attachment['name']) . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output file contents
    readfile($filePath);
    exit;
    
} catch (Throwable $e) {
    http_response_code(500);
    die('Download failed: ' . $e->getMessage());
}