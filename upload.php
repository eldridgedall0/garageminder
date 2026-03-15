<?php
/**
 * File Upload Handler - Multi-user Ready
 * Handles attachment uploads for service entries
 * Stores files in user-specific directories
 */

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

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

// Get entry ID
$entryId = $_POST['entry_id'] ?? '';
if (empty($entryId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Entry ID required']);
    exit;
}

// Check if files were uploaded
if (empty($_FILES['files'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No files uploaded']);
    exit;
}

try {
    $pdo = db_get_pdo();
    
    // Verify entry exists AND belongs to user's vehicle
    $stmt = $pdo->prepare("
        SELECT e.`id`, e.`vehicle_id` 
        FROM `entries` e
        JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
        WHERE e.`id` = :id AND v.`user_id` = :uid
    ");
    $stmt->execute([':id' => $entryId, ':uid' => $userId]);
    $entry = $stmt->fetch();
    
    if (!$entry) {
        // Entry doesn't exist yet - this can happen if saveData() hasn't completed
        // Wait briefly and retry once
        usleep(500000); // 0.5 seconds
        $stmt->execute([':id' => $entryId, ':uid' => $userId]);
        $entry = $stmt->fetch();
        
        if (!$entry) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Entry not found or access denied']);
            exit;
        }
    }
    
    // Check current attachment count and enforce subscription limits
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `entry_attachments` WHERE `entry_id` = :id");
    $stmt->execute([':id' => $entryId]);
    $currentCount = (int) $stmt->fetchColumn();

    // ── SUBSCRIPTION-AWARE ATTACHMENT LIMIT ─────────────────────────────────
    // WP admin stores 'attachments_per_entry' as the sole attachment limit key.
    // There is NO 'enable_local_upload' key in WordPress — that key doesn't exist
    // and always resolves to false, blocking every upload regardless of tier.
    // The only gate needed: attachments_per_entry > 0 means uploads are allowed.
    if ($userId !== 'default' && function_exists('gm_get_user_limits')) {
        $limits         = gm_get_user_limits($userId);
        $maxAttachments = (int) ($limits['attachments_per_entry'] ?? 0);
    } else {
        // Single-user mode or WP unavailable — fall back to config.php constant
        $maxAttachments = defined('ENTRY_MAX_ATTACHMENTS') ? (int) ENTRY_MAX_ATTACHMENTS : 2;
    }

    $remainingSlots = max(0, $maxAttachments - $currentCount);

    if ($maxAttachments <= 0) {
        http_response_code(403);
        echo json_encode([
            'success'     => false,
            'error'       => 'attachment_limit_reached',
            'message'     => 'File attachments are not available on your current plan. Please upgrade.',
            'upgrade_url' => function_exists('gm_get_upgrade_url') ? gm_get_upgrade_url('attachments') : '',
        ]);
        exit;
    }

    if ($remainingSlots <= 0) {
        http_response_code(403);
        echo json_encode([
            'success'     => false,
            'error'       => 'attachment_limit_reached',
            'message'     => "Maximum attachments ({$maxAttachments}) already reached for this entry.",
            'upgrade_url' => function_exists('gm_get_upgrade_url') ? gm_get_upgrade_url('attachments') : '',
        ]);
        exit;
    }
    
    // Get user-specific attachments directory
    $userDir = get_user_attachments_path($userId);
    $entryDir = $userDir . '/' . $entryId;
    
    if (!is_dir($entryDir)) {
        if (!mkdir($entryDir, 0755, true)) {
            throw new RuntimeException('Failed to create attachments directory');
        }
    }
    
    // Process uploaded files
    $files = $_FILES['files'];
    $uploadedFiles = [];
    $errors = [];
    
    // Handle both single and multiple file uploads
    if (is_array($files['name'])) {
        $fileCount = count($files['name']);
        for ($i = 0; $i < $fileCount && count($uploadedFiles) < $remainingSlots; $i++) {
            $file = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            
            if (!validate_file_upload($file)) {
                $errors[] = "File '{$file['name']}' is invalid or exceeds size limit";
                continue;
            }
            
            // Generate secure filename
            $secureFilename = generate_secure_filename($file['name']);
            $filePath = $entryDir . '/' . $secureFilename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                $errors[] = "Failed to save file '{$file['name']}'";
                continue;
            }
            
            // Store in database - relative path includes user ID for multi-user
            $attachmentId = 'att_' . time() . '_' . bin2hex(random_bytes(8));
            $stmt = $pdo->prepare("INSERT INTO `entry_attachments` 
                (`id`, `entry_id`, `name`, `mime_type`, `size`, `file_path`, `uploaded_at`) 
                VALUES (:id, :entry_id, :name, :mime, :size, :path, NOW())");
            
            $relativePath = $userId . '/' . $entryId . '/' . $secureFilename;
            $stmt->execute([
                ':id'       => $attachmentId,
                ':entry_id' => $entryId,
                ':name'     => $file['name'],
                ':mime'     => $file['type'],
                ':size'     => $file['size'],
                ':path'     => $relativePath,
            ]);
            
            $uploadedFiles[] = [
                'id'       => $attachmentId,
                'name'     => $file['name'],
                'size'     => $file['size'],
                'type'     => $file['type'],
                'filePath' => $relativePath,
            ];
        }
    } else {
        // Single file upload
        if (validate_file_upload($files)) {
            $secureFilename = generate_secure_filename($files['name']);
            $filePath = $entryDir . '/' . $secureFilename;
            
            if (move_uploaded_file($files['tmp_name'], $filePath)) {
                $attachmentId = 'att_' . time() . '_' . bin2hex(random_bytes(8));
                $stmt = $pdo->prepare("INSERT INTO `entry_attachments` 
                    (`id`, `entry_id`, `name`, `mime_type`, `size`, `file_path`, `uploaded_at`) 
                    VALUES (:id, :entry_id, :name, :mime, :size, :path, NOW())");
                
                $relativePath = $userId . '/' . $entryId . '/' . $secureFilename;
                $stmt->execute([
                    ':id'       => $attachmentId,
                    ':entry_id' => $entryId,
                    ':name'     => $files['name'],
                    ':mime'     => $files['type'],
                    ':size'     => $files['size'],
                    ':path'     => $relativePath,
                ]);
                
                $uploadedFiles[] = [
                    'id'       => $attachmentId,
                    'name'     => $files['name'],
                    'size'     => $files['size'],
                    'type'     => $files['type'],
                    'filePath' => $relativePath,
                ];
            } else {
                $errors[] = "Failed to save file '{$files['name']}'";
            }
        } else {
            $errors[] = "File '{$files['name']}' is invalid or exceeds size limit";
        }
    }
    
    // Return response
    $response = [
        'success' => count($uploadedFiles) > 0,
        'uploaded' => $uploadedFiles,
        'count' => count($uploadedFiles),
    ];
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
    }
    
    echo json_encode($response);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Upload failed: ' . $e->getMessage()
    ]);
}