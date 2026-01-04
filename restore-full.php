<?php
/**
 * Full Restore Handler - Multi-user Ready
 * Doesn't use api.php to avoid header conflicts
 * Updated: Includes vehicle details (year, make, model, engine, bodyClass) and photos
 */

ob_start();
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    ob_clean();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Method not allowed');
    }
    
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
        
        // Check subscription if required
        if (defined('REQUIRE_SUBSCRIPTION') && REQUIRE_SUBSCRIPTION) {
            if (!gm_user_has_subscription($userId)) {
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
    
    // Check file upload
    if (empty($_FILES['backup_file'])) {
        throw new RuntimeException('No file uploaded');
    }
    
    $uploadedFile = $_FILES['backup_file'];
    
    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL => 'Partial upload',
            UPLOAD_ERR_NO_FILE => 'No file',
            UPLOAD_ERR_NO_TMP_DIR => 'No temp directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write',
        ];
        throw new RuntimeException('Upload error: ' . ($errors[$uploadedFile['error']] ?? 'Unknown'));
    }
    
    // Read and parse JSON
    $jsonContent = file_get_contents($uploadedFile['tmp_name']);
    if ($jsonContent === false) {
        throw new RuntimeException('Cannot read uploaded file');
    }
    
    $backup = json_decode($jsonContent, true);
    if ($backup === null) {
        throw new RuntimeException('Invalid JSON: ' . json_last_error_msg());
    }
    
    // Handle different backup formats
    if (!isset($backup['data'])) {
        // Legacy format - data is at root level
        if (isset($backup['vehicles'])) {
            $backup = [
                'data' => $backup,
                'attachments_embedded' => [],
                'vehicle_photos_embedded' => [],
                'version' => '1.0'
            ];
        } else {
            throw new RuntimeException('Invalid backup format');
        }
    }
    
    $data = $backup['data'];
    $attachmentsEmbedded = $backup['attachments_embedded'] ?? [];
    $vehiclePhotosEmbedded = $backup['vehicle_photos_embedded'] ?? []; // NEW
    
    if (!isset($data['vehicles']) || !is_array($data['vehicles'])) {
        throw new RuntimeException('Invalid backup: missing vehicles');
    }
    
    // Get database connection
    $pdo = db_get_pdo();
    $pdo->beginTransaction();
    
    try {
        // ============================================
        // ENSURE VEHICLE DETAILS COLUMNS EXIST
        // ============================================
        try {
            $pdo->query("SELECT `year`, `make`, `model`, `engine`, `body_class`, `photo_path` FROM `vehicles` LIMIT 1");
        } catch (Throwable $e) {
            // Add missing columns
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
                } catch (Throwable $e2) {
                    // Column likely already exists
                }
            }
        }
        
        // ============================================
        // CLEAR EXISTING DATA FOR THIS USER
        // ============================================
        
        // Get user's vehicle IDs first
        $stmt = $pdo->prepare("SELECT `id` FROM `vehicles` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        $existingVehicleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Also check if any vehicles from the backup already exist (with any user_id)
        $backupVehicleIds = array_column($data['vehicles'] ?? [], 'id');
        if (!empty($backupVehicleIds)) {
            $placeholders = implode(',', array_fill(0, count($backupVehicleIds), '?'));
            $stmt = $pdo->prepare("SELECT `id` FROM `vehicles` WHERE `id` IN ($placeholders)");
            $stmt->execute($backupVehicleIds);
            $conflictingVehicleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Merge with existing vehicle IDs for cleanup
            $existingVehicleIds = array_unique(array_merge($existingVehicleIds, $conflictingVehicleIds));
        }
        
        // Delete attachment files for user's entries
        if (!empty($existingVehicleIds)) {
            $placeholders = implode(',', array_fill(0, count($existingVehicleIds), '?'));
            $stmt = $pdo->prepare("
                SELECT a.`file_path` FROM `entry_attachments` a
                JOIN `entries` e ON a.`entry_id` = e.`id`
                WHERE e.`vehicle_id` IN ($placeholders) AND a.`file_path` IS NOT NULL
            ");
            $stmt->execute($existingVehicleIds);
            $existingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($existingFiles as $filePath) {
                if ($filePath) {
                    $fullPath = ATTACHMENTS_PATH . '/' . $filePath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                    // Try to remove empty directory
                    $dir = dirname($fullPath);
                    if (is_dir($dir) && count(scandir($dir)) === 2) {
                        @rmdir($dir);
                    }
                }
            }
            
            // Delete vehicle photos
            $stmt = $pdo->prepare("SELECT `photo_path` FROM `vehicles` WHERE `id` IN ($placeholders) AND `photo_path` IS NOT NULL");
            $stmt->execute($existingVehicleIds);
            $existingPhotos = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($existingPhotos as $photoPath) {
                if ($photoPath) {
                    $fullPath = ATTACHMENTS_PATH . '/' . $photoPath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            
            // Delete entries (cascades to attachments)
            $stmt = $pdo->prepare("DELETE FROM `entries` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($existingVehicleIds);
            
            // Delete reminders
            $stmt = $pdo->prepare("DELETE FROM `reminders` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($existingVehicleIds);
            
            // Delete vehicle intervals
            $stmt = $pdo->prepare("DELETE FROM `vehicle_intervals` WHERE `vehicle_id` IN ($placeholders)");
            $stmt->execute($existingVehicleIds);
            
            // Delete the vehicles themselves
            $stmt = $pdo->prepare("DELETE FROM `vehicles` WHERE `id` IN ($placeholders)");
            $stmt->execute($existingVehicleIds);
        }
        
        // Also delete any vehicles owned by this user that weren't in the list above
        $stmt = $pdo->prepare("DELETE FROM `vehicles` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        // Delete user's service types
        $stmt = $pdo->prepare("DELETE FROM `service_types` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        // Delete user's settings
        $stmt = $pdo->prepare("DELETE FROM `settings` WHERE `user_id` = :uid");
        $stmt->execute([':uid' => $userId]);
        
        // Delete user's entry templates (if table exists)
        try {
            $stmt = $pdo->prepare("DELETE FROM `entry_templates` WHERE `user_id` = :uid");
            $stmt->execute([':uid' => $userId]);
        } catch (Throwable $e) {
            // Table might not exist - ignore
        }
        
        // ============================================
        // RESTORE VEHICLES (WITH NEW DETAIL FIELDS)
        // ============================================
        if (!empty($data['vehicles'])) {
            $stmt = $pdo->prepare("INSERT INTO `vehicles` 
                (`id`, `user_id`, `name`, `current_odo`, `vin`, `plate`, `year`, `make`, `model`, `engine`, `body_class`, `photo_path`) 
                VALUES (:id, :uid, :name, :odo, :vin, :plate, :year, :make, :model, :engine, :body_class, :photo_path)");
            foreach ($data['vehicles'] as $v) {
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
        
        // ============================================
        // RESTORE SERVICE TYPES
        // ============================================
        if (!empty($data['serviceTypes'])) {
            $stmt = $pdo->prepare("INSERT INTO `service_types` (`user_id`, `name`, `interval_miles`, `interval_months`) 
                                   VALUES (:uid, :name, :miles, :months)
                                   ON DUPLICATE KEY UPDATE 
                                   `interval_miles` = VALUES(`interval_miles`), 
                                   `interval_months` = VALUES(`interval_months`)");
            
            $insertedNames = [];
            
            foreach ($data['serviceTypes'] as $st) {
                if (is_string($st)) {
                    $name = $st;
                    $miles = null;
                    $months = null;
                } else {
                    $name = $st['name'] ?? '';
                    $miles = $st['intervalMiles'] ?? null;
                    $months = $st['intervalMonths'] ?? null;
                }
                if ($name === '') continue;
                
                // Skip duplicates within same backup
                $nameKey = strtolower(trim($name));
                if (isset($insertedNames[$nameKey])) continue;
                $insertedNames[$nameKey] = true;
                
                $stmt->execute([
                    ':uid' => $userId,
                    ':name' => $name,
                    ':miles' => $miles,
                    ':months' => $months
                ]);
            }
        }
        
        // ============================================
        // RESTORE ENTRIES
        // ============================================
        $restoredVehicleIds = array_column($data['vehicles'] ?? [], 'id');
        
        if (!empty($data['entries'])) {
            $stmt = $pdo->prepare("INSERT INTO `entries` 
                (`id`,`vehicle_id`,`date`,`odo`,`notes`,`cost`,`next_date`,`next_odo`,`services_json`,`created_at`,`updated_at`)
                VALUES (:id,:vehicle_id,:date,:odo,:notes,:cost,:next_date,:next_odo,:services_json,:created_at,:updated_at)");
            
            foreach ($data['entries'] as $e) {
                // Skip entries for vehicles not being restored
                if (!in_array($e['vehicleId'], $restoredVehicleIds)) {
                    continue;
                }
                
                $services_json = null;
                if (isset($e['services']) && is_array($e['services'])) {
                    $services_json = json_encode($e['services'], JSON_UNESCAPED_UNICODE);
                }
                
                $stmt->execute([
                    ':id'            => $e['id'],
                    ':vehicle_id'    => $e['vehicleId'],
                    ':date'          => $e['date'] ?? null,
                    ':odo'           => $e['odo'] ?? null,
                    ':notes'         => $e['notes'] ?? null,
                    ':cost'          => $e['cost'] ?? null,
                    ':next_date'     => $e['nextDate'] ?? null,
                    ':next_odo'      => $e['nextOdo'] ?? null,
                    ':services_json' => $services_json,
                    ':created_at'    => $e['createdAt'] ?? date('c'),
                    ':updated_at'    => $e['updatedAt'] ?? date('c'),
                ]);
            }
        }
        
        // ============================================
        // RESTORE REMINDERS
        // ============================================
        if (!empty($data['reminders'])) {
            $stmt = $pdo->prepare("INSERT INTO `reminders` 
                (`id`,`vehicle_id`,`service_name`,`title`,`base_odo`,`base_date`,`interval_miles`,`interval_months`,`next_odo`,`next_date`,`notes`,`created_at`,`updated_at`)
                VALUES (:id,:vehicle_id,:service_name,:title,:base_odo,:base_date,:interval_miles,:interval_months,:next_odo,:next_date,:notes,:created_at,:updated_at)");
            
            foreach ($data['reminders'] as $r) {
                // Skip reminders for vehicles not being restored
                if (!in_array($r['vehicleId'], $restoredVehicleIds)) {
                    continue;
                }
                
                $stmt->execute([
                    ':id'              => $r['id'],
                    ':vehicle_id'      => $r['vehicleId'],
                    ':service_name'    => $r['serviceName'],
                    ':title'           => $r['title'] ?? null,
                    ':base_odo'        => $r['baseOdo'] ?? null,
                    ':base_date'       => $r['baseDate'] ?? null,
                    ':interval_miles'  => $r['intervalMiles'] ?? null,
                    ':interval_months' => $r['intervalMonths'] ?? null,
                    ':next_odo'        => $r['nextOdo'] ?? null,
                    ':next_date'       => $r['nextDate'] ?? null,
                    ':notes'           => $r['notes'] ?? null,
                    ':created_at'      => $r['createdAt'] ?? date('c'),
                    ':updated_at'      => $r['updatedAt'] ?? date('c'),
                ]);
            }
        }
        
        // ============================================
        // RESTORE VEHICLE INTERVALS
        // ============================================
        if (!empty($data['vehicleIntervals'])) {
            $stmt = $pdo->prepare("INSERT INTO `vehicle_intervals` (`vehicle_id`,`service_name`,`interval_miles`,`interval_months`)
                                   VALUES (:vehicle_id,:service_name,:interval_miles,:interval_months)");
            
            foreach ($data['vehicleIntervals'] as $vid => $services) {
                // Skip intervals for vehicles not being restored
                if (!in_array($vid, $restoredVehicleIds)) {
                    continue;
                }
                
                if (!is_array($services)) continue;
                
                foreach ($services as $sname => $intervals) {
                    $stmt->execute([
                        ':vehicle_id'      => $vid,
                        ':service_name'    => $sname,
                        ':interval_miles'  => $intervals['intervalMiles'] ?? null,
                        ':interval_months' => $intervals['intervalMonths'] ?? null,
                    ]);
                }
            }
        }
        
        // ============================================
        // RESTORE SETTINGS
        // ============================================
        if (!empty($data['settings'])) {
            $stmt = $pdo->prepare("INSERT INTO `settings` (`key`, `user_id`, `value`) VALUES (:k, :uid, :v)
                                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            
            $s = $data['settings'];
            $defaultAppName = defined('APP_NAME') ? APP_NAME : 'Garage Maintenance';
            $stmt->execute([':k' => 'site_title', ':uid' => $userId, ':v' => $s['siteTitle'] ?? $defaultAppName]);
            $stmt->execute([':k' => 'unit', ':uid' => $userId, ':v' => $s['unit'] ?? 'mi']);
            $stmt->execute([':k' => 'timezone', ':uid' => $userId, ':v' => $s['timezone'] ?? '']);
            $stmt->execute([':k' => 'keep_form_open', ':uid' => $userId, ':v' => !empty($s['keepFormOpen']) ? 'true' : 'false']);
            
            // Reminder thresholds
            if (isset($s['upcomingThresholdDays'])) {
                $stmt->execute([':k' => 'upcoming_threshold_days', ':uid' => $userId, ':v' => $s['upcomingThresholdDays']]);
            }
            if (isset($s['upcomingThresholdMiles'])) {
                $stmt->execute([':k' => 'upcoming_threshold_miles', ':uid' => $userId, ':v' => $s['upcomingThresholdMiles']]);
            }
            if (isset($s['overdueThresholdDays'])) {
                $stmt->execute([':k' => 'overdue_threshold_days', ':uid' => $userId, ':v' => $s['overdueThresholdDays']]);
            }
            if (isset($s['overdueThresholdMiles'])) {
                $stmt->execute([':k' => 'overdue_threshold_miles', ':uid' => $userId, ':v' => $s['overdueThresholdMiles']]);
            }
        }
        
        if (isset($data['activeVehicleId'])) {
            $stmt = $pdo->prepare("INSERT INTO `settings` (`key`, `user_id`, `value`) VALUES ('active_vehicle_id', :uid, :v)
                                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
            $stmt->execute([':uid' => $userId, ':v' => $data['activeVehicleId'] ?? '']);
        }
        
        // ============================================
        // RESTORE ENTRY TEMPLATES
        // ============================================
        if (!empty($data['entryTemplates']) && is_array($data['entryTemplates'])) {
            // Ensure table exists
            try {
                $pdo->query("SELECT 1 FROM `entry_templates` LIMIT 1");
            } catch (Throwable $e) {
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
            
            $stmt = $pdo->prepare("INSERT INTO `entry_templates` 
                (`id`, `user_id`, `name`, `services_json`, `misc_cost`, `notes`, 
                 `next_date_offset_days`, `next_odo_offset`, `created_at`, `updated_at`)
                VALUES (:id, :uid, :name, :services_json, :misc_cost, :notes,
                        :next_date_offset_days, :next_odo_offset, :created_at, :updated_at)");
            
            foreach ($data['entryTemplates'] as $t) {
                $services_json = null;
                if (isset($t['services']) && is_array($t['services'])) {
                    $services_json = json_encode($t['services'], JSON_UNESCAPED_UNICODE);
                }
                
                try {
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
                } catch (Throwable $e) {
                    // Skip problematic templates
                }
            }
        }
        
        // ============================================
        // RESTORE EMBEDDED ENTRY ATTACHMENTS
        // ============================================
        $attachmentsRestored = 0;
        $attachmentErrors = [];
        
        if (!empty($attachmentsEmbedded)) {
            // Ensure attachments directory exists
            if (!is_dir(ATTACHMENTS_PATH)) {
                @mkdir(ATTACHMENTS_PATH, 0755, true);
            }
            
            $stmt = $pdo->prepare("INSERT INTO `entry_attachments` 
                (`id`, `entry_id`, `name`, `mime_type`, `size`, `file_path`) 
                VALUES (:id, :entry_id, :name, :mime, :size, :path)");
            
            // Get restored entry IDs
            $restoredEntryIds = array_column($data['entries'] ?? [], 'id');
            
            foreach ($attachmentsEmbedded as $att) {
                try {
                    if (empty($att['data_base64']) || empty($att['entry_id']) || empty($att['id'])) {
                        continue;
                    }
                    
                    // Only restore attachments for entries in this backup
                    if (!in_array($att['entry_id'], $restoredEntryIds)) {
                        continue;
                    }
                    
                    // Decode base64
                    $fileData = base64_decode($att['data_base64']);
                    if ($fileData === false) {
                        $attachmentErrors[] = "Decode failed: " . ($att['name'] ?? 'unknown');
                        continue;
                    }
                    
                    // Create user/entry directory structure
                    $userDir = ATTACHMENTS_PATH . '/' . $userId;
                    if (!is_dir($userDir)) {
                        @mkdir($userDir, 0755, true);
                    }
                    $entryDir = $userDir . '/' . $att['entry_id'];
                    if (!is_dir($entryDir)) {
                        @mkdir($entryDir, 0755, true);
                    }
                    
                    // Get filename
                    $filename = !empty($att['file_path']) ? basename($att['file_path']) : ($att['name'] ?? 'file');
                    $filePath = $entryDir . '/' . $filename;
                    $relativePath = $userId . '/' . $att['entry_id'] . '/' . $filename;
                    
                    // Write file
                    if (@file_put_contents($filePath, $fileData) === false) {
                        $attachmentErrors[] = "Write failed: " . ($att['name'] ?? 'unknown');
                        continue;
                    }
                    
                    // Insert DB record
                    $stmt->execute([
                        ':id' => $att['id'],
                        ':entry_id' => $att['entry_id'],
                        ':name' => $att['name'] ?? 'attachment',
                        ':mime' => $att['mime_type'] ?? 'application/octet-stream',
                        ':size' => $att['size'] ?? strlen($fileData),
                        ':path' => $relativePath,
                    ]);
                    
                    $attachmentsRestored++;
                    
                } catch (Throwable $e) {
                    $attachmentErrors[] = ($att['name'] ?? 'unknown') . ": " . $e->getMessage();
                }
            }
        }
        
        // ============================================
        // RESTORE VEHICLE PHOTOS (NEW)
        // ============================================
        $vehiclePhotosRestored = 0;
        
        if (!empty($vehiclePhotosEmbedded)) {
            // Ensure attachments directory exists
            if (!is_dir(ATTACHMENTS_PATH)) {
                @mkdir(ATTACHMENTS_PATH, 0755, true);
            }
            
            foreach ($vehiclePhotosEmbedded as $photo) {
                try {
                    if (empty($photo['data_base64']) || empty($photo['vehicle_id'])) {
                        continue;
                    }
                    
                    // Only restore photos for vehicles in this backup
                    if (!in_array($photo['vehicle_id'], $restoredVehicleIds)) {
                        continue;
                    }
                    
                    // Decode base64
                    $fileData = base64_decode($photo['data_base64']);
                    if ($fileData === false) {
                        $attachmentErrors[] = "Vehicle photo decode failed: " . $photo['vehicle_id'];
                        continue;
                    }
                    
                    // Create user/vehicles directory structure
                    $userDir = ATTACHMENTS_PATH . '/' . $userId;
                    if (!is_dir($userDir)) {
                        @mkdir($userDir, 0755, true);
                    }
                    $vehiclesDir = $userDir . '/vehicles';
                    if (!is_dir($vehiclesDir)) {
                        @mkdir($vehiclesDir, 0755, true);
                    }
                    
                    // Get filename from original path or generate new one
                    $filename = !empty($photo['file_path']) ? basename($photo['file_path']) : ($photo['vehicle_id'] . '_' . time() . '.jpg');
                    $filePath = $vehiclesDir . '/' . $filename;
                    $relativePath = $userId . '/vehicles/' . $filename;
                    
                    // Write file
                    if (@file_put_contents($filePath, $fileData) === false) {
                        $attachmentErrors[] = "Vehicle photo write failed: " . $photo['vehicle_id'];
                        continue;
                    }
                    
                    // Update vehicle record with photo path
                    $stmt = $pdo->prepare("UPDATE `vehicles` SET `photo_path` = :path WHERE `id` = :id AND `user_id` = :uid");
                    $stmt->execute([
                        ':path' => $relativePath,
                        ':id' => $photo['vehicle_id'],
                        ':uid' => $userId
                    ]);
                    
                    $vehiclePhotosRestored++;
                    
                } catch (Throwable $e) {
                    $attachmentErrors[] = "Vehicle photo " . ($photo['vehicle_id'] ?? 'unknown') . ": " . $e->getMessage();
                }
            }
        }
        
        $pdo->commit();
        
        ob_end_clean();
        
        echo json_encode([
            'success' => true,
            'message' => 'Backup restored successfully',
            'data' => $data,
            'attachments_restored' => $attachmentsRestored,
            'vehicle_photos_restored' => $vehiclePhotosRestored,
            'attachments_errors' => $attachmentErrors
        ]);
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    ob_end_clean();
    error_log('Restore error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}