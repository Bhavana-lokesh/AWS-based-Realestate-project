CREATE TABLE IF NOT EXISTS `property_map_geocode_cache` (
    `address_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `latitude` DECIMAL(10,7) DEFAULT NULL,
    `longitude` DECIMAL(10,7) DEFAULT NULL,
    `display_name` TEXT DEFAULT NULL,
    `has_result` TINYINT(1) NOT NULL,
    `location_level` ENUM('address', 'locality') NOT NULL DEFAULT 'address',
    `zoom` TINYINT UNSIGNED NOT NULL DEFAULT 15,
    `expires_at` DATETIME NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`address_hash`),
    KEY `idx_property_map_geocode_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `property_map_geocode_state` (
    `state_id` TINYINT UNSIGNED NOT NULL,
    `last_request_at` DATETIME(6) DEFAULT NULL,
    PRIMARY KEY (`state_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `property_map_geocode_state` (`state_id`, `last_request_at`)
VALUES (1, NULL);
