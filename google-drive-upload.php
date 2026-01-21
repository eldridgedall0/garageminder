<?php
/**
 * Google Drive Upload Handler
 * Handles uploading files to user's Google Drive and attaching them to entries
 * 
 * Endpoints:
 * - POST ?action=upload - Upload file to Google Drive
 * - POST ?action=attach - Attach existing Drive file to entry
 * - GET  ?action=picker_token - Get access token for Drive Picker
 * - POST ?action=create_folder - Ensure app folder exists in user's Drive
 */

header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/config.php';

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

// Check if Google Drive is enabled
if (!defined('GOOGLE_DRIVE_ENABLED') || !GOOGLE_DRIVE_ENABLED) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'google_drive_not_enabled']);
    exit;
}

$action = $_REQUEST['action'] ?? 'attach';

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Get valid access token for user
 */
function getValidAccessToken(string $userId): ?string {
    $pdo = db_get_pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM `google_drive_tokens` WHERE `user_id` = :user_id");
    $stmt->execute([':user_id' => $userId]);
    $tokens = $stmt->fetch();
    
    if (!$tokens) {
        return null;
    }
    
    // Check if token is expired (with 5 minute buffer)
    $expiresAt = strtotime($tokens['expires_at']);
    if (time() > ($expiresAt - 300)) {
        // Token expired, try to refresh
        if (empty($tokens['refresh_token'])) {
            return null;
        }
        
        $postData = [
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $tokens['refresh_token'],
            'grant_type' => 'refresh_token',
        ];
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $newTokens = json_decode($response, true);
        if (!$newTokens || !isset($newTokens['access_token'])) {
            return null;
        }
        
        // Update stored tokens
        $newExpiresAt = date('Y-m-d H:i:s', time() + ($newTokens['expires_in'] ?? 3600));
        $updateStmt = $pdo->prepare("
            UPDATE `google_drive_tokens` 
            SET `access_token` = :access_token, `expires_at` = :expires_at, `updated_at` = NOW()
            WHERE `user_id` = :user_id
        ");
        $updateStmt->execute([
            ':access_token' => $newTokens['access_token'],
            ':expires_at' => $newExpiresAt,
            ':user_id' => $userId,
        ]);
        
        return $newTokens['access_token'];
    }
    
    return $tokens['access_token'];
}

/**
 * Get or create app folder in user's Drive
 */
function getOrCreateAppFolder(string $accessToken): ?string {
    $folderName = defined('GOOGLE_DRIVE_FOLDER_NAME') ? GOOGLE_DRIVE_FOLDER_NAME : 'TrackMyWrench Attachments';
    
    // Search for existing folder
    $query = urlencode("name='$folderName' and mimeType='application/vnd.google-apps.folder' and trashed=false");
    
    $ch = curl_init("https://www.googleapis.com/drive/v3/files?q=$query&spaces=drive&fields=files(id,name)");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $result = json_decode($response, true);
    
    if (!empty($result['files'])) {
        return $result['files'][0]['id'];
    }
    
    // Create new folder
    $metadata = [
        'name' => $folderName,
        'mimeType' => 'application/vnd.google-apps.folder',
    ];
    
    $ch = curl_init('https://www.googleapis.com/drive/v3/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($metadata),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    $folder = json_decode($response, true);
    return $folder['id'] ?? null;
}

/**
 * Set file permission to "anyone with link can view"
 */
function setFilePublicPermission(string $accessToken, string $fileId): bool {
    $permission = [
        'type' => 'anyone',
        'role' => 'reader',
    ];
    
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId/permissions");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($permission),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

/**
 * Get file metadata from Drive
 */
function getFileMetadata(string $accessToken, string $fileId): ?array {
    $fields = 'id,name,mimeType,size,webViewLink,webContentLink,thumbnailLink';
    
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/$fileId?fields=$fields");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return null;
    }
    
    return json_decode($response, true);
}

/**
 * Upload file to Google Drive
 */
function uploadFileToDrive(string $accessToken, string $folderId, array $file): ?array {
    // Validate file
    $allowedMimes = defined('GOOGLE_DRIVE_ALLOWED_TYPES') ? GOOGLE_DRIVE_ALLOWED_TYPES : ALLOWED_MIME_TYPES;
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimes)) {
        throw new RuntimeException('File type not allowed: ' . $mimeType);
    }
    
    // Check file size
    $maxBytes = (defined('ENTRY_MAX_ATTACHMENT_SIZE_MB') ? ENTRY_MAX_ATTACHMENT_SIZE_MB : 5) * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File too large');
    }
    
    // Prepare metadata
    $metadata = [
        'name' => $file['name'],
        'parents' => [$folderId],
    ];
    
    // Read file content
    $fileContent = file_get_contents($file['tmp_name']);
    if ($fileContent === false) {
        throw new RuntimeException('Cannot read uploaded file');
    }
    
    // Create multipart body
    $boundary = '-------' . uniqid();
    $body = "--$boundary\r\n";
    $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
    $body .= json_encode($metadata) . "\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: $mimeType\r\n\r\n";
    $body .= $fileContent . "\r\n";
    $body .= "--$boundary--";
    
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,webViewLink,webContentLink');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $accessToken",
            "Content-Type: multipart/related; boundary=$boundary",
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_TIMEOUT => 120,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Drive upload failed: HTTP $httpCode - $response - $curlError");
        
        // Check for quota exceeded
        $errorData = json_decode($response, true);
        if (isset($errorData['error']['errors'][0]['reason']) && 
            $errorData['error']['errors'][0]['reason'] === 'storageQuotaExceeded') {
            throw new RuntimeException('Google Drive storage quota exceeded. Try uploading locally instead.');
        }
        
        throw new RuntimeException('Upload to Google Drive failed');
    }
    
    return json_decode($response, true);
}

/**
 * Validate file extension against allowed list
 */
function isAllowedFileType(string $filename, string $mimeType): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $allowedExt = defined('ALLOWED_EXTENSIONS') ? ALLOWED_EXTENSIONS : ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    return in_array($ext, $allowedExt);
}

// ========================================
// HANDLE ACTIONS
// ========================================

try {
    $pdo = db_get_pdo();
    
    switch ($action) {
        case 'picker_token':
            // Return access token for Drive Picker
            
            // First check if the tokens table exists
            try {
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'google_drive_tokens'");
                if ($tableCheck->rowCount() === 0) {
                    echo json_encode([
                        'success' => false,
                        'error' => 'table_missing',
                        'message' => 'Google Drive tokens table does not exist. Please run the database setup SQL.',
                        'debug' => [
                            'user_id_checked' => $userId,
                            'table_exists' => false
                        ]
                    ]);
                    exit;
                }
            } catch (Exception $e) {
                // Table check failed, continue anyway
            }
            
            $accessToken = getValidAccessToken($userId);
            
            if (!$accessToken) {
                // Debug: Check if tokens exist at all
                $stmt = $pdo->prepare("SELECT user_id, expires_at, LENGTH(access_token) as token_len, LENGTH(refresh_token) as refresh_len FROM `google_drive_tokens` WHERE `user_id` = :user_id");
                $stmt->execute([':user_id' => $userId]);
                $debugTokens = $stmt->fetch();
                
                echo json_encode([
                    'success' => false,
                    'error' => 'not_connected',
                    'message' => 'Google Drive not connected. Please authorize first.',
                    'auth_url' => 'google-drive-auth.php?action=authorize',
                    'debug' => [
                        'user_id_checked' => $userId,
                        'tokens_found' => $debugTokens ? true : false,
                        'tokens_info' => $debugTokens ?: null
                    ]
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'access_token' => $accessToken,
                'client_id' => GOOGLE_CLIENT_ID,
                'app_id' => explode('-', GOOGLE_CLIENT_ID)[0] ?? '', // Extract app ID
                'api_key' => defined('GOOGLE_API_KEY') ? GOOGLE_API_KEY : '',
            ]);
            exit;
        
        case 'create_folder':
            // Create/get app folder
            $accessToken = getValidAccessToken($userId);
            
            if (!$accessToken) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'not_connected']);
                exit;
            }
            
            $folderId = getOrCreateAppFolder($accessToken);
            
            if (!$folderId) {
                throw new RuntimeException('Failed to create app folder');
            }
            
            echo json_encode([
                'success' => true,
                'folder_id' => $folderId,
            ]);
            exit;
        
        case 'upload':
            // Upload file to Google Drive
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $accessToken = getValidAccessToken($userId);
            
            if (!$accessToken) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'not_connected',
                    'message' => 'Google Drive not connected'
                ]);
                exit;
            }
            
            $entryId = $_POST['entry_id'] ?? '';
            if (empty($entryId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Entry ID required']);
                exit;
            }
            
            // Verify entry exists and belongs to user
            $stmt = $pdo->prepare("
                SELECT e.`id` FROM `entries` e
                JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
                WHERE e.`id` = :id AND v.`user_id` = :uid
            ");
            $stmt->execute([':id' => $entryId, ':uid' => $userId]);
            
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Entry not found']);
                exit;
            }
            
            // Check attachment count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `entry_attachments` WHERE `entry_id` = :id");
            $stmt->execute([':id' => $entryId]);
            $currentCount = (int)$stmt->fetchColumn();
            
            $maxAttachments = defined('ENTRY_MAX_ATTACHMENTS') ? ENTRY_MAX_ATTACHMENTS : 2;
            if ($currentCount >= $maxAttachments) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'max_attachments',
                    'message' => "Maximum $maxAttachments attachments per entry"
                ]);
                exit;
            }
            
            // Get or create folder
            $folderId = getOrCreateAppFolder($accessToken);
            if (!$folderId) {
                throw new RuntimeException('Cannot access Google Drive folder');
            }
            
            // Handle file upload
            if (empty($_FILES['file'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No file uploaded']);
                exit;
            }
            
            $file = $_FILES['file'];
            
            // Upload to Drive
            $driveFile = uploadFileToDrive($accessToken, $folderId, $file);
            
            if (!$driveFile || empty($driveFile['id'])) {
                throw new RuntimeException('Upload failed');
            }
            
            // Set public permission
            setFilePublicPermission($accessToken, $driveFile['id']);
            
            // Get shareable link
            $viewLink = $driveFile['webViewLink'] ?? "https://drive.google.com/file/d/{$driveFile['id']}/view";
            $downloadLink = $driveFile['webContentLink'] ?? "https://drive.google.com/uc?export=download&id={$driveFile['id']}";
            
            // Store attachment in database
            $attachmentId = 'att_gdrive_' . time() . '_' . bin2hex(random_bytes(6));
            
            $stmt = $pdo->prepare("
                INSERT INTO `entry_attachments` 
                (`id`, `entry_id`, `name`, `mime_type`, `size`, `file_path`, `source`, `external_url`, `drive_file_id`, `uploaded_at`)
                VALUES (:id, :entry_id, :name, :mime, :size, '', 'google_drive', :url, :file_id, NOW())
            ");
            
            $stmt->execute([
                ':id' => $attachmentId,
                ':entry_id' => $entryId,
                ':name' => $driveFile['name'] ?? $file['name'],
                ':mime' => $driveFile['mimeType'] ?? $file['type'],
                ':size' => $driveFile['size'] ?? $file['size'],
                ':url' => $downloadLink,
                ':file_id' => $driveFile['id'],
            ]);
            
            echo json_encode([
                'success' => true,
                'attachment' => [
                    'id' => $attachmentId,
                    'name' => $driveFile['name'] ?? $file['name'],
                    'size' => (int)($driveFile['size'] ?? $file['size']),
                    'type' => $driveFile['mimeType'] ?? $file['type'],
                    'source' => 'google_drive',
                    'external_url' => $downloadLink,
                    'drive_file_id' => $driveFile['id'],
                ],
            ]);
            exit;
        
        case 'attach':
        default:
            // Attach existing Drive file to entry
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $accessToken = getValidAccessToken($userId);
            
            if (!$accessToken) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'error' => 'not_connected',
                    'message' => 'Google Drive not connected'
                ]);
                exit;
            }
            
            // Get JSON body or POST data
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            
            $entryId = $input['entry_id'] ?? '';
            $files = $input['files'] ?? [];
            
            if (empty($entryId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Entry ID required']);
                exit;
            }
            
            if (empty($files) || !is_array($files)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No files provided']);
                exit;
            }
            
            // Verify entry exists and belongs to user
            $stmt = $pdo->prepare("
                SELECT e.`id` FROM `entries` e
                JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
                WHERE e.`id` = :id AND v.`user_id` = :uid
            ");
            $stmt->execute([':id' => $entryId, ':uid' => $userId]);
            
            if (!$stmt->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Entry not found']);
                exit;
            }
            
            // Check attachment count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `entry_attachments` WHERE `entry_id` = :id");
            $stmt->execute([':id' => $entryId]);
            $currentCount = (int)$stmt->fetchColumn();
            
            $maxAttachments = defined('ENTRY_MAX_ATTACHMENTS') ? ENTRY_MAX_ATTACHMENTS : 2;
            $remainingSlots = max(0, $maxAttachments - $currentCount);
            
            if ($remainingSlots <= 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'max_attachments',
                    'message' => "Maximum $maxAttachments attachments per entry"
                ]);
                exit;
            }
            
            // Process each file
            $attached = [];
            $errors = [];
            
            $stmt = $pdo->prepare("
                INSERT INTO `entry_attachments` 
                (`id`, `entry_id`, `name`, `mime_type`, `size`, `file_path`, `source`, `external_url`, `drive_file_id`, `uploaded_at`)
                VALUES (:id, :entry_id, :name, :mime, :size, '', 'google_drive', :url, :file_id, NOW())
            ");
            
            foreach (array_slice($files, 0, $remainingSlots) as $file) {
                $fileId = $file['id'] ?? '';
                $fileName = $file['name'] ?? '';
                $fileMime = $file['mimeType'] ?? '';
                $fileSize = $file['size'] ?? 0;
                
                if (empty($fileId) || empty($fileName)) {
                    $errors[] = 'Invalid file data';
                    continue;
                }
                
                // Validate file type
                if (!isAllowedFileType($fileName, $fileMime)) {
                    $errors[] = "File type not allowed: $fileName";
                    continue;
                }
                
                // Set public permission on the file
                setFilePublicPermission($accessToken, $fileId);
                
                // Get file metadata for download link
                $metadata = getFileMetadata($accessToken, $fileId);
                $downloadUrl = $metadata['webContentLink'] ?? "https://drive.google.com/uc?export=download&id=$fileId";
                
                // Create attachment record
                $attachmentId = 'att_gdrive_' . time() . '_' . bin2hex(random_bytes(6));
                
                try {
                    $stmt->execute([
                        ':id' => $attachmentId,
                        ':entry_id' => $entryId,
                        ':name' => $fileName,
                        ':mime' => $fileMime,
                        ':size' => (int)$fileSize,
                        ':url' => $downloadUrl,
                        ':file_id' => $fileId,
                    ]);
                    
                    $attached[] = [
                        'id' => $attachmentId,
                        'name' => $fileName,
                        'size' => (int)$fileSize,
                        'type' => $fileMime,
                        'source' => 'google_drive',
                        'external_url' => $downloadUrl,
                        'drive_file_id' => $fileId,
                    ];
                } catch (PDOException $e) {
                    $errors[] = "Database error for $fileName";
                    error_log("Attachment insert error: " . $e->getMessage());
                }
            }
            
            echo json_encode([
                'success' => count($attached) > 0,
                'attached' => $attached,
                'count' => count($attached),
                'errors' => $errors,
            ]);
            exit;
    }
    
} catch (Throwable $e) {
    error_log("Google Drive upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'server_error',
        'message' => $e->getMessage()
    ]);
}
