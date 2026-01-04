<?php
/**
 * Vehicle Photo Upload Handler - Multi-user Ready
 * Handles photo uploads for vehicles
 * Stores files in user-specific directories
 * 
 * Usage: POST /vehicle-photo.php
 *   - vehicle_id: The vehicle ID
 *   - photo: The uploaded file
 * 
 * Or DELETE /vehicle-photo.php?vehicle_id=xxx to remove photo
 */

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

// Set security headers
gm_set_security_headers();

// ========================================
// MULTI-USER: Get current user ID
// ========================================
$userId = 'default';
if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
    $userId = gm_get_current_user_id();
    
    if (!$userId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'authentication_required',
            'message' => 'Please log in to continue'
        ]);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// ========================================
// DELETE - Remove vehicle photo
// ========================================
if ($method === 'DELETE' || ($method === 'POST' && isset($_POST['_method']) && $_POST['_method'] === 'DELETE')) {
    $vehicleId = $_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? '';
    
    if (empty($vehicleId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Vehicle ID required']);
        exit;
    }
    
    try {
        $pdo = db_get_pdo();
        
        // Verify ownership
        if (!gm_user_owns_vehicle($pdo, $userId, $vehicleId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
        
        // Get current photo path
        $stmt = $pdo->prepare("SELECT `photo_path` FROM `vehicles` WHERE `id` = :id");
        $stmt->execute([':id' => $vehicleId]);
        $vehicle = $stmt->fetch();
        
        if ($vehicle && !empty($vehicle['photo_path'])) {
            // Delete the file
            $fullPath = ATTACHMENTS_PATH . '/' . $vehicle['photo_path'];
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
            
            // Clear the database field
            $stmt = $pdo->prepare("UPDATE `vehicles` SET `photo_path` = NULL WHERE `id` = :id");
            $stmt->execute([':id' => $vehicleId]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Photo removed']);
        
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to remove photo: ' . $e->getMessage()]);
    }
    exit;
}

// ========================================
// POST - Upload vehicle photo
// ========================================
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get vehicle ID
$vehicleId = $_POST['vehicle_id'] ?? '';
if (empty($vehicleId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vehicle ID required']);
    exit;
}

// Check if file was uploaded
if (empty($_FILES['photo'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No photo uploaded']);
    exit;
}

$file = $_FILES['photo'];

// Validate file
if ($file['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server size limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form size limit',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by extension',
    ];
    $msg = $errorMessages[$file['error']] ?? 'Unknown upload error';
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

// Validate file type (images only)
$allowedImageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExtensions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP']);
    exit;
}

// Verify MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedImageTypes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid image file']);
    exit;
}

// Check file size (max 5MB for photos)
$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize || $file['size'] <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File size must be between 1 byte and 5MB']);
    exit;
}

try {
    $pdo = db_get_pdo();
    
    // Verify vehicle exists AND belongs to user
    if (!gm_user_owns_vehicle($pdo, $userId, $vehicleId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Vehicle not found or access denied']);
        exit;
    }
    
    // Get current photo to delete later
    $stmt = $pdo->prepare("SELECT `photo_path` FROM `vehicles` WHERE `id` = :id");
    $stmt->execute([':id' => $vehicleId]);
    $vehicle = $stmt->fetch();
    $oldPhotoPath = $vehicle['photo_path'] ?? null;
    
    // Create directory structure: /attachments/{user_id}/vehicles/
    $userDir = get_user_attachments_path($userId);
    $vehiclesDir = $userDir . '/vehicles';
    
    if (!is_dir($vehiclesDir)) {
        if (!mkdir($vehiclesDir, 0755, true)) {
            throw new RuntimeException('Failed to create vehicles photo directory');
        }
    }
    
    // Generate filename: {vehicle_id}_{timestamp}.{ext}
    $safeVehicleId = preg_replace('/[^a-zA-Z0-9_-]/', '', $vehicleId);
    $filename = $safeVehicleId . '_' . time() . '.' . $ext;
    $filePath = $vehiclesDir . '/' . $filename;
    
    // Resize image if needed (max 1200px width, maintain aspect ratio)
    $resized = resizeImageIfNeeded($file['tmp_name'], $mimeType, 1200);
    
    if ($resized) {
        // Save resized image
        if (!file_put_contents($filePath, $resized)) {
            throw new RuntimeException('Failed to save photo');
        }
    } else {
        // Move original file
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            throw new RuntimeException('Failed to save photo');
        }
    }
    
    // Update database with relative path
    $relativePath = $userId . '/vehicles/' . $filename;
    $stmt = $pdo->prepare("UPDATE `vehicles` SET `photo_path` = :path WHERE `id` = :id");
    $stmt->execute([':path' => $relativePath, ':id' => $vehicleId]);
    
    // Delete old photo file if it exists
    if ($oldPhotoPath) {
        $oldFullPath = ATTACHMENTS_PATH . '/' . $oldPhotoPath;
        if (file_exists($oldFullPath)) {
            @unlink($oldFullPath);
        }
    }
    
    echo json_encode([
        'success' => true,
        'photoPath' => $relativePath,
        'message' => 'Photo uploaded successfully'
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Upload failed: ' . $e->getMessage()
    ]);
}

/**
 * Resize image if it exceeds max width
 * Returns resized image data or false if no resize needed
 */
function resizeImageIfNeeded(string $tmpPath, string $mimeType, int $maxWidth): ?string {
    // Get image dimensions
    $info = getimagesize($tmpPath);
    if (!$info) {
        return null;
    }
    
    $width = $info[0];
    $height = $info[1];
    
    // No resize needed if already small enough
    if ($width <= $maxWidth) {
        return null;
    }
    
    // Calculate new dimensions
    $ratio = $maxWidth / $width;
    $newWidth = $maxWidth;
    $newHeight = (int)($height * $ratio);
    
    // Create image resource based on type
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            $src = imagecreatefromjpeg($tmpPath);
            break;
        case 'image/png':
            $src = imagecreatefrompng($tmpPath);
            break;
        case 'image/gif':
            $src = imagecreatefromgif($tmpPath);
            break;
        case 'image/webp':
            $src = imagecreatefromwebp($tmpPath);
            break;
        default:
            return null;
    }
    
    if (!$src) {
        return null;
    }
    
    // Create resized image
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Resize
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // Output to buffer
    ob_start();
    switch ($mimeType) {
        case 'image/jpeg':
        case 'image/jpg':
            imagejpeg($dst, null, 85);
            break;
        case 'image/png':
            imagepng($dst, null, 8);
            break;
        case 'image/gif':
            imagegif($dst);
            break;
        case 'image/webp':
            imagewebp($dst, null, 85);
            break;
    }
    $data = ob_get_clean();
    
    // Cleanup
    imagedestroy($src);
    imagedestroy($dst);
    
    return $data;
}