<?php
/**
 * Server-Side Backup Creation - Direct Download
 * Creates backup and streams it directly to browser (no intermediate file)
 * Multi-user Ready - Only backs up current user's data
 * Updated: Includes vehicle details (year, make, model, engine, bodyClass) and photos
 */

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

// Check if this is a download request or info request
$downloadMode = isset($_GET['download']) && $_GET['download'] === '1';

if (!$downloadMode) {
    // Info mode - return JSON with backup details
    header('Content-Type: application/json; charset=utf-8');
}

try {
    ob_clean();
    
    require __DIR__ . '/config.php';
    
    // ========================================
    // MULTI-USER: Get current user ID
    // ========================================
    $userId = 'default';
    if (defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER) {
        $userId = gm_get_current_user_id();
        
        if (!$userId) {
            if ($downloadMode) {
                die('Authentication required. Please log in.');
            }
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'authentication_required',
                'message' => 'Please log in to continue'
            ]);
            exit;
        }
        
        // Check subscription if required
        if (defined('REQUIRE_SUBSCRIPTION') && REQUIRE_SUBSCRIPTION) {
            if (!gm_user_has_subscription($userId)) {
                if ($downloadMode) {
                    die('Active subscription required.');
                }
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'subscription_required',
                    'message' => 'Active subscription required'
                ]);
                exit;
            }
        }
    }
    
    set_time_limit(300);
    ini_set('memory_limit', '256M');
    
    // Get PDO connection
    $pdo = db_get_pdo();
    
    // ============================================
    // LOAD USER'S DATA ONLY
    // ============================================
    
    // Vehicles - filter by user_id - WITH NEW DETAIL FIELDS
    $stmt = $pdo->prepare("SELECT * FROM `vehicles` WHERE `user_id` = :uid ORDER BY `name` ASC");
    $stmt->execute([':uid' => $userId]);
    $vehicles = $stmt->fetchAll();
    
    $vehicles_out = [];
    $userVehicleIds = [];
    $vehiclePhotoPaths = []; // Track vehicle photos for embedding
    
    foreach ($vehicles as $v) {
        $userVehicleIds[] = $v['id'];
        
        // Track photo path for later embedding
        if (!empty($v['photo_path'])) {
            $vehiclePhotoPaths[$v['id']] = $v['photo_path'];
        }
        
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

    // Service types - filter by user_id
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

    // Entries - filter by user's vehicles
    $entries_out = [];
    $attachments_by_entry = [];
    
    if (!empty($userVehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($userVehicleIds), '?'));
        
        // Get entries for user's vehicles
        $stmt = $pdo->prepare("SELECT * FROM `entries` WHERE `vehicle_id` IN ($placeholders) ORDER BY `date` DESC, `created_at` DESC");
        $stmt->execute($userVehicleIds);
        $entries = $stmt->fetchAll();
        
        // Get entry IDs for attachment lookup
        $entryIds = array_column($entries, 'id');
        
        // Get attachments for user's entries
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

    // Reminders - filter by user's vehicles
    $reminders_out = [];
    if (!empty($userVehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($userVehicleIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM `reminders` WHERE `vehicle_id` IN ($placeholders) ORDER BY `next_date` IS NULL, `next_date` ASC");
        $stmt->execute($userVehicleIds);
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

    // Vehicle intervals - filter by user's vehicles
    $vehicle_intervals = [];
    if (!empty($userVehicleIds)) {
        $placeholders = implode(',', array_fill(0, count($userVehicleIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM `vehicle_intervals` WHERE `vehicle_id` IN ($placeholders)");
        $stmt->execute($userVehicleIds);
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

    // Entry templates - filter by user_id
    $templates_out = [];
    try {
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
    } catch (Throwable $e) {
        // Table might not exist yet - ignore
    }

    // Settings - filter by user_id
    $stmt = $pdo->prepare("SELECT `key`, `value` FROM `settings` WHERE `user_id` = :uid");
    $stmt->execute([':uid' => $userId]);
    $settingsRows = $stmt->fetchAll();
    $settingsMap = [];
    foreach ($settingsRows as $row) {
        $settingsMap[$row['key']] = $row['value'];
    }
    
    $defaultAppName = defined('APP_NAME') ? APP_NAME : 'Garage Maintenance';
    
    $settings = [
        'siteTitle'              => $settingsMap['site_title'] ?? $defaultAppName,
        'unit'                   => $settingsMap['unit'] ?? 'mi',
        'timezone'               => !empty($settingsMap['timezone']) ? $settingsMap['timezone'] : null,
        'keepFormOpen'           => ($settingsMap['keep_form_open'] ?? 'false') === 'true',
        'upcomingThresholdDays'  => isset($settingsMap['upcoming_threshold_days']) ? (int)$settingsMap['upcoming_threshold_days'] : 14,
        'upcomingThresholdMiles' => isset($settingsMap['upcoming_threshold_miles']) ? (int)$settingsMap['upcoming_threshold_miles'] : 500,
        'overdueThresholdDays'   => isset($settingsMap['overdue_threshold_days']) ? (int)$settingsMap['overdue_threshold_days'] : 0,
        'overdueThresholdMiles'  => isset($settingsMap['overdue_threshold_miles']) ? (int)$settingsMap['overdue_threshold_miles'] : 0,
    ];
    
    $activeVehicleId = $settingsMap['active_vehicle_id'] ?? null;

    $data = [
        'vehicles'         => $vehicles_out,
        'serviceTypes'     => $service_types_out,
        'entries'          => $entries_out,
        'reminders'        => $reminders_out,
        'vehicleIntervals' => $vehicle_intervals,
        'entryTemplates'   => $templates_out,
        'settings'         => $settings,
        'activeVehicleId'  => $activeVehicleId ?: null,
    ];
    
    // ============================================
    // GET ENTRY ATTACHMENTS AS BASE64
    // ============================================
    $attachmentsData = [];
    $attachmentErrors = [];
    $totalAttachmentSize = 0;
    
    if (defined('ATTACHMENTS_PATH') && is_dir(ATTACHMENTS_PATH) && !empty($userVehicleIds)) {
        // Get entry IDs for user's vehicles
        $placeholders = implode(',', array_fill(0, count($userVehicleIds), '?'));
        $stmt = $pdo->prepare("SELECT `id` FROM `entries` WHERE `vehicle_id` IN ($placeholders)");
        $stmt->execute($userVehicleIds);
        $userEntryIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userEntryIds)) {
            $entryPlaceholders = implode(',', array_fill(0, count($userEntryIds), '?'));
            $stmt = $pdo->prepare("SELECT `id`, `entry_id`, `name`, `mime_type`, `size`, `file_path` 
                                   FROM `entry_attachments` 
                                   WHERE `entry_id` IN ($entryPlaceholders) AND `file_path` IS NOT NULL AND `file_path` != ''");
            $stmt->execute($userEntryIds);
            $attachments = $stmt->fetchAll();
            
            foreach ($attachments as $att) {
                $filePath = ATTACHMENTS_PATH . '/' . $att['file_path'];
                
                if (file_exists($filePath) && is_readable($filePath)) {
                    $fileContent = @file_get_contents($filePath);
                    
                    if ($fileContent !== false) {
                        $attachmentsData[] = [
                            'id' => $att['id'],
                            'entry_id' => $att['entry_id'],
                            'name' => $att['name'],
                            'mime_type' => $att['mime_type'],
                            'size' => $att['size'],
                            'file_path' => $att['file_path'],
                            'data_base64' => base64_encode($fileContent)
                        ];
                        $totalAttachmentSize += strlen($fileContent);
                    } else {
                        $attachmentErrors[] = "Could not read: " . $att['name'];
                    }
                } else {
                    $attachmentErrors[] = "File not found: " . $att['file_path'];
                }
            }
        }
    }
    
    // ============================================
    // GET VEHICLE PHOTOS AS BASE64
    // ============================================
    $vehiclePhotosData = [];
    $totalPhotoSize = 0;
    
    if (defined('ATTACHMENTS_PATH') && is_dir(ATTACHMENTS_PATH) && !empty($vehiclePhotoPaths)) {
        foreach ($vehiclePhotoPaths as $vehicleId => $photoPath) {
            $filePath = ATTACHMENTS_PATH . '/' . $photoPath;
            
            if (file_exists($filePath) && is_readable($filePath)) {
                $fileContent = @file_get_contents($filePath);
                
                if ($fileContent !== false) {
                    // Detect MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $filePath);
                    finfo_close($finfo);
                    
                    $vehiclePhotosData[] = [
                        'vehicle_id' => $vehicleId,
                        'file_path' => $photoPath,
                        'mime_type' => $mimeType,
                        'size' => strlen($fileContent),
                        'data_base64' => base64_encode($fileContent)
                    ];
                    $totalPhotoSize += strlen($fileContent);
                } else {
                    $attachmentErrors[] = "Could not read vehicle photo: " . $photoPath;
                }
            } else {
                $attachmentErrors[] = "Vehicle photo not found: " . $photoPath;
            }
        }
    }
    
    // ============================================
    // CREATE BACKUP OBJECT
    // ============================================
    $backup = [
        'version' => '2.2', // Updated version for vehicle details + photos
        'created_at' => date('Y-m-d H:i:s'),
        'created_timezone' => date_default_timezone_get(),
        'user_id' => $userId,
        'multi_user_backup' => defined('ENABLE_MULTI_USER') && ENABLE_MULTI_USER,
        'data' => $data,
        'attachments_embedded' => $attachmentsData,
        'vehicle_photos_embedded' => $vehiclePhotosData, // NEW: Vehicle photos
        'attachment_count' => count($attachmentsData),
        'vehicle_photo_count' => count($vehiclePhotosData), // NEW
        'backup_type' => 'full_json'
    ];
    
    $jsonBackup = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($jsonBackup === false) {
        throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
    }
    
    ob_end_clean();
    
    // ============================================
    // OUTPUT
    // ============================================
    if ($downloadMode) {
        // Direct download - stream the file
        $filename = 'garage_maintenance_backup_' . date('Y-m-d_H-i-s') . '.json';
        
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($jsonBackup));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo $jsonBackup;
        exit;
    } else {
        // Info mode - return success JSON
        echo json_encode([
            'success' => true,
            'message' => 'Backup ready',
            'size' => strlen($jsonBackup),
            'size_formatted' => formatBytes(strlen($jsonBackup)),
            'attachment_count' => count($attachmentsData),
            'vehicle_photo_count' => count($vehiclePhotosData),
            'attachment_size_formatted' => formatBytes($totalAttachmentSize + $totalPhotoSize),
            'download_url' => 'backup-create.php?download=1&t=' . time(),
            'created' => date('Y-m-d H:i:s'),
            'user_id' => $userId,
            'vehicle_count' => count($vehicles_out),
            'entry_count' => count($entries_out),
            'reminder_count' => count($reminders_out),
            'warnings' => $attachmentErrors
        ]);
    }
    
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Backup error: ' . $e->getMessage());
    
    http_response_code(500);
    
    if ($downloadMode) {
        die('Backup failed: ' . $e->getMessage());
    } else {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]);
    }
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}