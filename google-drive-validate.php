<?php
/**
 * Google Drive File Validation Handler
 * Validates if a Google Drive file is still accessible
 * Called when user clicks download on a Drive attachment
 * 
 * GET ?id=attachment_id - Validate and redirect to file
 */

require __DIR__ . '/config.php';

// ========================================
// MULTI-USER: Get current user ID
// ========================================
$userId = 'default';
if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
    $userId = gm_get_current_user_id();
    
    if (!$userId) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'authentication_required',
            'message' => 'Please log in to continue'
        ]);
        exit;
    }
}

$attachmentId = $_GET['id'] ?? '';

if (empty($attachmentId)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Attachment ID required']);
    exit;
}

try {
    $pdo = db_get_pdo();
    
    // Get attachment details and verify ownership
    $stmt = $pdo->prepare("
        SELECT a.*, v.`user_id`
        FROM `entry_attachments` a
        JOIN `entries` e ON a.`entry_id` = e.`id`
        JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
        WHERE a.`id` = :id AND v.`user_id` = :uid
    ");
    $stmt->execute([':id' => $attachmentId, ':uid' => $userId]);
    $attachment = $stmt->fetch();
    
    if (!$attachment) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Attachment not found']);
        exit;
    }
    
    // Check if this is a Google Drive attachment
    if ($attachment['source'] !== 'google_drive') {
        // Local file - redirect to regular download
        header('Location: download.php?id=' . urlencode($attachmentId));
        exit;
    }
    
    // For Google Drive files, validate the link is still accessible
    $driveFileId = $attachment['drive_file_id'];
    $externalUrl = $attachment['external_url'];
    
    if (empty($driveFileId) && empty($externalUrl)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'file_unavailable',
            'message' => 'File link is no longer available'
        ]);
        exit;
    }
    
    // Try to validate the file exists by making a HEAD request
    // We don't need auth for this - we just check if the public link works
    $checkUrl = $externalUrl ?: "https://drive.google.com/uc?export=download&id=$driveFileId";
    
    $ch = curl_init($checkUrl);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TrackMyWrench/2.0)',
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    
    // Check response
    // Note: Google Drive may return 200 even for deleted files (shows error page)
    // But typically returns 403/404 for truly inaccessible files
    if ($httpCode >= 400 && $httpCode < 500) {
        http_response_code(410); // Gone
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'file_unavailable',
            'message' => 'This file is no longer available. It may have been deleted from Google Drive or sharing permissions changed.',
            'suggestion' => 'You can delete this attachment and upload a new file.'
        ]);
        exit;
    }
    
    // File appears accessible - return the URL for download
    // Check if this is an API request or redirect request
    $format = $_GET['format'] ?? 'redirect';
    
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'url' => $checkUrl,
            'name' => $attachment['name'],
            'type' => $attachment['mime_type'],
            'size' => $attachment['size'],
            'source' => 'google_drive'
        ]);
        exit;
    }
    
    // Default: redirect to the file
    header('Location: ' . $checkUrl);
    exit;
    
} catch (Throwable $e) {
    error_log("Drive validation error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'validation_error',
        'message' => 'Could not validate file access'
    ]);
}
