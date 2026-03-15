<?php
/**
 * WordPress Subscription Integration for GarageMinder
 *
 * Bridges GarageMinder with the dynamic tmw-wp membership system.
 *
 * KEY DESIGN PRINCIPLES:
 *  - ZERO hardcoded tier slugs or membership plugin names.
 *  - Delegates entirely to the WordPress theme's adapter pattern.
 *  - Falls back gracefully to GM_FALLBACK_TIER / GM_FALLBACK_LIMITS when WP unavailable.
 *  - All functions wrapped in function_exists() guards to prevent fatal redeclaration errors.
 *
 * FALLBACK CONFIGURATION (add to config.php):
 *  const GM_FALLBACK_TIER = 'paid';
 *  const GM_FALLBACK_LIMITS = [...];
 *
 * @package GarageMinder
 * @version 3.2
 */

if (!function_exists('gm_sub_fallback_tier')) {
    function gm_sub_fallback_tier(): string {
        return defined('GM_FALLBACK_TIER') ? (string) GM_FALLBACK_TIER : 'paid';
    }
}

if (!function_exists('gm_sub_fallback_limits')) {
    function gm_sub_fallback_limits(): array {
        if (defined('GM_FALLBACK_LIMITS') && is_array(GM_FALLBACK_LIMITS)) {
            return GM_FALLBACK_LIMITS;
        }
        // Key names match what tmw_get_tier_values() stores in the WP 'tmw_tier_values' option.
        // WP admin keys: max_vehicles, max_entries, attachments_per_entry, max_templates,
        //   recalls_enabled, vehicle_photos, export_level, api_access, team_members.
        // NOTE: There are NO separate enable_local_upload / enable_gdrive keys in WP.
        return [
            'max_vehicles'          => 2,
            'max_entries'           => 50,
            'attachments_per_entry' => 0,   // 0 = uploads not allowed on this tier
            'max_templates'         => 3,
            'recalls_enabled'       => false, // WP key (not enable_recalls)
            'vehicle_photos'        => false, // WP key (not enable_vehicle_photos)
            'export_level'          => 'none', // WP values: none | basic | advanced
            'api_access'            => false, // WP key (not enable_api)
            'team_members'          => 0,
        ];
    }
}

// ===========================================================================
// TIER RESOLUTION
// ===========================================================================

if (!function_exists('gm_get_user_subscription_tier')) {
    /**
     * Get the subscription tier slug for a user.
     *
     * Resolution order:
     *  1. tmw_get_user_tier() — WP theme adapter chain (handles Stripe, SWPM, PMPro, etc.)
     *  2. tmw_subscription_tier user meta
     *  3. GM_FALLBACK_TIER constant (or 'paid' if unset)
     *
     * @param string|null $userId WordPress user ID (null = current user)
     * @return string Tier slug
     */
    function gm_get_user_subscription_tier(?string $userId = null): string {
        if (!function_exists('gm_load_wordpress') || !gm_load_wordpress()) {
            return gm_sub_fallback_tier();
        }

        if (!$userId) {
            $userId = function_exists('gm_get_current_user_id') ? gm_get_current_user_id() : null;
        }

        if (!$userId || $userId === 'default') {
            return gm_sub_fallback_tier();
        }

        // 1. WP theme adapter-aware function (Stripe, SWPM, PMPro, WooCommerce, user-meta)
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

        return gm_sub_fallback_tier();
    }
}

// ===========================================================================
// LIMIT RESOLUTION
// ===========================================================================

if (!function_exists('gm_get_tier_limits')) {
    /**
     * Get the limits array for a given tier slug.
     *
     * @param string $tier Tier slug (empty = use fallback tier)
     * @return array Limits key => value array
     */
    function gm_get_tier_limits(string $tier = ''): array {
        if ($tier === '') {
            $tier = gm_sub_fallback_tier();
        }

        if (!function_exists('gm_load_wordpress') || !gm_load_wordpress()) {
            return gm_sub_fallback_limits();
        }

        // 1. WP theme dynamic tier values (reads tmw_tier_values WP option)
        if (function_exists('tmw_get_tier_limits')) {
            $limits = tmw_get_tier_limits($tier);
            if (!empty($limits) && is_array($limits)) {
                return $limits;
            }
        }

        // 2. Legacy WP option (backward compat)
        if (function_exists('get_option')) {
            $settings = get_option('gm_subscription_settings', []);
            if (!empty($settings[$tier]) && is_array($settings[$tier])) {
                return $settings[$tier];
            }
        }

        // 3. Try fallback tier before giving up
        $fallback = gm_sub_fallback_tier();
        if ($tier !== $fallback && function_exists('tmw_get_tier_limits')) {
            $limits = tmw_get_tier_limits($fallback);
            if (!empty($limits) && is_array($limits)) {
                return $limits;
            }
        }

        return gm_sub_fallback_limits();
    }
}

if (!function_exists('gm_get_user_limits')) {
    /**
     * Get the limits for a specific user (resolves their tier first).
     *
     * Resolution order:
     *  1. tmw_get_user_limits() via WP theme — reads live tmw_tier_values WP option
     *  2. gm_get_tier_limits() via adapter chain fallback
     *
     * Important: attachments_per_entry comes directly from WP admin tier settings.
     * If WP returns 0 but ENTRY_MAX_ATTACHMENTS constant is set in config.php,
     * we use the constant as a floor so uploads are never accidentally blocked by
     * a WP connectivity failure.
     *
     * @param string|null $userId WordPress user ID (null = current user)
     * @return array Limits array
     */
    function gm_get_user_limits(?string $userId = null): array {
        $limits = [];

        if (function_exists('gm_load_wordpress') && gm_load_wordpress() && function_exists('tmw_get_user_limits')) {
            $wpLimits = tmw_get_user_limits($userId ? (int) $userId : 0);
            if (!empty($wpLimits) && is_array($wpLimits)) {
                $limits = $wpLimits;
            }
        }

        if (empty($limits)) {
            $tier   = gm_get_user_subscription_tier($userId);
            $limits = gm_get_tier_limits($tier);
        }

        // Safety net: if WP returned attachments_per_entry=0 but config.php defines
        // ENTRY_MAX_ATTACHMENTS > 0, use the constant as the floor. This prevents
        // a WP load failure from silently blocking all file uploads.
        if (
            isset($limits['attachments_per_entry']) &&
            (int) $limits['attachments_per_entry'] <= 0 &&
            defined('ENTRY_MAX_ATTACHMENTS') &&
            (int) ENTRY_MAX_ATTACHMENTS > 0
        ) {
            $limits['attachments_per_entry'] = (int) ENTRY_MAX_ATTACHMENTS;
        }

        return $limits;
    }
}

// ===========================================================================
// FEATURE / CAPABILITY CHECKS
// ===========================================================================

if (!function_exists('gm_user_can')) {
    /**
     * Check whether the user can use a specific feature.
     * Feature keys map directly to limit keys in WP Admin tier values.
     *
     * @param string      $feature  Limit key (e.g. 'enable_recalls', 'export_level')
     * @param string|null $userId   WordPress user ID (null = current user)
     * @return bool
     */
    function gm_user_can(string $feature, ?string $userId = null): bool {
        if (function_exists('gm_load_wordpress') && gm_load_wordpress() && function_exists('gm_has_feature')) {
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
}

// ===========================================================================
// COUNTED LIMIT CHECKS
// ===========================================================================

if (!function_exists('gm_can_add_vehicle')) {
    /**
     * Check whether the user can add another vehicle.
     */
    function gm_can_add_vehicle(PDO $pdo, ?string $userId = null): bool {
        if (!$userId && function_exists('gm_get_current_user_id')) {
            $userId = gm_get_current_user_id();
        }
        if (!$userId || $userId === 'default') { return true; }

        $limits      = gm_get_user_limits($userId);
        $maxVehicles = (int) ($limits['max_vehicles'] ?? -1);
        if ($maxVehicles < 0) { return true; }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `vehicles` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn() < $maxVehicles;
    }
}

if (!function_exists('gm_can_add_entry')) {
    /**
     * Check whether the user can add another service entry.
     */
    function gm_can_add_entry(PDO $pdo, ?string $userId = null): bool {
        if (!$userId && function_exists('gm_get_current_user_id')) {
            $userId = gm_get_current_user_id();
        }
        if (!$userId || $userId === 'default') { return true; }

        $limits     = gm_get_user_limits($userId);
        $maxEntries = (int) ($limits['max_entries'] ?? -1);
        if ($maxEntries < 0) { return true; }

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM `entries` e
            JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
            WHERE v.`user_id` = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn() < $maxEntries;
    }
}

if (!function_exists('gm_can_add_attachment')) {
    /**
     * Check whether the user can add another attachment to a specific entry.
     */
    function gm_can_add_attachment(PDO $pdo, string $entryId, ?string $userId = null): bool {
        if (!$userId && function_exists('gm_get_current_user_id')) {
            $userId = gm_get_current_user_id();
        }
        if (!$userId || $userId === 'default') { return true; }

        $limits         = gm_get_user_limits($userId);
        $maxAttachments = (int) ($limits['attachments_per_entry'] ?? 0);
        if ($maxAttachments <= 0) { return false; }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `entry_attachments` WHERE `entry_id` = :eid");
        $stmt->execute([':eid' => $entryId]);
        return (int) $stmt->fetchColumn() < $maxAttachments;
    }
}

// ===========================================================================
// USAGE SUMMARY
// ===========================================================================

if (!function_exists('gm_get_remaining_counts')) {
    /**
     * Return current usage and remaining capacity for the user.
     */
    function gm_get_remaining_counts(PDO $pdo, ?string $userId = null): array {
        if (!$userId && function_exists('gm_get_current_user_id')) {
            $userId = gm_get_current_user_id();
        }

        $tier   = gm_get_user_subscription_tier($userId);
        $limits = gm_get_user_limits($userId);

        $vehicleCount = 0;
        $entryCount   = 0;

        if ($userId && $userId !== 'default') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `vehicles` WHERE `user_id` = :uid");
            $stmt->execute([':uid' => $userId]);
            $vehicleCount = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM `entries` e
                JOIN `vehicles` v ON e.`vehicle_id` = v.`id`
                WHERE v.`user_id` = :uid
            ");
            $stmt->execute([':uid' => $userId]);
            $entryCount = (int) $stmt->fetchColumn();
        }

        $maxVehicles = isset($limits['max_vehicles']) ? (int) $limits['max_vehicles'] : -1;
        $maxEntries  = isset($limits['max_entries'])  ? (int) $limits['max_entries']  : -1;

        $exportLevel = $limits['export_level'] ?? 'none';
        $canExport   = $exportLevel !== '' && $exportLevel !== 'none';

        // WP stores 'recalls_enabled'; fallback to 'enable_recalls' for safety
        $recallsVal = $limits['recalls_enabled'] ?? $limits['enable_recalls'] ?? false;
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
}

// ===========================================================================
// UPGRADE URLS & MESSAGING
// ===========================================================================

if (!function_exists('gm_get_upgrade_url')) {
    /**
     * Return the upgrade / pricing page URL.
     */
    function gm_get_upgrade_url(string $feature = ''): string {
        if (!function_exists('gm_load_wordpress') || !gm_load_wordpress()) {
            if (defined('CUSTOM_SUBSCRIBE_URL') && CUSTOM_SUBSCRIBE_URL !== '') {
                return CUSTOM_SUBSCRIBE_URL;
            }
            return '/pricing/';
        }

        if (function_exists('tmw_get_upgrade_url')) {
            return tmw_get_upgrade_url();
        }

        if (function_exists('gm_get_wp_auth_settings')) {
            $auth = gm_get_wp_auth_settings();
            if (!empty($auth['pricing_url'])) {
                return $auth['pricing_url'];
            }
        }

        return function_exists('home_url') ? home_url('/pricing/') : '/pricing/';
    }
}

if (!function_exists('gm_format_limit_message')) {
    /**
     * Format a human-readable "limit reached" message with an upgrade link.
     */
    function gm_format_limit_message(string $limitType, array $counts): string {
        $tierSlug = $counts['tier'] ?? gm_sub_fallback_tier();

        $tierName = (function_exists('gm_load_wordpress') && gm_load_wordpress() && function_exists('tmw_get_tier_name'))
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
}

// ===========================================================================
// API SUBSCRIPTION RESPONSE HELPER
// ===========================================================================

if (!function_exists('gm_get_subscription_api_response')) {
    /**
     * Build the complete subscription payload for the api.php load response.
     * The frontend reads data.subscription to enable/disable features.
     */
    function gm_get_subscription_api_response(PDO $pdo, string $userId): array {
        $tier      = gm_get_user_subscription_tier($userId);
        // Use gm_get_user_limits (not gm_get_tier_limits) so the ENTRY_MAX_ATTACHMENTS
        // safety net is applied — this ensures the frontend gets the correct
        // attachments_per_entry value even when WP load fails.
        $limits    = gm_get_user_limits($userId);
        $remaining = gm_get_remaining_counts($pdo, $userId);

        // Apply the same safety net to $remaining so features block is consistent
        if ((int) ($remaining['attachments_per_entry'] ?? 0) <= 0 &&
            defined('ENTRY_MAX_ATTACHMENTS') && (int) ENTRY_MAX_ATTACHMENTS > 0) {
            $remaining['attachments_per_entry'] = (int) ENTRY_MAX_ATTACHMENTS;
        }

        $tierName = (function_exists('gm_load_wordpress') && gm_load_wordpress() && function_exists('tmw_get_tier_name'))
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

                // WP export_level values: 'none' | 'basic' | 'advanced'
                'export'                => $remaining['can_export'],
                'export_level'          => $remaining['export_level'],
                // export_bulk: true when level is 'advanced' (WP) or legacy 'bulk'
                'export_bulk'           => in_array($remaining['export_level'], ['advanced', 'bulk'], true),

                // WP only has 'attachments_per_entry' (number). No separate
                // enable_local_upload or enable_gdrive keys exist in WP.
                // Both upload types are allowed whenever per_entry > 0.
                'attachments'           => $remaining['attachments_per_entry'] > 0,
                'attachments_per_entry' => $remaining['attachments_per_entry'],
                'local_upload'          => $remaining['attachments_per_entry'] > 0,
                'gdrive'                => $remaining['attachments_per_entry'] > 0,

                // WP key is 'vehicle_photos', not 'enable_vehicle_photos'
                'vehicle_photos'        => (bool) ($limits['vehicle_photos'] ?? $limits['enable_vehicle_photos'] ?? false),

                'templates'             => (int)  ($limits['max_templates']          ?? 0) !== 0,
                'max_templates'         => (int)  ($limits['max_templates']          ?? 0),
            ],

            'upgrade_url' => gm_get_upgrade_url(),
        ];
    }
}