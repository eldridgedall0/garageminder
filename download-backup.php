<?php
/**
 * Secure Backup Download Handler - Multi-User Ready
 * Serves backup files (JSON format) with proper validation
 * Each user can only download their own backups
 */

require __DIR__ . '/config.php';

// Get parameters
$filename = $_GET['file'] ?? '';
$token = $_GET['token'] ?? '';

// Validate inputs
if (empty($filename) || empty($token)) {
    http_response_code(400);
    die('Invalid request');
}

// Sanitize filename (prevent directory traversal)
$filename = basename($filename);

// Accept both .json and .zip extensions for backwards compatibility
if (!preg_match('/^backup_[\w\-]+\.(json|zip)$/', $filename)) {
    http_response_code(400);
    die('Invalid filename');
}

try {
    // Get current user ID (multi-user ready)
    $userId = getCurrentUserId();
    
    // Construct file path (user-specific directory)
    $backupsDir = __DIR__ . '/backups/' . sanitizePathComponent($userId);
    $filePath = $backupsDir . '/' . $filename;
    
    // Verify file exists
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('Backup file not found');
    }
    
    // Security: Verify the file is within the user's backup directory
    $realPath = realpath($filePath);
    $realBackupsDir = realpath($backupsDir);
    
    if ($realPath === false || $realBackupsDir === false || strpos($realPath, $realBackupsDir) !== 0) {
        http_response_code(403);
        die('Access denied: Path traversal detected');
    }
    
    // Verify token matches (security check)
    if (!verify_backup_token($filename, $token)) {
        http_response_code(403);
        die('Access denied: Invalid token');
    }
    
    // Get file info
    $fileSize = filesize($filePath);
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    
    // Set content type based on extension
    $contentType = ($extension === 'json') 
        ? 'application/json' 
        : 'application/zip';
    
    // Generate download filename
    $downloadFilename = 'garage_maintenance_backup_' . date('Y-m-d') . '.' . $extension;
    
    // Send headers
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Output file
    readfile($filePath);
    exit;
    
} catch (Throwable $e) {
    http_response_code(500);
    die('Download failed: ' . $e->getMessage());
}

/**
 * Get current user ID
 * Returns 'default' for single-user mode
 */
function getCurrentUserId(): string {
    if (!defined('ENABLE_MULTI_USER') || !ENABLE_MULTI_USER) {
        return 'default';
    }
    
    // Multi-user mode: get from session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    
    // Not logged in - deny access in multi-user mode
    http_response_code(401);
    die('Authentication required');
}

/**
 * Sanitize a string for use in file paths
 */
function sanitizePathComponent(string $str): string {
    $str = preg_replace('/[^a-zA-Z0-9_-]/', '_', $str);
    $str = preg_replace('/_+/', '_', $str);
    return substr($str, 0, 64) ?: 'unknown';
}

/**
 * Verify backup token
 * Extracts token from filename and compares
 */
function verify_backup_token(string $filename, string $token): bool {
    // Format: backup_2024-01-15_10-30-00_abc123token.json or .zip
    if (preg_match('/backup_[\d\-_]+_([a-f0-9]+)\.(json|zip)$/', $filename, $matches)) {
        $filenameToken = $matches[1];
        return hash_equals($filenameToken, $token);
    }
    return false;
}