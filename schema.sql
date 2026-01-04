-- ========================================
-- GARAGE MAINTENANCE - MULTI-USER SCHEMA
-- ========================================
-- Updated with vehicle details fields (year, make, model, engine, body_class, photo_path)

CREATE TABLE `vehicles` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_odo` int DEFAULT NULL,
  `vin` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plate` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` int DEFAULT NULL,
  `make` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_class` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `insurance_expiry` date DEFAULT NULL,
  `registration_expiry` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_vehicles_user` (`user_id`),
  KEY `idx_vehicles_make_model` (`make`, `model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `service_types` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `interval_miles` int DEFAULT NULL,
  `interval_months` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_service_name` (`user_id`,`name`),
  KEY `idx_service_types_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entries` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date DEFAULT NULL,
  `odo` int DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `cost` decimal(10,2) DEFAULT NULL,
  `next_date` date DEFAULT NULL,
  `next_odo` int DEFAULT NULL,
  `services_json` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_entries_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_entries_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reminders` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vehicle_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_odo` int DEFAULT NULL,
  `base_date` date DEFAULT NULL,
  `interval_miles` int DEFAULT NULL,
  `interval_months` int DEFAULT NULL,
  `next_odo` int DEFAULT NULL,
  `next_date` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reminders_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_reminders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `vehicle_intervals` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `vehicle_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `service_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `interval_miles` int DEFAULT NULL,
  `interval_months` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_vehicle_service` (`vehicle_id`,`service_name`),
  KEY `idx_vi_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_vi_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default',
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`key`,`user_id`),
  KEY `idx_settings_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `entry_attachments` (
  `id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` int DEFAULT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attach_entry` (`entry_id`),
  KEY `idx_file_path` (`file_path`),
  CONSTRAINT `fk_attach_entry` FOREIGN KEY (`entry_id`) REFERENCES `entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `entry_templates` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ========================================
-- VEHICLE DETAILS FIELDS
-- ========================================
-- The vehicles table now includes:
-- ✅ year - Model year (e.g., 2024)
-- ✅ make - Manufacturer (e.g., Toyota, Ford)
-- ✅ model - Model name (e.g., Camry, F-150)
-- ✅ engine - Engine description (e.g., 3.5L V6 Turbo)
-- ✅ body_class - Body type from NHTSA (e.g., Sedan, SUV, Pickup)
-- ✅ photo_path - Path to user-uploaded vehicle photo