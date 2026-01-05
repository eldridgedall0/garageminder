<?php
// Database configuration - change to match your server
// Using GM_ prefix to avoid collision with WordPress constants
const GM_DB_HOST    = 'localhost';
const GM_DB_NAME    = 'yesca_gm';
const GM_DB_USER    = 'yesca_gm';
const GM_DB_PASS    = '0nP;e(8KIo;][@5r';
const GM_DB_CHARSET = 'utf8mb4';

// Wordpress Subscription Integration
require_once(__DIR__ . '/wp-subscription-integration.php');

// ========================================
// APP BRANDING CONFIGURATION
// ========================================
// Change these values to customize the app name throughout the entire application
const APP_NAME = 'TrackMyWrench';        // Full app name (used in exports, disclaimers, copyright)
const APP_SHORT_NAME = 'TrackMyWrench';             // Short name for PWA/mobile app title
const APP_DOMAIN = 'trackmywrench.com';          // Domain for branding/alt text
const APP_TAGLINE = '';
const APP_COPYRIGHT_YEAR = '2025';             // Copyright year in footer
const APP_VERSION = '2.3';                     // Version displayed in footer

const DASHBOARD_HISTORY_PER_PAGE = 10; // entries per page in Vehicle Overview

const ENTRY_MAX_ATTACHMENTS = 2; // max attachments per service entry
const ENTRY_MAX_ATTACHMENT_SIZE_MB = 5; // max size per attachment file in MB

// ========================================
// FILE STORAGE CONFIGURATION
// ========================================
const ATTACHMENTS_PATH = __DIR__ . '/attachments';
const ATTACHMENTS_URL_BASE = '/garage/download.php?id=';

// Allowed file extensions (security - whitelist only)
const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

// Allowed MIME types (security - validated on upload)
const ALLOWED_MIME_TYPES = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
];

// ========================================
// MULTI-USER SETTINGS (WordPress Integration)
// ========================================
const ENABLE_MULTI_USER = true;  // Set to true to enable WordPress authentication
const REQUIRE_SUBSCRIPTION = false; // Set to true when you have a subscription plugin installed

// WordPress configuration - Your WordPress installation path
const WP_PATH = '/home2/yesca/public_html/gm';

// Subscription settings
const SUBSCRIPTION_REQUIRED_ROLE = 'subscriber'; // Minimum WP role required (or use plugin-based check)
const SUBSCRIPTION_META_KEY = 'garage_subscription_active'; // User meta key for custom subscription tracking

// ========================================
// CUSTOM PAGE URLs (for plugins like Theme My Login, WPForms, Ultimate Member, etc.)
// Set these if you're using custom login/logout/profile pages
// Leave empty ('') to use WordPress defaults
// ========================================
const CUSTOM_LOGIN_URL = '/gm/';        // e.g., '/login/' or '/my-account/'
const CUSTOM_LOGOUT_URL = '/gm/';       // e.g., '/logout/' or leave empty to use wp_logout_url()
const CUSTOM_REGISTER_URL = '/register/';     // e.g., '/register/' or '/signup/'
const CUSTOM_PROFILE_URL = '/my-profile/';      // e.g., '/my-account/' or '/profile/'
const CUSTOM_SUBSCRIBE_URL = '';    // e.g., '/pricing/' or '/subscribe/'

// Where to redirect after logout (leave empty to redirect back to the garage app)
// e.g., '/goodbye/', '/logged-out/', or full URL 'https://example.com/'
const LOGOUT_REDIRECT_URL = '/gm/';

// Security settings
const REQUIRE_HTTPS = false; // Set to true in production
const API_RATE_LIMIT_PER_MINUTE = 60; // Max API calls per user per minute

// Session timeout (used with WordPress sessions)
const SESSION_TIMEOUT_MINUTES = 120;

// ========================================
// HELPER FUNCTION: Get app config for JavaScript
// ========================================
function gm_get_app_config(): array {
    return [
        'appName'        => defined('APP_NAME') ? APP_NAME : 'Garage Maintenance',
        'appShortName'   => defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'MyWrench',
        'appDomain'      => defined('APP_DOMAIN') ? APP_DOMAIN : '',
        'appTagline'     => defined('APP_TAGLINE') ? APP_TAGLINE : '',
        'copyrightYear'  => defined('APP_COPYRIGHT_YEAR') ? APP_COPYRIGHT_YEAR : date('Y'),
        'appVersion'     => defined('APP_VERSION') ? APP_VERSION : '1.0',
    ];
}

// ========================================
// SECURITY FUNCTIONS
// ========================================

/**
 * Enforce HTTPS in production
 */
function gm_enforce_https(): void {
    if (!defined('REQUIRE_HTTPS') || !REQUIRE_HTTPS) {
        return;
    }
    
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    if (!$isHttps && php_sapi_name() !== 'cli') {
        $redirectUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirectUrl, true, 301);
        exit;
    }
}

/**
 * Set secure response headers
 */
function gm_set_security_headers(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/**
 * Simple file-based rate limiting
 */
function gm_check_rate_limit(string $userId): bool {
    $limit = defined('API_RATE_LIMIT_PER_MINUTE') ? API_RATE_LIMIT_PER_MINUTE : 60;
    $key = 'rate_limit_' . md5($userId . '_' . floor(time() / 60));
    
    // Use WordPress transients if available
    if (function_exists('get_transient')) {
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, 60);
        return true;
    }
    
    // Fallback: file-based
    $cacheDir = sys_get_temp_dir() . '/gm_rate_limit';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0700, true);
    }
    
    $cacheFile = $cacheDir . '/' . $key;
    $count = file_exists($cacheFile) ? (int) @file_get_contents($cacheFile) : 0;
    
    if ($count >= $limit) {
        return false;
    }
    
    @file_put_contents($cacheFile, $count + 1);
    return true;
}

/**
 * Sanitize user ID to prevent injection
 */
function gm_sanitize_user_id($userId): ?string {
    if ($userId === null || $userId === '') {
        return null;
    }
    
    if ($userId === 'default') {
        return 'default';
    }
    
    if (is_numeric($userId) && (int)$userId > 0) {
        return (string)(int)$userId;
    }
    
    return null;
}

// ========================================
// WORDPRESS INTEGRATION
// ========================================

/**
 * Load WordPress core (lightweight load for auth only)
 */
function gm_load_wordpress(): bool {
    static $loaded = null;
    
    if ($loaded !== null) {
        return $loaded;
    }
    
    if (!defined('ENABLE_MULTI_USER') || !ENABLE_MULTI_USER) {
        $loaded = false;
        return false;
    }
    
    $wp_load = WP_PATH . '/wp-load.php';
    
    if (!file_exists($wp_load)) {
        error_log('GarageMaintenance: WordPress not found at: ' . $wp_load);
        $loaded = false;
        return false;
    }
    
    // Prevent WordPress from outputting content or redirecting
    if (!defined('WP_USE_THEMES')) {
        define('WP_USE_THEMES', false);
    }
    if (!defined('DOING_AJAX')) {
        define('DOING_AJAX', true); // Prevents some redirects
    }
    if (!defined('SHORTINIT')) {
        define('SHORTINIT', false); // We need full WP for user functions
    }
    
    // Suppress errors during WordPress load
    $old_error_reporting = error_reporting();
    error_reporting(E_ERROR | E_PARSE);
    
    try {
        // Store current working directory
        $original_cwd = getcwd();
        
        // Change to WordPress directory before loading
        chdir(WP_PATH);
        
        require_once($wp_load);
        
        // Restore original working directory
        chdir($original_cwd);
        
        $loaded = true;
    } catch (Throwable $e) {
        error_log('GarageMaintenance: Failed to load WordPress: ' . $e->getMessage());
        $loaded = false;
    }
    
    // Restore error reporting
    error_reporting($old_error_reporting);
    
    return $loaded;
}


/**
 * Get theme auth settings (from WP option gm_auth_settings) if available.
 * This lets the PHP Garage app and the WP theme share destinations seamlessly.
 */
function gm_get_wp_auth_settings(): array {
    if (!gm_load_wordpress()) {
        return [];
    }

    $opts = get_option('gm_auth_settings', []);
    return is_array($opts) ? $opts : [];
}

/**
 * Get the configured Garage Web App URL from WordPress (theme setting),
 * falling back to the app's computed URL.
 */
function gm_get_wp_app_url(string $fallback): string {
    $opts = gm_get_wp_auth_settings();
    if (!empty($opts['app_url'])) {
        return (string) $opts['app_url'];
    }
    return $fallback;
}

/**
 * Get Subscribe URL from WordPress (theme setting),
 * falling back to constant or default.
 */
function gm_get_wp_subscribe_url(string $fallback): string {
    $opts = gm_get_wp_auth_settings();
    if (!empty($opts['subscribe_url'])) {
        return (string) $opts['subscribe_url'];
    }
    return $fallback;
}

/**
 * Get current WordPress user ID
 */
function gm_get_current_user_id(): ?string {
    if (!gm_load_wordpress()) {
        return 'default'; // Single-user mode
    }
    
    if (!function_exists('wp_get_current_user')) {
        return null;
    }
    
    $user = wp_get_current_user();
    if ($user && $user->ID > 0) {
        return (string) $user->ID;
    }
    
    return null;
}

/**
 * Get current WordPress user info
 */
function gm_get_current_user_info(): ?array {
    if (!gm_load_wordpress()) {
        return null;
    }
    
    if (!function_exists('wp_get_current_user')) {
        return null;
    }
    
    $user = wp_get_current_user();
    if (!$user || $user->ID === 0) {
        return null;
    }
    
    return [
        'id' => $user->ID,
        'username' => $user->user_login,
        'email' => $user->user_email,
        'display_name' => $user->display_name,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'avatar_url' => function_exists('get_avatar_url') ? get_avatar_url($user->ID, ['size' => 64]) : null,
    ];
}

/**
 * Check if user has required subscription
 */
function gm_user_has_subscription(string $userId): bool {
    if (!defined('REQUIRE_SUBSCRIPTION') || !REQUIRE_SUBSCRIPTION) {
        return true; // Subscription not required
    }
    
    if (!gm_load_wordpress()) {
        return true; // Can't check, allow access
    }
    
    // Method 1: Check user role
    $user = get_user_by('ID', $userId);
    if ($user) {
        $required_role = defined('SUBSCRIPTION_REQUIRED_ROLE') ? SUBSCRIPTION_REQUIRED_ROLE : 'subscriber';
        if (in_array($required_role, $user->roles, true) || in_array('administrator', $user->roles, true)) {
            return true;
        }
    }
    
    // Method 2: Check user meta
    $meta_key = defined('SUBSCRIPTION_META_KEY') ? SUBSCRIPTION_META_KEY : 'garage_subscription_active';
    $subscription_active = get_user_meta($userId, $meta_key, true);
    if ($subscription_active === 'yes' || $subscription_active === '1' || $subscription_active === true) {
        return true;
    }
    
    // Method 3: WooCommerce Subscriptions (if available)
    if (function_exists('wcs_user_has_subscription')) {
        if (wcs_user_has_subscription($userId, '', 'active')) {
            return true;
        }
    }
    
    // Method 4: Paid Memberships Pro (if available)
    if (function_exists('pmpro_hasMembershipLevel')) {
        if (pmpro_hasMembershipLevel(null, $userId)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get authentication URLs
 */
function gm_get_auth_urls(): array {
    // Build the garage app URL
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    $app_url = rtrim($scheme . '://' . $host . $script_dir, '/') . '/';
    
    // Check if WordPress provides a custom app URL
    $app_url = gm_get_wp_app_url($app_url);
    
    // Login URL
    $login_url = '';
    if (defined('CUSTOM_LOGIN_URL') && CUSTOM_LOGIN_URL !== '') {
        $login_url = CUSTOM_LOGIN_URL;
        // Add redirect parameter if it's a relative URL
        if (strpos($login_url, '?') === false) {
            $login_url .= '?redirect_to=' . urlencode($app_url);
        }
    } elseif (function_exists('wp_login_url')) {
        $login_url = wp_login_url($app_url);
    } else {
        $login_url = '/wp-login.php?redirect_to=' . urlencode($app_url);
    }
    
    // Logout URL
    $logout_redirect = defined('LOGOUT_REDIRECT_URL') && LOGOUT_REDIRECT_URL !== '' 
        ? LOGOUT_REDIRECT_URL 
        : $app_url;
    
    $logout_url = '';
    if (defined('CUSTOM_LOGOUT_URL') && CUSTOM_LOGOUT_URL !== '') {
        $logout_url = CUSTOM_LOGOUT_URL;
    } elseif (function_exists('wp_logout_url')) {
        $logout_url = wp_logout_url($logout_redirect);
    } else {
        $logout_url = '/wp-login.php?action=logout&redirect_to=' . urlencode($logout_redirect);
    }
    
    // Register URL
    $register_url = '';
    if (defined('CUSTOM_REGISTER_URL') && CUSTOM_REGISTER_URL !== '') {
        $register_url = CUSTOM_REGISTER_URL;
    } elseif (function_exists('wp_registration_url')) {
        $register_url = wp_registration_url();
    } else {
        $register_url = '/wp-login.php?action=register';
    }
    
    // Profile URL
    $profile_url = '';
    if (defined('CUSTOM_PROFILE_URL') && CUSTOM_PROFILE_URL !== '') {
        $profile_url = CUSTOM_PROFILE_URL;
    } elseif (function_exists('get_edit_profile_url')) {
        $profile_url = get_edit_profile_url();
    } else {
        $profile_url = '/wp-admin/profile.php';
    }
    
    // Subscribe URL
    $subscribe_url = '';
    if (defined('CUSTOM_SUBSCRIBE_URL') && CUSTOM_SUBSCRIBE_URL !== '') {
        $subscribe_url = CUSTOM_SUBSCRIBE_URL;
    } else {
        $subscribe_url = gm_get_wp_subscribe_url('/pricing/');
    }
    
    return [
        'app_url' => $app_url,
        'login_url' => $login_url,
        'logout_url' => $logout_url,
        'register_url' => $register_url,
        'profile_url' => $profile_url,
        'subscribe_url' => $subscribe_url,
    ];
}

/**
 * Require authentication for page - redirects to login
 */
function gm_require_auth_page(): string {
    if (!defined('ENABLE_MULTI_USER') || !ENABLE_MULTI_USER) {
        return 'default';
    }
    
    $user_id = gm_get_current_user_id();
    
    if (!$user_id) {
        $auth_urls = gm_get_auth_urls();
        header('Location: ' . $auth_urls['login_url']);
        exit;
    }
    
    return $user_id;
}

/**
 * Require authentication for API - returns JSON error
 */
function gm_require_auth_api(): string {
    if (!defined('ENABLE_MULTI_USER') || !ENABLE_MULTI_USER) {
        return 'default';
    }
    
    $user_id = gm_get_current_user_id();
    
    if (!$user_id) {
        // Build app index URL (not the API endpoint)
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $script_dir = dirname($_SERVER['SCRIPT_NAME']);
        $app_url = $scheme . '://' . $host . $script_dir . '/';
        
        // Get login URL that redirects to app index
        $login_url = function_exists('wp_login_url') ? wp_login_url($redirect_to) : '/wp-login.php?redirect_to=' . urlencode($redirect_to);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'authentication_required',
            'message' => 'Please log in to continue',
            'login_url' => $login_url
        ]);
        exit;
    }
    
    // Check rate limiting
    if (!gm_check_rate_limit($user_id)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'rate_limit_exceeded',
            'message' => 'Too many requests. Please wait a moment.'
        ]);
        exit;
    }
    
    // Check subscription
    if (defined('REQUIRE_SUBSCRIPTION') && REQUIRE_SUBSCRIPTION) {
        if (!gm_user_has_subscription($user_id)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'subscription_required',
                'message' => 'Active subscription required',
                'subscribe_url' => gm_get_auth_urls()['subscribe_url']
            ]);
            exit;
        }
    }
    
    return $user_id;
}

// ========================================
// OWNERSHIP VALIDATION FUNCTIONS
// ========================================

/**
 * Check if user owns a vehicle
 */
function gm_user_owns_vehicle(PDO $pdo, string $userId, string $vehicleId): bool {
    if ($userId === 'default') {
        return true; // Single-user mode
    }
    
    $stmt = $pdo->prepare("SELECT 1 FROM `vehicles` WHERE `id` = :vid AND `user_id` = :uid LIMIT 1");
    $stmt->execute([':vid' => $vehicleId, ':uid' => $userId]);
    return $stmt->fetch() !== false;
}

/**
 * Check if user owns an entry (via vehicle ownership)
 */
function gm_user_owns_entry(PDO $pdo, string $userId, string $entryId): bool {
    if ($userId === 'default') {
        return true;
    }
    
    $stmt = $pdo->prepare("
        SELECT 1 FROM `entries` e
        JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
        WHERE e.`id` = :eid AND v.`user_id` = :uid
        LIMIT 1
    ");
    $stmt->execute([':eid' => $entryId, ':uid' => $userId]);
    return $stmt->fetch() !== false;
}

/**
 * Check if user owns an attachment (via entry->vehicle ownership)
 */
function gm_user_owns_attachment(PDO $pdo, string $userId, string $attachmentId): bool {
    if ($userId === 'default') {
        return true;
    }
    
    $stmt = $pdo->prepare("
        SELECT 1 FROM `entry_attachments` a
        JOIN `entries` e ON a.`entry_id` = e.`id`
        JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
        WHERE a.`id` = :aid AND v.`user_id` = :uid
        LIMIT 1
    ");
    $stmt->execute([':aid' => $attachmentId, ':uid' => $userId]);
    return $stmt->fetch() !== false;
}

// ========================================
// DATABASE CONNECTION
// ========================================
function db_get_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . GM_DB_HOST . ';dbname=' . GM_DB_NAME . ';charset=' . GM_DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, GM_DB_USER, GM_DB_PASS, $options);
    return $pdo;
}

// ========================================
// FILE STORAGE HELPER FUNCTIONS
// ========================================

/**
 * Ensure the main attachments directory exists
 */
function ensure_attachments_directory(): void {
    if (!is_dir(ATTACHMENTS_PATH)) {
        if (!mkdir(ATTACHMENTS_PATH, 0755, true)) {
            throw new RuntimeException('Failed to create attachments directory: ' . ATTACHMENTS_PATH);
        }
        $htaccess = "# Deny all direct access\nOrder deny,allow\nDeny from all\n";
        file_put_contents(ATTACHMENTS_PATH . '/.htaccess', $htaccess);
    }
}

/**
 * Get the directory path for a specific entry's attachments
 */
function get_entry_attachments_path(string $entryId): string {
    ensure_attachments_directory();
    
    // Sanitize entry ID to prevent path traversal
    $safeEntryId = preg_replace('/[^a-zA-Z0-9_-]/', '', $entryId);
    if ($safeEntryId !== $entryId || empty($safeEntryId)) {
        throw new RuntimeException('Invalid entry ID');
    }
    
    $entryDir = ATTACHMENTS_PATH . '/' . $safeEntryId;
    if (!is_dir($entryDir)) {
        if (!mkdir($entryDir, 0755, true)) {
            throw new RuntimeException('Failed to create entry attachments directory: ' . $entryDir);
        }
    }
    return $entryDir;
}

/**
 * Get the directory path for a user's attachments (multi-user mode)
 * Structure: /attachments/{user_id}/{entry_id}/files
 */
function get_user_attachments_path(string $userId): string {
    ensure_attachments_directory();
    
    // Sanitize user ID to prevent path traversal
    $safeUserId = preg_replace('/[^a-zA-Z0-9_-]/', '', $userId);
    if ($safeUserId !== $userId || empty($safeUserId)) {
        throw new RuntimeException('Invalid user ID');
    }
    
    $userDir = ATTACHMENTS_PATH . '/' . $safeUserId;
    if (!is_dir($userDir)) {
        if (!mkdir($userDir, 0755, true)) {
            throw new RuntimeException('Failed to create user attachments directory: ' . $userDir);
        }
    }
    return $userDir;
}

/**
 * Generate a secure filename to prevent collisions and security issues
 */
function generate_secure_filename(string $originalName): string {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $basename = pathinfo($originalName, PATHINFO_FILENAME);
    
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
    $basename = substr($basename, 0, 100);
    
    $unique = time() . '_' . bin2hex(random_bytes(8));
    
    return $basename . '_' . $unique . '.' . $ext;
}

/**
 * Validate uploaded file meets security and size requirements
 */
function validate_file_upload(array $file): bool {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $maxBytes = ENTRY_MAX_ATTACHMENT_SIZE_MB * 1024 * 1024;
    if ($file['size'] > $maxBytes || $file['size'] <= 0) {
        return false;
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return false;
    }
    
    if (!empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            return false;
        }
    }
    
    return true;
}

// Initialize security
gm_enforce_https();
