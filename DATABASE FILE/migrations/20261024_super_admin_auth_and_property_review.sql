ALTER TABLE `user`
    ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN `phone_normalized` VARCHAR(32) DEFAULT NULL;

UPDATE `user`
SET `phone_normalized` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`phone_num`, '+', ''), ' ', ''), '-', ''), '(', ''), ')', ''), '.', '');

UPDATE `user`
SET `role` = CASE
    WHEN LOWER(TRIM(`role`)) IN ('admin', 'super admin', 'super_admin') THEN 'super_admin'
    WHEN LOWER(TRIM(`role`)) = 'seller' THEN 'Agent'
    WHEN LOWER(TRIM(`role`)) = 'agent' THEN 'Agent'
    WHEN LOWER(TRIM(`role`)) = 'buyer' THEN 'Buyer'
    ELSE `role`
END;

ALTER TABLE `user`
    ADD INDEX `idx_user_phone_role_active` (`phone_normalized`, `role`, `is_active`);

ALTER TABLE `property_listings`
    ADD COLUMN `review_status` ENUM('pending', 'approved', 'rejected') DEFAULT NULL,
    ADD COLUMN `rejection_reason` TEXT DEFAULT NULL;

UPDATE `property_listings` SET `review_status` = 'approved' WHERE `review_status` IS NULL;

ALTER TABLE `property_listings`
    MODIFY COLUMN `review_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending';

CREATE TABLE `auth_otp_challenges` (
    `challenge_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `purpose` ENUM('buyer_login', 'password_reset') NOT NULL,
    `code_hash` VARCHAR(255) NOT NULL,
    `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `resend_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `request_ip_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `last_sent_at` DATETIME NOT NULL,
    `consumed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`challenge_id`),
    KEY `idx_otp_user_purpose_created` (`user_id`, `purpose`, `created_at`),
    KEY `idx_otp_ip_purpose_created` (`request_ip_hash`, `purpose`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `auth_password_login_limits` (
    `identifier_hash` CHAR(64) NOT NULL,
    `request_ip_hash` CHAR(64) NOT NULL,
    `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `window_started_at` DATETIME NOT NULL,
    `locked_until` DATETIME DEFAULT NULL,
    PRIMARY KEY (`identifier_hash`, `request_ip_hash`),
    KEY `idx_password_login_limits_window` (`window_started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `property_reports` (
    `report_id` INT(11) NOT NULL AUTO_INCREMENT,
    `property_id` INT(11) NOT NULL,
    `reporter_id` INT(11) NOT NULL,
    `details` TEXT NOT NULL,
    `status` ENUM('open', 'reviewed', 'dismissed') NOT NULL DEFAULT 'open',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed_at` DATETIME DEFAULT NULL,
    `reviewed_by` INT(11) DEFAULT NULL,
    PRIMARY KEY (`report_id`),
    UNIQUE KEY `unique_reporter_property` (`reporter_id`, `property_id`),
    KEY `idx_property_reports_status_created` (`status`, `created_at`),
    KEY `idx_property_reports_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
