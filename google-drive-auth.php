<?php
/**
 * Google Drive OAuth Handler
 * Handles OAuth flow for Google Drive integration
 * 
 * Endpoints:
 * - ?action=authorize - Start OAuth flow
 * - ?action=callback (default) - Handle OAuth callback
 * - ?action=revoke - Revoke access and clear tokens
 * - ?action=status - Check if user has valid tokens
 */

require __DIR__ . '/config.php';

// ========================================
// MULTI-USER: Get current user ID
// ========================================
$userId = 'default';
if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
    $userId = gm_get_current_user_id();
    
    if (!$userId) {
        // For status check, return JSON
        if (($_GET['action'] ?? '') === 'status') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'not_authenticated']);
            exit;
        }
        // For other actions, redirect to login
        $authUrls = gm_get_auth_urls();
        header('Location: ' . $authUrls['login_url']);
        exit;
    }
}

// Check if Google Drive is enabled
if (!defined('GOOGLE_DRIVE_ENABLED') || !GOOGLE_DRIVE_ENABLED) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'google_drive_not_enabled']);
    exit;
}

$action = $_GET['action'] ?? 'callback';

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Generate and store OAuth state in database
 */
function generateAndStoreState(string $userId): string {
    $pdo = db_get_pdo();
    $state = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minute expiry
    
    // Delete any existing state for this user
    $stmt = $pdo->prepare("DELETE FROM `google_oauth_states` WHERE `user_id` = :user_id");
    $stmt->execute([':user_id' => $userId]);
    
    // Insert new state
    $stmt = $pdo->prepare("
        INSERT INTO `google_oauth_states` (`user_id`, `state`, `expires_at`)
        VALUES (:user_id, :state, :expires_at)
    ");
    $stmt->execute([
        ':user_id' => $userId,
        ':state' => $state,
        ':expires_at' => $expiresAt
    ]);
    
    return $state;
}

/**
 * Validate OAuth state from database
 */
function validateState(string $state): ?string {
    $pdo = db_get_pdo();
    
    $stmt = $pdo->prepare("
        SELECT `user_id` FROM `google_oauth_states` 
        WHERE `state` = :state AND `expires_at` > NOW()
    ");
    $stmt->execute([':state' => $state]);
    $row = $stmt->fetch();
    
    if ($row) {
        // Delete the used state
        $stmt = $pdo->prepare("DELETE FROM `google_oauth_states` WHERE `state` = :state");
        $stmt->execute([':state' => $state]);
        return $row['user_id'];
    }
    
    return null;
}

/**
 * Build OAuth authorization URL
 */
function buildAuthUrl(string $userId): string {
    $state = generateAndStoreState($userId);
    
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent', // Force consent to get refresh token
        'include_granted_scopes' => 'true',
        'state' => $state,
    ];
    
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * Exchange authorization code for tokens
 */
function exchangeCodeForTokens(string $code): array {
    $postData = [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
    ];
    
    // Log the redirect URI being used (for debugging)
    error_log("Google OAuth: Using redirect_uri: " . GOOGLE_REDIRECT_URI);
    
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
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("Google OAuth curl error: " . $curlError);
        throw new RuntimeException('Network error: ' . $curlError);
    }
    
    if ($httpCode !== 200) {
        error_log("Google OAuth token exchange failed: HTTP $httpCode - $response");
        
        // Parse error for better message
        $errorData = json_decode($response, true);
        $errorMsg = 'Failed to exchange authorization code';
        if ($errorData && isset($errorData['error_description'])) {
            $errorMsg = $errorData['error_description'];
        } elseif ($errorData && isset($errorData['error'])) {
            $errorMsg = $errorData['error'];
        }
        
        throw new RuntimeException($errorMsg);
    }
    
    $tokens = json_decode($response, true);
    if (!$tokens || !isset($tokens['access_token'])) {
        error_log("Google OAuth invalid token response: $response");
        throw new RuntimeException('Invalid token response from Google');
    }
    
    return $tokens;
}

/**
 * Store tokens in database
 */
function storeTokens(string $userId, array $tokens): void {
    $pdo = db_get_pdo();
    
    $expiresAt = date('Y-m-d H:i:s', time() + ($tokens['expires_in'] ?? 3600));
    
    error_log("Google Drive: Storing tokens for user_id='$userId', expires_at='$expiresAt'");
    
    $stmt = $pdo->prepare("
        INSERT INTO `google_drive_tokens` 
        (`user_id`, `access_token`, `refresh_token`, `token_type`, `expires_at`, `scope`)
        VALUES (:user_id, :access_token, :refresh_token, :token_type, :expires_at, :scope)
        ON DUPLICATE KEY UPDATE
        `access_token` = VALUES(`access_token`),
        `refresh_token` = COALESCE(VALUES(`refresh_token`), `refresh_token`),
        `token_type` = VALUES(`token_type`),
        `expires_at` = VALUES(`expires_at`),
        `scope` = VALUES(`scope`),
        `updated_at` = NOW()
    ");
    
    $stmt->execute([
        ':user_id' => $userId,
        ':access_token' => $tokens['access_token'],
        ':refresh_token' => $tokens['refresh_token'] ?? '',
        ':token_type' => $tokens['token_type'] ?? 'Bearer',
        ':expires_at' => $expiresAt,
        ':scope' => $tokens['scope'] ?? '',
    ]);
    
    $rowCount = $stmt->rowCount();
    error_log("Google Drive: Token store result - rows affected: $rowCount");
}

/**
 * Get stored tokens for user
 */
function getStoredTokens(string $userId): ?array {
    $pdo = db_get_pdo();
    
    $stmt = $pdo->prepare("SELECT * FROM `google_drive_tokens` WHERE `user_id` = :user_id");
    $stmt->execute([':user_id' => $userId]);
    
    return $stmt->fetch() ?: null;
}

/**
 * Refresh access token using refresh token
 */
function refreshAccessToken(string $refreshToken): array {
    $postData = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
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
        error_log("Google OAuth token refresh failed: HTTP $httpCode - $response");
        throw new RuntimeException('Failed to refresh access token');
    }
    
    $tokens = json_decode($response, true);
    if (!$tokens || !isset($tokens['access_token'])) {
        throw new RuntimeException('Invalid refresh token response');
    }
    
    return $tokens;
}

/**
 * Delete stored tokens for user
 */
function deleteTokens(string $userId): void {
    $pdo = db_get_pdo();
    $stmt = $pdo->prepare("DELETE FROM `google_drive_tokens` WHERE `user_id` = :user_id");
    $stmt->execute([':user_id' => $userId]);
}

/**
 * Get valid access token (refreshing if needed)
 */
function getValidAccessToken(string $userId): ?string {
    $tokens = getStoredTokens($userId);
    
    if (!$tokens) {
        return null;
    }
    
    // Check if token is expired (with 5 minute buffer)
    $expiresAt = strtotime($tokens['expires_at']);
    if (time() > ($expiresAt - 300)) {
        // Token expired or about to expire, refresh it
        if (empty($tokens['refresh_token'])) {
            // No refresh token, user needs to re-authorize
            deleteTokens($userId);
            return null;
        }
        
        try {
            $newTokens = refreshAccessToken($tokens['refresh_token']);
            $newTokens['refresh_token'] = $tokens['refresh_token']; // Keep existing refresh token
            storeTokens($userId, $newTokens);
            return $newTokens['access_token'];
        } catch (Throwable $e) {
            error_log("Failed to refresh Google token for user $userId: " . $e->getMessage());
            deleteTokens($userId);
            return null;
        }
    }
    
    return $tokens['access_token'];
}

// ========================================
// HANDLE ACTIONS
// ========================================

switch ($action) {
    case 'authorize':
        // Redirect to Google OAuth
        $authUrl = buildAuthUrl($userId);
        header('Location: ' . $authUrl);
        exit;
    
    case 'callback':
        // Handle OAuth callback
        header('Content-Type: text/html');
        
        // Check for errors
        if (isset($_GET['error'])) {
            $error = htmlspecialchars($_GET['error']);
            $errorDesc = htmlspecialchars($_GET['error_description'] ?? 'Authorization denied');
            echo "<!DOCTYPE html><html><head><title>Authorization Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'$error',message:'$errorDesc'},'*');window.close();</script>";
            echo "<p>Authorization failed: $errorDesc</p><p><a href='javascript:window.close()'>Close this window</a></p>";
            echo "</body></html>";
            exit;
        }
        
        // Verify state and get user ID from database
        $state = $_GET['state'] ?? '';
        
        if (empty($state)) {
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'missing_state',message:'Missing state parameter'},'*');window.close();</script>";
            echo "<p>Missing state parameter. Please try again.</p>";
            echo "</body></html>";
            exit;
        }
        
        $validatedUserId = validateState($state);
        if (!$validatedUserId) {
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'invalid_state',message:'Invalid or expired state parameter. Please try again.'},'*');window.close();</script>";
            echo "<p>Invalid or expired state parameter. Please try again.</p>";
            echo "</body></html>";
            exit;
        }
        
        // Get authorization code
        $code = $_GET['code'] ?? '';
        if (empty($code)) {
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'no_code',message:'No authorization code received'},'*');window.close();</script>";
            echo "<p>No authorization code received.</p>";
            echo "</body></html>";
            exit;
        }
        
        try {
            // Exchange code for tokens
            $tokens = exchangeCodeForTokens($code);
            
            // Store tokens for the validated user
            storeTokens($validatedUserId, $tokens);
            
            // Success - notify opener and close
            echo "<!DOCTYPE html><html><head><title>Success</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-success'},'*');window.close();</script>";
            echo "<p>Authorization successful! This window should close automatically.</p>";
            echo "<p><a href='javascript:window.close()'>Close this window</a></p>";
            echo "</body></html>";
        } catch (Throwable $e) {
            error_log("Google OAuth callback error: " . $e->getMessage());
            $errorMsg = $e->getMessage();
            $safeError = htmlspecialchars($errorMsg);
            $jsError = addslashes($errorMsg);
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'token_exchange',message:'$jsError'},'*');setTimeout(function(){window.close();},5000);</script>";
            echo "<p><strong>Authorization failed:</strong> $safeError</p>";
            echo "<p style='font-size:0.9em;color:#666;'>Common causes:</p>";
            echo "<ul style='font-size:0.85em;color:#666;'>";
            echo "<li>Redirect URI mismatch - check Google Cloud Console</li>";
            echo "<li>Invalid client credentials</li>";
            echo "<li>Authorization code expired or already used</li>";
            echo "</ul>";
            echo "<p style='font-size:0.85em;'>Expected redirect URI: <code>" . htmlspecialchars(GOOGLE_REDIRECT_URI) . "</code></p>";
            echo "<p><a href='javascript:window.close()'>Close this window</a></p>";
            echo "</body></html>";
        }
        exit;
    
    case 'revoke':
        // Revoke access and delete tokens
        header('Content-Type: application/json');
        
        try {
            $tokens = getStoredTokens($userId);
            
            if ($tokens && !empty($tokens['access_token'])) {
                // Revoke token at Google
                $ch = curl_init('https://oauth2.googleapis.com/revoke?token=' . urlencode($tokens['access_token']));
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
            
            // Delete stored tokens
            deleteTokens($userId);
            
            echo json_encode(['success' => true, 'message' => 'Google Drive access revoked']);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    
    case 'status':
        // Check if user has valid tokens
        header('Content-Type: application/json');
        
        $accessToken = getValidAccessToken($userId);
        
        if ($accessToken) {
            echo json_encode([
                'success' => true,
                'connected' => true,
                'message' => 'Google Drive connected'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'connected' => false,
                'message' => 'Google Drive not connected',
                'auth_url' => 'google-drive-auth.php?action=authorize'
            ]);
        }
        exit;
    
    case 'debug':
        // Show configuration for debugging (without exposing secrets)
        header('Content-Type: application/json');
        
        $clientIdMasked = substr(GOOGLE_CLIENT_ID, 0, 20) . '...' . substr(GOOGLE_CLIENT_ID, -10);
        $hasSecret = !empty(GOOGLE_CLIENT_SECRET) && strlen(GOOGLE_CLIENT_SECRET) > 10;
        
        echo json_encode([
            'success' => true,
            'config' => [
                'google_drive_enabled' => defined('GOOGLE_DRIVE_ENABLED') && GOOGLE_DRIVE_ENABLED,
                'client_id_preview' => $clientIdMasked,
                'client_id_length' => strlen(GOOGLE_CLIENT_ID),
                'has_client_secret' => $hasSecret,
                'redirect_uri' => GOOGLE_REDIRECT_URI,
                'current_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
                'expected_callback_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/google-drive-auth.php',
            ],
            'user_id' => $userId,
            'note' => 'Ensure GOOGLE_REDIRECT_URI in config.php EXACTLY matches what is in Google Cloud Console > Credentials > OAuth 2.0 Client IDs > Authorized redirect URIs'
        ], JSON_PRETTY_PRINT);
        exit;
    
    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
