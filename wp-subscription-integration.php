<?php
/**
 * WordPress Subscription Integration for GarageMinder
 *
 * Bridges GarageMinder with the dynamic tmw-wp membership system.
 *
 * KEY DESIGN PRINCIPLES:
 *  - ZERO hardcoded tier slugs (free/paid/fleet) or membership plugin names.
 *  - Delegates entirely to the WordPress theme's adapter pattern, which already
 *    handles plugin switching (Stripe <=> SWPM <=> PMPro <=> etc.) transparently.
 *  - Falls back gracefully to GM_FALLBACK_TIER / GM_FALLBACK_LIMITS from
 *    config.php when WordPress is unavailable (single-user / dev mode).
 *
 * HOW THE ADAPTER CHAIN WORKS (configured in WP Admin -> General Settings):
 *  membership_plugin = 'stripe'            -> TMW_Stripe_Adapter
 *  membership_plugin = 'simple-membership' -> TMW_Simple_Membership_Adapter
 *  membership_plugin = 'pmpro'             -> TMW_PMPro_Adapter
 *  membership_plugin = 'woocommerce'       -> TMW_WooCommerce_Adapter
 *  membership_plugin = 'user-meta'         -> TMW_User_Meta_Adapter (manual)
 *  Any adapter -> tmw_get_user_tier() -> tier slug -> tmw_get_tier_limits() -> limits array
 *
 * FALLBACK CONFIGURATION (add to config.php):
 *  const GM_FALLBACK_TIER = 'paid';   // tier slug used when WP unavailable
 *  const GM_FALLBACK_LIMITS = [...];  // optional explicit limits array
 *
 * @package GarageMinder
 * @version 3.1
 */

// ===========================================================================
// INTERNAL HELPERS (private to this file)
// ===========================================================================

/**
 * Return the configured fallback tier slug (used when WP cannot be loaded).
 * Reads GM_FALLBACK_TIER from config.php; defaults to 'paid' so single-user
 * installs continue to work with full access.
 */
function _gm_fallback_tier(): string {
    return defined('GM_FALLBACK_TIER') ? (string) GM_FALLBACK_TIER : 'paid';
}

/**
 * Return the fallback limits array (used when WP is completely unavailable).
 * If GM_FALLBACK_LIMITS is defined in config.php that array is used exactly.
 * Otherwise a safe built-in minimum is returned so the app never hard-crashes.
 *
 * To override, add to config.php:
 *   const GM_FALLBACK_LIMITS = [
 *       'max_vehicles'          => 2,
 *       'max_entries'           => 50,
 *       'attachments_per_entry' => 0,
 *       'enable_recalls'        => false,
 *       'export_level'          => 'none',
 *       'max_templates'         => 3,
 *       'enable_vehicle_photos' => false,
 *       'enable_local_upload'   => false,
 *       'enable_gdrive'         => true,
 *       'enable_api'            => false,
 *       'team_members'          => 0,
 *   ];
 */
function _gm_fallback_limits(): array {
    if (defined('GM_FALLBACK_LIMITS') && is_array(GM_FALLBACK_LIMITS)) {
        return GM_FALLBACK_LIMITS;
    }
    return [
        'max_vehicles'          => 2,
        'max_entries'           => 50,
        'attachments_per_entry' => 0,
        'enable_recalls'        => false,
        'export_level'          => 'none',
        'max_templates'         => 3,
        'enable_vehicle_photos' => false,
        'enable_local_upload'   => false,
        'enable_gdrive'         => true,
        'enable_api'            => false,
        'team_members'          => 0,
    ];
}


// ===========================================================================
// TIER RESOLUTION
// ===========================================================================

/**
 * Get the subscription tier slug for a user.
 *
 * Resolution order (stops at first success):
 *  1. tmw_get_user_tier() from the WP theme - internally calls
 *     tmw_get_membership_adapter() which returns the adapter for whichever
 *     plugin is configured (Stripe, SWPM, PMPro, WooCommerce, user-meta).
 *     This one call handles ALL plugin switching transparently.
 *  2. tmw_subscription_tier user meta (written by every adapter on change).
 *  3. GM_FALLBACK_TIER constant (or 'paid' if not set).
 *
 * The returned slug is NEVER validated against a fixed list - custom tier slugs
 * configured in WP Admin work automatically.
 *
 * @param string|null $userId WordPress user ID (null = current user)
 * @return string Tier slug
 */
function gm_get_user_subscription_tier(?string $userId = null): string {

    if (!gm_load_wordpress()) {
        return _gm_fallback_tier();
    }

    if (!$userId) {
        $userId = gm_get_current_user_id();
    }

    if (!$userId || $userId === 'default') {
        return _gm_fallback_tier();
    }

    // 1. WP theme adapter-aware function (handles Stripe, SWPM, PMPro, etc.)
    if (function_exists('tmw_get_user_tier')) {
        $tier = tmw_get_user_tier((int) $userId);
        if ($tier && $tier !== 'none') {
            return $tier;
        }
    }

    // 2. User meta written by adapters on subscription changes
    if (function_exists('get_user_meta')) {
        $tier = get_user_meta((int) $userId, 'tmw_subscription_tier', true);
        if ($tier && is_string($tier) && $tier !== '') {
            if (function_exists('tmw_get_tiers')) {
                $tiers = tmw_get_tiers();
                if (isset($tiers[$tier])) {
                    return $tier;
                }
            } else {
                return $tier;
            }
        }
    }

    // 3. Configurable fallback
    return _gm_fallback_tier();
}


// ===========================================================================
// LIMIT RESOLUTION
// ===========================================================================

/**
 * Get the limits array for a given tier slug.
 *
 * Resolution order:
 *  1. tmw_get_tier_limits($tier) from WP theme - reads from the
 *     tmw_tier_values WP option managed in WP Admin.
 *  2. Legacy gm_subscription_settings WP option (backward compat).
 *  3. _gm_fallback_limits() when WP is unreachable or tier is not found.
 *
 * @param string $tier Tier slug (empty = use fallback tier)
 * @return array Limits key => value array
 */
function gm_get_tier_limits(string $tier = ''): array {

    if ($tier === '') {
        $tier = _gm_fallback_tier();
    }

    if (!gm_load_wordpress()) {
        return _gm_fallback_limits();
    }

    // 1. WP theme dynamic tier values
    if (function_exists('tmw_get_tier_limits')) {
        $limits = tmw_get_tier_limits($tier);
        if (!empty($limits) && is_array($limits)) {
            return $limits;
        }
    }

    // 2. Legacy WP option
    $settings = get_option('gm_subscription_settings', []);
    if (!empty($settings[$tier]) && is_array($settings[$tier])) {
        return $settings[$tier];
    }

    // 3. Try fallback tier before giving up
    $fallback = _gm_fallback_tier();
    if ($tier !== $fallback && function_exists('tmw_get_tier_limits')) {
        $limits = tmw_get_tier_limits($fallback);
        if (!empty($limits) && is_array($limits)) {
            return $limits;
        }
    }

    return _gm_fallback_limits();
}

/**
 * Get the limits for a specific user (resolves their tier first).
 *
 * @param string|null $userId WordPress user ID (null = current user)
 * @return array Limits array
 */
function gm_get_user_limits(?string $userId = null): array {

    if (gm_load_wordpress() && function_exists('tmw_get_user_limits')) {
        $limits = tmw_get_user_limits($userId ? (int) $userId : 0);
        if (!empty($limits) && is_array($limits)) {
            return $limits;
        }
    }

    $tier = gm_get_user_subscription_tier($userId);
    return gm_get_tier_limits($tier);
}


// ===========================================================================
// FEATURE / CAPABILITY CHECKS
// ===========================================================================

/**
 * Check whether the user can use a specific feature.
 *
 * Feature keys map directly to limit keys configured in WP Admin tier values.
 * New features added there are supported here automatically - no code change needed.
 *
 * Common keys (examples - exact keys depend on your WP Admin tier configuration):
 *   enable_recalls, export_level, attachments_per_entry,
 *   enable_vehicle_photos, enable_local_upload, enable_gdrive, enable_api
 *
 * @param string      $feature  Limit key
 * @param string|null $userId   WordPress user ID (null = current user)
 * @return bool
 */
function gm_user_can(string $feature, ?string $userId = null): bool {

    if (gm_load_wordpress() && function_exists('gm_has_feature')) {
        return (bool) gm_has_feature($userId ? (int) $userId : 0, $feature);
    }

    $limits = gm_get_user_limits($userId);

    if (!array_key_exists($feature, $limits)) {
        return false;
    }

    $value = $limits[$feature];

    if (is_bool($value))    { return $value; }
    if (is_numeric($value)) { return (int) $value > 0 || (int) $value === -1; }

    return $value !== '' && $value !== 'none' && $value !== '0';
}


// ===========================================================================
// COUNTED LIMIT CHECKS
// ===========================================================================

/**
 * Check whether the user can add another vehicle.
 *
 * @param PDO         $pdo
 * @param string|null $userId
 * @return bool
 */
function gm_can_add_vehicle(PDO $pdo, ?string $userId = null): bool {
    if (!$userId) { $userId = gm_get_current_user_id(); }
    if (!$userId || $userId === 'default') { return true; }

    if (gm_load_wordpress() && function_exists('gm_can_add')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        return (bool) gm_can_add((int) $userId, 'max_vehicles', (int) $stmt->fetchColumn());
    }

    $limits      = gm_get_user_limits($userId);
    $maxVehicles = (int) ($limits['max_vehicles'] ?? -1);
    if ($maxVehicles < 0) { return true; }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE user_id = :uid");
    $stmt->execute([':uid' => $userId]);
    return (int) $stmt->fetchColumn() < $maxVehicles;
}

/**
 * Check whether the user can add another service entry.
 *
 * @param PDO         $pdo
 * @param string|null $userId
 * @return bool
 */
function gm_can_add_entry(PDO $pdo, ?string $userId = null): bool {
    if (!$userId) { $userId = gm_get_current_user_id(); }
    if (!$userId || $userId === 'default') { return true; }

    $limits     = gm_get_user_limits($userId);
    $maxEntries = (int) ($limits['max_entries'] ?? -1);
    if ($maxEntries < 0) { return true; }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM entries e
        JOIN vehicles v ON e.vehicle_id = v.id
        WHERE v.user_id = :uid
    ");
    $stmt->execute([':uid' => $userId]);
    return (int) $stmt->fetchColumn() < $maxEntries;
}

/**
 * Check whether the user can add another attachment to a specific entry.
 *
 * @param PDO         $pdo
 * @param string      $entryId
 * @param string|null $userId
 * @return bool
 */
function gm_can_add_attachment(PDO $pdo, string $entryId, ?string $userId = null): bool {
    if (!$userId) { $userId = gm_get_current_user_id(); }
    if (!$userId || $userId === 'default') { return true; }

    $limits         = gm_get_user_limits($userId);
    $maxAttachments = (int) ($limits['attachments_per_entry'] ?? 0);
    if ($maxAttachments <= 0) { return false; }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM entry_attachments WHERE entry_id = :eid");
    $stmt->execute([':eid' => $entryId]);
    return (int) $stmt->fetchColumn() < $maxAttachments;
}


// ===========================================================================
// USAGE SUMMARY
// ===========================================================================

/**
 * Return current usage and remaining capacity for the user.
 * Called by gm_get_subscription_api_response() to build the frontend payload.
 *
 * @param PDO         $pdo
 * @param string|null $userId
 * @return array
 */
function gm_get_remaining_counts(PDO $pdo, ?string $userId = null): array {
    if (!$userId) { $userId = gm_get_current_user_id(); }

    $tier   = gm_get_user_subscription_tier($userId);
    $limits = gm_get_user_limits($userId);

    $vehicleCount = 0;
    $entryCount   = 0;

    if ($userId && $userId !== 'default') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $vehicleCount = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM entries e
            JOIN vehicles v ON e.vehicle_id = v.id
            WHERE v.user_id = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $entryCount = (int) $stmt->fetchColumn();
    }

    $maxVehicles = isset($limits['max_vehicles']) ? (int) $limits['max_vehicles'] : -1;
    $maxEntries  = isset($limits['max_entries'])  ? (int) $limits['max_entries']  : -1;

    $exportLevel = $limits['export_level'] ?? 'none';
    $canExport   = $exportLevel !== '' && $exportLevel !== 'none';

    $recallsVal = $limits['enable_recalls'] ?? false;
    $canRecalls = is_bool($recallsVal) ? $recallsVal : (bool)(int) $recallsVal;

    return [
        'tier'                  => $tier,
        'vehicles_used'         => $vehicleCount,
        'vehicles_max'          => $maxVehicles,
        'vehicles_remaining'    => $maxVehicles < 0 ? -1 : max(0, $maxVehicles - $vehicleCount),
        'vehicles_unlimited'    => $maxVehicles < 0,
        'entries_used'          => $entryCount,
        'entries_max'           => $maxEntries,
        'entries_remaining'     => $maxEntries < 0 ? -1 : max(0, $maxEntries - $entryCount),
        'entries_unlimited'     => $maxEntries < 0,
        'attachments_per_entry' => (int) ($limits['attachments_per_entry'] ?? 0),
        'can_use_recalls'       => $canRecalls,
        'can_export'            => $canExport,
        'export_level'          => $exportLevel,
    ];
}


// ===========================================================================
// UPGRADE URLS & MESSAGING
// ===========================================================================

/**
 * Return the upgrade / pricing page URL.
 * Delegates to the WP theme which knows the correct URL for the active
 * payment plugin (Stripe checkout vs. SWPM join page vs. PMPro, etc.).
 *
 * @param string $feature Optional context hint
 * @return string
 */
function gm_get_upgrade_url(string $feature = ''): string {
    if (!gm_load_wordpress()) {
        if (defined('CUSTOM_SUBSCRIBE_URL') && CUSTOM_SUBSCRIBE_URL !== '') {
            return CUSTOM_SUBSCRIBE_URL;
        }
        return '/pricing/';
    }

    if (function_exists('tmw_get_upgrade_url')) {
        return tmw_get_upgrade_url();
    }

    $auth = gm_get_wp_auth_settings();
    if (!empty($auth['pricing_url'])) {
        return $auth['pricing_url'];
    }

    return function_exists('home_url') ? home_url('/pricing/') : '/pricing/';
}

/**
 * Format a human-readable "limit reached" message with an upgrade link.
 *
 * @param string $limitType  'vehicles' | 'entries' | 'attachments' | generic
 * @param array  $counts     Result of gm_get_remaining_counts()
 * @return string HTML message
 */
function gm_format_limit_message(string $limitType, array $counts): string {
    $tierSlug = $counts['tier'] ?? _gm_fallback_tier();

    $tierName = (gm_load_wordpress() && function_exists('tmw_get_tier_name'))
        ? tmw_get_tier_name($tierSlug)
        : ucfirst($tierSlug);

    $upgradeUrl = gm_get_upgrade_url($limitType);
    $esc        = function_exists('esc_url') ? esc_url($upgradeUrl) : htmlspecialchars($upgradeUrl, ENT_QUOTES);
    $link       = "<a href=\"{$esc}\">Upgrade your plan</a>";

    switch ($limitType) {
        case 'vehicles':
            return sprintf(
                "You've reached your %s plan limit of %d vehicles. %s for more.",
                $tierName, (int) ($counts['vehicles_max'] ?? 0), $link
            );
        case 'entries':
            return sprintf(
                "You've reached your %s plan limit of %d service entries. %s for unlimited.",
                $tierName, (int) ($counts['entries_max'] ?? 0), $link
            );
        case 'attachments':
            $max = (int) ($counts['attachments_per_entry'] ?? 0);
            if ($max === 0) {
                return sprintf(
                    "Attachments are not available on the %s plan. %s to attach files.",
                    $tierName, $link
                );
            }
            return sprintf(
                "You can attach up to %d file(s) per entry on the %s plan. %s for more.",
                $max, $tierName, $link
            );
        default:
            return sprintf("This feature is not available on your current plan. %s to unlock.", $link);
    }
}


// ===========================================================================
// API SUBSCRIPTION RESPONSE HELPER
// ===========================================================================

/**
 * Build the complete subscription payload for the api.php load response.
 * The frontend reads data.subscription to enable/disable features and UI elements.
 *
 * @param PDO    $pdo
 * @param string $userId
 * @return array
 */
function gm_get_subscription_api_response(PDO $pdo, string $userId): array {
    $tier      = gm_get_user_subscription_tier($userId);
    $limits    = gm_get_tier_limits($tier);
    $remaining = gm_get_remaining_counts($pdo, $userId);

    $tierName = (gm_load_wordpress() && function_exists('tmw_get_tier_name'))
        ? tmw_get_tier_name($tier)
        : ucfirst($tier);

    return [
        'tier'      => $tier,
        'tier_name' => $tierName,
        'is_active' => true,
        'limits'    => $limits,

        'usage' => [
            'vehicles' => [
                'used'      => $remaining['vehicles_used'],
                'max'       => $remaining['vehicles_max'],
                'remaining' => $remaining['vehicles_remaining'],
                'unlimited' => $remaining['vehicles_unlimited'],
            ],
            'entries' => [
                'used'      => $remaining['entries_used'],
                'max'       => $remaining['entries_max'],
                'remaining' => $remaining['entries_remaining'],
                'unlimited' => $remaining['entries_unlimited'],
            ],
        ],

        'features' => [
            'recalls'               => $remaining['can_use_recalls'],
            'export'                => $remaining['can_export'],
            'export_level'          => $remaining['export_level'],
            'attachments'           => $remaining['attachments_per_entry'] > 0,
            'attachments_per_entry' => $remaining['attachments_per_entry'],
            'vehicle_photos'        => (bool) ($limits['enable_vehicle_photos'] ?? false),
            'local_upload'          => (bool) ($limits['enable_local_upload']   ?? false),
            'gdrive'                => (bool) ($limits['enable_gdrive']          ?? false),
            'templates'             => (int)  ($limits['max_templates']          ?? 0) !== 0,
            'max_templates'         => (int)  ($limits['max_templates']          ?? 0),
        ],

        'upgrade_url' => gm_get_upgrade_url(),
    ];
}
