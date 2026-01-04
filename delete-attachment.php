<?php
/**
 * File Deletion Handler - Multi-user Ready
 * Deletes attachment file and database record
 * Validates user ownership before deletion
 */

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

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

$attachmentId = $_POST['attachment_id'] ?? '';

if (empty($attachmentId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Attachment ID required']);
    exit;
}

try {
    $pdo = db_get_pdo();
    
    // Get attachment details AND verify user owns the entry
    $stmt = $pdo->prepare("
        SELECT a.`id`, a.`entry_id`, a.`file_path`
        FROM `entry_attachments` a
        JOIN `entries` e ON a.`entry_id` = e.`id`
        JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
        WHERE a.`id` = :id AND v.`user_id` = :uid
    ");
    $stmt->execute([':id' => $attachmentId, ':uid' => $userId]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Attachment not found or access denied']);
        exit;
    }
    
    // Delete physical file
    $filePath = ATTACHMENTS_PATH . '/' . $attachment['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
        
        // Try to remove empty entry directory
        $entryDir = dirname($filePath);
        if (is_dir($entryDir) && count(scandir($entryDir)) === 2) { // Only . and ..
            rmdir($entryDir);
            
            // Try to remove empty user directory
            $userDir = dirname($entryDir);
            if (is_dir($userDir) && count(scandir($userDir)) === 2) {
                rmdir($userDir);
            }
        }
    }
    
    // Delete database record
    $stmt = $pdo->prepare("DELETE FROM `entry_attachments` WHERE `id` = :id");
    $stmt->execute([':id' => $attachmentId]);
    
    echo json_encode(['success' => true, 'message' => 'Attachment deleted']);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()]);
}