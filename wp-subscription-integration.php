<?php
/**
 * WordPress Theme Subscription Integration
 * 
 * Add this file to your GarageMinder installation and include it in config.php
 * This provides functions to check subscription tiers and limits from the WordPress theme.
 *
 * Usage: require_once(__DIR__ . '/wp-subscription-integration.php');
 *
 * @package GarageMinder
 * @version 2.4
 */

// ========================================
// SUBSCRIPTION TIER FUNCTIONS
// ========================================

/**
 * Get user's subscription tier from WordPress
 * 
 * @param string|null $userId WordPress user ID (null for current user)
 * @return string 'free', 'paid', or 'fleet'
 */
function gm_get_user_subscription_tier(?string $userId = null): string {
    if (!gm_load_wordpress()) {
        return 'paid'; // Fallback: allow full access in single-user mode
    }
    
    if (!$userId) {
        $userId = gm_get_current_user_id();
    }
    
    if (!$userId || $userId === 'default') {
        return 'paid'; // Single-user mode gets full access
    }
    
    // Check if the theme function exists
    if (function_exists('tmw_get_user_tier')) {
        return tmw_get_user_tier((int) $userId);
    }
    
    // Fallback: Check user meta directly
    $tier = get_user_meta((int) $userId, 'tmw_subscription_tier', true);
    if (in_array($tier, ['free', 'paid', 'fleet'], true)) {
        return $tier;
    }
    
    // Fallback: Check Simple Membership if available
    if (function_exists('SwpmMemberUtils') || class_exists('SwpmMemberUtils')) {
        global $wpdb;
        $level_id = $wpdb->get_var($wpdb->prepare(
            "SELECT membership_level FROM {$wpdb->prefix}swpm_members_tbl WHERE member_id = %d",
            $userId
        ));
        
        if ($level_id) {
            $level_mapping = get_option('gm_level_mapping', []);
            
            if (!empty($level_mapping['free_level_id']) && $level_id == $level_mapping['free_level_id']) {
                return 'free';
            }
            if (!empty($level_mapping['paid_level_id']) && $level_id == $level_mapping['paid_level_id']) {
                return 'paid';
            }
            if (!empty($level_mapping['fleet_level_id']) && $level_id == $level_mapping['fleet_level_id']) {
                return 'fleet';
            }
        }
    }
    
    return 'free'; // Default fallback
}

/**
 * Get subscription limits for a tier
 *
 * @param string $tier 'free', 'paid', or 'fleet'
 * @return array Limits array
 */
function gm_get_tier_limits(string $tier = 'free'): array {
    // Default limits
    $defaults = [
        'free' => [
            'max_vehicles' => 2,
            'max_entries' => 50,
            'attachments_per_entry' => 0,
            'enable_recalls' => false,
            'export_level' => 'none',
            'max_templates' => 3,
            'enable_vehicle_photos' => false,
            'enable_api' => false,
            'team_members' => 0,
        ],
        'paid' => [
            'max_vehicles' => 10,
            'max_entries' => -1, // Unlimited
            'attachments_per_entry' => 2,
            'enable_recalls' => true,
            'export_level' => 'standard',
            'max_templates' => 15,
            'enable_vehicle_photos' => true,
            'enable_api' => false,
            'team_members' => 0,
        ],
        'fleet' => [
            'max_vehicles' => -1, // Unlimited
            'max_entries' => -1,
            'attachments_per_entry' => 5,
            'enable_recalls' => true,
            'export_level' => 'bulk',
            'max_templates' => -1,
            'enable_vehicle_photos' => true,
            'enable_api' => true,
            'team_members' => 10,
        ],
    ];
    
    if (!gm_load_wordpress()) {
        return $defaults[$tier] ?? $defaults['free'];
    }
    
    // Try to get from WordPress theme settings
    if (function_exists('tmw_get_tier_limits')) {
        return tmw_get_tier_limits($tier);
    }
    
    // Try to get from WordPress options
    $settings = get_option('gm_subscription_settings', []);
    
    if (!empty($settings[$tier]) && is_array($settings[$tier])) {
        return array_merge($defaults[$tier] ?? $defaults['free'], $settings[$tier]);
    }
    
    return $defaults[$tier] ?? $defaults['free'];
}

/**
 * Check if user can perform a feature action
 *
 * @param string $feature Feature name (e.g., 'recalls', 'export', 'attachments')
 * @param string|null $userId WordPress user ID (null for current user)
 * @return bool
 */
function gm_user_can(string $feature, ?string $userId = null): bool {
    $limits = gm_get_user_limits($userId);
    
    switch ($feature) {
        case 'recalls':
            return !empty($limits['enable_recalls']);
            
        case 'export':
        case 'export_csv':
        case 'export_pdf':
            return !empty($limits['export_level']) && $limits['export_level'] !== 'none';
            
        case 'export_bulk':
            return !empty($limits['export_level']) && $limits['export_level'] === 'bulk';
            
        case 'attachments':
            return isset($limits['attachments_per_entry']) && $limits['attachments_per_entry'] > 0;
            
        case 'vehicle_photos':
            return !empty($limits['enable_vehicle_photos']);
            
        case 'api':
            return !empty($limits['enable_api']);
            
        case 'templates':
            return !isset($limits['max_templates']) || $limits['max_templates'] !== 0;
            
        default:
            return true;
    }
}

/**
 * Check if user can add another vehicle
 *
 * @param PDO $pdo Database connection
 * @param string|null $userId WordPress user ID (null for current user)
 * @return bool
 */
function gm_can_add_vehicle(PDO $pdo, ?string $userId = null): bool {
    if (!$userId) {
        $userId = gm_get_current_user_id();
    }
    
    if (!$userId || $userId === 'default') {
        return true; // Single-user mode
    }
    
    $limits = gm_get_user_limits($userId);
    $maxVehicles = $limits['max_vehicles'] ?? -1;
    
    if ($maxVehicles < 0) {
        return true; // Unlimited
    }
    
    // Count current vehicles
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    $count = (int) $stmt->fetchColumn();
    
    return $count < $maxVehicles;
}

/**
 * Check if user can add another entry
 *
 * @param PDO $pdo Database connection
 * @param string|null $userId WordPress user ID (null for current user)
 * @return bool
 */
function gm_can_add_entry(PDO $pdo, ?string $userId = null): bool {
    if (!$userId) {
        $userId = gm_get_current_user_id();
    }
    
    if (!$userId || $userId === 'default') {
        return true; // Single-user mode
    }
    
    $limits = gm_get_user_limits($userId);
    $maxEntries = $limits['max_entries'] ?? -1;
    
    if ($maxEntries < 0) {
        return true; // Unlimited
    }
    
    // Count current entries across all user's vehicles
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM entries e
        JOIN vehicles v ON e.vehicle_id = v.id
        WHERE v.user_id = :uid
    ");
    $stmt->execute([':uid' => $userId]);
    $count = (int) $stmt->fetchColumn();
    
    return $count < $maxEntries;
}

/**
 * Check if user can add another attachment to an entry
 *
 * @param PDO $pdo Database connection
 * @param string $entryId Entry ID
 * @param string|null $userId WordPress user ID (null for current user)
 * @return bool
 */
function gm_can_add_attachment(PDO $pdo, string $entryId, ?string $userId = null): bool {
    if (!$userId) {
        $userId = gm_get_current_user_id();
    }
    
    if (!$userId || $userId === 'default') {
        return true; // Single-user mode (use config constant)
    }
    
    $limits = gm_get_user_limits($userId);
    $maxAttachments = $limits['attachments_per_entry'] ?? 0;
    
    if ($maxAttachments <= 0) {
        return false; // No attachments allowed
    }
    
    // Count current attachments for this entry
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_attachments WHERE entry_id = :eid");
    $stmt->execute([':eid' => $entryId]);
    $count = (int) $stmt->fetchColumn();
    
    return $count < $maxAttachments;
}

/**
 * Get remaining counts for user
 *
 * @param PDO $pdo Database connection
 * @param string|null $userId WordPress user ID (null for current user)
 * @return array [vehicles_remaining, entries_remaining, etc.]
 */
function gm_get_remaining_counts(PDO $pdo, ?string $userId = null): array {
    if (!$userId) {
        $userId = gm_get_current_user_id();
    }
    
    $limits = gm_get_user_limits($userId);
    $tier = gm_get_user_subscription_tier($userId);
    
    // Count vehicles
    $vehicleCount = 0;
    if ($userId && $userId !== 'default') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $vehicleCount = (int) $stmt->fetchColumn();
    }
    
    // Count entries
    $entryCount = 0;
    if ($userId && $userId !== 'default') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM entries e
            JOIN vehicles v ON e.vehicle_id = v.id
            WHERE v.user_id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $entryCount = (int) $stmt->fetchColumn();
    }
    
    $maxVehicles = $limits['max_vehicles'] ?? -1;
    $maxEntries = $limits['max_entries'] ?? -1;
    
    return [
        'tier' => $tier,
        'vehicles_used' => $vehicleCount,
        'vehicles_max' => $maxVehicles,
        'vehicles_remaining' => $maxVehicles < 0 ? -1 : max(0, $maxVehicles - $vehicleCount),
        'vehicles_unlimited' => $maxVehicles < 0,
        'entries_used' => $entryCount,
        'entries_max' => $maxEntries,
        'entries_remaining' => $maxEntries < 0 ? -1 : max(0, $maxEntries - $entryCount),
        'entries_unlimited' => $maxEntries < 0,
        'attachments_per_entry' => $limits['attachments_per_entry'] ?? 0,
        'can_use_recalls' => !empty($limits['enable_recalls']),
        'can_export' => !empty($limits['export_level']) && $limits['export_level'] !== 'none',
        'export_level' => $limits['export_level'] ?? 'none',
    ];
}

/**
 * Get upgrade URL with context
 *
 * @param string $feature Feature user is trying to access
 * @return string URL to pricing/upgrade page
 */
function gm_get_upgrade_url(string $feature = ''): string {
    if (!gm_load_wordpress()) {
        return '/pricing/';
    }
    
    // Get from theme if available
    if (function_exists('tmw_get_upgrade_url')) {
        return tmw_get_upgrade_url($feature);
    }
    
    // Get from auth settings
    $auth_settings = gm_get_wp_auth_settings();
    if (!empty($auth_settings['pricing_url'])) {
        return $auth_settings['pricing_url'];
    }
    
    return home_url('/pricing/');
}

/**
 * Format limit reached message
 *
 * @param string $limitType 'vehicles', 'entries', 'attachments'
 * @param array $counts Result from gm_get_remaining_counts()
 * @return string Human-readable message
 */
function gm_format_limit_message(string $limitType, array $counts): string {
    $tier = ucfirst($counts['tier'] ?? 'free');
    $upgradeUrl = gm_get_upgrade_url($limitType);
    
    switch ($limitType) {
        case 'vehicles':
            $max = $counts['vehicles_max'];
            return sprintf(
                'You\'ve reached your %s plan limit of %d vehicles. <a href="%s">Upgrade your plan</a> for more.',
                $tier, $max, esc_url($upgradeUrl)
            );
            
        case 'entries':
            $max = $counts['entries_max'];
            return sprintf(
                'You\'ve reached your %s plan limit of %d service entries. <a href="%s">Upgrade your plan</a> for unlimited entries.',
                $tier, $max, esc_url($upgradeUrl)
            );
            
        case 'attachments':
            $max = $counts['attachments_per_entry'];
            if ($max === 0) {
                return sprintf(
                    'Attachments are not available on the %s plan. <a href="%s">Upgrade your plan</a> to attach files.',
                    $tier, esc_url($upgradeUrl)
                );
            }
            return sprintf(
                'You can only attach %d files per entry on the %s plan. <a href="%s">Upgrade for more.</a>',
                $max, $tier, esc_url($upgradeUrl)
            );
            
        default:
            return sprintf(
                'This feature is not available on your current plan. <a href="%s">Upgrade to unlock.</a>',
                esc_url($upgradeUrl)
            );
    }
}

// ========================================
// API SUBSCRIPTION ENDPOINT
// ========================================

/**
 * Get subscription status for API response
 * Can be called from api.php to add subscription info
 *
 * @param PDO $pdo Database connection
 * @param string $userId User ID
 * @return array Subscription data for JSON response
 */
function gm_get_subscription_api_response(PDO $pdo, string $userId): array {
    $tier = gm_get_user_subscription_tier($userId);
    $limits = gm_get_tier_limits($tier);
    $remaining = gm_get_remaining_counts($pdo, $userId);
    
    return [
        'tier' => $tier,
        'tier_name' => ucfirst($tier),
        'is_active' => true, // Would check expiry in production
        'limits' => $limits,
        'usage' => [
            'vehicles' => [
                'used' => $remaining['vehicles_used'],
                'max' => $remaining['vehicles_max'],
                'remaining' => $remaining['vehicles_remaining'],
                'unlimited' => $remaining['vehicles_unlimited'],
            ],
            'entries' => [
                'used' => $remaining['entries_used'],
                'max' => $remaining['entries_max'],
                'remaining' => $remaining['entries_remaining'],
                'unlimited' => $remaining['entries_unlimited'],
            ],
        ],
        'features' => [
            'recalls' => $remaining['can_use_recalls'],
            'export' => $remaining['can_export'],
            'export_level' => $remaining['export_level'],
            'attachments' => $remaining['attachments_per_entry'] > 0,
            'attachments_per_entry' => $remaining['attachments_per_entry'],
        ],
        'upgrade_url' => gm_get_upgrade_url(),
    ];
}
