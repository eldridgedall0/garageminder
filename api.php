<?php
// Start output buffering to capture any WordPress output
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__ . '/config.php';

// Clear any WordPress output
ob_end_clean();

// Set security headers
gm_set_security_headers();

// ========================================
// MULTI-USER: Authenticate and get user ID
// ========================================
$currentUserId = gm_require_auth_api();

// ---- Database connection check ----
function gm_db_check() {
    try {
        $pdo = db_get_pdo();
        $pdo->query("SELECT 1");
        return true;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success'  => false,
            'db_error' => true,
            'message'  => 'Database connection failed',
            'details'  => $e->getMessage(),
        ]);
        exit;
    }
}

function gm_default_service_types(): array {
    return [
        // Engine & powertrain (core)
        ['name' => 'Oil change',                          'interval_miles' => 5000,  'interval_months' => 6],
        ['name' => 'Oil filter change',                   'interval_miles' => 5000,  'interval_months' => 6],
        ['name' => 'Engine air filter replacement',       'interval_miles' => null,  'interval_months' => 24],
        ['name' => 'Cabin air filter replacement',        'interval_miles' => null,  'interval_months' => 12],
        ['name' => 'Spark plug replacement',              'interval_miles' => 60000, 'interval_months' => null],
        ['name' => 'Serpentine / drive belt replacement', 'interval_miles' => 60000, 'interval_months' => null],

        // Transmission & drivetrain
        ['name' => 'Transmission fluid change',           'interval_miles' => 60000, 'interval_months' => 60],
        ['name' => 'Differential fluid change',           'interval_miles' => 60000, 'interval_months' => null],
        ['name' => 'Transfer case fluid change',          'interval_miles' => 60000, 'interval_months' => null],
        ['name' => 'Power steering fluid change',         'interval_miles' => 60000, 'interval_months' => null],

        // Brakes
        ['name' => 'Brake fluid change',                  'interval_miles' => null,  'interval_months' => 24],
        ['name' => 'Brake pad replacement',               'interval_miles' => 40000, 'interval_months' => null],
        ['name' => 'Brake rotor replacement',             'interval_miles' => 80000, 'interval_months' => null],

        // Cooling system
        ['name' => 'Coolant change',                      'interval_miles' => 60000, 'interval_months' => 60],
        ['name' => 'Radiator / cooling system service',   'interval_miles' => null,  'interval_months' => null],

        // Tires & wheels
        ['name' => 'Tire rotation',                       'interval_miles' => 5000,  'interval_months' => 6],
        ['name' => 'Wheel alignment',                     'interval_miles' => null,  'interval_months' => 12],
        ['name' => 'Wheel balance',                       'interval_miles' => null,  'interval_months' => null],

        // Electrical & battery
        ['name' => '12V battery replacement',             'interval_miles' => null,  'interval_months' => 48],
        ['name' => 'Charging system service',             'interval_miles' => null,  'interval_months' => null],

        // Suspension & steering
        ['name' => 'Suspension inspection',               'interval_miles' => null,  'interval_months' => 12],
        ['name' => 'Steering inspection',                 'interval_miles' => null,  'interval_months' => 12],

        // Safety / legal / ownership
        ['name' => 'Vehicle inspection (state / safety)', 'interval_miles' => null,  'interval_months' => 12],
        ['name' => 'Emissions test',                      'interval_miles' => null,  'interval_months' => 24],
        ['name' => 'Registration renewal',                'interval_miles' => null,  'interval_months' => 12],
        ['name' => 'Insurance renewal',                   'interval_miles' => null,  'interval_months' => 12],
        ['name' => 'Recall service completed',            'interval_miles' => null,  'interval_months' => null],
    ];
}

/**
 * Ensure default service types exist for user
 */
function gm_ensure_default_service_types(PDO $pdo, string $userId): void {
    // Check if user has any service types
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `service_types` WHERE `user_id` = :uid");
    $stmt->execute([':uid' => $userId]);
    $count = (int) $stmt->fetchColumn();
    
    if ($count > 0) {
        return;
    }
    
    // Insert defaults for this user
    $stmt = $pdo->prepare("INSERT INTO `service_types` (`user_id`, `name`, `interval_miles`, `interval_months`) VALUES (:uid, :name, :miles, :months)");
    foreach (gm_default_service_types() as $st) {
        $stmt->execute([
            ':uid'    => $userId,
            ':name'   => $st['name'],
            ':miles'  => $st['interval_miles'],
            ':months' => $st['interval_months'],
        ]);
    }
}

/**
 * Ensure entry_templates table exists
 */
function gm_ensure_templates_table(PDO $pdo): void {
    try {
        $pdo->query("SELECT 1 FROM `entry_templates` LIMIT 1");
    } catch (Throwable $e) {
        // Table doesn't exist, create it
        $pdo->exec("CREATE TABLE IF NOT EXISTS `entry_templates` (
            `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
            `user_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
            `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `services_json` longtext COLLATE utf8mb4_unicode_ci,
            `misc_cost` decimal(10,2) DEFAULT NULL,
            `notes` text COLLATE utf8mb4_unicode_ci,
            `next_date_offset_days` int DEFAULT NULL,
            `next_odo_offset` int DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_templates_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

/**
 * Ensure vehicle details columns exist (migration helper)
 */
function gm_ensure_vehicle_details_columns(PDO $pdo): void {
    try {
        // Check if columns exist by trying to select them
        $pdo->query("SELECT `year`, `make`, `model`, `engine`, `body_class`, `photo_path` FROM `vehicles` LIMIT 1");
    } catch (Throwable $e) {
        // Columns don't exist, add them
        try {
            $pdo->exec("ALTER TABLE `vehicles`
                ADD COLUMN `year` INT NULL,
                ADD COLUMN `make` VARCHAR(100) NULL,
                ADD COLUMN `model` VARCHAR(100) NULL,
                ADD COLUMN `engine` VARCHAR(255) NULL,
                ADD COLUMN `body_class` VARCHAR(100) NULL,
                ADD COLUMN `photo_path` VARCHAR(500) NULL");
        } catch (Throwable $e2) {
            // Columns might already exist partially, try adding them one by one
            $columns = [
                'year' => 'INT NULL',
                'make' => 'VARCHAR(100) NULL',
                'model' => 'VARCHAR(100) NULL',
                'engine' => 'VARCHAR(255) NULL',
                'body_class' => 'VARCHAR(100) NULL',
                'photo_path' => 'VARCHAR(500) NULL'
            ];
            foreach ($columns as $col => $type) {
                try {
                    $pdo->exec("ALTER TABLE `vehicles` ADD COLUMN `$col` $type");
                } catch (Throwable $e3) {
                    // Column likely already exists
                }
            }
        }
    }
}

/**
 * Get a setting for specific user
 */
function gm_get_setting(PDO $pdo, string $key, string $userId, $default = null) {
    $stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = :k AND `user_id` = :uid");
    $stmt->execute([':k' => $key, ':uid' => $userId]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    return $row['value'];
}

/**
 * Set a setting for specific user
 */
function gm_set_setting(PDO $pdo, string $key, string $userId, $value): void {
    $stmt = $pdo->prepare("INSERT INTO `settings` (`key`, `user_id`, `value`) VALUES (:k, :uid, :v)
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([':k' => $key, ':uid' => $userId, ':v' => (string) $value]);
}

/**
 * Load all data for the current user
 */
function gm_load_data(string $userId): array {
    gm_db_check();
    $pdo = db_get_pdo();
    gm_ensure_default_service_types($pdo, $userId);
    gm_ensure_templates_table($pdo);
    gm_ensure_vehicle_details_columns($pdo);

    // Vehicles - filter by user
    $stmt = $pdo->prepare("SELECT * FROM `vehicles` WHERE `user_id` = :uid ORDER BY `name` ASC");
    $stmt->execute([':uid' => $userId]);
    $vehicles = $stmt->fetchAll();
    
    $vehicles_out = [];
    $vehicleIds = [];
    foreach ($vehicles as $v) {
        $vehicleIds[] = $v['id'];
        $vehicles_out[] = [
            'id'         => $v['id'],
            'name'       => $v['name'],
            'currentOdo' => $v['current_odo'] !== null ? (int) $v['current_odo'] : null,
            'vin'        => $v['vin'],
            'plate'      => $v['plate'],
            // Vehicle detail fields
            'year'       => isset($v['year']) && $v['year'] !== null ? (int) $v['year'] : null,
            'make'       => $v['make'] ?? null,
            'model'      => $v['model'] ?? null,
            'engine'     => $v['engine'] ?? null,
            'bodyClass'  => $v['body_class'] ?? null,
            'photoPath'  => $v['photo_path'] ?? null,
        ];
    }

    // Service types - user's own
    $stmt = $pdo->prepare("SELECT * FROM `service_types` WHERE `user_id` = :uid ORDER BY `id` ASC");
    $stmt->execute([':uid' => $userId]);
    $service_types = $stmt->fetchAll();
    
    $service_types_out = [];
    foreach ($service_types as $st) {
        $service_types_out[] = [
            'name'          => $st['name'],
            'intervalMiles' => $st['interval_miles'] !== null ? (int) $st['interval_miles'] : null,
            'intervalMonths'=> $st['interval_months'] !== null ? (int) $st['interval_months'] : null,
        ];
    }

    // Entries + attachments - only for user's vehicles
    $entries_out = [];
    $attachments_by_entry = [];
    
    if (!empty($vehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        
        // Get entries for user's vehicles
        $stmt = $pdo->prepare("SELECT * FROM `entries` WHERE `vehicle_id` IN ($placeholders) ORDER BY `date` DESC, `created_at` DESC");
        $stmt->execute($vehicleIds);
        $entries = $stmt->fetchAll();

        // Get entry IDs for attachment query
        $entryIds = array_column($entries, 'id');
        
        if (!empty($entryIds)) {
            $entryPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
            $att_stmt = $pdo->prepare("SELECT * FROM `entry_attachments` WHERE `entry_id` IN ($entryPlaceholders)");
            $att_stmt->execute($entryIds);
            
            foreach ($att_stmt as $row) {
                $attachments_by_entry[$row['entry_id']][] = [
                    'id'       => $row['id'],
                    'name'     => $row['name'],
                    'size'     => $row['size'] !== null ? (int) $row['size'] : null,
                    'type'     => $row['mime_type'],
                    'filePath' => $row['file_path'],
                ];
            }
        }

        foreach ($entries as $e) {
            $services = [];
            if (!empty($e['services_json'])) {
                $decoded = json_decode($e['services_json'], true);
                if (is_array($decoded)) {
                    $services = $decoded;
                }
            }

            $entries_out[] = [
                'id'          => $e['id'],
                'vehicleId'   => $e['vehicle_id'],
                'date'        => $e['date'],
                'odo'         => $e['odo'] !== null ? (int) $e['odo'] : null,
                'notes'       => $e['notes'] ?? '',
                'cost'        => $e['cost'] !== null ? (float) $e['cost'] : null,
                'nextDate'    => $e['next_date'],
                'nextOdo'     => $e['next_odo'] !== null ? (int) $e['next_odo'] : null,
                'createdAt'   => $e['created_at'],
                'updatedAt'   => $e['updated_at'],
                'attachments' => $attachments_by_entry[$e['id']] ?? [],
                'services'    => $services,
            ];
        }
    }

    // Reminders - only for user's vehicles
    $reminders_out = [];
    if (!empty($vehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM `reminders` WHERE `vehicle_id` IN ($placeholders) ORDER BY `next_date` IS NULL, `next_date` ASC");
        $stmt->execute($vehicleIds);
        $reminders = $stmt->fetchAll();
        
        foreach ($reminders as $r) {
            $reminders_out[] = [
                'id'            => $r['id'],
                'vehicleId'     => $r['vehicle_id'],
                'serviceName'   => $r['service_name'],
                'title'         => $r['title'],
                'baseOdo'       => $r['base_odo'] !== null ? (int) $r['base_odo'] : null,
                'baseDate'      => $r['base_date'],
                'intervalMiles' => $r['interval_miles'] !== null ? (int) $r['interval_miles'] : null,
                'intervalMonths'=> $r['interval_months'] !== null ? (int) $r['interval_months'] : null,
                'nextOdo'       => $r['next_odo'] !== null ? (int) $r['next_odo'] : null,
                'nextDate'      => $r['next_date'],
                'notes'         => $r['notes'],
                'createdAt'     => $r['created_at'],
                'updatedAt'     => $r['updated_at'],
            ];
        }
    }

    // Vehicle-specific intervals - only for user's vehicles
    $vehicle_intervals = [];
    if (!empty($vehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM `vehicle_intervals` WHERE `vehicle_id` IN ($placeholders)");
        $stmt->execute($vehicleIds);
        $vi_rows = $stmt->fetchAll();
        
        foreach ($vi_rows as $vi) {
            $vid   = $vi['vehicle_id'];
            $sname = $vi['service_name'];
            if (!isset($vehicle_intervals[$vid])) {
                $vehicle_intervals[$vid] = [];
            }
            $vehicle_intervals[$vid][$sname] = [
                'intervalMiles'  => $vi['interval_miles'] !== null ? (int) $vi['interval_miles'] : null,
                'intervalMonths' => $vi['interval_months'] !== null ? (int) $vi['interval_months'] : null,
            ];
        }
    }

    // Entry Templates - user-specific
    $templates_out = [];
    $stmt = $pdo->prepare("SELECT * FROM `entry_templates` WHERE `user_id` = :uid ORDER BY `name` ASC");
    $stmt->execute([':uid' => $userId]);
    $templates = $stmt->fetchAll();
    
    foreach ($templates as $t) {
        $services = [];
        if (!empty($t['services_json'])) {
            $decoded = json_decode($t['services_json'], true);
            if (is_array($decoded)) {
                $services = $decoded;
            }
        }
        
        $templates_out[] = [
            'id'                 => $t['id'],
            'name'               => $t['name'],
            'services'           => $services,
            'miscCost'           => $t['misc_cost'] !== null ? (float) $t['misc_cost'] : null,
            'notes'              => $t['notes'] ?? '',
            'nextDateOffsetDays' => $t['next_date_offset_days'] !== null ? (int) $t['next_date_offset_days'] : null,
            'nextOdoOffset'      => $t['next_odo_offset'] !== null ? (int) $t['next_odo_offset'] : null,
            'createdAt'          => $t['created_at'],
            'updatedAt'          => $t['updated_at'],
        ];
    }

    // Settings - user-specific
    $defaultAppName = defined('APP_NAME') ? APP_NAME : 'Garage Maintenance';
    $siteTitle       = gm_get_setting($pdo, 'site_title', $userId, $defaultAppName);
    $unit            = gm_get_setting($pdo, 'unit', $userId, 'mi');
    $timezone        = gm_get_setting($pdo, 'timezone', $userId, '');
    $keepFormOpen    = gm_get_setting($pdo, 'keep_form_open', $userId, 'false') === 'true';
    $activeVehicleId = gm_get_setting($pdo, 'active_vehicle_id', $userId, null);
    
    $upcomingDays  = (int) gm_get_setting($pdo, 'upcoming_threshold_days', $userId, '14');
    $upcomingMiles = (int) gm_get_setting($pdo, 'upcoming_threshold_miles', $userId, '500');
    $overdueDays   = (int) gm_get_setting($pdo, 'overdue_threshold_days', $userId, '0');
    $overdueMiles  = (int) gm_get_setting($pdo, 'overdue_threshold_miles', $userId, '0');

    $settings = [
        'siteTitle'              => $siteTitle,
        'unit'                   => $unit,
        'timezone'               => $timezone !== '' ? $timezone : null,
        'keepFormOpen'           => $keepFormOpen,
        'upcomingThresholdDays'  => $upcomingDays,
        'upcomingThresholdMiles' => $upcomingMiles,
        'overdueThresholdDays'   => $overdueDays,
        'overdueThresholdMiles'  => $overdueMiles,
    ];

    $historyPerPage = defined('DASHBOARD_HISTORY_PER_PAGE') ? (int) DASHBOARD_HISTORY_PER_PAGE : 10;
    $entryMaxAttachments = defined('ENTRY_MAX_ATTACHMENTS') ? (int) ENTRY_MAX_ATTACHMENTS : 5;
    $entryMaxAttachmentSizeMB = defined('ENTRY_MAX_ATTACHMENT_SIZE_MB') ? (int) ENTRY_MAX_ATTACHMENT_SIZE_MB : 10;

    // Get user info for display (multi-user mode)
    $userInfo = null;
    $authUrls = null;
    if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
        $userInfo = gm_get_current_user_info();
        $authUrls = gm_get_auth_urls();
    }

    // ── Subscription / tier limits payload ──────────────────────────────────
    // Only meaningful in multi-user mode with a real user ID.
    // In single-user mode we set null so the frontend defaults to full access.
    $subscription = null;
    if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER && $userId !== 'default') {
        if (function_exists('gm_get_subscription_api_response')) {
            $subscription = gm_get_subscription_api_response($pdo, $userId);
        }
    }

    return [
        'vehicles'         => $vehicles_out,
        'serviceTypes'     => $service_types_out,
        'entries'          => $entries_out,
        'reminders'        => $reminders_out,
        'vehicleIntervals' => $vehicle_intervals,
        'entryTemplates'   => $templates_out,
        'settings'         => $settings,
        'activeVehicleId'  => $activeVehicleId ?: null,
        'dashboardHistoryPerPage'   => $historyPerPage,
        'entryMaxAttachments'       => $entryMaxAttachments,
        'entryMaxAttachmentSizeMB'  => $entryMaxAttachmentSizeMB,
        // Multi-user data
        'user'             => $userInfo,
        'authUrls'         => $authUrls,
        'multiUserEnabled' => defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER,
        // Subscription / tier limits (null in single-user mode → frontend grants full access)
        'subscription'     => $subscription,
    ];
}

/**
 * Save data for the current user
 */
function gm_save_data(array $payload, string $userId): void {
    gm_db_check();
    $pdo = db_get_pdo();
    gm_ensure_templates_table($pdo);
    gm_ensure_vehicle_details_columns($pdo);
    $pdo->beginTransaction();

    try {
        // Get existing vehicle IDs for this user
        $stmt = $pdo->prepare("SELECT `id` FROM `vehicles` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        $existingVehicleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // New vehicle IDs from payload
        $newVehicleIds = [];
        if (!empty($payload['vehicles'])) {
            $newVehicleIds = array_column($payload['vehicles'], 'id');
        }
        
        // Delete vehicles that are no longer in the list
        $toDelete = array_diff($existingVehicleIds, $newVehicleIds);
        if (!empty($toDelete)) {
            $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
            $stmt = $pdo->prepare("DELETE FROM `vehicles` WHERE `id` IN ($placeholders) AND `user_id` = ?");
            $stmt->execute(array_merge(array_values($toDelete), [$userId]));
        }
        
        // ── VEHICLE LIMIT CHECK (backend enforcement) ───────────────────────
        // Only blocks when the user is genuinely adding a NEW vehicle that would
        // push them past the limit. Existing vehicles being updated/saved never
        // trigger this — that would break normal saves like odometer updates.
        if (!empty($payload['vehicles']) && is_array($payload['vehicles']) &&
            $userId !== 'default' && function_exists('gm_get_user_limits')) {

            $payloadVehicleIds = array_column($payload['vehicles'], 'id');
            $newlyAdded        = array_diff($payloadVehicleIds, $existingVehicleIds);

            if (!empty($newlyAdded)) {
                $limits      = gm_get_user_limits($userId);
                $maxVehicles = (int) ($limits['max_vehicles'] ?? -1);

                if ($maxVehicles >= 0) {
                    // Use DB count (before this transaction) + count of new IDs being added
                    $countAfterSave = count($existingVehicleIds) + count($newlyAdded);
                    if ($countAfterSave > $maxVehicles) {
                        $pdo->rollBack();
                        http_response_code(403);
                        echo json_encode([
                            'success'     => false,
                            'error'       => 'vehicle_limit_reached',
                            'message'     => "Your plan allows a maximum of {$maxVehicles} vehicle(s). Please upgrade to add more.",
                            'upgrade_url' => function_exists('gm_get_upgrade_url') ? gm_get_upgrade_url('vehicles') : '',
                        ]);
                        exit;
                    }
                }
            }
        }

        // Upsert vehicles - with new detail fields
        if (!empty($payload['vehicles']) && is_array($payload['vehicles'])) {
            $stmt = $pdo->prepare("INSERT INTO `vehicles` 
                (`id`, `user_id`, `name`, `current_odo`, `vin`, `plate`, `year`, `make`, `model`, `engine`, `body_class`, `photo_path`) 
                VALUES (:id, :uid, :name, :odo, :vin, :plate, :year, :make, :model, :engine, :body_class, :photo_path)
                ON DUPLICATE KEY UPDATE 
                    `name` = VALUES(`name`), 
                    `current_odo` = VALUES(`current_odo`), 
                    `vin` = VALUES(`vin`), 
                    `plate` = VALUES(`plate`),
                    `year` = VALUES(`year`),
                    `make` = VALUES(`make`),
                    `model` = VALUES(`model`),
                    `engine` = VALUES(`engine`),
                    `body_class` = VALUES(`body_class`),
                    `photo_path` = VALUES(`photo_path`)");
            foreach ($payload['vehicles'] as $v) {
                $stmt->execute([
                    ':id'         => $v['id'],
                    ':uid'        => $userId,
                    ':name'       => $v['name'],
                    ':odo'        => $v['currentOdo'] ?? null,
                    ':vin'        => $v['vin'] ?? null,
                    ':plate'      => $v['plate'] ?? null,
                    ':year'       => $v['year'] ?? null,
                    ':make'       => $v['make'] ?? null,
                    ':model'      => $v['model'] ?? null,
                    ':engine'     => $v['engine'] ?? null,
                    ':body_class' => $v['bodyClass'] ?? null,
                    ':photo_path' => $v['photoPath'] ?? null,
                ]);
            }
        }

        // Service types - delete user's and re-insert
        $stmt = $pdo->prepare("DELETE FROM `service_types` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        if (!empty($payload['serviceTypes']) && is_array($payload['serviceTypes'])) {
            // Use INSERT IGNORE or ON DUPLICATE KEY UPDATE to handle duplicates
            $stmt = $pdo->prepare("INSERT INTO `service_types` (`user_id`, `name`, `interval_miles`, `interval_months`) 
                                   VALUES (:uid, :name, :miles, :months)
                                   ON DUPLICATE KEY UPDATE 
                                   `interval_miles` = VALUES(`interval_miles`), 
                                   `interval_months` = VALUES(`interval_months`)");
            
            $insertedNames = []; // Track inserted names to prevent duplicates in same payload
            
            foreach ($payload['serviceTypes'] as $st) {
                if (is_string($st)) {
                    $name = $st;
                    $im   = null;
                    $imo  = null;
                } else {
                    $name = $st['name'] ?? '';
                    $im   = $st['intervalMiles'] ?? null;
                    $imo  = $st['intervalMonths'] ?? null;
                }
                if ($name === '') continue;
                
                // Skip if we already inserted this name in this batch
                $nameKey = strtolower(trim($name));
                if (isset($insertedNames[$nameKey])) {
                    continue;
                }
                $insertedNames[$nameKey] = true;
                
                $stmt->execute([
                    ':uid'   => $userId,
                    ':name'  => $name,
                    ':miles' => $im,
                    ':months'=> $imo,
                ]);
            }
        }

        // Entries - use UPSERT to preserve attachment foreign keys
        // First, get the list of entry IDs being saved
        $incomingEntryIds = [];
        if (!empty($payload['entries']) && is_array($payload['entries'])) {
            foreach ($payload['entries'] as $e) {
                if (in_array($e['vehicleId'], $newVehicleIds)) {
                    $incomingEntryIds[] = $e['id'];
                }
            }
        }
        
        // Delete entries that are no longer in the payload AND their attachment files
        if (!empty($newVehicleIds)) {
            $placeholders = implode(',', array_fill(0, count($newVehicleIds), '?'));
            
            // Get entries to be deleted (so we can clean up their attachments)
            if (!empty($incomingEntryIds)) {
                $entryPlaceholders = implode(',', array_fill(0, count($incomingEntryIds), '?'));
                $stmt = $pdo->prepare("SELECT `id` FROM `entries` WHERE `vehicle_id` IN ($placeholders) AND `id` NOT IN ($entryPlaceholders)");
                $stmt->execute(array_merge($newVehicleIds, $incomingEntryIds));
            } else {
                $stmt = $pdo->prepare("SELECT `id` FROM `entries` WHERE `vehicle_id` IN ($placeholders)");
                $stmt->execute($newVehicleIds);
            }
            $entriesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete attachment files for entries being deleted
            if (!empty($entriesToDelete)) {
                $delPlaceholders = implode(',', array_fill(0, count($entriesToDelete), '?'));
                $stmt = $pdo->prepare("SELECT `file_path` FROM `entry_attachments` WHERE `entry_id` IN ($delPlaceholders) AND `file_path` IS NOT NULL");
                $stmt->execute($entriesToDelete);
                $attachmentPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($attachmentPaths as $filePath) {
                    if ($filePath) {
                        $fullPath = ATTACHMENTS_PATH . '/' . $filePath;
                        if (file_exists($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                }
                
                // Now delete the entry records (cascades to attachments)
                $stmt = $pdo->prepare("DELETE FROM `entries` WHERE `id` IN ($delPlaceholders)");
                $stmt->execute($entriesToDelete);
            }
        }

        // ── ENTRY LIMIT CHECK (backend enforcement) ─────────────────────────
        // Only blocks genuinely NEW entries that push the user past their limit.
        // The payload always contains all existing entries (full sync model), so
        // comparing payload count directly would 403 every save when at the limit.
        if (!empty($payload['entries']) && is_array($payload['entries']) &&
            $userId !== 'default' && function_exists('gm_get_user_limits')) {

            $limits     = gm_get_user_limits($userId);
            $maxEntries = (int) ($limits['max_entries'] ?? -1);

            if ($maxEntries >= 0) {
                // Get current entry IDs from DB for this user
                if (!empty($newVehicleIds)) {
                    $epPlaceholders = implode(',', array_fill(0, count($newVehicleIds), '?'));
                    $estmt = $pdo->prepare(
                        "SELECT `id` FROM `entries` WHERE `vehicle_id` IN ($epPlaceholders)"
                    );
                    $estmt->execute($newVehicleIds);
                    $existingEntryIds = $estmt->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $existingEntryIds = [];
                }

                // Count only genuinely NEW entry IDs in the payload
                $payloadEntryIds = array_column($payload['entries'], 'id');
                $newEntryCount   = count(array_diff($payloadEntryIds, $existingEntryIds));

                if ($newEntryCount > 0) {
                    $totalAfterSave = count($existingEntryIds) + $newEntryCount;
                    if ($totalAfterSave > $maxEntries) {
                        $pdo->rollBack();
                        http_response_code(403);
                        echo json_encode([
                            'success'     => false,
                            'error'       => 'entry_limit_reached',
                            'message'     => "Your plan allows a maximum of {$maxEntries} service entr(ies). Please upgrade for unlimited entries.",
                            'upgrade_url' => function_exists('gm_get_upgrade_url') ? gm_get_upgrade_url('entries') : '',
                        ]);
                        exit;
                    }
                }
            }
        }

        // Upsert entries
        if (!empty($payload['entries']) && is_array($payload['entries'])) {
            $stmt = $pdo->prepare("INSERT INTO `entries` (`id`, `vehicle_id`, `date`, `odo`, `notes`, `cost`, `next_date`, `next_odo`, `services_json`, `created_at`, `updated_at`)
                                   VALUES (:id, :vid, :dt, :odo, :notes, :cost, :nd, :no, :svc, :created, :updated)
                                   ON DUPLICATE KEY UPDATE 
                                   `vehicle_id` = VALUES(`vehicle_id`),
                                   `date` = VALUES(`date`),
                                   `odo` = VALUES(`odo`),
                                   `notes` = VALUES(`notes`),
                                   `cost` = VALUES(`cost`),
                                   `next_date` = VALUES(`next_date`),
                                   `next_odo` = VALUES(`next_odo`),
                                   `services_json` = VALUES(`services_json`),
                                   `updated_at` = VALUES(`updated_at`)");
            foreach ($payload['entries'] as $e) {
                // Skip entries for vehicles not in the current user's list
                if (!in_array($e['vehicleId'], $newVehicleIds)) {
                    continue;
                }
                
                $services_json = null;
                if (!empty($e['services']) && is_array($e['services'])) {
                    $services_json = json_encode($e['services'], JSON_UNESCAPED_UNICODE);
                }
                
                $stmt->execute([
                    ':id'       => $e['id'],
                    ':vid'      => $e['vehicleId'],
                    ':dt'       => $e['date'] ?? null,
                    ':odo'      => $e['odo'] ?? null,
                    ':notes'    => $e['notes'] ?? null,
                    ':cost'     => $e['cost'] ?? null,
                    ':nd'       => $e['nextDate'] ?? null,
                    ':no'       => $e['nextOdo'] ?? null,
                    ':svc'      => $services_json,
                    ':created'  => $e['createdAt'] ?? date('c'),
                    ':updated'  => $e['updatedAt'] ?? date('c'),
                ]);
            }
        }

        // Reminders
        if (!empty($newVehicleIds)) {
            $placeholders = implode(',', array_fill(0, count($newVehicleIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM `reminders` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($newVehicleIds);
        }

        if (!empty($payload['reminders']) && is_array($payload['reminders'])) {
            $stmt = $pdo->prepare("INSERT INTO `reminders` 
                (`id`, `vehicle_id`, `service_name`, `title`, `base_odo`, `base_date`, `interval_miles`, `interval_months`, `next_odo`, `next_date`, `notes`, `created_at`, `updated_at`)
                VALUES (:id, :vid, :svc, :title, :bodo, :bdate, :imiles, :imonths, :nodo, :ndate, :notes, :created, :updated)");
            foreach ($payload['reminders'] as $r) {
                // Skip reminders for vehicles not in user's list
                if (!in_array($r['vehicleId'], $newVehicleIds)) {
                    continue;
                }
                $stmt->execute([
                    ':id'       => $r['id'],
                    ':vid'      => $r['vehicleId'],
                    ':svc'      => $r['serviceName'],
                    ':title'    => $r['title'] ?? null,
                    ':bodo'     => $r['baseOdo'] ?? null,
                    ':bdate'    => $r['baseDate'] ?? null,
                    ':imiles'   => $r['intervalMiles'] ?? null,
                    ':imonths'  => $r['intervalMonths'] ?? null,
                    ':nodo'     => $r['nextOdo'] ?? null,
                    ':ndate'    => $r['nextDate'] ?? null,
                    ':notes'    => $r['notes'] ?? null,
                    ':created'  => $r['createdAt'] ?? date('c'),
                    ':updated'  => $r['updatedAt'] ?? date('c'),
                ]);
            }
        }

        // Vehicle-specific intervals
        if (!empty($newVehicleIds)) {
            $placeholders = implode(',', array_fill(0, count($newVehicleIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM `vehicle_intervals` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($newVehicleIds);
        }

        if (!empty($payload['vehicleIntervals']) && is_array($payload['vehicleIntervals'])) {
            $stmt = $pdo->prepare("INSERT INTO `vehicle_intervals` (`vehicle_id`, `service_name`, `interval_miles`, `interval_months`) VALUES (:vid, :svc, :miles, :months)");
            foreach ($payload['vehicleIntervals'] as $vid => $services) {
                // Skip intervals for vehicles not in user's list
                if (!in_array($vid, $newVehicleIds)) {
                    continue;
                }
                if (!is_array($services)) continue;
                foreach ($services as $sname => $intervals) {
                    $stmt->execute([
                        ':vid'    => $vid,
                        ':svc'    => $sname,
                        ':miles'  => $intervals['intervalMiles'] ?? null,
                        ':months' => $intervals['intervalMonths'] ?? null,
                    ]);
                }
            }
        }

        // ── TEMPLATE LIMIT CHECK (backend enforcement) ──────────────────────
        // Only blocks when user is adding NEW templates beyond their plan limit.
        // Full-sync payload always sends all existing templates, so comparing the
        // total payload count would 403 every save once the user is at the limit.
        if (!empty($payload['entryTemplates']) && is_array($payload['entryTemplates']) &&
            $userId !== 'default' && function_exists('gm_get_user_limits')) {

            $limits       = gm_get_user_limits($userId);
            $maxTemplates = (int) ($limits['max_templates'] ?? -1);

            if ($maxTemplates >= 0) {
                // Get current template IDs from DB
                $tstmt = $pdo->prepare(
                    "SELECT `id` FROM `entry_templates` WHERE `user_id` = :uid"
                );
                $tstmt->execute([':uid' => $userId]);
                $existingTemplateIds = $tstmt->fetchAll(PDO::FETCH_COLUMN);

                $payloadTemplateIds  = array_column($payload['entryTemplates'], 'id');
                $newTemplateCount    = count(array_diff($payloadTemplateIds, $existingTemplateIds));

                if ($newTemplateCount > 0) {
                    $totalAfterSave = count($existingTemplateIds) + $newTemplateCount;
                    if ($totalAfterSave > $maxTemplates) {
                        $pdo->rollBack();
                        http_response_code(403);
                        echo json_encode([
                            'success'     => false,
                            'error'       => 'template_limit_reached',
                            'message'     => "Your plan allows a maximum of {$maxTemplates} template(s). Please upgrade for more.",
                            'upgrade_url' => function_exists('gm_get_upgrade_url') ? gm_get_upgrade_url('templates') : '',
                        ]);
                        exit;
                    }
                }
            }
        }

        // Entry Templates - user-specific
        $stmt = $pdo->prepare("DELETE FROM `entry_templates` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        if (!empty($payload['entryTemplates']) && is_array($payload['entryTemplates'])) {
            $stmt = $pdo->prepare("INSERT INTO `entry_templates` 
                (`id`, `user_id`, `name`, `services_json`, `misc_cost`, `notes`, `next_date_offset_days`, `next_odo_offset`, `created_at`, `updated_at`)
                VALUES (:id, :uid, :name, :services_json, :misc_cost, :notes, :next_date_offset_days, :next_odo_offset, :created_at, :updated_at)");
            foreach ($payload['entryTemplates'] as $t) {
                $services_json = null;
                if (!empty($t['services']) && is_array($t['services'])) {
                    $services_json = json_encode($t['services'], JSON_UNESCAPED_UNICODE);
                }
                
                $stmt->execute([
                    ':id'                    => $t['id'],
                    ':uid'                   => $userId,
                    ':name'                  => $t['name'] ?? 'Untitled',
                    ':services_json'         => $services_json,
                    ':misc_cost'             => $t['miscCost'] ?? null,
                    ':notes'                 => $t['notes'] ?? null,
                    ':next_date_offset_days' => $t['nextDateOffsetDays'] ?? null,
                    ':next_odo_offset'       => $t['nextOdoOffset'] ?? null,
                    ':created_at'            => $t['createdAt'] ?? date('c'),
                    ':updated_at'            => $t['updatedAt'] ?? date('c'),
                ]);
            }
        }

        // Settings - user-specific
        if (!empty($payload['settings']) && is_array($payload['settings'])) {
            $defaultAppName = defined('APP_NAME') ? APP_NAME : 'Garage Maintenance';
            $siteTitle = $payload['settings']['siteTitle'] ?? $defaultAppName;
            $unit      = $payload['settings']['unit'] ?? 'mi';
            $timezone  = $payload['settings']['timezone'] ?? '';
            $keepFormOpen = isset($payload['settings']['keepFormOpen']) && $payload['settings']['keepFormOpen'] ? 'true' : 'false';
            
            $upcomingDays  = $payload['settings']['upcomingThresholdDays'] ?? 14;
            $upcomingMiles = $payload['settings']['upcomingThresholdMiles'] ?? 500;
            $overdueDays   = $payload['settings']['overdueThresholdDays'] ?? 0;
            $overdueMiles  = $payload['settings']['overdueThresholdMiles'] ?? 0;

            gm_set_setting($pdo, 'upcoming_threshold_days', $userId, $upcomingDays);
            gm_set_setting($pdo, 'upcoming_threshold_miles', $userId, $upcomingMiles);
            gm_set_setting($pdo, 'overdue_threshold_days', $userId, $overdueDays);
            gm_set_setting($pdo, 'overdue_threshold_miles', $userId, $overdueMiles);
            gm_set_setting($pdo, 'site_title', $userId, $siteTitle);
            gm_set_setting($pdo, 'unit', $userId, $unit);
            gm_set_setting($pdo, 'timezone', $userId, $timezone);
            gm_set_setting($pdo, 'keep_form_open', $userId, $keepFormOpen);
        }

        if (isset($payload['activeVehicleId'])) {
            gm_set_setting($pdo, 'active_vehicle_id', $userId, $payload['activeVehicleId'] ?: '');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ----- Routing -----
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'load') {
    try {
        $data = gm_load_data($currentUserId);
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST' && $action === 'save') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
        exit;
    }

    try {
        gm_save_data($decoded['data'], $currentUserId);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// User info endpoint
if ($method === 'GET' && $action === 'user') {
    $userInfo = gm_get_current_user_info();
    $authUrls = gm_get_auth_urls();
    
    echo json_encode([
        'success' => true,
        'user' => $userInfo,
        'authUrls' => $authUrls,
        'multiUserEnabled' => defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER,
    ]);
    exit;
}

// Clear all user data (including attachment files)
if ($method === 'POST' && $action === 'clearUserData') {
    try {
        $userId = gm_require_auth_api();
        $pdo = db_get_pdo();
        
        // Get user's vehicle IDs
        $stmt = $pdo->prepare("SELECT `id` FROM `vehicles` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        $vehicleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($vehicleIds)) {
            $placeholders = implode(',', array_fill(0, count($vehicleIds), '?'));
            
            // Get all entry IDs for user's vehicles
            $stmt = $pdo->prepare("SELECT `id` FROM `entries` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($vehicleIds);
            $entryIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete all attachment files
            if (!empty($entryIds)) {
                $entryPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
                $stmt = $pdo->prepare("SELECT `file_path` FROM `entry_attachments` WHERE `entry_id` IN ($entryPlaceholders) AND `file_path` IS NOT NULL");
                $stmt->execute($entryIds);
                $attachmentPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($attachmentPaths as $filePath) {
                    if ($filePath) {
                        $fullPath = ATTACHMENTS_PATH . '/' . $filePath;
                        if (file_exists($fullPath)) {
                            @unlink($fullPath);
                        }
                    }
                }
                
                // Delete attachment records
                $stmt = $pdo->prepare("DELETE FROM `entry_attachments` WHERE `entry_id` IN ($entryPlaceholders)");
                $stmt->execute($entryIds);
            }
            
            // Delete vehicle photos
            $stmt = $pdo->prepare("SELECT `photo_path` FROM `vehicles` WHERE `user_id` = :uid AND `photo_path` IS NOT NULL");
            $stmt->execute([':uid' => $userId]);
            $photoPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($photoPaths as $photoPath) {
                if ($photoPath) {
                    $fullPath = ATTACHMENTS_PATH . '/' . $photoPath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            
            // Delete entries, reminders, vehicle_intervals
            $stmt = $pdo->prepare("DELETE FROM `entries` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($vehicleIds);
            
            $stmt = $pdo->prepare("DELETE FROM `reminders` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($vehicleIds);
            
            $stmt = $pdo->prepare("DELETE FROM `vehicle_intervals` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($vehicleIds);
        }
        
        // Delete vehicles
        $stmt = $pdo->prepare("DELETE FROM `vehicles` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        // Delete service types
        $stmt = $pdo->prepare("DELETE FROM `service_types` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        // Delete settings
        $stmt = $pdo->prepare("DELETE FROM `settings` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        // Try to remove user's attachment directory
        $userDir = ATTACHMENTS_PATH . '/' . $userId;
        if (is_dir($userDir)) {
            // Recursively delete any remaining files/folders
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($userDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($userDir);
        }
        
        echo json_encode(['success' => true, 'message' => 'All data cleared successfully']);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);