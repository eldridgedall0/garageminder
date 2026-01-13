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
 * Build OAuth authorization URL
 */
function buildAuthUrl(): string {
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent', // Force consent to get refresh token
        'include_granted_scopes' => 'true',
    ];
    
    // Add state parameter for CSRF protection
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    $params['state'] = $state;
    
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
        error_log("Google OAuth token exchange failed: HTTP $httpCode - $response");
        throw new RuntimeException('Failed to exchange authorization code');
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
// START SESSION FOR STATE MANAGEMENT
// ========================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ========================================
// HANDLE ACTIONS
// ========================================

switch ($action) {
    case 'authorize':
        // Redirect to Google OAuth
        $authUrl = buildAuthUrl();
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
        
        // Verify state (CSRF protection)
        $state = $_GET['state'] ?? '';
        $sessionState = $_SESSION['google_oauth_state'] ?? '';
        
        if (empty($state) || $state !== $sessionState) {
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'invalid_state',message:'Invalid state parameter'},'*');window.close();</script>";
            echo "<p>Invalid state parameter. Please try again.</p>";
            echo "</body></html>";
            exit;
        }
        
        // Clear state from session
        unset($_SESSION['google_oauth_state']);
        
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
            
            // Store tokens
            storeTokens($userId, $tokens);
            
            // Success - notify opener and close
            echo "<!DOCTYPE html><html><head><title>Success</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-success'},'*');window.close();</script>";
            echo "<p>Authorization successful! This window should close automatically.</p>";
            echo "<p><a href='javascript:window.close()'>Close this window</a></p>";
            echo "</body></html>";
        } catch (Throwable $e) {
            error_log("Google OAuth callback error: " . $e->getMessage());
            $error = htmlspecialchars($e->getMessage());
            echo "<!DOCTYPE html><html><head><title>Error</title></head><body>";
            echo "<script>window.opener && window.opener.postMessage({type:'google-drive-auth-error',error:'token_exchange',message:'$error'},'*');window.close();</script>";
            echo "<p>Authorization failed: $error</p>";
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
    
    default:
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit;
}
